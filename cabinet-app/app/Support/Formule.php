<?php

namespace App\Support;

/**
 * Calcul saisi dans un montant (fonction formule de la saisie des pièces) : « 450+632 », « 100/5 », « 201*32 », « (1200-200)*1,19 ».
 * Chiffres, virgule ou point décimal, espaces, + - * / et parenthèses ; rien d'autre n'est évalué.
 */
final class Formule
{
    private string $s;
    private int $i = 0;

    private function __construct(string $s)
    {
        $this->s = $s;
    }

    /** Valeur de la formule (arrondie au millime), null si elle est vide ou invalide (division par zéro comprise). */
    public static function evaluer(?string $saisie): ?float
    {
        $s = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $saisie));
        if ($s === '' || ! preg_match('#^[0-9.+\-*/()]+$#', $s)) return null;
        $p = new self($s);
        $v = $p->somme();
        if ($v === null || $p->i !== strlen($s) || ! is_finite($v)) return null;
        return round($v, 3);
    }

    private function somme(): ?float
    {
        $v = $this->produit();
        while ($v !== null && $this->i < strlen($this->s) && in_array($this->s[$this->i], ['+', '-'], true)) {
            $op = $this->s[$this->i++];
            $d = $this->produit();
            if ($d === null) return null;
            $v = $op === '+' ? $v + $d : $v - $d;
        }
        return $v;
    }

    private function produit(): ?float
    {
        $v = $this->facteur();
        while ($v !== null && $this->i < strlen($this->s) && in_array($this->s[$this->i], ['*', '/'], true)) {
            $op = $this->s[$this->i++];
            $d = $this->facteur();
            if ($d === null || ($op === '/' && $d == 0.0)) return null;
            $v = $op === '*' ? $v * $d : $v / $d;
        }
        return $v;
    }

    private function facteur(): ?float
    {
        if ($this->i >= strlen($this->s)) return null;
        $c = $this->s[$this->i];
        if ($c === '-' || $c === '+') {
            $this->i++;
            $v = $this->facteur();
            return $v === null ? null : ($c === '-' ? -$v : $v);
        }
        if ($c === '(') {
            $this->i++;
            $v = $this->somme();
            if ($v === null || ($this->s[$this->i] ?? '') !== ')') return null;
            $this->i++;
            return $v;
        }
        if (! preg_match('#\G[0-9]*\.?[0-9]+|\G[0-9]+\.#', $this->s, $m, 0, $this->i)) return null;
        $this->i += strlen($m[0]);
        return (float) $m[0];
    }
}
