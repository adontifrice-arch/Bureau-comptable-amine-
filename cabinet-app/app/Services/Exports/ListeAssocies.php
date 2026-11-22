<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Services\Actes\Balises;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Tableau des associés / actionnaires (pièce de base du dossier juridique), au modèle du cabinet,
 * rempli d'après la fiche client : associés, et gérant s'il détient des parts.
 */
class ListeAssocies
{
    public const COLONNES = ['GENRE', 'NOM & PRENOM', 'TYPE ID', 'N° IDENTITE', 'DATE DE NAISSANCE', 'NATIONALITE', '% dans le capital',
        'PARTS EN NUMERAIRES', 'PARTS EN NATURE', 'TOTAL PARTS/ACTIONS'];

    /** Lignes du tableau d'après la fiche (aussi utilisées pour l'aperçu). */
    public static function lignes(Client $c): array
    {
        return Balises::detenteurs($c)->map(function ($a) {
            $parts = (int) ($a['p'] ?? 0) ?: (($a['pd'] ?? null) && ($a['pf'] ?? null) ? (int) $a['pf'] - (int) $a['pd'] + 1 : 0);
            // Associé société : identifiant unique (affiché « MF » comme dans le modèle), ou RCS d'une société étrangère ;
            // personne : CIN, passeport ou carte de séjour
            [$type, $numero] = match (true) {
                ! empty($a['iu']) => ['MF', $a['iu']],
                ! empty($a['rcs']) => ['RCS', $a['rcs']],
                ! empty($a['cin']) => ['CIN', $a['cin']],
                ! empty($a['pas']) => ['PASSEPORT', $a['pas']],
                ! empty($a['cs']) => ['CARTE DE SEJOUR', $a['cs']],
                default => ['', ''],
            };
            $societe = ! empty($a['iu']) || ! empty($a['rcs']) || (! $a['civ'] && preg_match('/^(st[ée]|soci[ée]t[ée])\b|\b(sarl|suarl|s\.?a\.?|sas|sasu|ltd|gmbh|holding)\b/iu', (string) $a['nom']));
            return [
                'genre' => match ($a['civ'] ?? null) { 'M' => 'MR', 'Mme' => 'MME', default => $societe ? 'STE' : '' },
                'nom' => mb_strtoupper(trim((string) $a['nom'])),
                'type' => $societe && $type === '' ? 'MF' : $type,
                'numero' => (string) $numero,
                'naissance' => $a['dn'] ?? null,
                'nationalite' => mb_strtoupper((string) ($a['nat'] ?? '')),
                'numeraire' => ($a['ap'] ?? 'numeraire') === 'nature' ? 0 : $parts,
                'nature' => ($a['ap'] ?? 'numeraire') === 'nature' ? $parts : 0,
                'total' => $parts,
            ];
        })->filter(fn ($l) => $l['nom'] !== '' && $l['nom'] !== mb_strtoupper(Balises::VIDE))->values()->all();
    }

    /** Identifiant unique (RNE) : les 7 chiffres et la lettre du matricule fiscal. */
    public static function identifiant(Client $c): string
    {
        $mf = strtoupper(preg_replace('/[\s\/.-]/', '', (string) $c->matricule_fiscal));
        return preg_match('/^(\d{7}[A-Z])/', $mf, $m) ? $m[1] : (string) $c->matricule_fiscal;
    }

    public static function classeur(Client $c): Spreadsheet
    {
        $ss = new Spreadsheet();
        $ss->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $ws = $ss->getActiveSheet()->setTitle('Associés');
        foreach (['A' => 8, 'B' => 15, 'C' => 5.71, 'D' => 11.43, 'E' => 12, 'F' => 14, 'G' => 16.86, 'H' => 14.43, 'I' => 14.43, 'J' => 14.86, 'K' => 12.86, 'L' => 15.43] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }

        $ws->mergeCells('A1:L1');
        $ws->setCellValue('A1', 'TABLEAU DES ASSOCIES/ACTIONNAIRES');
        $ws->getStyle('A1')->getFont()->setSize(16)->setBold(true);
        $ws->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getRowDimension(1)->setRowHeight(21);

        $ws->setCellValue('A3', 'IDENTIFIANT UNIQUE: ');
        $ws->setCellValueExplicit('C3', self::identifiant($c), DataType::TYPE_STRING);
        $ws->setCellValue('D3', mb_strtoupper($c->nom));
        $ws->getStyle('A3:D3')->getFont()->setSize(14)->setBold(true);
        $ws->getRowDimension(3)->setRowHeight(18.75);

        $ws->setCellValue('B5', 'CAPITAL SOCIAL: ');
        $ws->setCellValue('E5', (float) $c->capital ?: null);
        $ws->setCellValue('H5', 'VALEUR NOMINAL:');
        $ws->setCellValue('K5', $c->valeur_nominale !== null ? (float) $c->valeur_nominale : null);
        $ws->getStyle('B5:K5')->getFont()->setSize(12)->setBold(true);
        $ws->getStyle('E5')->getNumberFormat()->setFormatCode('#,##0.000');

        // En-têtes (ligne 7) : A, B:D fusionnées, puis E à L
        $entetes = ['A7' => self::COLONNES[0], 'B7' => self::COLONNES[1]];
        foreach (array_slice(self::COLONNES, 2) as $i => $t) $entetes[chr(ord('E') + $i).'7'] = $t;
        foreach ($entetes as $cell => $t) $ws->setCellValue($cell, $t);
        $ws->mergeCells('B7:D7');
        $ws->getStyle('A7:L7')->getFont()->setBold(true);
        $ws->getStyle('A7:L7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getRowDimension(7)->setRowHeight(45.75);

        $lignes = self::lignes($c);
        $totalParts = (int) $c->nb_parts ?: array_sum(array_column($lignes, 'total'));
        $r = 8;
        foreach ($lignes as $l) {
            $ws->mergeCells("B$r:D$r");
            $ws->setCellValue("A$r", $l['genre']);
            $ws->setCellValue("B$r", $l['nom']);
            $ws->setCellValue("E$r", $l['type']);
            $ws->setCellValueExplicit("F$r", $l['numero'], DataType::TYPE_STRING);
            if ($l['naissance']) $ws->setCellValue("G$r", DateExcel::PHPToExcel($l['naissance']));
            $ws->setCellValue("H$r", $l['nationalite']);
            $ws->setCellValue("I$r", $totalParts ? "=L$r/$totalParts" : null);
            $ws->setCellValue("J$r", $l['numeraire']);
            $ws->setCellValue("K$r", $l['nature']);
            $ws->setCellValue("L$r", "=J$r+K$r");
            $r++;
        }
        $fin = max(8, $r - 1);
        if ($lignes) {
            // Ligne des totaux
            $ws->mergeCells("B$r:D$r");
            $ws->setCellValue("B$r", 'TOTAL');
            foreach (['I', 'J', 'K', 'L'] as $col) $ws->setCellValue("$col$r", "=SUM({$col}8:$col$fin)");
            $ws->getStyle("A$r:L$r")->getFont()->setBold(true);
            $fin = $r;
        }
        $ws->getStyle("A7:L$fin")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $ws->getStyle("B8:D$fin")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $ws->getStyle("F8:F$fin")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $ws->getStyle("G8:G$fin")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->getStyle("I8:I$fin")->getNumberFormat()->setFormatCode('0.00%');

        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setPrintArea('A1:L'.max($fin, 12));
        return $ss;
    }

    /** Fichier temporaire (à supprimer après usage) et son nom, au format xlsx ou pdf. */
    public static function fichier(Client $c, string $format = 'xlsx'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'assoc');
        if ($format === 'pdf') {
            $lignes = self::lignes($c);
            \Barryvdh\DomPDF\Facade\Pdf::loadView('clients.liste-associes-pdf', ['client' => $c, 'lignes' => $lignes, 'identifiant' => self::identifiant($c),
                'total' => (int) $c->nb_parts ?: array_sum(array_column($lignes, 'total'))])->setPaper('a4', 'landscape')->save($tmp);
        } else {
            (new Xlsx(self::classeur($c)))->save($tmp);
        }
        return [$tmp, 'Liste des associés - '.Str::of($c->nom)->ascii()->replaceMatches('/[^A-Za-z0-9 _-]/', '')->trim().'.'.($format === 'pdf' ? 'pdf' : 'xlsx')];
    }
}
