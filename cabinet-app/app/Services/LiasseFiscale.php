<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Liasse;
use App\Models\PassageFiscal;
use XMLWriter;

/**
 * Liasse fiscale — cahier des charges CIMF, cas général (config/liasse.php) :
 * F6001 bilan actif, F6002 bilan passif, F6003 état de résultat, F6004 flux de trésorerie (modèle autorisé),
 * F6005 tableau de détermination du résultat fiscal.
 *
 * Les montants viennent de la balance de l'exercice (affectation des comptes par préfixe), sauf ceux saisis dans la liasse,
 * qui les remplacent. Les lignes de total sont toujours recalculées selon les formules du cahier des charges.
 * La colonne N-1 reprend la liasse de l'exercice précédent (balance N-1 et ses propres saisies).
 */
class LiasseFiscale
{
    public const DOCUMENTS = ['F6001', 'F6002', 'F6003', 'F6004', 'F6005'];

    /** Colonnes saisissables par document (les autres sont calculées ou reprises de N-1). */
    public const SAISIE = ['F6001' => ['brut', 'amort'], 'F6002' => ['n'], 'F6003' => ['n'], 'F6004' => ['n'], 'F6005' => ['n']];

    /** Correspondance F6004 (modèle autorisé) ← clés de EtatsFinanciers::flux(). */
    private const FLUX = [2 => 'rn', 3 => 'amort', 4 => 'stocks', 5 => 'creances', 6 => 'autres_actifs', 7 => 'dettes', 8 => 'pmv', 9 => 'transferts',
        10 => 'autres_exp', 12 => 'acq', 13 => 'ces', 14 => 'acq_fin', 15 => 'ces_fin', 16 => 'autres_inv', 18 => 'emission', 19 => 'dividendes',
        20 => 'emprunts', 21 => 'remboursements', 22 => 'autres_fin', 23 => 'change', 24 => 'autres_postes', 26 => 'tresorerie_debut', 27 => 'tresorerie_fin'];

    public static function document(string $code): array
    {
        return config('liasse.documents.'.$code);
    }

    /** Ligne affectée à un compte : préfixe le plus long de la table (actif, passif, resultat). */
    public static function ligne(string $table, string $compte): mixed
    {
        $t = config('liasse.comptes.'.$table);
        for ($l = strlen($compte); $l >= 1; $l--) {
            if (isset($t[substr($compte, 0, $l)])) return $t[substr($compte, 0, $l)];
        }
        return null;
    }

    /** Montants issus de la balance d'un exercice, par document et colonne : lignes feuilles uniquement. */
    public static function depuisBalance(int $client, int $annee): array
    {
        $brut = []; $amort = []; $passif = []; $res = [];
        $ajoute = function (array &$t, $l, float $v) { $t[$l] = round(($t[$l] ?? 0) + $v, 3); };
        foreach (Compta::balance($client, $annee) as $b) {
            $s = round($b['sd'] - $b['sc'], 3);
            if (abs($s) < 0.0005) continue;
            $c = (string) $b['compte']; $k = $c[0]; $p2 = substr($c, 0, 2);
            if ($k === '6' || $k === '7') {
                [$l, $sens] = self::ligne('resultat', $c) ?? ($k === '6' ? [59, 'D'] : [16, 'C']);
                $ajoute($res, $l, $sens === 'D' ? $s : -$s);
            } elseif ($k === '1') {
                $ajoute($passif, self::ligne('passif', $c) ?? 21, -$s);
            } elseif (in_array($p2, ['28', '29', '39', '49', '59'], true)) {
                // Amortissements et provisions : colonne « Amort./Prov. » de l'actif correspondant (2813 → 213, 391 → 31…)
                $ajoute($amort, self::ligne('actif', $k.substr($c, 2)) ?? self::defautActif($k), -$s);
            } elseif ($k === '2' || $k === '3' || ($s > 0 && ($k === '4' || $k === '5'))) {
                $ajoute($brut, self::ligne('actif', $c) ?? self::defautActif($k), $s);
            } elseif ($k === '4' || $k === '5') {
                $ajoute($passif, self::ligne('passif', $c) ?? ($k === '4' ? 43 : 51), -$s);
            }
        }
        return ['F6001' => ['brut' => $brut, 'amort' => $amort], 'F6002' => ['n' => $passif], 'F6003' => ['n' => $res]];
    }

    private static function defautActif(string $classe): int
    {
        return ['2' => 17, '3' => 43, '4' => 58, '5' => 63][$classe] ?? 67;
    }

    /** Calcule toutes les lignes d'un document à partir des feuilles ($v), selon les formules de totalisation. */
    public static function evaluer(string $code, array $v): array
    {
        $lignes = self::document($code)['lignes'];
        $memo = [];
        $f = function (int $n) use (&$f, &$memo, $lignes, $v) {
            if (array_key_exists($n, $memo)) return $memo[$n];
            $formule = $lignes[$n][1] ?? null;
            if (! $formule || $formule === '=') return $memo[$n] = round((float) ($v[$n] ?? 0), 3);
            $t = 0.0;
            preg_match_all('/([+-]?)\s*(\d+)(?:\.\.(\d+))?/', $formule, $m, PREG_SET_ORDER);
            foreach ($m as $x) {
                $signe = $x[1] === '-' ? -1 : 1;
                for ($i = (int) $x[2]; $i <= (int) (($x[3] ?? '') !== '' ? $x[3] : $x[2]); $i++) $t += $signe * $f($i);
            }
            return $memo[$n] = round($t, 3);
        };
        $r = [];
        foreach (array_keys($lignes) as $n) $r[$n] = $f($n);
        return $r;
    }

    /** Lignes feuilles (saisissables) d'un document. */
    public static function feuilles(string $code): array
    {
        return array_keys(array_filter(self::document($code)['lignes'], fn ($l) => empty($l[1])));
    }

    /** Applique les saisies (non nulles) d'une colonne sur les montants de base. */
    private static function avecSaisies(array $base, ?array $saisies): array
    {
        foreach ((array) $saisies as $l => $v) if ($v !== null && $v !== '') $base[(int) $l] = round((float) $v, 3);
        return $base;
    }

    /** F6005 : réintégrations, déductions et règles du cahier des charges (sup, inf, cas 1 à 5). */
    public static function resultatFiscal(float $resultat, array $s): array
    {
        $v = array_fill(2, 107, 0.0);
        foreach ($s as $l => $x) $v[(int) $l] = round((float) $x, 3);
        $v[2] = round($resultat, 3);
        $somme = fn (int $a, int $b) => round(array_sum(array_map(fn ($i) => $v[$i], range($a, $b))), 3);
        $e = self::evaluer('F6005', $v);
        foreach ([3, 25, 33, 39, 45, 54] as $l) $v[$l] = $e[$l];
        $v[55] = round($v[2] + $v[45] - $v[54], 3);
        $zero = function (int $a, int $b) use (&$v) { for ($i = $a; $i <= $b; $i++) $v[$i] = 0.0; };

        if ($v[55] < 0) {                                       // cas 1 : déficit fiscal
            $zero(56, 101);
            $v[102] = $v[55];
            $v[107] = round(max(0, -($v[102] + $v[103] - $v[104] - $v[105] - $v[106])), 3);
            return $v;
        }
        $v[56] = $v[55];
        $v[61] = round($v[56] - min($somme(57, 60), $v[56] / 2), 3);
        $v[63] = round(max($v[61] - $v[62], 0), 3);
        $x = round($v[63] + $v[64] - $v[65] - $v[66] - $v[67], 3);
        if ($x < 0) {                                           // cas 2 à 4 : déficit après amortissements et déficits reportés
            $zero(68, 101);
            $v[102] = 0.0;
            [$v[103], $v[104], $v[105], $v[106]] = [$v[64], $v[65], $v[66], $v[67]];
            $v[107] = round(-$x, 3);
            return $v;
        }
        $v[68] = $x;
        $v[80] = round(max($v[68] - $somme(69, 79), 0), 3);
        $v[81] = $somme(82, 85); $v[86] = $somme(87, 90); $v[91] = round($v[81] + $v[86], 3);
        $v[92] = round($v[80] - $v[91], 3);
        $v[97] = $somme(93, 96);
        $v[98] = round($v[80] - $v[97], 3);
        if ($v[98] < 0) { $v[98] = 0.0; $zero(99, 101); }      // cas 5
        else $v[101] = round($v[98] - $v[99] + $v[100], 3);
        $zero(102, 107);
        return $v;
    }

    /** Liasse d'un exercice (sans la colonne N-1) : ['F6001' => ['brut' => [...], 'amort' => [...], 'net' => [...]], ...]. */
    private static function exercice(Client $client, int $annee, array $flux): array
    {
        $S = (array) (Liasse::where('client_id', $client->id)->where('annee', $annee)->first()?->saisies ?? []);
        $B = self::depuisBalance($client->id, $annee);

        $D['F6003']['n'] = self::evaluer('F6003', self::avecSaisies($B['F6003']['n'], $S['F6003']['n'] ?? null));

        $brut = self::evaluer('F6001', self::avecSaisies($B['F6001']['brut'], $S['F6001']['brut'] ?? null));
        $amort = self::evaluer('F6001', self::avecSaisies($B['F6001']['amort'], $S['F6001']['amort'] ?? null));
        $D['F6001'] = ['brut' => $brut, 'amort' => $amort, 'net' => array_map(fn ($b, $a) => round($b - $a, 3), $brut, $amort)];
        $D['F6001']['net'] = array_combine(array_keys($brut), $D['F6001']['net']);

        $p = self::avecSaisies($B['F6002']['n'], $S['F6002']['n'] ?? null);
        $p[7] = $D['F6003']['n'][89];
        $D['F6002']['n'] = self::evaluer('F6002', $p);

        $f = [];
        foreach (self::FLUX as $l => $k) $f[$l] = round((float) ($flux[$k] ?? 0), 3);
        $D['F6004']['n'] = self::evaluer('F6004', self::avecSaisies($f, $S['F6004']['n'] ?? null));

        // F6005 : résultat après modifications comptables, avant impôt ; réintégrations / déductions du tableau de passage à défaut de saisie
        $sf = (array) ($S['F6005']['n'] ?? []);
        $passage = PassageFiscal::where('client_id', $client->id)->where('annee', $annee)->first()?->donnees ?? [];
        if (! isset($sf[44]) || $sf[44] === '') $sf[44] = (float) ($passage['rei'] ?? 0);
        if (! isset($sf[53]) || $sf[53] === '') $sf[53] = (float) ($passage['ded'] ?? 0);
        $sf = array_filter($sf, fn ($x) => $x !== null && $x !== '');
        $D['F6005']['n'] = self::resultatFiscal($D['F6003']['n'][89] + $D['F6003']['n'][77], $sf);

        return ['D' => $D, 'B' => $B, 'S' => $S, 'vide' => ! array_filter($B['F6003']['n']) && ! array_filter($B['F6001']['brut']) && ! array_filter($B['F6002']['n'])];
    }

    /** Liasse complète de l'exercice : documents (N et N-1), montants de la balance, saisies et contrôles. */
    public static function calculer(Client $client, int $annee): array
    {
        $ef = EtatsFinanciers::calculer($client, $annee);
        $N = self::exercice($client, $annee, $ef['F']);
        $N1 = self::exercice($client, $annee - 1, $ef['F1']);
        $D = $N['D'];
        $D['F6001']['net1'] = $N1['D']['F6001']['net'];
        foreach (['F6002', 'F6003', 'F6004', 'F6005'] as $code) $D[$code]['n1'] = $N1['D'][$code]['n'];

        return ['annee' => $annee, 'docs' => $D, 'balance' => $N['B'], 'saisies' => $N['S'], 'vide' => $N['vide'],
            'controles' => self::controles($client, $D)];
    }

    /** Matricule fiscal à 13 caractères (7 chiffres, clé, code TVA, catégorie, 000), ou null s'il ne respecte pas le format. */
    public static function matricule(?string $mf): ?string
    {
        $m = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $mf));
        if (preg_match('/^\d{7}[A-Z][A-Z][A-Z]$/', $m)) $m .= '000';
        return preg_match('/^[0-9]{7}[ABCDEFGHJKLMNPQRSTVWXYZ][ABDNP][CMNP]000$/', $m) ? $m : null;
    }

    private static function controles(Client $client, array $D): array
    {
        $ecart = fn ($a, $b) => abs($a - $b) < 0.0005;
        $c = [];
        $c[] = ['Matricule fiscal du déclarant au format 13 caractères (ex. 1234567AAM000)', (bool) self::matricule($client->matricule_fiscal), $client->matricule_fiscal ?: 'non renseigné'];
        $c[] = ['Total des actifs (F6001) = total des capitaux propres et passifs (F6002) — N', $ecart($D['F6001']['net'][68], $D['F6002']['n'][53]),
            tnd($D['F6001']['net'][68]).' / '.tnd($D['F6002']['n'][53])];
        $c[] = ['Total des actifs = total des capitaux propres et passifs — N-1', $ecart($D['F6001']['net1'][68], $D['F6002']['n1'][53]),
            tnd($D['F6001']['net1'][68]).' / '.tnd($D['F6002']['n1'][53])];
        $c[] = ['Trésorerie de clôture (F6004) = trésorerie d’ouverture + variation', $ecart($D['F6004']['n'][27], $D['F6004']['n'][26] + $D['F6004']['n'][25]),
            tnd($D['F6004']['n'][27]).' / '.tnd($D['F6004']['n'][26] + $D['F6004']['n'][25])];
        $c[] = ['Trésorerie d’ouverture N (F6004) = trésorerie de clôture N-1', $ecart($D['F6004']['n'][26], $D['F6004']['n1'][27]),
            tnd($D['F6004']['n'][26]).' / '.tnd($D['F6004']['n1'][27])];
        $negatifs = [];
        foreach ($D as $code => $cols) {
            $lignes = self::document($code)['lignes'];
            foreach ($cols as $col => $vals) foreach ($vals as $l => $v) {
                if ($v < -0.0005 && empty($lignes[$l][2])) $negatifs[] = $code.'/'.$l;
            }
        }
        $c[] = ['Aucun montant négatif dans une zone de type « nombre positif »', ! $negatifs, $negatifs ? implode(', ', array_slice(array_unique($negatifs), 0, 12)).(count($negatifs) > 12 ? '…' : '') : ''];
        return $c;
    }

    // ---------- Fichiers XML ----------

    /** Entête par défaut, complétée par celle enregistrée pour l'exercice. */
    public static function entete(Client $client, int $annee): array
    {
        $infos = \App\Http\Controllers\LiasseController::infosEtats($client);
        $mf = self::matricule($client->matricule_fiscal);
        $forme = $client->estPhysique() ? 'PP' : (preg_match('/\b(snc|scs|soci[ée]t[ée] de personnes|en nom collectif|commandite simple)\b/iu', (string) $client->forme) ? 'SP' : 'SC');
        $defaut = ['activite' => $infos['activite'] ?? '', 'adresse' => $client->adresse ?? '', 'debut' => "01/01/$annee", 'cloture' => "31/12/$annee",
            'acte' => '0', 'nature' => 'D', 'unite' => 'millimes', 'categorie' => $mf ? $mf[9] : ($client->estPhysique() ? 'C' : 'M'), 'forme' => $forme];
        $e = (array) (Liasse::where('client_id', $client->id)->where('annee', $annee)->first()?->entete ?? []);
        return array_merge($defaut, array_filter($e, fn ($v) => $v !== null && $v !== ''));
    }

    /** Montant au format XML : entier (millimes ou dinars arrondis, selon l'entête). */
    private static function entier(float $v, string $unite): string
    {
        return (string) (int) round($unite === 'dinars' ? $v : $v * 1000);
    }

    /** Nom du fichier : [CODE_FORMULAIRE]-[MATRICULE_FISCAL (7 chiffres + clé)]-[EXERCICE].xml */
    public static function nomFichier(string $code, Client $client, int $annee): string
    {
        $mf = self::matricule($client->matricule_fiscal) ?? strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $client->matricule_fiscal));
        return $code.'-'.substr($mf ?: '00000000', 0, 8).'-'.$annee.'.xml';
    }

    public static function xml(string $code, Client $client, int $annee, array $liasse, ?array $entete = null): string
    {
        $e = $entete ?? self::entete($client, $annee);
        $ns = config('liasse.namespace');
        $doc = self::document($code);
        $w = new XMLWriter();
        $w->openMemory(); $w->setIndent(true); $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('lf:'.$code);
        $w->writeAttribute('xmlns:lf', $ns);
        $w->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $w->writeAttribute('xsi:schemaLocation', $ns.' '.$code.'.xsd');

        $w->startElement('lf:Entete');
        $txt = fn ($s) => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $s)), 0, 250);
        $w->writeElement('lf:MatriculeFiscalDeclarant', self::matricule($client->matricule_fiscal) ?? strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $client->matricule_fiscal)));
        $w->writeElement('lf:NometPrenomouRaisonSociale', $txt($client->nom));
        $w->writeElement('lf:Activite', $txt($e['activite']));
        $w->writeElement('lf:Adresse', $txt($e['adresse']));
        $w->writeElement('lf:Exercice', (string) $annee);
        $w->writeElement('lf:DateDebutExercice', $e['debut']);
        $w->writeElement('lf:DateClotureExercice', $e['cloture']);
        $w->writeElement('lf:ActeDeDepot', (string) $e['acte']);
        $w->writeElement('lf:NatureDepot', $e['nature']);
        $w->endElement();

        $vals = $liasse['docs'][$code];
        if ($code === 'F6005') {
            foreach (['0' => 'n', '1' => 'n1'] as $chiffre => $col) {
                $v = $vals[$col];
                $w->startElement("lf:F6005{$chiffre}000");
                $w->writeAttribute('categorie', $e['categorie']);
                $w->writeAttribute('codeformejuridique', $e['forme']);
                $w->endElement();
                $w->startElement("lf:F6005{$chiffre}001");
                $w->writeAttribute('resultat', $v[2] < 0 ? 'P' : 'B');
                $w->writeAttribute("F6005{$chiffre}002", self::entier(abs($v[2]), $e['unite']));
                $w->endElement();
                for ($l = 3; $l <= 108; $l++) $w->writeElement(sprintf('lf:F6005%s%03d', $chiffre, $l), self::entier($v[$l] ?? 0, $e['unite']));
            }
        } else {
            foreach ($doc['colonnes'] as $col => $def) {
                [$chiffre, , $decalage] = $def + [2 => 0];
                foreach ($doc['lignes'] as $l => $ligne) {
                    $w->writeElement(sprintf('lf:%s%s%03d', $code, $chiffre, $l + $decalage), self::entier($vals[$col][$l] ?? 0, $e['unite']));
                }
            }
        }
        $w->endElement();
        $w->endDocument();
        return $w->outputMemory();
    }

    /** Archive ZIP des fichiers F6001 à F6005 de l'exercice ; renvoie le chemin du fichier temporaire. */
    public static function zip(Client $client, int $annee): string
    {
        $liasse = self::calculer($client, $annee);
        $entete = self::entete($client, $annee);
        $chemin = tempnam(sys_get_temp_dir(), 'lf');
        $z = new \ZipArchive();
        $z->open($chemin, \ZipArchive::OVERWRITE);
        foreach (self::DOCUMENTS as $code) $z->addFromString(self::nomFichier($code, $client, $annee), self::xml($code, $client, $annee, $liasse, $entete));
        $z->close();
        return $chemin;
    }

    // ---------- Validation ----------

    /** Dossier des schémas XSD officiels (F6001_2015.xsd…, Entete.xsd, Typescommuns.xsd), déposés depuis l'écran. */
    public static function dossierXsd(): string
    {
        return config('liasse.xsd_dir') ?: storage_path('app/liasse-xsd');
    }

    /** Schéma officiel d'un document s'il a été déposé (F6001.xsd ou F6001_2015.xsd…), sinon null. */
    public static function xsd(string $code): ?string
    {
        foreach (glob(self::dossierXsd().'/'.$code.'*.xsd') ?: [] as $f) return $f;
        return null;
    }

    /**
     * Valide les fichiers XML de l'exercice : par le schéma XSD officiel s'il est déposé, et toujours par les règles
     * du cahier des charges (structure, entête, types, nombres entiers, signes, formules de totalisation, nom du fichier).
     * Renvoie, par document : fichier, xsd (bool), erreurs [].
     */
    public static function valider(Client $client, int $annee): array
    {
        $liasse = self::calculer($client, $annee);
        $entete = self::entete($client, $annee);
        $r = [];
        foreach (self::DOCUMENTS as $code) {
            $fichier = self::nomFichier($code, $client, $annee);
            $xml = self::xml($code, $client, $annee, $liasse, $entete);
            $erreurs = self::verifier($code, $fichier, $xml, $entete['unite']);
            $schema = self::xsd($code);
            if ($schema && ! $erreurs['bloquant']) {
                $prec = libxml_use_internal_errors(true);
                libxml_clear_errors();
                $dom = new \DOMDocument();
                $dom->loadXML($xml);
                if (! @$dom->schemaValidate($schema)) {
                    foreach (array_slice(libxml_get_errors(), 0, 30) as $e) $erreurs['liste'][] = 'XSD ligne '.$e->line.' : '.trim($e->message);
                }
                libxml_clear_errors();
                libxml_use_internal_errors($prec);
            }
            $r[$code] = ['fichier' => $fichier, 'xsd' => (bool) $schema, 'erreurs' => $erreurs['liste']];
        }
        return $r;
    }

    /** Contrôle d'un fichier selon le cahier des charges. */
    public static function verifier(string $code, string $fichier, string $xml, string $unite = 'millimes'): array
    {
        $E = [];
        $prec = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prec);
        if (! $ok) return ['bloquant' => true, 'liste' => ['Fichier XML mal formé.']];

        $ns = config('liasse.namespace');
        $racine = $dom->documentElement;
        if ($racine->localName !== $code || $racine->namespaceURI !== $ns) $E[] = "Élément racine attendu : lf:$code dans l’espace de noms $ns.";
        if (! preg_match('/^'.$code.'-\d{7}[A-Z]-\d{4}\.xml$/', $fichier)) $E[] = "Nom de fichier non conforme au modèle [CODE]-[MATRICULE]-[EXERCICE].xml : $fichier";

        $x = new \DOMXPath($dom);
        $x->registerNamespace('lf', $ns);
        $val = fn (string $n) => ($node = $x->query('/lf:'.$code.'/lf:'.$n)->item(0)) ? trim($node->textContent) : null;

        // Entête
        $date = '#^((31/(0[13578]|1[02])/((19|20)\d{2}))|((0[1-9]|[12]\d|30)/(0[13456789]|1[012])/((19|20)\d{2}))|((0[1-9]|1\d|2[0-8])/02/((19|20)\d{2}))|(29/02/(19|20)(0[048]|[2468][048]|[13579][26])))$#';
        $regles = [
            'Entete/lf:MatriculeFiscalDeclarant' => ['/^[0-9]{7}[ABCDEFGHJKLMNPQRSTVWXYZ][ABDNP][CMNP]000$/', 'Matricule fiscal du déclarant (13 caractères, ex. 1234567AAM000)'],
            'Entete/lf:NometPrenomouRaisonSociale' => ['/^.{1,250}$/u', 'Nom ou raison sociale (1 à 250 caractères)'],
            'Entete/lf:Activite' => ['/^.{1,250}$/u', 'Activité (1 à 250 caractères) — à renseigner dans l’entête'],
            'Entete/lf:Adresse' => ['/^.{1,250}$/u', 'Adresse (1 à 250 caractères) — à renseigner dans l’entête ou la fiche client'],
            'Entete/lf:Exercice' => ['/^(19|20)\d{2}$/', 'Exercice (AAAA)'],
            'Entete/lf:DateDebutExercice' => [$date, 'Date de début d’exercice (JJ/MM/AAAA)'],
            'Entete/lf:DateClotureExercice' => [$date, 'Date de clôture d’exercice (JJ/MM/AAAA)'],
            'Entete/lf:ActeDeDepot' => ['/^[012]$/', 'Acte de dépôt (0, 1 ou 2)'],
            'Entete/lf:NatureDepot' => ['/^[DP]$/', 'Nature du dépôt (D ou P)'],
        ];
        foreach ($regles as $n => [$re, $lib]) {
            $v = $val($n);
            if ($v === null) $E[] = "Entête : $lib absent.";
            elseif (! preg_match($re, $v)) $E[] = "Entête : $lib invalide (« $v »).";
        }
        $d1 = $val('Entete/lf:DateDebutExercice'); $d2 = $val('Entete/lf:DateClotureExercice');
        if ($d1 && $d2 && preg_match($date, $d1) && preg_match($date, $d2)
            && \Carbon\Carbon::createFromFormat('d/m/Y', $d1)->gt(\Carbon\Carbon::createFromFormat('d/m/Y', $d2))) $E[] = 'Entête : la date de début est postérieure à la date de clôture.';

        // Montants : présence, unicité, entiers sur 13 chiffres au plus, signe selon le type
        $doc = self::document($code);
        $lus = [];
        foreach ($racine->childNodes as $n) {
            if (! $n instanceof \DOMElement || $n->localName === 'Entete') continue;
            if (isset($lus[$n->localName])) $E[] = 'Élément en double : '.$n->localName;
            $lus[$n->localName] = trim($n->textContent);
        }
        $attendus = [];
        if ($code === 'F6005') {
            foreach (['0', '1'] as $c) {
                $a = $x->query("/lf:F6005/lf:F6005{$c}000")->item(0);
                if (! $a) $E[] = "Élément F6005{$c}000 absent.";
                else {
                    if (! in_array($a->getAttribute('categorie'), ['M', 'C', 'P', 'N'], true)) $E[] = "F6005{$c}000 : catégorie invalide.";
                    if (! in_array($a->getAttribute('codeformejuridique'), ['PP', 'SP', 'SC'], true)) $E[] = "F6005{$c}000 : code forme juridique invalide.";
                }
                $b = $x->query("/lf:F6005/lf:F6005{$c}001")->item(0);
                if (! $b) $E[] = "Élément F6005{$c}001 absent.";
                else {
                    if (! in_array($b->getAttribute('resultat'), ['B', 'P'], true)) $E[] = "F6005{$c}001 : attribut resultat (B/P) invalide.";
                    if (! preg_match('/^\d{1,13}$/', $b->getAttribute("F6005{$c}002"))) $E[] = "F6005{$c}001 : montant F6005{$c}002 invalide.";
                }
                unset($lus["F6005{$c}000"], $lus["F6005{$c}001"]);
                foreach (range(3, 108) as $l) $attendus[sprintf('F6005%s%03d', $c, $l)] = [$c === '0' ? 'n' : 'n1', $l];
            }
        } else {
            foreach ($doc['colonnes'] as $col => $def) {
                [$chiffre, , $decalage] = $def + [2 => 0];
                foreach (array_keys($doc['lignes']) as $l) $attendus[sprintf('%s%s%03d', $code, $chiffre, $l + $decalage)] = [$col, $l];
            }
        }
        $valeurs = [];
        foreach ($attendus as $nom => [$col, $l]) {
            if (! array_key_exists($nom, $lus)) { $E[] = "Élément $nom absent."; continue; }
            $v = $lus[$nom];
            if (! preg_match('/^-?\d{1,13}$/', $v)) { $E[] = "$nom : « $v » n’est pas un nombre entier de 13 chiffres au plus."; continue; }
            if ((int) $v < 0 && empty($doc['lignes'][$l][2])) $E[] = "$nom (".$doc['lignes'][$l][0].") : montant négatif dans une zone « nombre positif ».";
            $valeurs[$col][$l] = (int) $v;
        }
        foreach (array_diff(array_keys($lus), array_keys($attendus)) as $nom) $E[] = "Élément inattendu : $nom";

        // Formules de totalisation (sur les montants du fichier ; tolérance d'arrondi en dinars)
        foreach ($valeurs as $col => $v) {
            foreach ($doc['lignes'] as $l => $ligne) {
                $f = $ligne[1] ?? null;
                if (! $f || $f === '=' || ! isset($v[$l])) continue;
                $t = 0; $termes = 0;
                preg_match_all('/([+-]?)\s*(\d+)(?:\.\.(\d+))?/', $f, $m, PREG_SET_ORDER);
                foreach ($m as $p) for ($i = (int) $p[2]; $i <= (int) (($p[3] ?? '') !== '' ? $p[3] : $p[2]); $i++) { $t += ($p[1] === '-' ? -1 : 1) * ($v[$i] ?? 0); $termes++; }
                $tol = $unite === 'dinars' ? $termes : 0;
                if (abs($t - $v[$l]) > $tol) $E[] = sprintf('%s ligne %d (%s, colonne %s) : %s ≠ %s = %s', $code, $l, $ligne[0], $col, number_format($v[$l], 0, ',', ' '), $f, number_format($t, 0, ',', ' '));
            }
        }
        return ['bloquant' => false, 'liste' => $E];
    }
}
