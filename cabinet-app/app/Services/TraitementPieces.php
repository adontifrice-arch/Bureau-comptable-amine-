<?php

namespace App\Services;

use App\Http\Controllers\PaieDossierController;
use App\Jobs\TraiterPieceRecue;
use App\Models\Client;
use App\Models\Document;
use App\Models\EcritureProposee;
use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\Immobilisation;
use App\Models\Salarie;
use App\Models\SalarieDocument;
use App\Services\Releves\ImportReleve;
use App\Support\CabinetCourant;
use App\Support\TypesPieces;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Traitement des documents reçus des clients, selon leur type (App\Support\TypesPieces) :
 * - comptabilité : factures d'achat et de vente (et leurs tableaux) proposées aux journaux ACH / VTE, relevés bancaires importés
 *   pour le journal BQ, traites, chèques, états de caisse et pièces diverses proposés en écritures (BQ, CAI, OD) — tout reste à valider ;
 * - immobilisations : facture d'immobilisation proposée au journal des achats (le bien entre au registre à sa validation),
 *   contrat de leasing inscrit au registre ;
 * - paie : pointage reporté dans la paie du mois, congés, acomptes et prêts lus puis importés après vérification,
 *   documents des salariés classés dans leur fiche.
 * Sans lecture automatique (pas de clé d'API, format non lisible), le document attend dans le module, « à saisir ».
 */
class TraitementPieces
{
    /** Classe le document reçu et, s'il est à traiter, le met en file de lecture (immédiate en mode synchrone). */
    public static function recevoir(Document $doc, ?string $type, ?string $periode = null): void
    {
        $type = isset(TypesPieces::TYPES[$type]) ? $type : 'autre';
        $dest = TypesPieces::destination($type);
        $doc->forceFill(['type_piece' => $type, 'destination' => $dest, 'categorie' => TypesPieces::categorie($type), 'periode' => $periode,
            'traitement' => $dest === 'ged' ? null : 'en_attente', 'traitement_message' => null, 'traitement_at' => null, 'resultat' => null])->save();
        if ($dest !== 'ged') TraiterPieceRecue::dispatch($doc->id, (int) $doc->cabinet_id);
    }

    /** Traite un document (appelé par la tâche en file, dans le cabinet du document). */
    public static function traiter(Document $doc): void
    {
        $client = $doc->client;
        if (! $client) { self::etat($doc, 'erreur', 'Document sans dossier client.'); return; }
        $chemin = Storage::path($doc->chemin);
        try {
            match ($doc->type_piece) {
                'facture_achat' => self::facture($doc, $client, $chemin, 'achat'),
                'facture_vente' => self::facture($doc, $client, $chemin, 'vente'),
                'facture_immobilisation' => self::facture($doc, $client, $chemin, 'immobilisation'),
                'tableau_achats' => self::tableau($doc, $client, $chemin, 'achat'),
                'tableau_ventes' => self::tableau($doc, $client, $chemin, 'vente'),
                'releve_bancaire' => self::releve($doc, $client, $chemin),
                'traites_cheques', 'etat_caisse', 'piece_od' => self::ecritures($doc, $client, $chemin),
                'contrat_leasing' => self::leasing($doc, $client, $chemin),
                'pointage' => self::pointage($doc, $client, $chemin),
                'etat_conges' => self::etatPaie($doc, $chemin, 'conges'),
                'etat_acomptes_prets' => self::etatPaie($doc, $chemin, 'acomptes_prets'),
                'contrat_travail', 'piece_identite', 'document_salarie' => self::documentSalarie($doc, $client, $chemin),
                default => self::etat($doc, null, null),
            };
        } catch (SansLecture $e) {
            self::etat($doc, 'a_saisir', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            self::etat($doc, 'erreur', $e->getMessage());
        }
    }

    // ---------- Comptabilité ----------

    private static function facture(Document $doc, Client $client, string $chemin, string $sens): void
    {
        self::exigerLecture($doc, 'la facture dans Comptabilité → '.($sens === 'vente' ? 'Factures de vente' : 'Factures d’achat'));
        $j = LecturePieces::facture($chemin, $doc->nom, $sens === 'vente' ? 'vente' : 'achat', self::comptes($sens));
        $id = self::creerFacture($doc, $client, $j, $sens);
        $journal = $sens === 'vente' ? 'des ventes' : 'des achats';
        self::etat($doc, 'propose', 'Facture lue : proposée au journal '.$journal.($sens === 'immobilisation' ? ' (le bien entrera au registre des immobilisations à sa validation)' : '').', à vérifier et valider.',
            [$sens === 'vente' ? 'factures_ventes' : 'factures_recues' => [$id], 'anomalies' => $j['anomalies'] ?? []]);
    }

    private static function tableau(Document $doc, Client $client, string $chemin, string $sens): void
    {
        self::exigerLecture($doc, 'les factures dans Comptabilité → '.($sens === 'vente' ? 'Factures de vente' : 'Factures d’achat'));
        $j = LecturePieces::tableauFactures($chemin, $doc->nom, $sens, self::comptes($sens));
        $ids = [];
        foreach ((array) ($j['factures'] ?? []) as $f) {
            if ($id = self::creerFacture($doc, $client, $f, $sens, false)) $ids[] = $id;
        }
        if (! $ids) throw new SansLecture('Aucune facture reconnue dans le tableau : saisissez-les dans la comptabilité.');
        self::etat($doc, 'propose', count($ids).' facture(s) lue(s) : proposée(s) au journal '.($sens === 'vente' ? 'des ventes' : 'des achats').', à vérifier et valider.',
            [$sens === 'vente' ? 'factures_ventes' : 'factures_recues' => $ids, 'anomalies' => $j['anomalies'] ?? []]);
    }

    /** Facture lue → proposition (statut « prop ») ; une copie du fichier reste attachée à la facture. Renvoie son identifiant. */
    private static function creerFacture(Document $doc, Client $client, array $j, string $sens, bool $exigerMontant = true): ?int
    {
        $vente = $sens === 'vente';
        $param = $vente ? Saisie::vente($client->id) : Saisie::achat($client->id);
        $lignes = collect((array) ($j['lignes'] ?? []))->map(fn ($l) => ['compte' => preg_replace('/\D/', '', (string) ($l['compte'] ?? '')),
            'ht' => (float) ($l['ht'] ?? 0), 'tva_taux' => (float) ($l['taux_tva'] ?? $param['tva_taux']), 'tva' => (float) ($l['tva'] ?? 0)])->all();
        $v = ($vente ? FactureVente::class : FactureRecue::class)::ventiler($lignes, (float) $param['tva_taux']);
        if ($v['ht'] <= 0) {
            if ($exigerMontant) throw new SansLecture('Montant hors taxe illisible sur la facture : saisissez-la dans la comptabilité.');
            return null;
        }
        $date = self::date($j['date'] ?? null) ?? ($doc->date ?? now());
        $tiers = trim((string) ($j['tiers'] ?? '')) ?: ($vente ? 'Client à préciser' : 'Fournisseur à préciser');
        $fichier = self::copier($doc, $vente ? 'factures-ventes' : 'factures-recues');
        $commun = $v + ['client_id' => $client->id, 'date' => $date, 'matricule_fiscal' => trim((string) ($j['matricule_fiscal'] ?? '')) ?: null,
            'reference' => trim((string) ($j['reference'] ?? '')) ?: null, 'description' => Str::limit(trim((string) ($j['description'] ?? '')), 250, '') ?: null,
            'timbre' => (float) ($j['timbre'] ?? 0), 'statut' => 'prop', 'fichier' => $fichier, 'document_id' => $doc->id];
        if ($vente) return FactureVente::create($commun + ['tiers' => Str::limit($tiers, 250, '')])->id;
        return FactureRecue::create($commun + ['fournisseur' => Str::limit($tiers, 250, ''), 'retenue_taux' => (float) ($j['retenue_taux'] ?? 0),
            'tva_deductible' => true, 'anomalies' => array_values((array) ($j['anomalies'] ?? []))])->id;
    }

    private static function releve(Document $doc, Client $client, string $chemin): void
    {
        $fichier = new UploadedFile($chemin, $doc->nom, $doc->mime, null, true);
        try {
            [$releve, $doublons] = ImportReleve::importer($client, $fichier, null);
        } catch (RuntimeException $e) {
            throw new SansLecture($e->getMessage().' Importez le relevé depuis Comptabilité → Banque.');
        }
        $n = $releve->lignes()->count();
        self::etat($doc, 'propose', $n.' opération(s) importée(s) au relevé'.($doublons ? ' ('.$doublons.' déjà importée(s) écartée(s))' : '')
            .' : vérifiez les contreparties puis comptabilisez au journal de banque.', ['releve' => $releve->id]);
    }

    private static function ecritures(Document $doc, Client $client, string $chemin): void
    {
        $journal = TypesPieces::journal($doc->type_piece) ?? 'OD';
        self::exigerLecture($doc, 'l’écriture dans Comptabilité → Journal & écritures');
        $j = LecturePieces::ecritures($chemin, $doc->nom, $doc->type_piece, $journal, self::comptes('tous'));
        $ids = [];
        foreach ((array) ($j['ecritures'] ?? []) as $e) {
            $lignes = collect((array) ($e['lignes'] ?? []))->map(fn ($l) => ['compte' => preg_replace('/\D/', '', (string) ($l['compte'] ?? '')),
                'libelle' => Str::limit((string) ($l['libelle'] ?? ''), 120, ''), 'debit' => r3($l['debit'] ?? 0), 'credit' => r3($l['credit'] ?? 0)])
                ->filter(fn ($l) => $l['compte'] !== '' && ($l['debit'] || $l['credit']))->values()->all();
            if (count($lignes) < 2) continue;
            $ids[] = EcritureProposee::create(['client_id' => $client->id, 'document_id' => $doc->id, 'journal' => $journal,
                'date' => self::date($e['date'] ?? null) ?? ($doc->date ?? now()), 'libelle' => Str::limit(trim((string) ($e['libelle'] ?? '')) ?: TypesPieces::libelle($doc->type_piece), 250, ''),
                'lignes' => $lignes, 'anomalies' => array_values((array) ($j['anomalies'] ?? [])), 'statut' => 'prop'])->id;
        }
        if (! $ids) throw new SansLecture('Aucune écriture reconnue sur la pièce : saisissez-la dans Comptabilité → Journal & écritures.');
        self::etat($doc, 'propose', count($ids).' écriture(s) proposée(s) au journal '.(Ecriture::JOURNAUX[$journal] ?? $journal).', à vérifier et valider (Comptabilité → Pièces reçues).',
            ['ecritures_proposees' => $ids, 'anomalies' => $j['anomalies'] ?? []]);
    }

    /** Écriture proposée → journal ; elle doit être équilibrée et sur des comptes du plan comptable. */
    public static function validerEcriture(EcritureProposee $p, ?int $user): Ecriture
    {
        if ($p->statut !== 'prop') throw new RuntimeException('Cette écriture a déjà été traitée.');
        if (! $p->equilibree()) throw new RuntimeException('Écriture déséquilibrée : débit '.tnd($p->totalDebit()).' ≠ crédit '.tnd($p->totalCredit()).'. Corrigez-la avant de valider.');
        $plan = Compta::plan();
        $inconnus = collect($p->lignes)->pluck('compte')->filter(fn ($c) => ! self::compteExiste((string) $c, $plan))->unique()->values();
        if ($inconnus->isNotEmpty()) throw new RuntimeException('Compte(s) absent(s) du plan comptable : '.$inconnus->implode(', ').'. Corrigez l’écriture ou ajoutez le compte.');
        return \Illuminate\Support\Facades\DB::transaction(function () use ($p, $user) {
            $e = Compta::passer($p->client_id, $p->date->format('Y-m-d'), $p->journal, $p->libelle, $p->lignes, 'ep:'.$p->id, $user);
            $p->update(['statut' => 'val', 'ecriture_id' => $e->id, 'valide_par' => $user]);
            return $e;
        });
    }

    // ---------- Immobilisations ----------

    private static function leasing(Document $doc, Client $client, string $chemin): void
    {
        self::exigerLecture($doc, 'le bien dans Immobilisations → Registre');
        $j = LecturePieces::leasing($chemin, $doc->nom);
        $valeur = r3($j['valeur_bien_ht'] ?? 0);
        $date = self::date($j['date_debut'] ?? null);
        if ($valeur <= 0 || ! $date) throw new SansLecture('Valeur du bien ou date de début illisible : inscrivez le bien dans Immobilisations → Registre.');
        $nature = isset(Amortissement::NATURES[$j['nature'] ?? '']) ? $j['nature'] : 'materiel';
        $i = Immobilisation::create(['client_id' => $client->id, 'nature' => $nature, 'designation' => Str::limit('Crédit-bail : '.(trim((string) ($j['designation'] ?? '')) ?: 'bien financé'), 250, ''),
            'date_mise_service' => $date, 'valeur_origine' => $valeur, 'tva' => r3($j['tva'] ?? 0), 'fournisseur' => Str::limit(trim((string) ($j['bailleur'] ?? '')), 250, '') ?: null,
            'reference' => Str::limit(trim((string) ($j['reference_contrat'] ?? '')), 100, '') ?: null, 'methode' => 'lineaire',
            'taux' => Amortissement::NATURES[$nature][3], 'prorata' => 'jour', 'valeur_residuelle' => min(r3($j['valeur_residuelle'] ?? 0), max(0, $valeur - 0.001))]);
        self::etat($doc, 'importe', 'Bien en crédit-bail inscrit au registre ('.tnd($valeur).', '.((int) ($j['duree_mois'] ?? 0) ?: '?').' mois) : vérifiez la nature, le taux et l’écriture d’acquisition à passer selon le contrat.',
            ['immobilisations' => [$i->id], 'leasing' => $j, 'anomalies' => $j['anomalies'] ?? []]);
    }

    // ---------- Paie ----------

    private static function pointage(Document $doc, Client $client, string $chemin): void
    {
        $ext = strtolower(pathinfo($doc->nom, PATHINFO_EXTENSION));
        $mois = $doc->periode;
        $lignes = null;
        if (in_array($ext, LecturePieces::EXT_TABLEURS, true)) $lignes = self::pointageTableur($chemin);    // colonnes reconnues sans IA
        if (! $lignes) {
            self::exigerLecture($doc, 'le pointage dans Paie → Fichier → Importer le pointage');
            $j = LecturePieces::pointage($chemin, $doc->nom);
            $mois = $mois ?: (preg_match('/^\d{4}-\d{2}$/', (string) ($j['mois'] ?? '')) ? $j['mois'] : null);
            $lignes = collect((array) ($j['lignes'] ?? []))->map(fn ($l) => ['matricule' => $l['matricule'] ?? null, 'cin' => $l['cin'] ?? null, 'nom' => $l['nom'] ?? null,
                'jours' => isset($l['jours']) ? (float) $l['jours'] : null, 'hs' => isset($l['heures_sup']) ? (float) $l['heures_sup'] : null, 'prime' => isset($l['prime']) ? (float) $l['prime'] : null])->all();
        }
        $mois = $mois ?: now()->subMonthNoOverflow()->format('Y-m');
        $sal = $client->salaries()->get();
        $parMois = []; $inconnus = [];
        foreach ($lignes as $l) {
            $e = PaieDossierController::trouverSalarie($sal, self::texte($l['matricule']), self::texte($l['cin']), self::texte($l['nom']));
            if (! $e) { if ($n = self::texte($l['nom']) ?: self::texte($l['matricule']) ?: self::texte($l['cin'])) $inconnus[] = $n; continue; }
            $parMois[$mois][$e->id] = ['jours' => $l['jours'], 'hs' => $l['hs'], 'prime' => $l['prime']];
        }
        if (! $parMois) throw new SansLecture('Aucun salarié du dossier reconnu dans le pointage'.($inconnus ? ' ('.implode(', ', array_slice($inconnus, 0, 5)).')' : '').' : importez-le depuis Paie → Fichier.');
        [$faits, $clos] = PaieDossierController::appliquerPointage($client, $parMois);
        if (! $faits) throw new SansLecture('La paie de '.mois_fr($mois).' est déjà clôturée : le pointage n’a pas été reporté.');
        self::etat($doc, $inconnus ? 'propose' : 'importe', 'Pointage reporté dans la paie de '.mois_fr($mois).' ('.count($parMois[$mois]).' salarié(s))'
            .($inconnus ? ' ; non reconnu(s) : '.implode(', ', array_slice(array_unique($inconnus), 0, 5)).' — à saisir' : '').'.', ['mois' => $mois, 'inconnus' => array_values(array_unique($inconnus))]);
    }

    /** Pointage d'un tableur aux colonnes reconnues (matricule / CIN / nom, jours, heures sup., primes) ; null si non reconnu. */
    private static function pointageTableur(string $chemin): ?array
    {
        try {
            $ws = IOFactory::load($chemin)->getActiveSheet();
        } catch (\Throwable) {
            return null;
        }
        $brut = $ws->toArray(null, true, false, false);
        $cols = [];
        foreach ($brut[0] ?? [] as $i => $entete) if ($k = PaieDossierController::colonnePointage((string) $entete)) $cols[$k] ??= $i;
        if (! array_intersect_key($cols, array_flip(['matricule', 'cin', 'nom'])) || ! array_intersect_key($cols, array_flip(['jours', 'hs', 'prime']))) return null;
        $num = fn ($v) => $v === null || $v === '' ? null : (is_numeric($v) ? (float) $v : (is_numeric($x = str_replace([' ', ','], ['', '.'], (string) $v)) ? (float) $x : null));
        $lignes = [];
        foreach (array_slice($brut, 1) as $l) {
            $v = fn ($k) => isset($cols[$k]) ? $l[$cols[$k]] : null;
            if (! array_filter([$v('matricule'), $v('cin'), $v('nom')])) continue;
            $lignes[] = ['matricule' => $v('matricule'), 'cin' => $v('cin'), 'nom' => $v('nom'), 'jours' => $num($v('jours')), 'hs' => $num($v('hs')), 'prime' => $num($v('prime'))];
        }
        return $lignes ?: null;
    }

    /** Congés, acomptes et prêts lus : gardés en proposition, importés après vérification (Paie → Documents reçus). */
    private static function etatPaie(Document $doc, string $chemin, string $nature): void
    {
        self::exigerLecture($doc, $nature === 'conges' ? 'les congés dans Paie → Congés' : 'les acomptes et prêts dans Paie');
        $j = $nature === 'conges' ? LecturePieces::conges($chemin, $doc->nom) : LecturePieces::acomptesPrets($chemin, $doc->nom);
        $lignes = array_values((array) ($j['lignes'] ?? []));
        if (! $lignes) throw new SansLecture('Aucune ligne reconnue dans le document : saisissez-les dans la paie.');
        self::etat($doc, 'propose', count($lignes).' ligne(s) lue(s) : vérifiez-les puis importez-les (Paie → Documents reçus).',
            ['lignes' => $lignes, 'anomalies' => $j['anomalies'] ?? []]);
    }

    /** Contrat, CIN ou autre document d'un salarié : classé dans sa fiche quand le salarié est reconnu. */
    private static function documentSalarie(Document $doc, Client $client, string $chemin): void
    {
        self::exigerLecture($doc, 'en choisissant le salarié (Paie → Documents reçus)');
        $j = LecturePieces::documentSalarie($chemin, $doc->nom);
        $sal = $client->salaries()->get();
        $e = PaieDossierController::trouverSalarie($sal, null, self::texte($j['cin'] ?? null), self::texte($j['nom'] ?? null))
            ?? PaieDossierController::trouverSalarie($sal, null, null, trim(($j['nom'] ?? '').' '.($j['prenom'] ?? '')))
            ?? PaieDossierController::trouverSalarie($sal, null, null, trim(($j['prenom'] ?? '').' '.($j['nom'] ?? '')));
        $type = self::typeDocumentSalarie($doc->type_piece, $j['nature'] ?? null, $j['face'] ?? null);
        if (! $e) {
            throw new SansLecture('Salarié non reconnu ('.trim(($j['prenom'] ?? '').' '.($j['nom'] ?? '')).($j['cin'] ? ', CIN '.$j['cin'] : '').') : choisissez-le pour classer le document.');
        }
        $sd = self::classerDansFiche($doc, $e, $type);
        self::etat($doc, 'importe', 'Classé dans la fiche de '.$e->nomComplet().' ('.(SalarieDocument::TYPES[$type] ?? $type).').', ['salarie_documents' => [$sd->id], 'salarie' => $e->id]);
    }

    public static function typeDocumentSalarie(?string $typePiece, ?string $nature = null, ?string $face = null): string
    {
        if ($typePiece === 'piece_identite' || $nature === 'cin') return $face === 'verso' ? 'cin2' : 'cin1';
        if ($typePiece === 'contrat_travail' || in_array($nature, ['contrat', 'civp'], true)) return 'contrat';
        if ($nature === 'diplome') return 'diplome';
        return 'autre';
    }

    public static function classerDansFiche(Document $doc, Salarie $e, string $type): SalarieDocument
    {
        return SalarieDocument::create(['salarie_id' => $e->id, 'type' => $type, 'nom' => $doc->nom, 'chemin' => self::copierVers($doc, 'employes/'.CabinetCourant::exiger().'/'.$e->id), 'mime' => $doc->mime]);
    }

    /**
     * Après validation (ou rejet) d'une proposition : le document passe à « intégré » quand toutes ses propositions sont traitées ;
     * une facture d'immobilisation validée entre au registre des immobilisations.
     */
    public static function apresValidation(?int $documentId): void
    {
        $doc = $documentId ? Document::find($documentId) : null;
        if (! $doc || $doc->traitement !== 'propose') return;
        $r = (array) $doc->resultat;
        $fr = FactureRecue::whereIn('id', (array) ($r['factures_recues'] ?? []))->get();
        $fv = FactureVente::whereIn('id', (array) ($r['factures_ventes'] ?? []))->get();
        $ep = EcritureProposee::whereIn('id', (array) ($r['ecritures_proposees'] ?? []))->get();
        if ($doc->type_piece === 'facture_immobilisation' && $doc->client) {
            $aImporter = $fr->where('statut', 'val')->filter(fn ($f) => ! Immobilisation::where('facture_recue_id', $f->id)->exists())->pluck('id')->all();
            if ($aImporter && ($n = ComptabilisationAmortissements::importer($doc->client, $aImporter))) {
                $r['immobilisations'] = Immobilisation::whereIn('facture_recue_id', $fr->pluck('id'))->pluck('id')->all();
            }
        }
        $toutes = $fr->concat($fv)->concat($ep);
        if ($toutes->isNotEmpty() && $toutes->every(fn ($x) => $x->statut !== 'prop')) {
            $nbVal = $toutes->where('statut', 'val')->count();
            self::etat($doc, 'importe', $nbVal.' proposition(s) validée(s)'.(($rej = $toutes->count() - $nbVal) ? ', '.$rej.' rejetée(s)' : '')
                .(! empty($r['immobilisations']) ? ' ; bien inscrit au registre des immobilisations' : '').'.', $r);
        } elseif (! empty($r['immobilisations'])) {
            $doc->forceFill(['resultat' => $r])->save();
        }
    }

    // ---------- Outils ----------

    private static function exigerLecture(Document $doc, string $saisie): void
    {
        if (! LecturePieces::disponible()) throw new SansLecture('Lecture automatique non configurée : saisissez '.$saisie.'.');
        if (! LecturePieces::lisible($doc->nom)) throw new SansLecture('Format non lisible automatiquement : saisissez '.$saisie.'.');
    }

    /** Comptes proposés à l'IA selon la nature de la pièce (« numéro libellé »). */
    private static function comptes(string $sens): array
    {
        $motif = match ($sens) { 'vente' => '/^7/', 'achat' => '/^(6|2(?![89]))/', 'immobilisation' => '/^2(?![89])/', default => '/^\d/' };
        return collect(Compta::plan())->filter(fn ($l, $n) => preg_match($motif, (string) $n))->map(fn ($l, $n) => "$n $l")->values()->all();
    }

    /** Compte du plan, ou sous-compte d'un compte du plan (541 sous 54, 4011000 sous 401), comme à la saisie manuelle. */
    private static function compteExiste(string $compte, array $plan): bool
    {
        if ($compte === '' || ! ctype_digit($compte)) return false;
        for ($n = strlen($compte); $n >= 2; $n--) if (isset($plan[substr($compte, 0, $n)])) return true;
        return false;
    }

    /** Copie du fichier pour la facture créée (elle garde sa pièce même si le document est retiré de la GED). */
    private static function copier(Document $doc, string $dossier): ?string
    {
        return self::copierVers($doc, $dossier.'/'.CabinetCourant::exiger());
    }

    private static function copierVers(Document $doc, string $dossier): ?string
    {
        if (! Storage::exists($doc->chemin)) return null;
        $dest = $dossier.'/'.Str::random(20).'.'.strtolower(pathinfo($doc->nom, PATHINFO_EXTENSION) ?: 'bin');
        Storage::copy($doc->chemin, $dest);
        return $dest;
    }

    private static function date(?string $v): ?string
    {
        if (! $v || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
        try { return Carbon::createFromFormat('Y-m-d', $v)->format('Y-m-d'); } catch (\Throwable) { return null; }
    }

    private static function texte($v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    public static function etat(Document $doc, ?string $statut, ?string $message, array $resultat = []): void
    {
        $doc->forceFill(['traitement' => $statut, 'traitement_message' => $message, 'traitement_at' => now(), 'resultat' => $resultat ?: null])->save();
    }
}
