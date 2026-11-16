<?php

namespace App\Services\Exports;

use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\Response;

/**
 * État imprimable (spécification §8) : un tableau rendu à l'identique en impression papier, PDF
 * (en-tête cabinet, client, période, date d'édition, « Page i / n »), Excel (nombres au format #,##0.000,
 * dates réelles, volets figés) et Word (tableau avec en-tête répété, orientation adaptée).
 *
 * Colonnes : [libellé, type] avec type text | date | num. Lignes : ['cellules' => [...], 'style' => normal|groupe|soustotal|total].
 */
class Etat
{
    public array $lignes = [];

    /** Logo à afficher à la place de celui du cabinet (chemin sur le disque public), par ex. le logo de l'employeur sur les états de paie. */
    public ?string $logo = null;

    public function __construct(
        public string $titre,
        public string $client,
        public string $periode,
        public array $colonnes,
        public bool $paysage = true,
    ) {}

    public function ligne(array $cellules, string $style = 'normal'): static
    {
        $this->lignes[] = ['cellules' => $cellules, 'style' => $style];
        return $this;
    }

    public function nomFichier(string $ext): string
    {
        return Str::slug(str_replace('/', '-', $this->titre.' '.$this->client.' '.$this->periode)).'.'.$ext;
    }

    /** Valeur affichée d'une cellule (impression, PDF, Word). */
    public function texte($v, string $type): string
    {
        if ($v === null || $v === '') return '';
        return match ($type) {
            'num' => is_numeric($v) ? number_format((float) $v, 3, ',', ' ') : (string) $v,
            'date' => $v instanceof \DateTimeInterface ? $v->format('d/m/Y') : Carbon::parse($v)->format('d/m/Y'),
            default => (string) $v,
        };
    }

    private function cab(): array
    {
        return $this->logo ? ['logo' => $this->logo] + Setting::cabinet() : Setting::cabinet();
    }

    public function reponse(string $format): Response
    {
        return match ($format) {
            'pdf' => $this->pdf(),
            'xlsx' => $this->xlsx(),
            'docx' => $this->docx(),
            default => response()->view('etats.imprimer', ['etat' => $this, 'cab' => $this->cab(), 'impression' => true]),
        };
    }

    private function pdf(): Response
    {
        $pdf = Pdf::loadView('etats.imprimer', ['etat' => $this, 'cab' => $this->cab(), 'impression' => false])
            ->setPaper('a4', $this->paysage ? 'landscape' : 'portrait')->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $d = $pdf->getDomPDF();
        $d->render();
        $c = $d->getCanvas();
        $police = $d->getFontMetrics()->getFont('Helvetica');
        $c->page_text($c->get_width() - 90, $c->get_height() - 24, 'Page {PAGE_NUM} / {PAGE_COUNT}', $police, 8, [0.4, 0.4, 0.4]);
        $c->page_text(36, $c->get_height() - 24, 'Édité le '.now()->format('d/m/Y à H:i'), $police, 8, [0.4, 0.4, 0.4]);
        return response($d->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$this->nomFichier('pdf').'"']);
    }

    private function xlsx(): Response
    {
        $ss = new Spreadsheet();
        $ss->getProperties()->setTitle($this->titre)->setCreator(Setting::cabinet()['nom']);
        $ws = $ss->getActiveSheet();
        $ws->setTitle(Str::limit(preg_replace('#[\\\\/?*:\[\]]#', ' ', $this->titre), 28, ''));
        $ws->getPageSetup()->setOrientation($this->paysage ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);

        $nb = count($this->colonnes);
        $der = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($nb);
        $ws->setCellValue('A1', $this->titre)->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->setCellValue('A2', Setting::cabinet()['nom'].' · '.$this->client.' · '.$this->periode);
        $ws->setCellValue('A3', 'Édité le '.now()->format('d/m/Y H:i'));
        $ws->getStyle('A2:A3')->getFont()->setSize(9)->getColor()->setRGB('555555');

        $l = 5;
        foreach ($this->colonnes as $k => [$libelle]) $ws->setCellValue([$k + 1, $l], $libelle);
        $ws->getStyle("A$l:$der$l")->applyFromArray(['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]], 'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($l, $l);
        $ws->freezePane('A'.($l + 1));

        foreach ($this->lignes as $ligne) {
            $l++;
            foreach ($ligne['cellules'] as $k => $v) {
                if ($v === null || $v === '') continue;
                $type = $this->colonnes[$k][1] ?? 'text';
                if ($type === 'num' && is_numeric($v)) $ws->setCellValue([$k + 1, $l], (float) $v);
                elseif ($type === 'date') $ws->setCellValue([$k + 1, $l], DateExcel::PHPToExcel(Carbon::parse($v)->startOfDay()));
                else $ws->setCellValueExplicit([$k + 1, $l], (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            if ($ligne['style'] !== 'normal') {
                $ws->getStyle("A$l:$der$l")->getFont()->setBold(true);
                if ($ligne['style'] === 'total') $ws->getStyle("A$l:$der$l")->getBorders()->getTop()->setBorderStyle(Border::BORDER_DOUBLE);
                if ($ligne['style'] === 'soustotal') $ws->getStyle("A$l:$der$l")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
            }
        }
        foreach ($this->colonnes as $k => [, $type]) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($k + 1);
            if ($type === 'num') $ws->getStyle($col.'6:'.$col.$l)->getNumberFormat()->setFormatCode('#,##0.000');
            if ($type === 'date') $ws->getStyle($col.'6:'.$col.$l)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            $ws->getColumnDimension($col)->setWidth($type === 'text' ? ($k === 0 ? 34 : 18) : ($type === 'date' ? 12 : 15));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($ss))->save($tmp);
        return response()->download($tmp, $this->nomFichier('xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    private function docx(): Response
    {
        $w = new PhpWord();
        $w->setDefaultFontName('Calibri');
        $w->setDefaultFontSize(8);
        $s = $w->addSection(['orientation' => $this->paysage ? 'landscape' : 'portrait', 'marginLeft' => 700, 'marginRight' => 700, 'marginTop' => 700, 'marginBottom' => 700]);
        $s->addText($this->titre, ['bold' => true, 'size' => 14]);
        $s->addText(Setting::cabinet()['nom'].' · '.$this->client.' · '.$this->periode, ['size' => 9, 'color' => '555555']);
        $s->addText('Édité le '.now()->format('d/m/Y à H:i'), ['size' => 8, 'color' => '777777']);
        $s->addFooter()->addPreserveText('Page {PAGE} / {NUMPAGES}', ['size' => 8, 'color' => '666666'], ['alignment' => Jc::END]);

        $t = $s->addTable(['borderSize' => 4, 'borderColor' => 'BBBBBB', 'cellMargin' => 50, 'width' => 5000, 'unit' => 'pct']);
        $t->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);           // en-tête répété sur chaque page
        foreach ($this->colonnes as [$libelle, $type]) {
            $t->addCell(null, ['bgColor' => 'E2E8F0'])->addText($libelle, ['bold' => true], ['alignment' => $type === 'num' ? Jc::END : Jc::START]);
        }
        foreach ($this->lignes as $ligne) {
            $t->addRow(null, ['cantSplit' => true]);
            $gras = $ligne['style'] !== 'normal';
            foreach ($this->colonnes as $k => [, $type]) {
                $t->addCell()->addText($this->texte($ligne['cellules'][$k] ?? null, $type), ['bold' => $gras], ['alignment' => $type === 'num' ? Jc::END : Jc::START]);
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        IOFactory::createWriter($w, 'Word2007')->save($tmp);
        return response()->download($tmp, $this->nomFichier('docx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])->deleteFileAfterSend();
    }
}
