<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Validation par des schémas XSD 1.1 (ceux de la liasse fiscale de la DGI), que libxml ne lit qu'en XSD 1.0.
 *
 * 1. Les types conditionnels (xsd:alternative) sont résolus d'après le fichier validé : l'élément reçoit le type
 *    de la première alternative dont le test est vrai.
 * 2. Le schéma ainsi réduit à XSD 1.0 (sans xsd:assert ni attributs vc:*) valide la structure et les types par libxml.
 * 3. Les assertions (xsd:assert) sont évaluées ici, par un évaluateur du sous-ensemble de XPath 2.0 qu'elles emploient :
 *    noms d'éléments, attributs (@x), nombres, chaînes, sum(), if/then/else, or/and, comparaisons (= != < <= > >=,
 *    eq ne lt le gt ge), + - * div, séquences entre parenthèses.
 */
class Xsd11
{
    private const XS = 'http://www.w3.org/2001/XMLSchema';
    private const VC = 'http://www.w3.org/2007/XMLSchema-versioning';

    /** Valide un document ; renvoie la liste des erreurs (vide si conforme). */
    public static function valider(string $xml, string $schema, int $max = 30): array
    {
        $prec = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = new DOMDocument();
        if (! $doc->loadXML($xml)) {
            libxml_use_internal_errors($prec);
            return ['Fichier XML mal formé.'];
        }

        // Schémas (principal et inclus), réduits à XSD 1.0 dans un dossier temporaire
        $dossier = sys_get_temp_dir().'/xsd11-'.bin2hex(random_bytes(6));
        mkdir($dossier);
        $asserts = [];
        try {
            $a_faire = [basename($schema)]; $vus = [];
            while ($nom = array_shift($a_faire)) {
                if (isset($vus[$nom])) continue;
                $vus[$nom] = true;
                $s = new DOMDocument();
                if (! @$s->load(dirname($schema).'/'.$nom)) return ['Schéma illisible : '.$nom];
                $x = new DOMXPath($s);
                $x->registerNamespace('xsd', self::XS);
                foreach ($x->query('/xsd:schema/xsd:include/@schemaLocation | /xsd:schema/xsd:import/@schemaLocation') as $inc) $a_faire[] = basename($inc->value);

                // Types conditionnels : choix de l'alternative d'après les éléments du document
                foreach (iterator_to_array($x->query('//xsd:element[xsd:alternative]')) as $el) {
                    $type = null;
                    foreach (self::instances($doc, $el) as $n) {
                        foreach ($x->query('xsd:alternative', $el) as $alt) {
                            if (! $alt->hasAttribute('test') || self::vrai(self::evaluer($alt->getAttribute('test'), $n))) { $type = $alt->getAttribute('type'); break; }
                        }
                        break;
                    }
                    if ($type) $el->setAttribute('type', $type);
                    foreach (iterator_to_array($x->query('xsd:alternative', $el)) as $alt) $el->removeChild($alt);
                }
                foreach (iterator_to_array($x->query('//xsd:assert')) as $as) {
                    $asserts[] = [$as->getAttribute('test'), self::proprietaire($as)];
                    $as->parentNode->removeChild($as);
                }
                foreach (iterator_to_array($x->query('//@*[namespace-uri()="'.self::VC.'"]')) as $at) $at->ownerElement->removeAttributeNode($at);
                $s->save($dossier.'/'.$nom);
            }

            // Structure et types (XSD 1.0)
            libxml_clear_errors();
            $E = [];
            if (! $doc->schemaValidate($dossier.'/'.basename($schema))) {
                foreach (libxml_get_errors() as $e) $E[] = 'XSD ligne '.$e->line.' : '.trim($e->message);
            }
            libxml_clear_errors();
        } finally {
            array_map('unlink', glob($dossier.'/*') ?: []);
            @rmdir($dossier);
            libxml_use_internal_errors($prec);
        }

        // Assertions (seulement si la structure est valide : elles supposent des montants numériques)
        if (! $E) {
            foreach ($asserts as [$test, $chemin]) {
                foreach (self::trouver($doc, $chemin) as $n) {
                    try {
                        $ok = self::vrai(self::evaluer($test, $n));
                    } catch (\Throwable $t) {
                        $E[] = 'Règle XSD non évaluée ('.$t->getMessage().') : '.self::court($test);
                        continue;
                    }
                    if (! $ok) $E[] = 'Règle XSD non respectée : '.self::court($test).self::detail($test, $n);
                }
            }
        }
        return array_slice($E, 0, $max);
    }

    // ---------- Localisation dans le document ----------

    /** Chemin (noms des xsd:element ancêtres) du type qui porte l'assertion. */
    private static function proprietaire(DOMElement $as): array
    {
        $noms = [];
        for ($p = $as->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            if ($p->localName === 'element' && $p->namespaceURI === self::XS) array_unshift($noms, $p->getAttribute('name'));
        }
        return $noms;
    }

    private static function trouver(DOMDocument $doc, array $chemin): array
    {
        $n = [$doc->documentElement];
        if (! $chemin || $chemin[0] !== $doc->documentElement->localName) return [];
        foreach (array_slice($chemin, 1) as $nom) {
            $suivants = [];
            foreach ($n as $p) foreach ($p->childNodes as $c) if ($c instanceof DOMElement && $c->localName === $nom) $suivants[] = $c;
            $n = $suivants;
        }
        return $n;
    }

    private static function instances(DOMDocument $doc, DOMElement $decl): array
    {
        $chemin = [];
        for ($p = $decl; $p instanceof DOMElement; $p = $p->parentNode) {
            if ($p->localName === 'element' && $p->namespaceURI === self::XS) array_unshift($chemin, $p->getAttribute('name'));
        }
        return self::trouver($doc, $chemin);
    }

    private static function court(string $test): string
    {
        return preg_replace('/\s+/', ' ', str_replace('lf:', '', trim($test)));
    }

    /** Pour « X = expression », les deux valeurs comparées. */
    private static function detail(string $test, DOMNode $n): string
    {
        if (! preg_match('/^\s*(lf:\w+)\s*=\s*(.+)$/s', $test, $m)) return '';
        try {
            $g = self::nombres(self::evaluer($m[1], $n)); $d = self::nombres(self::evaluer($m[2], $n));
        } catch (\Throwable) {
            return '';
        }
        return ' (lu '.($g ? implode(' ', $g) : 'vide').', attendu '.($d ? implode(' ', $d) : 'vide').')';
    }

    private static function nombres(array $s): array
    {
        return array_map(fn ($v) => is_string($v) ? "'$v'" : (string) $v, self::atomiser($s));
    }

    // ---------- Évaluateur XPath 2.0 (sous-ensemble) ----------

    private static array $t = [];
    private static int $i = 0;
    private static DOMNode $ctx;

    /** Évalue une expression ; renvoie une séquence (tableau de nœuds, nombres ou chaînes). */
    public static function evaluer(string $expr, DOMNode $contexte): array
    {
        $sauve = [self::$t, self::$i, self::$ctx ?? null];
        self::$t = self::lexer($expr); self::$i = 0; self::$ctx = $contexte;
        try {
            $r = self::expr();
            if (self::$i < count(self::$t)) throw new \RuntimeException('jeton inattendu « '.self::$t[self::$i][1].' »');
            return $r;
        } finally {
            [self::$t, self::$i] = $sauve; if ($sauve[2]) self::$ctx = $sauve[2];
        }
    }

    private static function lexer(string $s): array
    {
        $re = '/\s*(?:(?<n>\d+(?:\.\d+)?)|\'(?<s1>[^\']*)\'|"(?<s2>[^"]*)"|(?<w>@?[A-Za-z_][\w.\-]*(?::[A-Za-z_][\w.\-]*)?)|(?<o>!=|<=|>=|[()=<>+\-*\/,]))/A';
        $t = []; $pos = 0; $len = strlen($s);
        while ($pos < $len && trim(substr($s, $pos)) !== '') {
            if (! preg_match($re, $s, $m, PREG_UNMATCHED_AS_NULL, $pos)) throw new \RuntimeException('expression illisible');
            $pos += strlen($m[0]);
            $t[] = match (true) {
                $m['n'] !== null => ['n', $m['n'] + 0],
                $m['s1'] !== null => ['s', $m['s1']],
                $m['s2'] !== null => ['s', $m['s2']],
                $m['w'] !== null => ['w', $m['w']],
                default => ['o', $m['o']],
            };
        }
        return $t;
    }
    private static function voir(): ?array { return self::$t[self::$i] ?? null; }

    private static function est(string $type, ?string $v = null): bool
    {
        $j = self::voir();
        return $j && $j[0] === $type && ($v === null || $j[1] === $v);
    }

    private static function attendre(string $type, string $v): void
    {
        if (! self::est($type, $v)) throw new \RuntimeException("« $v » attendu");
        self::$i++;
    }

    /** expr := single (',' single)* */
    private static function expr(): array
    {
        $r = self::single();
        while (self::est('o', ',')) { self::$i++; $r = array_merge($r, self::single()); }
        return $r;
    }

    /** single := if (expr) then single else single | ou — les deux branches sont analysées, celle retenue est rendue. */
    private static function single(): array
    {
        if (self::est('w', 'if') && (self::$t[self::$i + 1][1] ?? null) === '(') {
            self::$i++; self::attendre('o', '(');
            $c = self::expr(); self::attendre('o', ')');
            self::attendre('w', 'then');
            $alors = self::branche();
            self::attendre('w', 'else');
            $sinon = self::branche();
            return self::vrai($c) ? $alors : $sinon;
        }
        return self::ou();
    }

    /** Branche d’un if (analysée et évaluée même si elle n’est pas retenue). */
    private static function branche(): array
    {
        return self::single();
    }
    private static function ou(): array
    {
        $r = self::et();
        while (self::est('w', 'or')) { self::$i++; $d = self::et(); $r = [self::vrai($r) || self::vrai($d)]; }
        return $r;
    }

    private static function et(): array
    {
        $r = self::comparaison();
        while (self::est('w', 'and')) { self::$i++; $d = self::comparaison(); $r = [self::vrai($r) && self::vrai($d)]; }
        return $r;
    }

    private static function comparaison(): array
    {
        $g = self::addition();
        $j = self::voir();
        $gen = ['=', '!=', '<', '<=', '>', '>='];
        $val = ['eq' => '=', 'ne' => '!=', 'lt' => '<', 'le' => '<=', 'gt' => '>', 'ge' => '>='];
        if ($j && $j[0] === 'o' && in_array($j[1], $gen, true)) { self::$i++; return [self::comparer(self::atomiser($g), self::atomiser(self::addition()), $j[1])]; }
        if ($j && $j[0] === 'w' && isset($val[$j[1]])) {
            self::$i++;
            $a = self::atomiser($g); $b = self::atomiser(self::addition());
            if (! $a || ! $b) return [];
            return [self::comparer([$a[0]], [$b[0]], $val[$j[1]])];
        }
        return $g;
    }

    private static function comparer(array $a, array $b, string $op): bool
    {
        foreach ($a as $x) foreach ($b as $y) {
            if (is_string($x) !== is_string($y)) { $x = (string) $x; $y = (string) $y; }
            $ok = match ($op) { '=' => $x == $y, '!=' => $x != $y, '<' => $x < $y, '<=' => $x <= $y, '>' => $x > $y, '>=' => $x >= $y };
            if ($ok) return true;
        }
        return false;
    }

    private static function addition(): array
    {
        $r = self::multiplication();
        while (self::est('o', '+') || self::est('o', '-')) {
            $op = self::$t[self::$i++][1];
            $r = self::arith($r, self::multiplication(), $op);
        }
        return $r;
    }

    private static function multiplication(): array
    {
        $r = self::unaire();
        while (self::est('o', '*') || self::est('w', 'div')) {
            $op = self::$t[self::$i++][1];
            $r = self::arith($r, self::unaire(), $op);
        }
        return $r;
    }

    private static function arith(array $a, array $b, string $op): array
    {
        $a = self::atomiser($a); $b = self::atomiser($b);
        if (! $a || ! $b) return [];
        [$x, $y] = [self::num($a[0]), self::num($b[0])];
        return [match ($op) { '+' => $x + $y, '-' => $x - $y, '*' => $x * $y, 'div' => $y == 0 ? throw new \RuntimeException('division par zéro') : $x / $y }];
    }

    private static function unaire(): array
    {
        if (self::est('o', '-')) { self::$i++; $v = self::atomiser(self::unaire()); return $v ? [-self::num($v[0])] : []; }
        if (self::est('o', '+')) { self::$i++; return self::unaire(); }
        return self::chemin();
    }

    /** chemin := primaire ('/' étape)* — étape : @attribut, nom d'élément, ou expression (XPath 2.0 : valeur par nœud). */
    private static function chemin(): array
    {
        $r = self::primaire(self::$ctx);
        while (self::est('o', '/')) {
            self::$i++;
            $debut = self::$i; $suite = [];
            $noeuds = array_values(array_filter($r, fn ($v) => $v instanceof DOMNode));
            if (! $noeuds) { self::primaire(self::$ctx); $r = []; continue; }
            foreach ($noeuds as $n) { self::$i = $debut; $suite = array_merge($suite, self::primaire($n)); }
            $r = $suite;
        }
        return $r;
    }

    private static function primaire(DOMNode $ctx): array
    {
        $j = self::voir();
        if (! $j) throw new \RuntimeException('expression incomplète');
        self::$i++;
        if ($j[0] === 'n' || $j[0] === 's') return [$j[1]];
        if ($j === ['o', '(']) {
            if (self::est('o', ')')) { self::$i++; return []; }
            $sauve = self::$ctx; self::$ctx = $ctx;
            $r = self::expr(); self::$ctx = $sauve;
            self::attendre('o', ')');
            return $r;
        }
        if ($j[0] === 'w') {
            $w = $j[1];
            if (in_array($w, ['sum', 'abs', 'count', 'not', 'exists', 'empty', 'number', 'max', 'min'], true) && self::est('o', '(')) {
                self::$i++;
                $sauve = self::$ctx; self::$ctx = $ctx;
                $arg = self::est('o', ')') ? [] : self::expr();
                self::$ctx = $sauve;
                self::attendre('o', ')');
                $v = self::atomiser($arg);
                return match ($w) {
                    'sum' => [array_sum(array_map([self::class, 'num'], $v))],
                    'abs' => $v ? [abs(self::num($v[0]))] : [],
                    'count' => [count($arg)],
                    'not' => [! self::vrai($arg)],
                    'exists' => [count($arg) > 0],
                    'empty' => [count($arg) === 0],
                    'number' => $v ? [self::num($v[0])] : [NAN],
                    'max' => $v ? [max(array_map([self::class, 'num'], $v))] : [],
                    'min' => $v ? [min(array_map([self::class, 'num'], $v))] : [],
                };
            }
            if (str_starts_with($w, '@')) {
                $nom = substr($w, 1);
                return $ctx instanceof DOMElement && $ctx->hasAttribute($nom) ? [$ctx->getAttributeNode($nom)] : [];
            }
            $local = str_contains($w, ':') ? substr($w, strpos($w, ':') + 1) : $w;
            $r = [];
            foreach ($ctx->childNodes as $c) if ($c instanceof DOMElement && $c->localName === $local) $r[] = $c;
            return $r;
        }
        throw new \RuntimeException('jeton inattendu « '.$j[1].' »');
    }

    /** Atomisation : nœud → nombre si sa valeur est numérique, sinon chaîne. */
    private static function atomiser(array $s): array
    {
        return array_map(function ($v) {
            if ($v instanceof DOMNode) {
                $t = trim($v->textContent);
                return preg_match('/^[+-]?\d+(\.\d+)?$/', $t) ? $t + 0 : $t;
            }
            return $v;
        }, $s);
    }

    private static function num($v): int|float
    {
        if (is_bool($v)) return (int) $v;
        if (is_int($v) || is_float($v)) return $v;
        if (is_string($v) && preg_match('/^\s*[+-]?\d+(\.\d+)?\s*$/', $v)) return $v + 0;
        throw new \RuntimeException("« $v » n’est pas un nombre");
    }

    /** Valeur booléenne effective. */
    private static function vrai(array $s): bool
    {
        if (! $s) return false;
        if ($s[0] instanceof DOMNode) return true;
        if (count($s) > 1) throw new \RuntimeException('séquence de plusieurs valeurs en condition');
        $v = $s[0];
        return is_bool($v) ? $v : (is_string($v) ? $v !== '' : ($v != 0 && ! is_nan((float) $v)));
    }
}
