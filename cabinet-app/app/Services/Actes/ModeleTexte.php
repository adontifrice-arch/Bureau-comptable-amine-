<?php

namespace App\Services\Actes;

/** Modèles texte (.txt) : lecture (UTF-8 ou Windows-1252) et mise en forme HTML pour l'éditeur. */
class ModeleTexte
{
    public static function lire(string $chemin): string
    {
        $s = (string) file_get_contents($chemin);
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);                  // BOM UTF-8
        if (! mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        return str_replace(["\r\n", "\r"], "\n", $s);
    }

    /** Un paragraphe par bloc séparé d'une ligne vide ; les retours à la ligne simples sont conservés. */
    public static function html(string $texte): string
    {
        $blocs = preg_split('/\n\s*\n/', trim($texte));
        return implode('', array_map(fn ($b) => '<p>'.nl2br(e(trim($b)), false).'</p>', array_filter($blocs, fn ($b) => trim($b) !== '')));
    }
}
