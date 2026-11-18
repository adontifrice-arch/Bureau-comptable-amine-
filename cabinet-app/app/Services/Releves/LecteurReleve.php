<?php

namespace App\Services\Releves;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory as Tableur;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\IOFactory as Word;
use RuntimeException;
use Smalot\PdfParser\Parser as Pdf;

/**
 * Lecture d'un relevé bancaire : Excel / CSV (colonnes reconnues par leur en-tête), Word (tableaux ou texte),
 * PDF (texte extrait en local) et, en dernier recours, lecture par l'IA (PDF scanné, image).
 *
 * Chaque ligne : ['date' => 'AAAA-MM-JJ', 'libelle' => '', 'debit' => sortie, 'credit' => entrée, 'sens_incertain' => bool].
 * Débit et crédit s'entendent du point de vue de la banque, comme sur le relevé.
 */
class LecteurReleve
{
    public const EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv', 'txt', 'docx', 'pdf', 'jpg', 'jpeg', 'png'];

    /**
     * Montant tunisien : 1 250,000 · 1.250,000 · 1250.000 · -35,500 (décimales obligatoires). Trois décimales exactement,
     * ce qui sépare des montants que l'extraction PDF colle entre eux (« 5 950,00015 950,000 »).
     */
    private const MONTANT = '-?(?:0|[1-9]\d{0,2}(?:[ \x{00A0}\x{202F}.,]\d{3})+|[1-9]\d*)[.,](?:\d{3}|\d{2}(?!\d))';
    /** Date jj/mm/aaaa (éventuellement collée au texte suivant) ou jj/mm/aa. */
    private const DATE = '\d{1,2}[\/.\-]\d{1,2}[\/.\-](?:\d{4}|\d{2}(?!\d))';

    /** @return array{methode: string, lignes: array<int, array>, controle?: ?array{ok: bool, message: string}} */
    public static function lire(string $chemin, string $extension): array
    {
        $ext = strtolower($extension);
        return match (true) {
            in_array($ext, ['csv', 'txt'], true) => ['methode' => 'csv', 'lignes' => self::tableau(self::csv($chemin), self::texteFichier($chemin))],
            in_array($ext, ['xlsx', 'xls', 'ods'], true) => ['methode' => 'xlsx', 'lignes' => self::tableau(self::tableur($chemin))],
            $ext === 'docx' => ['methode' => 'docx', 'lignes' => self::word($chemin)],
            $ext === 'pdf' => self::pdf($chemin),
            in_array($ext, ['jpg', 'jpeg', 'png'], true) => ['methode' => 'ia', 'lignes' => []],     // image : seule l'IA peut la lire
            default => throw new RuntimeException('Format non pris en charge : '.$ext.'. Formats acceptés : Excel, CSV, Word (.docx), PDF.'),
        };
    }

    // ---------- Sources tabulaires ----------

    /** Texte d'un fichier en UTF-8 (sans BOM ; les exports Excel sous Windows sont en Windows-1252). */
    public static function texteFichier(string $chemin): string
    {
        $txt = (string) file_get_contents($chemin);
        if (str_starts_with($txt, "\xEF\xBB\xBF")) $txt = substr($txt, 3);
        return mb_check_encoding($txt, 'UTF-8') ? $txt : mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
    }

    public static function csv(string $chemin): array
    {
        $lignes = preg_split('/\R/', trim(self::texteFichier($chemin)));
        $debut = implode("\n", array_slice($lignes, 0, 30));
        $sep = collect([';', "\t", ',', '|'])->sortByDesc(fn ($s) => substr_count($debut, $s))->first();
        return array_map(fn ($l) => str_getcsv($l, $sep, '"', ''), $lignes);
    }

    public static function tableur(string $chemin): array
    {
        $feuille = Tableur::load($chemin)->getActiveSheet();
        return $feuille->toArray(null, true, false, false);      // valeurs brutes : dates Excel en numéros de série
    }

    public static function word(string $chemin): array
    {
        $tableaux = []; $texte = [];
        foreach (Word::load($chemin)->getSections() as $section) {
            foreach ($section->getElements() as $el) {
                if ($el instanceof Table) {
                    foreach ($el->getRows() as $row) $tableaux[] = array_map(fn ($c) => trim(self::texteWord($c)), $row->getCells());
                } else {
                    $texte[] = self::texteWord($el);
                }
            }
        }
        if ($tableaux && ($l = self::tableau($tableaux))) return $l;
        return self::texte(implode("\n", $texte));
    }

    public static function texteWord($el): string
    {
        if ($el instanceof TextBreak) return "\n";
        if ($el instanceof Text) return (string) $el->getText();
        if ($el instanceof Table) return implode("\n", array_map(fn ($r) => implode('  ', array_map(fn ($c) => self::texteWord($c), $r->getCells())), $el->getRows()));
        if ($el instanceof AbstractContainer) {
            $sep = class_basename($el) === 'TextRun' ? '' : "\n";
            return implode($sep, array_map(fn ($e) => self::texteWord($e), $el->getElements()));
        }
        return method_exists($el, 'getText') && is_string($el->getText()) ? $el->getText() : '';
    }

    /** PDF : lecture par positions (colonnes Débit / Crédit), sinon lecture du texte. */
    public static function pdf(string $chemin): array
    {
        $p = self::pdfPositions($chemin);
        if ($p && $p['lignes']) return ['methode' => 'pdf', 'lignes' => $p['lignes'], 'controle' => $p['controle']];
        return ['methode' => 'pdf', 'lignes' => self::texte(self::pdfTexte($chemin))];
    }

    /**
     * Lecture d'un PDF par la position des textes sur la page (relevés dont l'extraction colle les colonnes,
     * ex. Attijari : « 02  01 » sans année, montant collé à la date de valeur).
     * - une opération = une ligne dont le premier texte, à gauche, est une date (jj mm, jj/mm/aaaa…) ;
     * - l'année manquante vient de la date de valeur ;
     * - la colonne d'un montant (débit, crédit, solde) se déduit de sa position horizontale : les positions forment
     *   des groupes séparés, de gauche à droite débit, crédit, puis solde éventuel ;
     * - les lignes sans date sous une opération complètent son libellé (« ORG: CNSS », « DONT TVA… ») ;
     * - « SOLDE AU » et « TOTAUX » permettent de contrôler la lecture.
     *
     * @return ?array{lignes: array, controle: ?array{ok: bool, message: string}}
     */
    public static function pdfPositions(string $chemin): ?array
    {
        try {
            $pages = (new Pdf)->parseFile($chemin)->getPages();
        } catch (\Throwable) {
            return null;
        }
        $ops = []; $soldeInitial = null; $totaux = null; $xsMontants = [];
        foreach ($pages as $page) {
            try { $items = $page->getDataTm(); } catch (\Throwable) { continue; }
            $elements = [];
            foreach ($items as [$m, $t]) {
                $t = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', (string) $t)));
                if ($t === '' || preg_match('/\p{Arabic}/u', $t)) continue;
                $elements[] = ['x' => (float) $m[4], 'y' => (float) $m[5], 't' => $t];
            }
            // Regroupement en lignes (même hauteur à 2,5 points près), de haut en bas puis de gauche à droite
            usort($elements, fn ($a, $b) => $b['y'] <=> $a['y'] ?: $a['x'] <=> $b['x']);
            $lignes = [];
            foreach ($elements as $e) {
                $k = array_key_last($lignes);
                if ($k !== null && abs($lignes[$k]['y'] - $e['y']) <= 2.5) $lignes[$k]['el'][] = $e;
                else $lignes[] = ['y' => $e['y'], 'el' => [$e]];
            }
            $derniere = null;                                    // dernière opération de la page (lignes de suite)
            foreach ($lignes as $l) {
                $el = $l['el'];
                usort($el, fn ($a, $b) => $a['x'] <=> $b['x']);
                $texte = Str::upper(Str::ascii(implode(' ', array_column($el, 't'))));
                $montants = array_values(array_filter($el, fn ($e) => preg_match('/^'.self::MONTANT.'$/u', $e['t'])));
                if (preg_match('/^(SOLDE|REPORT|TOTAUX|TOTAL|NOUVEAU SOLDE|ANCIEN SOLDE)\b/', ltrim($texte))) {
                    if (preg_match('/^SOLDE AU/', ltrim($texte)) && $montants && $soldeInitial === null) $soldeInitial = ['x' => $montants[0]['x'], 'v' => self::montant($montants[0]['t'])];
                    if (preg_match('/^TOTA(UX|L)\b/', ltrim($texte))) $totaux = count($montants) >= 2 ? $montants : [];   // [] : montants sur une ligne suivante
                    $derniere = null;
                    continue;
                }
                // Totaux sur une ligne suivant « TOTAUX » : montants seuls
                if ($totaux === [] && count($montants) >= 2 && count($montants) === count($el)) { $totaux = $montants; continue; }

                $premier = $el[0];
                $date = null;
                if ($premier['x'] < 60 && preg_match('/^(\d{1,2})[\s\/.\-]+(\d{1,2})(?:[\s\/.\-]+(\d{2,4}))?$/', $premier['t'], $d) && (int) $d[1] >= 1 && (int) $d[1] <= 31 && (int) $d[2] >= 1 && (int) $d[2] <= 12) {
                    $date = ['j' => (int) $d[1], 'm' => (int) $d[2], 'a' => isset($d[3]) ? ((int) $d[3] < 100 ? 2000 + (int) $d[3] : (int) $d[3]) : null];
                }
                if (! $date) {
                    // Suite du libellé de l'opération précédente (juste en dessous, sans montant)
                    if ($derniere !== null && ! $montants && $premier['x'] >= 40 && $derniere['y'] - $l['y'] < 30) {
                        $ops[$derniere['i']]['libelle'] .= ' '.implode(' ', array_column($el, 't'));
                        $derniere['y'] = $l['y'];
                    }
                    continue;
                }
                $valeur = null; $libelle = [];
                foreach (array_slice($el, 1) as $e) {
                    if (in_array($e, $montants, true)) continue;
                    if (! $valeur && preg_match('/^(\d{1,2})[\s\/.\-]+(\d{1,2})[\s\/.\-]+(\d{4})$/', $e['t'], $v)) { $valeur = ['m' => (int) $v[2], 'a' => (int) $v[3]]; continue; }
                    $libelle[] = $e['t'];
                }
                if (! $montants) continue;
                $a = $date['a'];
                if (! $a && $valeur) $a = $valeur['a'] + ($date['m'] === 1 && $valeur['m'] === 12 ? 1 : 0) - ($date['m'] === 12 && $valeur['m'] === 1 ? 1 : 0);
                $a ??= (int) now()->year;
                if (! checkdate($date['m'], $date['j'], $a)) continue;
                $ops[] = ['date' => sprintf('%04d-%02d-%02d', $a, $date['m'], $date['j']), 'libelle' => trim(implode(' ', $libelle)) ?: 'Opération',
                    'montants' => array_map(fn ($e) => ['x' => $e['x'], 'v' => abs((float) self::montant($e['t']))], $montants)];
                foreach ($montants as $e) $xsMontants[] = $e['x'];
                $derniere = ['i' => array_key_last($ops), 'y' => $l['y']];
            }
        }
        if (! $ops) return null;

        // Colonnes : groupes de positions séparés d'au moins 25 points
        sort($xsMontants);
        $groupes = [[$xsMontants[0]]];
        foreach (array_slice($xsMontants, 1) as $x) {
            if ($x - end($groupes[array_key_last($groupes)]) > 25) $groupes[] = [$x];
            else $groupes[array_key_last($groupes)][] = $x;
        }
        $colonne = function (float $x) use ($groupes): ?int {
            foreach ($groupes as $i => $g) if ($x >= min($g) - 12 && $x <= max($g) + 12) return $i;
            return null;
        };
        $deuxColonnes = count($groupes) >= 2;

        $out = [];
        foreach ($ops as $o) {
            $debit = 0.0; $credit = 0.0; $incertain = false;
            if ($deuxColonnes) {
                foreach ($o['montants'] as $mt) {
                    $c = $colonne($mt['x']);
                    if ($c === 0) $debit += $mt['v'];
                    elseif ($c === 1) $credit += $mt['v'];          // groupe 2 et suivants : solde, ignoré
                }
            } else {
                $v = $o['montants'][0]['v'];
                $sens = self::sensDuLibelle($o['libelle']);
                [$debit, $credit, $incertain] = $sens ? [0.0, $v, false] : [$v, 0.0, $sens === null];
            }
            if ($debit < 0.0005 && $credit < 0.0005) continue;
            $out[] = ['date' => $o['date'], 'libelle' => Str::limit(trim(preg_replace('/\s+/u', ' ', $o['libelle'])), 250, ''),
                'debit' => round($debit, 3), 'credit' => round($credit, 3), 'sens_incertain' => $incertain];
        }

        // Contrôle avec les totaux du relevé (le total crédit inclut souvent le solde initial créditeur)
        $controle = null;
        if ($deuxColonnes && $totaux && count($totaux) >= 2) {
            $tot = [0 => 0.0, 1 => 0.0];
            foreach ($totaux as $t) { $c = $colonne($t['x']); if ($c === 0 || $c === 1) $tot[$c] += abs((float) self::montant($t['t'])); }
            $sd = round(array_sum(array_column($out, 'debit')), 3); $sc = round(array_sum(array_column($out, 'credit')), 3);
            $si = $soldeInitial ? ['c' => $colonne($soldeInitial['x']), 'v' => abs((float) $soldeInitial['v'])] : null;
            $okD = abs($sd - $tot[0]) < 0.01 || ($si && $si['c'] === 0 && abs($sd + $si['v'] - $tot[0]) < 0.01);
            $okC = abs($sc - $tot[1]) < 0.01 || ($si && $si['c'] === 1 && abs($sc + $si['v'] - $tot[1]) < 0.01);
            $controle = ['ok' => $okD && $okC, 'message' => $okD && $okC
                ? 'totaux du relevé vérifiés (débits '.number_format($sd, 3, ',', ' ').', crédits '.number_format($sc, 3, ',', ' ').')'
                : 'écart avec les totaux du relevé : débits lus '.number_format($sd, 3, ',', ' ').' / relevé '.number_format($tot[0], 3, ',', ' ')
                    .', crédits lus '.number_format($sc, 3, ',', ' ').' / relevé '.number_format($tot[1], 3, ',', ' ').' — vérifiez les lignes'];
        }
        return ['lignes' => $out, 'controle' => $controle];
    }

    public static function pdfTexte(string $chemin): string
    {
        try {
            return (new Pdf)->parseFile($chemin)->getText();
        } catch (\Throwable) {
            return '';                                              // PDF protégé ou image : l'IA prend le relais
        }
    }

    /** Lignes d'un tableau : repère l'en-tête (date, libellé, débit / crédit ou montant) puis lit les lignes datées. */
    public static function tableau(array $rows, ?string $texteBrut = null): array
    {
        $cols = null; $debut = 0;
        foreach (array_slice($rows, 0, 40, true) as $i => $row) {
            $c = self::colonnes((array) $row);
            if (isset($c['date']) && (isset($c['debit']) || isset($c['credit']) || isset($c['montant']))) { $cols = $c; $debut = $i + 1; break; }
        }
        if (! $cols) {
            // Sans en-tête reconnu : lecture comme du texte (le texte brut d'un CSV / TXT, que le découpage aurait coupé aux virgules)
            return self::texte($texteBrut ?? implode("\n", array_map(fn ($r) => implode('   ', array_map(fn ($v) => self::cellule($v), (array) $r)), $rows)));
        }
        $out = [];
        foreach (array_slice($rows, $debut) as $row) {
            $row = array_values((array) $row);
            $lib = trim(implode(' ', array_filter(array_map(fn ($k) => trim(self::cellule($row[$k] ?? '')), $cols['libelle'] ?? []))));
            $date = self::date($row[$cols['date']] ?? null);
            if (! $date) {
                // Libellé sur plusieurs lignes : suite de la ligne précédente
                if ($out && $lib !== '' && self::estVide($row, $cols)) $out[array_key_last($out)]['libelle'] .= ' '.$lib;
                continue;
            }
            if (isset($cols['montant'])) {
                $m = self::montant($row[$cols['montant']] ?? null) ?? 0.0;
                [$debit, $credit] = [$m < 0 ? -$m : 0.0, $m > 0 ? $m : 0.0];
            } else {
                $debit = abs(self::montant($row[$cols['debit'] ?? -1] ?? null) ?? 0.0);
                $credit = abs(self::montant($row[$cols['credit'] ?? -1] ?? null) ?? 0.0);
            }
            if ($debit < 0.0005 && $credit < 0.0005) continue;
            $out[] = ['date' => $date, 'libelle' => Str::limit($lib ?: 'Opération', 250, ''), 'debit' => round($debit, 3), 'credit' => round($credit, 3), 'sens_incertain' => false];
        }
        return $out;
    }

    private static function estVide(array $row, array $cols): bool
    {
        foreach (['debit', 'credit', 'montant'] as $k) if (isset($cols[$k]) && self::montant($row[$cols[$k]] ?? null)) return false;
        return true;
    }

    /** Colonnes reconnues d'après le texte de l'en-tête. */
    private static function colonnes(array $row): array
    {
        $c = []; $dates = [];
        foreach (array_values($row) as $i => $v) {
            $t = Str::lower(Str::ascii(trim(self::cellule($v))));
            if ($t === '') continue;
            if (str_contains($t, 'date')) { $dates[$i] = str_contains($t, 'valeur'); continue; }
            if (str_contains($t, 'solde')) continue;
            if (preg_match('/debit|retrait|sortie|decaiss|paiement/', $t)) { $c['debit'] ??= $i; continue; }
            if (preg_match('/credit|versement|entree|encaiss|recette/', $t)) { $c['credit'] ??= $i; continue; }
            if (preg_match('/montant|amount|somme/', $t)) { $c['montant'] ??= $i; continue; }
            if (preg_match('/libel|operation|designation|description|nature|motif|detail|reference|intitule|transaction/', $t)) $c['libelle'][] = $i;
        }
        if ($dates) $c['date'] = array_search(false, $dates, true) !== false ? array_search(false, $dates, true) : array_key_first($dates);
        if (isset($c['debit']) || isset($c['credit'])) unset($c['montant']);
        return $c;
    }

    private static function cellule($v): string
    {
        if ($v instanceof \DateTimeInterface) return $v->format('d/m/Y');
        return is_scalar($v) ? (string) $v : '';
    }

    // ---------- Texte (PDF, Word sans tableau) ----------

    /**
     * Lignes datées suivies de leurs montants. Avec un solde en fin de ligne (ou un « solde précédent »), le sens
     * de l'opération se déduit de la variation du solde ; sinon il est déduit du libellé et signalé comme incertain.
     */
    public static function texte(string $texte): array
    {
        $out = []; $solde = null;
        foreach (preg_split('/\R/u', $texte) as $ligne) {
            // Les colonnes extraites du PDF sont séparées par des tabulations (ou de larges espaces) : on les garde
            $ligne = trim(preg_replace(['/\x{00A0}|\x{202F}/u', '/ {3,}/'], [' ', "\t"], $ligne));
            if ($ligne === '') continue;
            if (! preg_match('/^('.self::DATE.')\s*(.*)$/u', $ligne, $m)) {
                $montants = self::montantsFinaux($ligne)[1];
                if (preg_match('/solde|report|ancien/i', Str::ascii($ligne)) && $montants) { $solde = self::montant(end($montants)); continue; }
                // Suite du libellé de l'opération précédente
                if ($out && ! $montants && mb_strlen($ligne) < 80 && ! preg_match('/^(page|date|total|libell)/i', Str::ascii($ligne))) $out[array_key_last($out)]['libelle'] .= ' '.$ligne;
                continue;
            }
            $date = self::date($m[1]);
            if (! $date) continue;
            $reste = trim(preg_replace('/^\s*'.self::DATE.'/u', ' ', $m[2]));            // date de valeur (souvent collée)
            [$libelle, $montants] = self::montantsFinaux($reste);
            if (! $montants) continue;
            $libelle = trim(preg_replace('/\s+/u', ' ', $libelle));
            if (preg_match('/^(solde|total|report|ancien solde|nouveau solde)/i', Str::ascii($libelle))) { $solde = self::montant(end($montants)); continue; }

            $valeurs = array_map(fn ($x) => self::montant($x), $montants);
            $operation = $valeurs[0];
            $nouveauSolde = count($valeurs) >= 2 ? end($valeurs) : null;
            $credit = null; $incertain = false;
            if ($operation < 0) $credit = false;
            elseif ($nouveauSolde !== null && $solde !== null && abs(abs($nouveauSolde - $solde) - $operation) < 0.01) $credit = $nouveauSolde > $solde;
            else {
                $sens = self::sensDuLibelle($libelle);
                $credit = $sens ?? false;
                $incertain = $sens === null;
            }
            if ($nouveauSolde !== null) $solde = $nouveauSolde;
            $mt = round(abs($operation), 3);
            if ($mt < 0.0005) continue;
            $out[] = ['date' => $date, 'libelle' => Str::limit($libelle ?: 'Opération', 250, ''), 'debit' => $credit ? 0.0 : $mt, 'credit' => $credit ? $mt : 0.0, 'sens_incertain' => $incertain];
        }
        return $out;
    }

    /** Sépare un libellé de ses montants finaux (au plus trois : débit, crédit, solde). */
    private static function montantsFinaux(string $t): array
    {
        $montants = [];
        // Une tabulation sépare deux colonnes : un montant ne la traverse pas (« FACT 12 » reste au libellé)
        $t = str_replace("\t", ' ¦ ', $t);
        while (count($montants) < 3 && preg_match('/^(.*?)\s*('.self::MONTANT.')\s*(?:TND|DT)?[\s¦]*$/u', $t, $m)) {
            array_unshift($montants, $m[2]);
            $t = $m[1];
        }
        return [trim(str_replace('¦', ' ', $t)), $montants];
    }

    /** true : encaissement, false : décaissement, null : indéterminé. */
    public static function sensDuLibelle(string $libelle): ?bool
    {
        $t = Str::upper(Str::ascii($libelle));
        if (preg_match('/\b(VIR(EMENT)?\s+(RECU|EN VOTRE FAVEUR|DE|FAVEUR)|VERSEMENT|REMISE|ENCAISSEMENT|INTERETS CREDITEURS|AVOIR|RECU)\b/', $t)) return true;
        if (preg_match('/\b(RETRAIT|CHEQUE|CHQ|PRELEVEMENT|PRLV|FRAIS|COMMISSION|COM|AGIOS|PAIEMENT|ACHAT|VIR(EMENT)?\s+(EMIS|PERMANENT|A|VERS)|CARTE|CB|TPE|DAB|TIMBRE|TVA|IMPOT|CNSS|ECHEANCE|LOYER)\b/', $t)) return false;
        return null;
    }

    // ---------- Valeurs ----------

    public static function date($v): ?string
    {
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
        if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d{5}(\.\d+)?$/', trim($v)))) {
            $n = (float) $v;
            return $n > 20000 && $n < 80000 ? DateExcel::excelToDateTimeObject($n)->format('Y-m-d') : null;
        }
        $v = trim((string) $v);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) [$a, $mo, $j] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})$/', $v, $m)) [$j, $mo, $a] = [(int) $m[1], (int) $m[2], (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3]];
        else return null;
        return checkdate($mo, $j, $a) ? sprintf('%04d-%02d-%02d', $a, $mo, $j) : null;
    }

    public static function montant($v): ?float
    {
        if (is_int($v) || is_float($v)) return (float) $v;
        $s = trim((string) $v);
        if ($s === '') return null;
        $negatif = preg_match('/^\(.*\)$|-\s*$|^-/', $s) === 1;
        $s = preg_replace('/[^\d.,]/u', '', $s);
        if ($s === '' || ! preg_match('/\d/', $s)) return null;
        $p = strrpos($s, '.'); $c = strrpos($s, ',');
        if ($p !== false && $c !== false) {                              // les deux : le dernier est le séparateur décimal
            $dec = $p > $c ? '.' : ',';
            $s = str_replace($dec === '.' ? ',' : '.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($c !== false) {
            $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($p !== false && substr_count($s, '.') > 1) {
            $s = preg_replace('/\.(?=.*\.)/', '', $s);                      // 1.250.000 → 1250.000 (millimes)
        }
        if (! is_numeric($s)) return null;
        return ($negatif ? -1 : 1) * (float) $s;
    }

    // ---------- IA ----------

    /** Lecture par l'IA (PDF scanné, image ou texte non reconnu). Nécessite ANTHROPIC_API_KEY. */
    public static function ia(string $chemin, string $extension, ?string $texte = null): array
    {
        $key = config('services.anthropic.key');
        if (! $key) throw new RuntimeException('La lecture par l’IA n’est pas configurée (ANTHROPIC_API_KEY dans le fichier .env).');
        $ext = strtolower($extension);
        $contenu = [];
        if ($ext === 'pdf') {
            $contenu[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(file_get_contents($chemin))]];
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $contenu[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $ext === 'png' ? 'image/png' : 'image/jpeg', 'data' => base64_encode(file_get_contents($chemin))]];
        }
        $prompt = 'Voici un relevé bancaire tunisien. Extrais TOUTES les opérations, dans l’ordre, et réponds UNIQUEMENT par un objet JSON : '
            .'{"lignes":[{"date":"AAAA-MM-JJ","libelle":"","debit":0,"credit":0}]}. '
            .'debit = montant de la colonne Débit (sortie du compte), credit = montant de la colonne Crédit (entrée). '
            .'Montants en dinars avec 3 décimales, sans séparateur de milliers. N’inclus ni les soldes, ni les totaux, ni les reports.'
            .($texte ? "\n\nTexte du relevé :\n".Str::limit($texte, 60000, '') : '');
        $contenu[] = ['type' => 'text', 'text' => $prompt];
        $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(180)
            ->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 16000,
                'messages' => [['role' => 'user', 'content' => $contenu]]])->throw()->json();
        $txt = collect($res['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
        $j = json_decode(preg_replace('/^[^{]*|[^}]*$/s', '', $txt), true) ?: [];
        $out = [];
        foreach ((array) ($j['lignes'] ?? []) as $l) {
            $date = self::date($l['date'] ?? null);
            $debit = abs((float) ($l['debit'] ?? 0)); $credit = abs((float) ($l['credit'] ?? 0));
            if (! $date || ($debit < 0.0005 && $credit < 0.0005)) continue;
            $out[] = ['date' => $date, 'libelle' => Str::limit(trim((string) ($l['libelle'] ?? '')) ?: 'Opération', 250, ''),
                'debit' => round($debit, 3), 'credit' => round($credit, 3), 'sens_incertain' => false];
        }
        return $out;
    }
}
