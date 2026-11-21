<?php

namespace App\Services;

use App\Models\Conge;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Lecture des pièces reçues des clients par l'IA (Claude, API Messages) : la pièce (PDF, image, tableur, Word)
 * est envoyée avec une consigne et un schéma JSON imposé (sorties structurées), et la réponse est un objet
 * conforme à ce schéma. Nécessite ANTHROPIC_API_KEY dans .env (modèle : ANTHROPIC_MODEL).
 *
 * Les montants sont lus, jamais inventés : tout doute est signalé dans « anomalies », et chaque proposition
 * reste à valider par un utilisateur autorisé.
 */
class LecturePieces
{
    public const EXT_IMAGES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    public const EXT_TABLEURS = ['xlsx', 'xls', 'ods', 'csv'];

    public static function disponible(): bool
    {
        return (bool) config('services.anthropic.key');
    }

    /** Vrai si l'IA peut lire ce fichier (PDF, image, tableur, Word, texte). */
    public static function lisible(string $nom): bool
    {
        $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
        return $ext === 'pdf' || isset(self::EXT_IMAGES[$ext]) || in_array($ext, [...self::EXT_TABLEURS, 'docx', 'txt'], true);
    }

    // ---------- Lectures par type de pièce ----------

    /** Facture d'achat, de vente ou d'immobilisation. $comptes : « numéro libellé » des comptes autorisés. */
    public static function facture(string $chemin, string $nom, string $sens, array $comptes): array
    {
        $tiers = $sens === 'vente' ? 'le client (destinataire de la facture)' : 'le fournisseur (émetteur de la facture)';
        $consigne = "Lis cette facture ".($sens === 'vente' ? 'de vente émise par notre client' : 'reçue par notre client')." et extrais-en les données comptables. "
            ."Le tiers est $tiers. Ventile le hors taxe par taux de TVA et par nature, en choisissant pour chaque ligne un compte parmi la liste fournie. "
            .'Date au format AAAA-MM-JJ ; montants en dinars tunisiens avec 3 décimales ; timbre fiscal à part. '
            .'Si un élément est illisible ou absent (matricule fiscal, date, total), laisse-le vide et signale-le dans anomalies, ainsi que toute incohérence de calcul.';
        return self::lire($chemin, $nom, $consigne.self::listeComptes($comptes), self::schemaFacture());
    }

    /** Tableau (liste) de factures : une entrée par facture. */
    public static function tableauFactures(string $chemin, string $nom, string $sens, array $comptes): array
    {
        $consigne = 'Ce document est un tableau récapitulatif des factures '.($sens === 'vente' ? 'de vente' : 'd’achat').' de notre client. '
            .'Extrais chaque facture (une entrée par ligne du tableau) avec son tiers, sa référence, sa date (AAAA-MM-JJ), son hors taxe ventilé par taux de TVA, '
            .'son timbre et son total. Choisis le compte de chaque ligne parmi la liste fournie. Ignore les lignes de totaux. '
            .'Signale dans anomalies les lignes illisibles ou incohérentes.';
        return self::lire($chemin, $nom, $consigne.self::listeComptes($comptes), [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['factures', 'anomalies'],
            'properties' => ['factures' => ['type' => 'array', 'items' => self::schemaFacture()], 'anomalies' => self::texteListe()],
        ]);
    }

    /** Traites, chèques, état de caisse ou pièce diverse : écritures équilibrées à proposer. */
    public static function ecritures(string $chemin, string $nom, string $nature, string $journal, array $comptes): array
    {
        $consigne = match ($nature) {
            'traites_cheques' => 'Ce document contient des copies de traites (effets) ou de chèques de notre client. Pour chaque traite ou chèque, propose l’écriture '
                .'correspondante (règlement d’un fournisseur ou encaissement d’un client par banque, effet à payer ou à recevoir), avec le tiers et le numéro dans le libellé.',
            'etat_caisse' => 'Ce document est un état (brouillard) de caisse de notre client. Propose une écriture par opération de caisse (recette ou dépense), '
                .'en contrepartie du compte de caisse 54.',
            default => 'Ce document est une pièce comptable de notre client. Propose la ou les écritures d’opérations diverses qu’elle justifie.',
        };
        $consigne .= " Journal à utiliser : $journal. Chaque écriture doit être équilibrée (total débit = total crédit), avec des comptes choisis dans la liste fournie. "
            .'Dates au format AAAA-MM-JJ, montants en dinars avec 3 décimales. Signale dans anomalies tout montant illisible ou toute écriture incertaine.';
        $ligne = ['type' => 'object', 'additionalProperties' => false, 'required' => ['compte', 'libelle', 'debit', 'credit'],
            'properties' => ['compte' => ['type' => 'string'], 'libelle' => ['type' => 'string'], 'debit' => ['type' => 'number'], 'credit' => ['type' => 'number']]];
        return self::lire($chemin, $nom, $consigne.self::listeComptes($comptes), [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['ecritures', 'anomalies'],
            'properties' => [
                'ecritures' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['date', 'libelle', 'lignes'],
                    'properties' => ['date' => ['type' => 'string'], 'libelle' => ['type' => 'string'], 'lignes' => ['type' => 'array', 'items' => $ligne]]]],
                'anomalies' => self::texteListe(),
            ],
        ]);
    }

    /** Contrat de leasing (crédit-bail) : le bien financé, pour le registre des immobilisations. */
    public static function leasing(string $chemin, string $nom): array
    {
        $consigne = 'Ce document est un contrat de leasing (crédit-bail) signé par notre client, le preneur. Extrais le bien financé et les conditions du contrat. '
            .'nature : la catégorie du bien parmi '.implode(', ', array_keys(Amortissement::NATURES)).'. Dates AAAA-MM-JJ, montants en dinars avec 3 décimales. '
            .'Signale dans anomalies les éléments absents ou illisibles.';
        return self::lire($chemin, $nom, $consigne, [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['bailleur', 'reference_contrat', 'designation', 'nature', 'date_debut', 'valeur_bien_ht', 'tva', 'duree_mois', 'loyer_mensuel_ht', 'valeur_residuelle', 'anomalies'],
            'properties' => [
                'bailleur' => ['type' => 'string'], 'reference_contrat' => ['type' => 'string'], 'designation' => ['type' => 'string'],
                'nature' => ['type' => 'string', 'enum' => array_keys(Amortissement::NATURES)], 'date_debut' => ['type' => 'string'],
                'valeur_bien_ht' => ['type' => 'number'], 'tva' => ['type' => 'number'], 'duree_mois' => ['type' => 'integer'],
                'loyer_mensuel_ht' => ['type' => 'number'], 'valeur_residuelle' => ['type' => 'number'], 'anomalies' => self::texteListe(),
            ],
        ]);
    }

    /** Pointage de la paie : jours travaillés, heures supplémentaires et primes par salarié. */
    public static function pointage(string $chemin, string $nom): array
    {
        return self::lire($chemin, $nom, 'Ce document est le pointage mensuel du personnel de notre client. Pour chaque salarié, relève son matricule, son numéro de CIN '
            .'et son nom tels qu’ils figurent (vides s’ils sont absents), les jours travaillés, les heures supplémentaires et les primes du mois (0 si absent). '
            .'mois : le mois concerné au format AAAA-MM s’il est indiqué, sinon vide.', [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['mois', 'lignes', 'anomalies'],
            'properties' => ['mois' => ['type' => 'string'], 'lignes' => ['type' => 'array', 'items' => self::schemaSalarie([
                'jours' => ['type' => 'number'], 'heures_sup' => ['type' => 'number'], 'prime' => ['type' => 'number']])], 'anomalies' => self::texteListe()],
        ]);
    }

    /** État des congés. */
    public static function conges(string $chemin, string $nom): array
    {
        return self::lire($chemin, $nom, 'Ce document est un état des congés du personnel de notre client. Relève chaque congé : salarié (matricule, CIN, nom), type de congé, '
            .'date de début et de fin (AAAA-MM-JJ), nombre de jours s’il est indiqué (0 sinon), observation.', [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['lignes', 'anomalies'],
            'properties' => ['lignes' => ['type' => 'array', 'items' => self::schemaSalarie([
                'type' => ['type' => 'string', 'enum' => array_keys(Conge::TYPES)], 'du' => ['type' => 'string'], 'au' => ['type' => 'string'],
                'jours' => ['type' => 'number'], 'observation' => ['type' => 'string']])], 'anomalies' => self::texteListe()],
        ]);
    }

    /** État des acomptes et des prêts au personnel. */
    public static function acomptesPrets(string $chemin, string $nom): array
    {
        return self::lire($chemin, $nom, 'Ce document est un état des acomptes et des prêts accordés au personnel de notre client. Pour chaque ligne : salarié (matricule, CIN, nom), '
            .'nature (acompte sur salaire, prêt de la société ou prêt CNSS), date (AAAA-MM-JJ), montant, mois de la première retenue sur la paie (AAAA-MM), '
            .'et pour un prêt le nombre d’échéances ou le montant de l’échéance (0 si absent) et la référence.', [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['lignes', 'anomalies'],
            'properties' => ['lignes' => ['type' => 'array', 'items' => self::schemaSalarie([
                'nature' => ['type' => 'string', 'enum' => ['acompte', 'pret_societe', 'pret_cnss']], 'date' => ['type' => 'string'], 'montant' => ['type' => 'number'],
                'mois_retenue' => ['type' => 'string'], 'nb_echeances' => ['type' => 'integer'], 'echeance' => ['type' => 'number'], 'reference' => ['type' => 'string']])],
                'anomalies' => self::texteListe()],
        ]);
    }

    /** Document d'un salarié (CIN, contrat, CIVP…) : à qui il appartient et ce que c'est. */
    public static function documentSalarie(string $chemin, string $nom): array
    {
        return self::lire($chemin, $nom, 'Ce document concerne un salarié de notre client (carte d’identité, contrat de travail, contrat CIVP, diplôme…). '
            .'Indique le nom et le prénom du salarié, son numéro de CIN s’il figure, la nature du document, et pour une carte d’identité la face (recto ou verso).', [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['nom', 'prenom', 'cin', 'nature', 'face', 'anomalies'],
            'properties' => ['nom' => ['type' => 'string'], 'prenom' => ['type' => 'string'], 'cin' => ['type' => 'string'],
                'nature' => ['type' => 'string', 'enum' => ['cin', 'contrat', 'civp', 'diplome', 'autre']], 'face' => ['type' => 'string', 'enum' => ['recto', 'verso', 'sans_objet']],
                'anomalies' => self::texteListe()],
        ]);
    }

    // ---------- Appel de l'API ----------

    /** Envoie la pièce et la consigne ; renvoie l'objet JSON conforme au schéma. */
    public static function lire(string $chemin, string $nom, string $consigne, array $schema): array
    {
        $key = config('services.anthropic.key');
        if (! $key) throw new RuntimeException('La lecture automatique n’est pas configurée (ANTHROPIC_API_KEY dans le fichier .env).');
        $contenu = self::contenu($chemin, $nom);
        $contenu[] = ['type' => 'text', 'text' => $consigne];

        $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'anthropic-beta' => 'server-side-fallback-2026-07-01'])
            ->timeout(300)->retry(2, 3000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException
                || ($e instanceof \Illuminate\Http\Client\RequestException && in_array($e->response->status(), [429, 500, 502, 503, 529], true)), throw: false)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 16000,
                'system' => 'Tu es un expert-comptable tunisien (Système comptable des entreprises). Tu lis des pièces avec rigueur : tu recopies ce qui est écrit, '
                    .'tu n’inventes aucun montant, et tu signales tout doute.',
                'messages' => [['role' => 'user', 'content' => $contenu]],
                'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                // En cas de refus par les filtres de sécurité, l'API relance la même requête sur un modèle de repli
                'fallbacks' => 'default',
            ]);
        if ($res->failed()) {
            throw new RuntimeException('Lecture par l’IA impossible ('.$res->status().') : '.($res->json('error.message') ?? 'réponse inattendue'));
        }
        $j = $res->json();
        if (($j['stop_reason'] ?? null) === 'refusal') throw new RuntimeException('L’IA a refusé de lire ce document.');
        if (($j['stop_reason'] ?? null) === 'max_tokens') throw new RuntimeException('Document trop long pour être lu en une fois : découpez-le.');
        $texte = collect($j['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
        $data = json_decode($texte, true);
        if (! is_array($data)) throw new RuntimeException('Réponse de l’IA illisible.');
        return $data;
    }

    /** Blocs de contenu : PDF et images tels quels, tableurs et Word convertis en texte. */
    private static function contenu(string $chemin, string $nom): array
    {
        $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
        if (! is_file($chemin)) throw new RuntimeException('Fichier introuvable.');
        if ($ext === 'pdf') {
            return [['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode((string) file_get_contents($chemin))]]];
        }
        if (isset(self::EXT_IMAGES[$ext])) {
            return [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => self::EXT_IMAGES[$ext], 'data' => base64_encode((string) file_get_contents($chemin))]]];
        }
        if (in_array($ext, self::EXT_TABLEURS, true)) return [['type' => 'text', 'text' => "Contenu du fichier « $nom » (tableur, une ligne par rangée, cellules séparées par ;) :\n".self::tableurEnTexte($chemin)]];
        if ($ext === 'docx') return [['type' => 'text', 'text' => "Contenu du document Word « $nom » :\n".self::docxEnTexte($chemin)]];
        if ($ext === 'txt') return [['type' => 'text', 'text' => "Contenu du fichier « $nom » :\n".file_get_contents($chemin)]];
        throw new RuntimeException('Format non lisible automatiquement : '.$ext.'.');
    }

    public static function tableurEnTexte(string $chemin): string
    {
        $out = [];
        foreach (IOFactory::load($chemin)->getAllSheets() as $feuille) {
            $out[] = '# Feuille : '.$feuille->getTitle();
            foreach ($feuille->toArray(null, true, true, false) as $ligne) {
                $cellules = array_map(fn ($v) => trim(str_replace(["\r", "\n", ';'], ' ', (string) $v)), $ligne);
                if (implode('', $cellules) !== '') $out[] = implode(';', $cellules);
            }
        }
        return implode("\n", $out);
    }

    private static function docxEnTexte(string $chemin): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($chemin) !== true) throw new RuntimeException('Document Word illisible.');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        $xml = preg_replace(['#</w:p>#', '#<w:tab/>#'], ["\n", "\t"], $xml);
        return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    // ---------- Schémas ----------

    private static function schemaFacture(): array
    {
        $ligne = ['type' => 'object', 'additionalProperties' => false, 'required' => ['compte', 'libelle', 'ht', 'taux_tva', 'tva'],
            'properties' => ['compte' => ['type' => 'string'], 'libelle' => ['type' => 'string'], 'ht' => ['type' => 'number'], 'taux_tva' => ['type' => 'number'], 'tva' => ['type' => 'number']]];
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['tiers', 'matricule_fiscal', 'reference', 'date', 'description', 'lignes', 'timbre', 'ttc', 'retenue_taux', 'anomalies'],
            'properties' => [
                'tiers' => ['type' => 'string'], 'matricule_fiscal' => ['type' => 'string'], 'reference' => ['type' => 'string'], 'date' => ['type' => 'string'],
                'description' => ['type' => 'string'], 'lignes' => ['type' => 'array', 'items' => $ligne], 'timbre' => ['type' => 'number'], 'ttc' => ['type' => 'number'],
                'retenue_taux' => ['type' => 'number'], 'anomalies' => self::texteListe(),
            ]];
    }

    private static function schemaSalarie(array $champs): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['matricule', 'cin', 'nom', ...array_keys($champs)],
            'properties' => ['matricule' => ['type' => 'string'], 'cin' => ['type' => 'string'], 'nom' => ['type' => 'string']] + $champs];
    }

    private static function texteListe(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }

    private static function listeComptes(array $comptes): string
    {
        return "\n\nComptes autorisés (numéro libellé) :\n".implode("\n", $comptes);
    }
}
