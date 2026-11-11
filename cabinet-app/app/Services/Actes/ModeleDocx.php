<?php

namespace App\Services\Actes;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Modèles Word (.docx) : détection des balises {{…}}, remplissage en conservant la mise en forme du modèle,
 * et conversion simplifiée en HTML pour l'éditeur de brouillon.
 *
 * Word découpe souvent une balise en plusieurs « runs » (« {{DENOMI » + « NATION}} ») : le remplacement
 * travaille donc sur le texte complet de chaque paragraphe puis le redistribue dans les runs d'origine :
 * la valeur prend la mise en forme du run où commence la balise.
 */
class ModeleDocx
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const TAILLE_MAX_XML = 20 * 1024 * 1024;   // garde-fou contre les archives « bombes »

    /** Parties du document qui peuvent contenir des balises : corps, en-têtes, pieds de page. */
    private static function parties(ZipArchive $zip): array
    {
        $p = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $n)) $p[] = $n;
        }
        return $p;
    }

    private static function ouvrir(string $chemin): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($chemin) !== true || $zip->locateName('word/document.xml') === false) {
            throw new RuntimeException('Le fichier n’est pas un document Word (.docx) valide.');
        }
        foreach (self::parties($zip) as $n) {
            if (($zip->statName($n)['size'] ?? 0) > self::TAILLE_MAX_XML) throw new RuntimeException('Le document Word est trop volumineux.');
        }
        return $zip;
    }

    private static function dom(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) throw new RuntimeException('Le document Word est illisible.');
        return $dom;
    }

    /** Texte des paragraphes du modèle (pour repérer les balises). */
    public static function texte(string $chemin): string
    {
        $zip = self::ouvrir($chemin);
        $out = [];
        foreach (self::parties($zip) as $n) {
            $xp = new DOMXPath(self::dom($zip->getFromName($n)));
            $xp->registerNamespace('w', self::W);
            foreach ($xp->query('//w:p') as $p) $out[] = implode('', array_map(fn ($t) => $t->textContent, self::textes($xp, $p)));
        }
        $zip->close();
        return implode("\n", $out);
    }

    /** Noeuds w:t qui appartiennent directement au paragraphe (hors paragraphes imbriqués des zones de texte). */
    private static function textes(DOMXPath $xp, DOMElement $p): array
    {
        $r = [];
        foreach ($xp->query('.//w:t', $p) as $t) {
            $anc = $t->parentNode;
            while ($anc && ! ($anc instanceof DOMElement && $anc->localName === 'p' && $anc->namespaceURI === self::W)) $anc = $anc->parentNode;
            if ($anc === $p) $r[] = $t;
        }
        return $r;
    }

    /** Copie du modèle avec les balises remplacées, écrite dans $destination. */
    public static function remplir(string $chemin, array $valeurs, string $destination): void
    {
        if (! copy($chemin, $destination)) throw new RuntimeException('Copie du modèle impossible.');
        $zip = self::ouvrir($destination);
        foreach (self::parties($zip) as $n) {
            $dom = self::dom($zip->getFromName($n));
            $xp = new DOMXPath($dom);
            $xp->registerNamespace('w', self::W);
            foreach ($xp->query('//w:p') as $p) self::remplirParagraphe($xp, $p, $valeurs);
            $zip->addFromString($n, $dom->saveXML());
        }
        $zip->close();
    }

    private static function remplirParagraphe(DOMXPath $xp, DOMElement $p, array $valeurs): void
    {
        $noeuds = self::textes($xp, $p);
        if (! $noeuds) return;
        $textes = array_map(fn ($t) => $t->textContent, $noeuds);
        $complet = implode('', $textes);
        if (! str_contains($complet, '{{')) return;
        if (! preg_match_all('/\{\{\s*([A-Z0-9_]+)\s*\}\}/', $complet, $m, PREG_OFFSET_CAPTURE)) return;

        // Position de début (en octets) de chaque noeud dans le texte complet.
        $debuts = [];
        $pos = 0;
        foreach ($textes as $i => $t) { $debuts[$i] = $pos; $pos += strlen($t); }
        $noeudA = function (int $offset) use ($debuts, $textes): int {
            for ($i = count($debuts) - 1; $i >= 0; $i--) if ($offset >= $debuts[$i] && ($offset < $debuts[$i] + strlen($textes[$i]) || $i === count($debuts) - 1)) return $i;
            return 0;
        };

        $modifies = [];
        // De la dernière balise à la première : les positions des balises précédentes restent valables.
        for ($k = count($m[0]) - 1; $k >= 0; $k--) {
            [$balise, $debut] = $m[0][$k];
            $nom = $m[1][$k][0];
            if (! array_key_exists($nom, $valeurs)) continue;          // balise inconnue : laissée telle quelle
            $fin = $debut + strlen($balise);
            $a = $noeudA($debut);
            $b = $noeudA($fin - 1);
            if ($a === $b) {
                $textes[$a] = substr($textes[$a], 0, $debut - $debuts[$a]).$valeurs[$nom].substr($textes[$a], $fin - $debuts[$a]);
            } else {
                $textes[$a] = substr($textes[$a], 0, $debut - $debuts[$a]).$valeurs[$nom];
                for ($i = $a + 1; $i < $b; $i++) $textes[$i] = '';
                $textes[$b] = substr($textes[$b], $fin - $debuts[$b]);
                $modifies[$b] = true;
            }
            $modifies[$a] = true;
            for ($i = $a + 1; $i < $b; $i++) $modifies[$i] = true;
        }

        foreach (array_keys($modifies) as $i) {
            $t = $noeuds[$i];
            $lignes = explode("\n", $textes[$i]);
            $t->textContent = array_shift($lignes);
            $t->setAttribute('xml:space', 'preserve');
            // Valeur sur plusieurs lignes : saut de ligne Word (w:br) dans le même run.
            $apres = $t;
            foreach ($lignes as $ligne) {
                $br = $t->ownerDocument->createElementNS(self::W, 'w:br');
                $nt = $t->ownerDocument->createElementNS(self::W, 'w:t');
                $nt->setAttribute('xml:space', 'preserve');
                $nt->appendChild($t->ownerDocument->createTextNode($ligne));
                $apres->parentNode->insertBefore($br, $apres->nextSibling);
                $br->parentNode->insertBefore($nt, $br->nextSibling);
                $apres = $nt;
            }
        }
    }

    /** Conversion simplifiée du corps du document en HTML (paragraphes, titres, gras/italique/souligné, alignement, tableaux). */
    public static function html(string $chemin): string
    {
        $zip = self::ouvrir($chemin);
        $dom = self::dom($zip->getFromName('word/document.xml'));
        $zip->close();
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::W);
        $body = $xp->query('/w:document/w:body')->item(0);
        return $body ? self::blocs($xp, $body) : '';
    }

    private static function blocs(DOMXPath $xp, DOMElement $parent): string
    {
        $h = '';
        foreach ($parent->childNodes as $n) {
            if (! $n instanceof DOMElement) continue;
            if ($n->localName === 'p') $h .= self::paragraphe($xp, $n);
            elseif ($n->localName === 'tbl') {
                $h .= '<table style="width:100%;border-collapse:collapse">';
                foreach ($xp->query('w:tr', $n) as $tr) {
                    $h .= '<tr>';
                    foreach ($xp->query('w:tc', $tr) as $tc) $h .= '<td style="border:1px solid #999;padding:4px;vertical-align:top">'.self::blocs($xp, $tc).'</td>';
                    $h .= '</tr>';
                }
                $h .= '</table>';
            } elseif ($n->localName === 'sdt') {
                $c = $xp->query('w:sdtContent', $n)->item(0);
                if ($c) $h .= self::blocs($xp, $c);
            }
        }
        return $h;
    }

    private static function paragraphe(DOMXPath $xp, DOMElement $p): string
    {
        $style = (string) $xp->evaluate('string(w:pPr/w:pStyle/@w:val)', $p);
        $jc = (string) $xp->evaluate('string(w:pPr/w:jc/@w:val)', $p);
        $align = ['center' => 'center', 'right' => 'right', 'end' => 'right', 'both' => 'justify', 'distribute' => 'justify'][$jc] ?? null;
        $tag = preg_match('/^(heading|titre|title)\s*(\d)?$/i', $style, $m) ? 'h'.min(4, max(2, (int) ($m[2] ?? 1) + 1)) : 'p';
        if ($xp->query('w:pPr/w:numPr', $p)->length) $tag = 'li';

        $in = '';
        foreach ($xp->query('w:r | w:hyperlink/w:r | w:ins/w:r | w:smartTag/w:r', $p) as $r) {
            $txt = '';
            foreach ($r->childNodes as $c) {
                if (! $c instanceof DOMElement) continue;
                $txt .= match ($c->localName) { 't' => e($c->textContent), 'tab' => '&emsp;', 'br', 'cr' => '<br>', default => '' };
            }
            if ($txt === '') continue;
            $on = fn ($prop) => $xp->query("w:rPr/w:$prop", $r)->length && ! in_array($xp->evaluate("string(w:rPr/w:$prop/@w:val)", $r), ['0', 'false', 'none'], true);
            if ($on('b')) $txt = "<b>$txt</b>";
            if ($on('i')) $txt = "<i>$txt</i>";
            if ($on('u')) $txt = "<u>$txt</u>";
            $in .= $txt;
        }
        $attr = $align ? ' style="text-align:'.$align.'"' : '';
        if ($tag === 'li') return "<ul><li$attr>".($in ?: '&nbsp;').'</li></ul>';
        return "<$tag$attr>".($in === '' ? '&nbsp;' : $in)."</$tag>";
    }
}
