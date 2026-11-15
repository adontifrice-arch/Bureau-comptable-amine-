<?php

namespace App\Models\Concerns;

/**
 * Facture ventilée sur plusieurs comptes (factures d'achat et de vente) : lignes [{compte, ht, tva_taux, tva}].
 * Les colonnes ht et tva portent les totaux, compte et tva_taux ceux de la première ligne.
 */
trait EstVentilee
{
    /** Lignes de la facture (une facture sans ventilation est une facture à une seule ligne). */
    public function lignesSaisie(): array
    {
        $l = (array) ($this->lignes ?? []);
        if (! $l) $l = [['compte' => $this->compte, 'ht' => $this->ht, 'tva_taux' => $this->tva_taux, 'tva' => $this->tva]];
        return array_map(fn ($x) => ['compte' => (string) ($x['compte'] ?? ''), 'ht' => round((float) ($x['ht'] ?? 0), 3),
            'tva_taux' => (float) ($x['tva_taux'] ?? 0), 'tva' => round((float) ($x['tva'] ?? 0), 3)], $l);
    }

    /** Une ligne au moins n'a pas encore de compte. */
    public function compteManquant(): bool
    {
        foreach ($this->lignesSaisie() as $l) if ($l['compte'] === '') return true;
        return false;
    }

    /** Lignes imputées en immobilisations (classe 2, hors amortissements 28 et provisions 29). */
    public function lignesImmobilisations(): array
    {
        return array_values(array_filter($this->lignesSaisie(), fn ($l) => preg_match('/^2[0-7]/', $l['compte'])));
    }

    /** Montants HT par compte (lignes d'un même compte regroupées), pour l'écriture. */
    public function htParCompte(): array
    {
        $r = [];
        foreach ($this->lignesSaisie() as $l) $r[$l['compte']] = round(($r[$l['compte']] ?? 0) + $l['ht'], 3);
        return $r;
    }

    /**
     * Lignes saisies → valeurs à enregistrer : lignes sans montant écartées, TVA calculée si absente,
     * totaux HT / TVA, compte et taux de la première ligne.
     */
    public static function ventiler(array $lignes, float $tauxDefaut): array
    {
        $l = [];
        foreach ($lignes as $x) {
            $ht = round((float) ($x['ht'] ?? 0), 3);
            if ($ht <= 0) continue;
            $taux = isset($x['tva_taux']) && $x['tva_taux'] !== '' && $x['tva_taux'] !== null ? (float) $x['tva_taux'] : $tauxDefaut;
            $tva = isset($x['tva']) && $x['tva'] !== '' && $x['tva'] !== null ? round((float) $x['tva'], 3) : round($ht * $taux / 100, 3);
            $l[] = ['compte' => trim((string) ($x['compte'] ?? '')), 'ht' => $ht, 'tva_taux' => $taux, 'tva' => $tva];
        }
        return ['lignes' => count($l) > 1 ? $l : null, 'ht' => round(array_sum(array_column($l, 'ht')), 3), 'tva' => round(array_sum(array_column($l, 'tva')), 3),
            'compte' => ($l[0]['compte'] ?? '') ?: null, 'tva_taux' => $l[0]['tva_taux'] ?? $tauxDefaut];
    }
}
