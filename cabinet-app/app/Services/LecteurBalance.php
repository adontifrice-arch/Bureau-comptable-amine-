<?php

namespace App\Services;

use App\Services\Releves\LecteurReleve as L;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory as Word;
use RuntimeException;
use Smalot\PdfParser\Parser as Pdf;

/**
 * Lecture d'une balance (balance N-1 d'un nouveau dossier) : Excel / CSV, Word (tableaux ou texte), PDF, et l'IA en dernier recours.
 *
 * Chaque compte : ['compte' => '401000', 'libelle' => 'Fournisseurs', 'debit' => solde débiteur, 'credit' => solde créditeur, 'doute' => bool].
 * Seul le solde de fin d'exercice est retenu. « doute » : sens du solde déduit de la nature du compte, à vérifier.
 *
 * Tableaux (Excel, Word, CSV) : l'en-tête désigne les colonnes ; les soldes sont la dernière colonne « Débit » et la dernière « Crédit »
 * (ou une colonne « Solde » signée). Texte (PDF, Word sans tableau) : les cellules vides disparaissent ; le dernier montant de la ligne
 * est le solde et son sens se déduit des colonnes de mouvements (débits − crédits = solde).
 */
class LecteurBalance
{
    public const EXTENSIONS = ['xlsx', 'xls', 'ods', 'csv', 'txt', 'docx', 'doc', 'rtf', 'pdf'];

    /**
     * Montant d'une ligne de texte, décimales obligatoires : 1 250,000 · 1.250,000 · 1,250.000 · 1250.000 · -35,500 · (35,500).
     * Le séparateur de milliers est le même dans tout le nombre et diffère du séparateur décimal : « 1 000,000 250,000 » donne deux montants.
     * Trois décimales (millimes) : sépare aussi les montants que l'extraction PDF colle entre eux (« 5 000,0003 000,000 »).
     */
    private const MONTANT = '\(?-?(?:[1-9]\d{0,2}(?:[ \x{00A0}\x{202F}]\d{3})+[.,]\d{3}|[1-9]\d{0,2}(?:\.\d{3})+,\d{3}|[1-9]\d{0,2}(?:,\d{3})+\.\d{3}|(?:0|[1-9]\d*)[.,](?:\d{3}|\d{1,2}(?!\d)))\)?-?';

    /** @return array{methode: string, lignes: array<int, array>} */
    public static function lire(string $chemin, string $extension): array
    {
        $ext = strtolower($extension);
        $lignes = match (true) {
            in_array($ext, ['csv', 'txt'], true) => self::tableau(L::csv($chemin)),
            in_array($ext, ['xlsx', 'xls', 'ods'], true) => self::tableau(L::tableur($chemin)),
            in_array($ext, ['docx', 'doc', 'rtf'], true) => self::word($chemin),
            $ext === 'pdf' => self::texte(L::pdfTexte($chemin)),
            default => throw new RuntimeException('Format non pris en charge : '.$ext.'. Formats acceptés : PDF, Excel (.xlsx, .xls, .csv), Word (.docx, .doc, .rtf).'),
        };
        $methode = $ext;
        if (! $lignes && $ext === 'pdf' && config('services.anthropic.key')) {
            $lignes = self::ia($chemin);                       // PDF scanné : seule l'IA peut le lire
            $methode = 'ia';
        }
        return ['methode' => $methode, 'lignes' => self::sansTotaux($lignes)];
    }

    // ---------- Word ----------

    private static function word(string $chemin): array
    {
        $debut = (string) file_get_contents($chemin, false, null, 0, 8);
        $lecteur = str_starts_with($debut, '{\rtf') ? 'RTF' : (str_starts_with($debut, 'PK') ? 'Word2007' : 'MsDoc');
        try {
            $doc = Word::load($chemin, $lecteur);
        } catch (\Throwable) {
            throw new RuntimeException('Ce document Word ne peut pas être lu. Enregistrez-le au format .docx ou exportez la balance en Excel ou en PDF.');
        }
        $tableaux = []; $texte = [];
        foreach ($doc->getSections() as $section) {
            foreach ($section->getElements() as $el) {
                if ($el instanceof Table) {
                    foreach ($el->getRows() as $row) $tableaux[] = array_map(fn ($c) => trim(L::texteWord($c)), $row->getCells());
                } else {
                    $texte[] = L::texteWord($el);
                }
            }
        }
        if ($tableaux && ($l = self::tableau($tableaux))) return $l;
        return self::texte(implode("\n", $texte));
    }

    // ---------- Tableaux ----------

    /** Lignes d'un tableau : colonnes désignées par l'en-tête, sinon lecture comme du texte. */
    public static function tableau(array $rows): array
    {
        $rows = array_values(array_map(fn ($r) => array_values(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', (array) $r)), $rows));
        // En-tête : lignes avant le premier compte, textes cumulés par colonne (« Soldes » au-dessus de « Débit | Crédit »)
        $entete = []; $premier = null;
        foreach (array_slice($rows, 0, 40, true) as $i => $row) {
            if (self::compteDeLaLigne($row) !== null) { $premier = $i; break; }
            foreach ($row as $k => $v) $entete[$k] = trim(($entete[$k] ?? '').' '.Str::lower(Str::ascii($v)));
        }
        $cols = self::colonnes($entete);
        if ($premier === null || ! $cols) return self::texte(implode("\n", array_map(fn ($r) => implode("\t", $r), $rows)));

        $out = [];
        foreach (array_slice($rows, $premier) as $row) {
            $compte = isset($cols['compte']) ? self::compte($row[$cols['compte']] ?? '') : self::compteDeLaLigne($row);
            if ($compte === null) continue;
            $libelle = isset($cols['libelle']) ? trim($row[$cols['libelle']] ?? '')
                : trim((string) collect($row)->first(fn ($v) => trim($v) !== '' && self::compte($v) === null && L::montant($v) === null));
            if (isset($cols['solde'])) {
                $s = (float) (L::montant($row[$cols['solde']] ?? '') ?? 0);
            } else {
                $s = abs((float) (L::montant($row[$cols['debit']] ?? '') ?? 0)) - abs((float) (L::montant($row[$cols['credit']] ?? '') ?? 0));
            }
            $out[] = self::ligne($compte, $libelle, $s, false);
        }
        return $out;
    }

    /** Colonnes : compte, libellé, et solde débiteur / créditeur (les dernières colonnes Débit et Crédit) ou solde signé. */
    private static function colonnes(array $entete): array
    {
        $c = []; $debits = []; $credits = []; $soldes = [];
        foreach ($entete as $k => $t) {
            if ($t === '') continue;
            if (preg_match('/debit/', $t)) $debits[] = $k;
            elseif (preg_match('/credit/', $t)) $credits[] = $k;
            elseif (preg_match('/solde/', $t)) $soldes[] = $k;
            elseif (preg_match('/intitul|libel|designation|nom du compte|nom/', $t)) $c['libelle'] ??= $k;
            elseif (preg_match('/compte|n°|no\b|numero|code/', $t)) $c['compte'] ??= $k;
        }
        if ($debits && $credits) return $c + ['debit' => end($debits), 'credit' => end($credits)];
        if ($soldes) return $c + ['solde' => end($soldes)];
        return [];
    }

    // ---------- Texte (PDF, Word sans tableau, tableau sans en-tête) ----------

    public static function texte(string $texte): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $texte) as $ligne) {
            $ligne = trim(preg_replace('/\x{00A0}|\x{202F}/u', ' ', $ligne));
            if (! preg_match('/^(\d{2,10})(?![\d.,\/])\s*(.*)$/su', $ligne, $m)) continue;
            $reste = str_replace("\t", ' ¦ ', $m[2]);
            preg_match_all('/'.self::MONTANT.'/u', $reste, $mt, PREG_OFFSET_CAPTURE);
            if (! $mt[0]) continue;
            $libelle = trim(str_replace('¦', ' ', substr($reste, 0, $mt[0][0][1])));
            $valeurs = array_map(fn ($x) => (float) L::montant($x[0]), $mt[0]);
            [$solde, $doute] = self::soldeDesMontants($m[1], $valeurs);
            $out[] = self::ligne($m[1], preg_replace('/\s+/u', ' ', $libelle), $solde, $doute);
        }
        return $out;
    }

    /**
     * Solde signé (débiteur > 0) d'une ligne de montants : le dernier montant est le solde ; les montants qui le précèdent
     * sont des colonnes Débit / Crédit alternées (report, mouvements, totaux) dont la différence doit donner ce solde.
     * @return array{0: float, 1: bool} solde, sens incertain
     */
    public static function soldeDesMontants(string $compte, array $v): array
    {
        $n = count($v);
        $dernier = $v[$n - 1];
        if ($dernier < 0) return [$dernier, false];                         // colonne « Solde » signée
        if ($n >= 2 && abs($dernier) < 0.0005) return [$v[$n - 2], false];  // solde débiteur, solde créditeur à 0,000
        $S = abs($dernier);
        $R = array_slice($v, 0, -1);
        // Toutes les façons de ranger les montants précédents dans des colonnes D, C, D, C… (dans l'ordre, cellules vides sautées) :
        // [colonnes sautées, sens] de chaque rangement dont la différence débits − crédits donne le solde.
        $trouves = [];
        $slots = 6;
        $ranger = function (int $i, int $slot, float $d, int $sautes) use (&$ranger, &$trouves, $R, $S, $slots) {
            if ($i === count($R)) {
                if ($i > 0 && abs(abs($d) - $S) < 0.0015 && $S > 0.0005) $trouves[] = [$sautes, $d > 0 ? 'd' : 'c'];
                return;
            }
            for ($s = $slot; $s < $slots; $s++) $ranger($i + 1, $s + 1, $d + ($s % 2 === 0 ? $R[$i] : -$R[$i]), $sautes + $s - $slot);
        };
        if (count($R) <= $slots) $ranger(0, 0, 0.0, 0);
        $sens = array_unique(array_column($trouves, 1));
        if (count($sens) === 1) return [$sens[0] === 'd' ? $S : -$S, false];
        // Rangements contradictoires : les colonnes toutes remplies l'emportent, si la ligne a au moins deux montants avant le solde
        $plein = array_values(array_filter($trouves, fn ($t) => $t[0] === 0));
        if (count($R) >= 2 && count(array_unique(array_column($plein, 1))) === 1) return [$plein[0][1] === 'd' ? $S : -$S, false];
        return [self::natureDebitrice($compte) ? $S : -$S, true];
    }

    /** Sens habituel du solde d'un compte (plan comptable tunisien). */
    public static function natureDebitrice(string $compte): bool
    {
        if (preg_match('/^(28|29|39|49|59)/', $compte)) return false;      // amortissements et provisions
        if (str_starts_with($compte, '4')) return (bool) preg_match('/^(41|409|471)/', $compte);   // clients, avances, charges constatées d'avance
        return (bool) preg_match('/^[2356]/', $compte);
    }

    // ---------- IA ----------

    /** Lecture par l'IA d'un PDF scanné. Nécessite ANTHROPIC_API_KEY. */
    private static function ia(string $chemin): array
    {
        $prompt = 'Voici la balance générale d’une entreprise tunisienne. Pour CHAQUE compte détaillé (pas les totaux ni les sous-totaux de classe), '
            .'donne le numéro de compte, son intitulé et son SOLDE de fin de période. Réponds UNIQUEMENT par un objet JSON : '
            .'{"lignes":[{"compte":"401000","libelle":"","solde_debit":0,"solde_credit":0}]}. Montants en dinars, 3 décimales, sans séparateur de milliers.';
        $res = Http::withHeaders(['x-api-key' => config('services.anthropic.key'), 'anthropic-version' => '2023-06-01'])->timeout(180)
            ->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model'), 'max_tokens' => 16000, 'messages' => [['role' => 'user', 'content' => [
                ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(file_get_contents($chemin))]],
                ['type' => 'text', 'text' => $prompt],
            ]]]])->throw()->json();
        $txt = collect($res['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');
        $j = json_decode(preg_replace('/^[^{]*|[^}]*$/s', '', $txt), true) ?: [];
        $out = [];
        foreach ((array) ($j['lignes'] ?? []) as $l) {
            $c = self::compte((string) ($l['compte'] ?? ''));
            if ($c === null) continue;
            $out[] = self::ligne($c, (string) ($l['libelle'] ?? ''), abs((float) ($l['solde_debit'] ?? 0)) - abs((float) ($l['solde_credit'] ?? 0)), false);
        }
        return $out;
    }

    // ---------- Outils ----------

    private static function ligne(string $compte, string $libelle, float $solde, bool $doute): array
    {
        $solde = round($solde, 3);
        return ['compte' => $compte, 'libelle' => Str::limit(trim($libelle), 120, ''), 'debit' => max($solde, 0.0), 'credit' => max(-$solde, 0.0), 'doute' => $doute];
    }

    private static function compte($v): ?string
    {
        $v = trim((string) $v);
        if (preg_match('/^\d+\.0+$/', $v)) $v = strstr($v, '.', true);        // 401000.0 (cellule numérique)
        return preg_match('/^\d{2,10}$/', $v) ? $v : null;
    }

    /** Numéro de compte d'une ligne de tableau : première cellule non vide, si c'est un numéro. */
    private static function compteDeLaLigne(array $row): ?string
    {
        foreach ($row as $v) if (trim((string) $v) !== '') return self::compte($v);
        return null;
    }

    /**
     * Retire les lignes de regroupement (« 40 Fournisseurs » suivi de 401, 404…) : un compte dont le numéro commence
     * un autre compte de la balance et dont le solde est la somme des siens. Retire aussi les comptes soldés.
     */
    public static function sansTotaux(array $lignes): array
    {
        $out = [];
        foreach ($lignes as $i => $l) {
            $s = $l['debit'] - $l['credit'];
            $enfants = array_filter($lignes, fn ($x, $j) => $j !== $i && strlen($x['compte']) > strlen($l['compte']) && str_starts_with($x['compte'], $l['compte']), ARRAY_FILTER_USE_BOTH);
            if ($enfants) {
                $somme = array_sum(array_map(fn ($x) => $x['debit'] - $x['credit'], $enfants));
                if (abs($somme - $s) < 0.0015 || abs($somme + $s) < 0.0015) continue;
            }
            if (abs($s) < 0.0005) continue;
            $out[] = $l;
        }
        return array_values($out);
    }
}
