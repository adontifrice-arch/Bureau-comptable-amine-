<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Models\Paie;
use Carbon\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Fichiers de virement des salaires aux formats des banques tunisiennes, choisis selon la banque de l'employeur
 * (compte à débiter) : un seul fichier par paie, avec tous les salariés payés par virement, quelle que soit leur banque.
 * Montants en millimes, enregistrements à positions fixes terminés par CR LF.
 */
class FichiersVirement
{
    public const MODELES = [
        'bt' => ['nom' => 'BT (Excel)', 'fichier' => 'VirBT.xlsx'],
        'stb' => ['nom' => 'STB', 'fichier' => 'FMANDAT.QUA'],
        'uib' => ['nom' => 'UIB — détail virement', 'fichier' => 'UIBFdetvirGlob.txt'],
        'uib-masse' => ['nom' => 'UIB — virement de masse', 'fichier' => 'UIBVirMasseGlob.SAL'],
        'biat' => ['nom' => 'BIAT', 'fichier' => 'VirBiatGlob.txt'],
        'atb' => ['nom' => 'ATB', 'fichier' => 'VIRCLT.txt'],
        'ubci' => ['nom' => 'UBCI', 'fichier' => 'VirUBCIGlob.txt'],
        'zitouna' => ['nom' => 'Zitouna', 'fichier' => 'ZitounaMassTransfer_{date}.txt'],
        'bh' => ['nom' => 'BH Bank', 'fichier' => 'BHVirMasseGlob.SAL'],
    ];

    /** Référence d'opération de la colonne « Référence » du modèle BT. */
    public const REFERENCE_BT = 118;

    /**
     * Données du virement : donneur d'ordre (RIB, raison sociale, matricule fiscal), libellés et bénéficiaires.
     * Renvoie [données, erreurs] : RIB de l'employeur ou d'un salarié absent ou incomplet (20 chiffres).
     */
    public static function donnees(Client $client, string $mois, ?string $paie = null): array
    {
        $erreurs = [];
        $rib = preg_replace('/\D/', '', (string) $client->paie_rib);
        if (strlen($rib) !== 20) $erreurs[] = 'RIB de l’employeur (Renseignements employeur) absent ou incomplet.';
        [$objet, $periode] = self::objet($client, $mois, $paie);
        $lignes = [];
        foreach (EtatsPaie::aVirer($client, $mois, null, $paie) as [$x, $s]) {
            $r = preg_replace('/\D/', '', (string) $s?->rib);
            if (strlen($r) !== 20) { $erreurs[] = 'RIB absent ou incomplet : '.$x['nom'].'.'; continue; }
            $montant = (int) round($x['net'] * 1000);
            if ($montant <= 0) continue;
            $lignes[] = ['nom' => self::texte($x['nom']), 'rib' => $r, 'montant' => $montant, 'matricule' => (string) ($s?->matricule ?? $x['matricule'] ?? '')];
        }
        if (! $lignes && ! $erreurs) $erreurs[] = 'Aucun salarié payé par virement pour cette paie.';
        return [[
            'rib' => $rib, 'nom' => self::texte($client->nom), 'mf' => self::matriculeFiscal($client->matricule_fiscal), 'date' => now(),
            'objet' => $objet, 'periode' => $periode, 'annee' => substr($mois, 0, 4), 'lignes' => $lignes,
        ], $erreurs];
    }

    /** Contenu du fichier au modèle $modele (texte, ou classeur Excel pour BT). */
    public static function contenu(string $modele, array $D): string
    {
        return match ($modele) {
            'bt' => self::bt($D), 'stb' => self::stb($D), 'uib' => self::uib($D, false), 'uib-masse' => self::uib($D, true),
            'biat' => self::biat($D, false), 'ubci' => self::biat($D, true), 'atb' => self::atb($D),
            'zitouna' => "\xEF\xBB\xBF".self::v1v2($D, true), 'bh' => self::v1v2($D, false),
        };
    }

    public static function nomFichier(string $modele, array $D): string
    {
        return str_replace('{date}', $D['date']->format('Ymd'), self::MODELES[$modele]['fichier']);
    }

    /** Objet du virement et période : « Salaire » et « Octobre 2026 » pour la paie du mois. */
    private static function objet(Client $client, string $mois, ?string $paie): array
    {
        $p = EtatsPaie::paieChoisie($paie);
        $libMois = Str::ucfirst(mois_fr($mois));
        return match (true) {
            in_array($p, ['normale', 'tous'], true) => ['Salaire', $libMois],
            ctype_digit($p) => [EtatsPaie::paieParNumero($client, $p)?->nomType() ?? 'Prime', $libMois],
            default => [Paie::TYPES[$p], substr($mois, 0, 4)],
        };
    }

    /** « Octobre 2026 » pour la paie du mois, « 13ème mois 2026 » pour une paie spéciale. */
    private static function libelle(array $D): string
    {
        return self::texte($D['objet'] === 'Salaire' ? $D['periode'] : $D['objet'].' '.$D['periode'], false);
    }

    /** Texte sans accents (un octet par caractère), en majuscules par défaut. */
    private static function texte(?string $s, bool $majuscules = true): string
    {
        $s = trim(preg_replace('/\s+/', ' ', Str::ascii((string) $s)));
        return $majuscules ? strtoupper($s) : $s;
    }

    /** Matricule fiscal réduit à l'identifiant : 7 chiffres et la lettre de contrôle (« 1693350E »). */
    private static function matriculeFiscal(?string $mf): string
    {
        $mf = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $mf));
        return preg_match('/^0*(\d{1,7})([A-Z])/', $mf, $m) ? str_pad($m[1], 7, '0', STR_PAD_LEFT).$m[2] : '';
    }

    /** Zone alphanumérique de $n caractères, cadrée à gauche. */
    private static function a(string $s, int $n): string
    {
        return str_pad(substr($s, 0, $n), $n);
    }

    /** Zone numérique de $n chiffres, cadrée à droite et complétée par des zéros. */
    private static function z(int|string $v, int $n): string
    {
        return str_pad(substr((string) $v, -$n), $n, '0', STR_PAD_LEFT);
    }

    private static function total(array $D): int
    {
        return array_sum(array_column($D['lignes'], 'montant'));
    }

    private static function lignes(array $L): string
    {
        return implode("\r\n", $L)."\r\n";
    }

    /** Matricule de l'employé sur 4 chiffres. */
    private static function matricule(string $m): string
    {
        return self::z(preg_replace('/\D/', '', $m) ?: '0', 4);
    }

    /** BT : classeur Excel (Nom, RIB, Montant en millimes, Opération, Référence) et ligne de total. */
    private static function bt(array $D): string
    {
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet()->setTitle('Feuil1');
        $ws->fromArray(['Nom', 'RIB', 'Montant', 'Opération', 'Référence'], null, 'A1');
        $op = self::texte($D['objet'], false).' '.self::texte($D['periode'], false);
        $i = 1;
        foreach ($D['lignes'] as $l) {
            $i++;
            $ws->setCellValue("A$i", $l['nom']);
            $ws->setCellValueExplicit("B$i", $l['rib']."'", \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $ws->setCellValue("C$i", $l['montant']);
            $ws->setCellValue("D$i", $op);
            $ws->setCellValue("E$i", self::REFERENCE_BT);
        }
        $ws->setCellValue('C'.($i + 1), self::total($D));
        foreach (['A' => 24.14, 'B' => 21.71, 'C' => 9, 'D' => 19.14, 'E' => 10.14] as $c => $w) $ws->getColumnDimension($c)->setWidth($w);
        ob_start();
        (new Xlsx($ss))->save('php://output');
        return ob_get_clean();
    }

    /** STB (.QUA) : début de fichier, donneur d'ordre (D), bénéficiaires (B), fin de fichier. */
    private static function stb(array $D): string
    {
        $date = $D['date']->format('d/m/Y');
        $L = ['DEBUT FICHIER QUA '.$date, 'D'.$D['rib'].self::a($D['nom'], 25).self::z(self::total($D), 15).'VOSA'];
        foreach ($D['lignes'] as $l) $L[] = 'B'.$l['rib'].self::a($l['nom'], 25).self::z($l['montant'], 15).'VOSA';
        $L[] = 'FIN FICHIER QUA '.$date;
        return self::lignes($L);
    }

    /** UIB : enregistrement global (09) et un enregistrement 02 par bénéficiaire, 160 caractères ; détail virement ou virement de masse. */
    private static function uib(array $D, bool $masse): string
    {
        $L = ['09'.substr($D['rib'], 0, 2).$D['rib'].self::z($D['mf'], 43).self::z(count($D['lignes']), 10).self::z(self::total($D), 12).str_repeat(' ', 20).str_repeat('0', 51)];
        foreach ($D['lignes'] as $l) {
            $tete = '02'.substr($l['rib'], 0, 2).$D['rib'].$l['rib'];
            $L[] = $tete.($masse
                    ? str_repeat('0', 20).self::a('Virement '.self::libelle($D).' '.$l['nom'], 50)
                    : '  '.self::a('Paie '.self::texte($D['objet'], false).' '.($D['objet'] === 'Salaire' ? $D['annee'] : self::texte($D['periode'], false)).' '.$l['nom'], 68))
                .self::z($l['montant'], 10).'  '.str_repeat('0', 34);
        }
        return self::lignes($L);
    }

    /** BIAT et UBCI : enregistrement global (type 1) et un enregistrement par bénéficiaire (type 2), 280 caractères. */
    private static function biat(array $D, bool $ubci): string
    {
        $date = $D['date']->format('Ymd');
        $tete = fn (string $type) => '110'.$D['rib'].$date.($ubci ? '0000' : '0001').$type.'1788';
        $L = [str_pad($tete('1').self::z(self::total($D), 17).self::z(count($D['lignes']), 10).self::a($D['nom'], 30)
            .($ubci ? str_repeat(' ', 20) : self::a('Virement '.self::libelle($D), 20)).$date, 280)];
        $objet = self::a(self::texte($D['objet'], false).' '.self::texte($D['periode'], false), 20);
        foreach ($D['lignes'] as $i => $l) {
            $ligne = $tete('2').self::z($l['montant'], 17).self::z($i + 1, 7).$l['rib'].self::a($l['nom'], 30);
            $ligne .= $ubci
                ? str_repeat('0', 16).self::matricule($l['matricule']).'000'.str_repeat(' ', 75).str_repeat('0', 16)
                : self::matricule($l['matricule']).str_repeat(' ', 16).'000'.$objet;
            $L[] = str_pad($ligne, 280);
        }
        return self::lignes($L);
    }

    /** ATB (VIRCLT) : en-tête 01 (compte à débiter, libellé, total, nombre) et un enregistrement 02 par bénéficiaire. */
    private static function atb(array $D): string
    {
        $lib = $D['objet'] === 'Salaire' ? 'Virement du mois de '.self::libelle($D) : 'Virement '.self::libelle($D);
        $L = ['01'.substr($D['rib'], 5, 13).self::a($lib, 70).self::z(self::total($D), 12).self::z(count($D['lignes']), 3)];
        foreach ($D['lignes'] as $i => $l) $L[] = '02'.self::z($i + 1, 3).'000  1000'.$l['rib'].self::a($l['nom'], 30).self::z($l['montant'], 12);
        return self::lignes($L);
    }

    /** Zitouna et BH Bank : enregistrement V1 (lot) et un enregistrement V2 par bénéficiaire, 240 caractères ; Zitouna avec référence et motif. */
    private static function v1v2(array $D, bool $zitouna): string
    {
        $date = $D['date']->format('Ymd');
        $lib = self::libelle($D);
        $ref = $zitouna ? self::a('SalGlob '.preg_replace('/ (\d{4})$/', '_$1', $lib), 20) : str_repeat(' ', 20);
        $L = [str_pad('V1  '.$D['rib'].$date.$ref.$date.'0001   '.self::z(count($D['lignes']), 7).'   788TND'.self::z(self::total($D), 18), 240)];
        foreach ($D['lignes'] as $i => $l) {
            $motif = $zitouna ? self::a('SalGlob '.$lib.' '.$l['nom'], 100) : str_repeat(' ', 100);
            $L[] = 'V2  '.$D['rib'].$l['rib'].self::a($l['nom'], 30).$ref.$date.'0001'.self::z($i + 1, 7).'000'.$motif.self::z($l['montant'], 18).str_repeat(' ', 6);
        }
        return self::lignes($L);
    }
}
