<?php

namespace App\Services\Actes;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Nettoyage du HTML d'un acte (éditeur riche) avant enregistrement, affichage et export PDF :
 * seules les balises de mise en forme d'un document sont conservées, avec quelques propriétés de style sûres.
 * Tout le reste (scripts, attributs on*, liens, images, styles arbitraires) est retiré.
 */
class HtmlActe
{
    private const BALISES = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'div', 'span', 'blockquote',
        'table', 'thead', 'tbody', 'tr', 'td', 'th', 'hr', 'sub', 'sup'];
    /** Balises supprimées avec leur contenu. */
    private const INTERDITES = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'head', 'title', 'meta', 'link', 'svg', 'math', 'form', 'input', 'button', 'select', 'textarea'];
    private const STYLES = [
        'text-align' => '/^(left|right|center|justify)$/',
        'font-size' => '/^\d{1,2}(\.\d)?(pt|px)$/',
        'font-weight' => '/^(bold|normal|[1-9]00)$/',
        'font-style' => '/^(italic|normal)$/',
        'text-decoration' => '/^(underline|none)$/',
        'margin-top' => '/^\d{1,3}px$/',
        'margin-left' => '/^\d{1,3}px$/',
        'width' => '/^\d{1,3}%$/',
        'border' => '/^\dpx solid #[0-9a-f]{3,6}$/i',
        'border-collapse' => '/^collapse$/',
        'padding' => '/^\d{1,2}px$/',
        'vertical-align' => '/^(top|middle|bottom)$/',
    ];
    /** Tailles de l'éditeur (execCommand fontSize 1 à 7) en points. */
    private const TAILLES = [1 => '8pt', 2 => '10pt', 3 => '12pt', 4 => '14pt', 5 => '18pt', 6 => '24pt', 7 => '32pt'];

    public static function nettoyer(string $html): string
    {
        if (trim($html) === '') return '';
        $dom = new DOMDocument();
        $prec = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="racine">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prec);

        $racine = $dom->getElementById('racine');
        if (! $racine) return '';
        self::enfants($racine);
        $out = '';
        foreach ($racine->childNodes as $n) $out .= $dom->saveHTML($n);
        return trim($out);
    }

    private static function enfants(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $n) {
            if ($n->nodeType === XML_TEXT_NODE) continue;
            if (! $n instanceof DOMElement) { $parent->removeChild($n); continue; }   // commentaires, instructions…
            $tag = strtolower($n->tagName);
            if (in_array($tag, self::INTERDITES, true)) { $parent->removeChild($n); continue; }
            if ($tag === 'font') $n = self::remplacer($n, 'span', ['font-size' => self::TAILLES[(int) $n->getAttribute('size')] ?? null]);
            elseif (! in_array($tag, self::BALISES, true)) {
                // Balise inconnue (a, img, font…) : on garde son contenu, pas la balise.
                self::enfants($n);
                while ($n->firstChild) $parent->insertBefore($n->firstChild, $n);
                $parent->removeChild($n);
                continue;
            }
            self::attributs($n);
            self::enfants($n);
        }
    }

    private static function remplacer(DOMElement $n, string $tag, array $styles): DOMElement
    {
        $nouveau = $n->ownerDocument->createElement($tag);
        while ($n->firstChild) $nouveau->appendChild($n->firstChild);
        $css = self::styles((string) $n->getAttribute('style'));
        foreach ($styles as $k => $v) if ($v) $css[$k] = $v;
        if ($css) $nouveau->setAttribute('style', self::css($css));
        $n->parentNode->replaceChild($nouveau, $n);
        return $nouveau;
    }

    private static function attributs(DOMElement $n): void
    {
        $style = self::styles((string) $n->getAttribute('style'));
        if (($align = strtolower((string) $n->getAttribute('align'))) && preg_match(self::STYLES['text-align'], $align)) $style['text-align'] ??= $align;
        foreach (iterator_to_array($n->attributes) as $a) $n->removeAttribute($a->nodeName);
        if ($style) $n->setAttribute('style', self::css($style));
    }

    /** Propriétés de style autorisées, valeurs contrôlées. */
    private static function styles(string $style): array
    {
        $ok = [];
        foreach (explode(';', $style) as $decl) {
            if (! str_contains($decl, ':')) continue;
            [$k, $v] = array_map('trim', explode(':', $decl, 2));
            $k = strtolower($k);
            $v = strtolower(preg_replace('/\s+/', ' ', $v));
            if (isset(self::STYLES[$k]) && preg_match(self::STYLES[$k], $v)) $ok[$k] = $v;
        }
        return $ok;
    }

    private static function css(array $s): string
    {
        return implode(';', array_map(fn ($k, $v) => "$k:$v", array_keys($s), $s));
    }
}
