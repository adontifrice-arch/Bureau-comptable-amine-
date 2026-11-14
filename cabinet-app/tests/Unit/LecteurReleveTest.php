<?php

namespace Tests\Unit;

use App\Services\Releves\LecteurReleve as L;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/** Lecture des relevés bancaires : Excel, CSV, Word, PDF (texte extrait en local). */
class LecteurReleveTest extends TestCase
{
    private array $fichiers = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiers as $f) @unlink($f);
        parent::tearDown();
    }

    private function fichier(string $ext): string
    {
        return $this->fichiers[] = tempnam(sys_get_temp_dir(), 'rel').'.'.$ext;
    }

    /** [date, libellé, débit, crédit] de chaque ligne lue. */
    private function resume(array $lignes): array
    {
        return array_map(fn ($l) => [$l['date'], $l['libelle'], $l['debit'], $l['credit']], $lignes);
    }

    public function test_montants_et_dates(): void
    {
        $this->assertSame(1250.0, L::montant('1 250,000'));
        $this->assertSame(1250.5, L::montant('1.250,500'));
        $this->assertSame(1250.0, L::montant('1250.000'));
        $this->assertSame(-35.5, L::montant('-35,500'));
        $this->assertSame(-35.5, L::montant('35,500-'));
        $this->assertSame(12.5, L::montant('12,500 TND'));
        $this->assertNull(L::montant(''));
        $this->assertSame('2026-01-15', L::date('15/01/2026'));
        $this->assertSame('2026-01-15', L::date('15-01-26'));
        $this->assertSame('2026-01-15', L::date('2026-01-15'));
        $this->assertSame('2026-01-15', L::date(DateExcel::formattedPHPToExcel(2026, 1, 15)));
        $this->assertNull(L::date('31/02/2026'));
        $this->assertNull(L::date('Libellé'));
    }

    public function test_csv_point_virgule_et_virgule_decimale(): void
    {
        $f = $this->fichier('csv');
        file_put_contents($f, mb_convert_encoding("Relevé BIAT\nDate;Date valeur;Libellé;Débit;Crédit;Solde\n"
            ."02/01/2026;03/01/2026;VIR RECU ALPHA;;5 950,000;15 950,000\n05/01/2026;05/01/2026;FRAIS TENUE DE COMPTE;12,500;;15 937,500\n"
            .";;suite du libellé;;;\nTotal;;;12,500;5 950,000;\n", 'Windows-1252', 'UTF-8'));

        $r = L::lire($f, 'csv');

        $this->assertSame('csv', $r['methode']);
        $this->assertSame([['2026-01-02', 'VIR RECU ALPHA', 0.0, 5950.0], ['2026-01-05', 'FRAIS TENUE DE COMPTE suite du libellé', 12.5, 0.0]], $this->resume($r['lignes']));
    }

    public function test_csv_avec_colonne_montant_signee(): void
    {
        $f = $this->fichier('csv');
        file_put_contents($f, "Date,Description,Montant\n2026-01-02,Versement espèces,\"1,250.000\"\n2026-01-03,Retrait DAB,-200.000\n");

        $this->assertSame([['2026-01-02', 'Versement espèces', 0.0, 1250.0], ['2026-01-03', 'Retrait DAB', 200.0, 0.0]], $this->resume(L::lire($f, 'csv')['lignes']));
    }

    public function test_excel_avec_dates_reelles(): void
    {
        $s = new Spreadsheet;
        $s->getActiveSheet()->fromArray([
            ['Banque Zitouna — relevé de janvier'], [],
            ['Date opération', 'Libellé', 'Débit', 'Crédit'],
            [DateExcel::formattedPHPToExcel(2026, 1, 10), 'CHEQUE 1234 STEG', 820, null],
            [DateExcel::formattedPHPToExcel(2026, 1, 12), 'VIREMENT CLIENT BETA', null, 3000.5],
        ]);
        $f = $this->fichier('xlsx');
        (new Xlsx($s))->save($f);

        $this->assertSame([['2026-01-10', 'CHEQUE 1234 STEG', 820.0, 0.0], ['2026-01-12', 'VIREMENT CLIENT BETA', 0.0, 3000.5]], $this->resume(L::lire($f, 'xlsx')['lignes']));
    }

    public function test_word_avec_tableau(): void
    {
        $w = new PhpWord;
        $sec = $w->addSection();
        $sec->addText('Relevé de compte — Amen Bank');
        $t = $sec->addTable();
        foreach ([['Date', 'Opération', 'Débit', 'Crédit'], ['03/02/2026', 'PRLV CNSS', '1 250,750', ''], ['04/02/2026', 'REMISE CHEQUES', '', '2 000,000']] as $row) {
            $t->addRow();
            foreach ($row as $c) $t->addCell(2000)->addText($c);
        }
        $f = $this->fichier('docx');
        WordIO::createWriter($w, 'Word2007')->save($f);

        $this->assertSame([['2026-02-03', 'PRLV CNSS', 1250.75, 0.0], ['2026-02-04', 'REMISE CHEQUES', 0.0, 2000.0]], $this->resume(L::lire($f, 'docx')['lignes']));
    }

    public function test_pdf_colonnes_collees_et_sens_deduit_du_solde(): void
    {
        $lignes = [['02/01/2026', '03/01/2026', 'VIR RECU SOCIETE ALPHA FACT 12', '', '5 950,000', '15 950,000'],
            ['05/01/2026', '05/01/2026', 'FRAIS TENUE DE COMPTE', '12,500', '', '15 937,500'],
            ['10/01/2026', '10/01/2026', 'OPERATION DIVERSE', '820,000', '', '15 117,500'],
            ['15/01/2026', '15/01/2026', 'OPERATION DIVERSE BIS', '', '1 250,750', '16 368,250']];
        $h = '<p>Solde précédent au 31/12/2025 : 10 000,000</p><table><tr><th>Date</th><th>Valeur</th><th>Libellé</th><th>Débit</th><th>Crédit</th><th>Solde</th></tr>';
        foreach ($lignes as $l) $h .= '<tr><td>'.implode('</td><td>', $l).'</td></tr>';
        $f = $this->fichier('pdf');
        file_put_contents($f, Pdf::loadHTML($h.'</table>')->output());

        $r = L::lire($f, 'pdf');

        $this->assertSame('pdf', $r['methode']);
        $this->assertSame([['2026-01-02', 'VIR RECU SOCIETE ALPHA FACT 12', 0.0, 5950.0], ['2026-01-05', 'FRAIS TENUE DE COMPTE', 12.5, 0.0],
            ['2026-01-10', 'OPERATION DIVERSE', 820.0, 0.0], ['2026-01-15', 'OPERATION DIVERSE BIS', 0.0, 1250.75]], $this->resume($r['lignes']));
        $this->assertFalse(collect($r['lignes'])->contains('sens_incertain', true));
    }

    /** Mise en page type Attijari : « jj  mm » sans année, date de valeur, colonnes Débit / Crédit sans solde, lignes de suite, totaux. */
    public function test_pdf_par_positions_sans_annee_ni_solde_avec_controle_des_totaux(): void
    {
        $cell = fn ($t, $x) => '<span style="position:absolute; left:'.$x.'pt">'.$t.'</span>';
        $ligne = fn ($y, array $c) => '<div style="position:absolute; top:'.$y.'pt; left:0; width:560pt; height:10pt">'.implode('', $c).'</div>';
        $ops = [
            ['31  12', 'REM TPE 301225 0004797', '30  12 2025', '', '73,000'],
            ['02  01', 'TICD TUNIS', '31  12 2025', '114,000', ''],
            ['02  01', 'REGLEMENT PRELEVEMENT 1250562', '02  01 2026', '1 250,750', ''],
            [null, 'ORG: CNSS', null, null, null],
            ['05  01', 'COMMISSION VIR AUT BQ 020253', '05  01 2026', '1,785', ''],
            [null, 'DONT TVA: 0,285', null, null, null],
            ['09  01', 'ENCAISSEMENT CHEQUE 472727', '09  01 2026', '', '10 104,000'],
        ];
        $h = '<html><body style="font-family: DejaVu Sans; font-size:7pt">'.$ligne(20, [$cell('DATE LIBELLE VALEUR DEBIT CREDIT', 15)])
            .$ligne(40, [$cell('SOLDE AU 31/12/2025', 180), $cell('2 526,313', 440)]);
        $y = 60;
        foreach ($ops as [$d, $lib, $val, $deb, $cre]) {
            $c = [$cell($lib, 60)];
            if ($d) $c = array_merge([$cell($d, 15)], $c, [$cell($val, 290)]);
            if ($deb) $c[] = $cell($deb, 360);
            if ($cre) $c[] = $cell($cre, 440);
            $h .= $ligne($y, $c); $y += 14;
        }
        $h .= $ligne($y + 10, [$cell('TOTAUX', 180), $cell('1 366,535', 360), $cell('12 703,313', 440)]).'</body></html>';
        $f = $this->fichier('pdf');
        file_put_contents($f, Pdf::loadHTML($h)->output());

        $r = L::lire($f, 'pdf');

        $this->assertSame([
            ['2025-12-31', 'REM TPE 301225 0004797', 0.0, 73.0],                          // année de la date de valeur
            ['2026-01-02', 'TICD TUNIS', 114.0, 0.0],                                     // valeur en 2025, opération en janvier : 2026
            ['2026-01-02', 'REGLEMENT PRELEVEMENT 1250562 ORG: CNSS', 1250.75, 0.0],      // ligne de suite rattachée
            ['2026-01-05', 'COMMISSION VIR AUT BQ 020253 DONT TVA: 0,285', 1.785, 0.0],   // « 0,285 » n'est pas un montant de l'opération
            ['2026-01-09', 'ENCAISSEMENT CHEQUE 472727', 0.0, 10104.0],
        ], $this->resume($r['lignes']));
        $this->assertFalse(collect($r['lignes'])->contains('sens_incertain', true));
        $this->assertTrue($r['controle']['ok'], $r['controle']['message']);              // crédits 10 177 + solde initial 2 526,313 = 12 703,313
    }

    public function test_texte_sans_solde_sens_selon_le_libelle(): void
    {
        $l = L::texte("12/03/2026 VERSEMENT ESPECES 500,000\n13/03/2026 PRELEVEMENT LOYER 900,000\n14/03/2026 OPERATION 42 75,000");

        $this->assertSame([['2026-03-12', 'VERSEMENT ESPECES', 0.0, 500.0], ['2026-03-13', 'PRELEVEMENT LOYER', 900.0, 0.0], ['2026-03-14', 'OPERATION 42', 75.0, 0.0]], $this->resume($l));
        $this->assertSame([false, false, true], array_column($l, 'sens_incertain'));      // « OPERATION 42 » : sens inconnu
    }
}
