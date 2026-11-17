<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Models\Declaration;

/**
 * Liste des bons de commandes et des factures d'achats en suspension des taxes sur le chiffre d'affaires :
 * fichier « BCD_Tn_AA » (cahier des charges DGI 2012) et contrôles repris de l'outil officiel bcdmag2012.
 * Enregistrements : EF (220 car.), DF (198 car. + « < » objet « /> »), TF (198 car.).
 */
class BonsCommandeSuspension extends FichierDgi
{
    public static function prefixe(): string
    {
        return 'BCD';
    }

    public static function controles(array $l, array $entete): array
    {
        $e = [];
        $aut = trim((string) ($l['aut'] ?? '') ?: (string) ($entete['aut'] ?? ''));
        if (strlen($aut) !== 12) $e[] = 'N° d’autorisation : 12 caractères attendus.';
        if (! preg_match('/^\d{1,13}$/', (string) ($l['att'] ?? ''))) $e[] = 'N° de bon de commande : chiffres uniquement (13 au plus).';
        if (empty($l['att_date'])) $e[] = 'Date du bon de commande manquante.';
        if ($m = self::erreurMatricule($l['id'] ?? null)) $e[] = $m;
        if (mb_strlen((string) ($l['nom'] ?? '')) > 40) $e[] = 'Raison sociale : 40 caractères au plus.';
        if (mb_strlen((string) ($l['fac'] ?? '')) > 30) $e[] = 'N° de facture : 30 caractères au plus.';
        if ((float) ($l['ht'] ?? 0) <= 0) $e[] = 'Prix HT invalide.';
        if ((float) ($l['tva'] ?? 0) > (float) ($l['ht'] ?? 0)) $e[] = 'La TVA ne doit pas dépasser le prix HT.';
        return $e;
    }

    /** Totaux pour le bordereau de transmission. */
    public static function totaux(Declaration $d): array
    {
        $L = collect($d->annexes['l'] ?? []);
        return ['lignes' => $L->count(), 'bons' => $L->pluck('att')->filter()->unique()->count(),
            'factures' => $L->map(fn ($l) => ($l['id'] ?? '').'|'.($l['fac'] ?? ''))->unique()->count(),
            'ht' => r3($L->sum('ht')), 'tva' => r3($L->sum('tva'))];
    }

    public static function fichier(Client $c, Declaration $d): string
    {
        $h = $d->champs ?? [];
        $ent = self::cle($c, $d);
        $out = [self::entete($c, $d)];
        $tht = 0; $ttva = 0; $n = 0;
        foreach ($d->annexes['l'] ?? [] as $l) {
            $ht = self::millimes($l['ht']); $tva = self::millimes($l['tva']);
            $tht += $ht; $ttva += $tva;
            $out[] = 'DF'.$ent.self::n(++$n, 6).self::x(($l['aut'] ?? '') ?: ($h['aut'] ?? ''), 30).self::n(preg_replace('/\D/', '', (string) ($l['att'] ?? '')), 13)
                .self::date($l['att_date'] ?? null).self::x(self::matricule($l['id'] ?? '')['brut'] ?? ($l['id'] ?? ''), 13).self::x($l['nom'] ?? '', 40)
                .self::x($l['fac'] ?? '', 30).self::date($l['fac_date'] ?? null).self::n($ht, 15).self::n($tva, 15)
                .'<'.mb_substr(self::ascii($l['objet'] ?? ''), 0, 320).'/>';
        }
        $out[] = 'TF'.$ent.self::n($n, 6).str_repeat(' ', 142).self::n($tht, 15).self::n($ttva, 15);
        return implode("\r\n", $out)."\r\n";
    }
}
