<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle de saisie rapide d'une facture d'achat ou de vente (fournisseur / client habituel).
 * « comptes » : comptes proposés avec leur taux de TVA ; le premier est appliqué (« compte » et « tva_taux » en sont la copie).
 */
class ModeleSaisie extends Model
{
    use AppartientAuCabinet;

    public const TYPES = ['achat' => 'Facture d’achat', 'vente' => 'Facture de vente'];

    protected $table = 'modeles_saisie';
    protected $guarded = [];
    protected $casts = ['tva_taux' => 'float', 'timbre' => 'float', 'retenue_taux' => 'float', 'tva_deductible' => 'boolean', 'comptes' => 'array'];

    /** Comptes proposés (le compte unique d'un modèle antérieur devient la seule ligne). */
    public function lignes(): array
    {
        $l = array_values(array_filter((array) $this->comptes, fn ($c) => ($c['compte'] ?? '') !== ''));
        if (! $l && $this->compte) $l = [['compte' => (string) $this->compte, 'tva_taux' => (float) $this->tva_taux]];
        return array_map(fn ($c) => ['compte' => (string) $c['compte'], 'tva_taux' => (float) ($c['tva_taux'] ?? $this->tva_taux)], $l);
    }

    /**
     * « Mémoriser comme modèle » coché lors de la saisie d'une facture : crée le modèle de ce tiers, ou met à jour le modèle
     * existant en ajoutant le compte de la facture à ses lignes (les autres lignes sont conservées).
     */
    public static function memoriser(int $client, string $type, array $d, string $tiers): void
    {
        if (trim($tiers) === '') return;
        $m = static::firstOrNew(['client_id' => $client, 'type' => $type, 'tiers' => $tiers]);
        $lignes = $m->exists ? $m->lignes() : [];
        $taux = (float) ($d['tva_taux'] ?? 19);
        // Comptes de la facture (ventilée ou non), dans l'ordre : ils passent en tête du modèle
        $factures = $d['lignes'] ?? null ?: [['compte' => $d['compte'] ?? '', 'tva_taux' => $taux]];
        foreach (array_reverse($factures) as $f) {
            $compte = (string) ($f['compte'] ?? '');
            if ($compte === '') continue;
            $lignes = array_values(array_filter($lignes, fn ($l) => $l['compte'] !== $compte));
            array_unshift($lignes, ['compte' => $compte, 'tva_taux' => (float) ($f['tva_taux'] ?? $taux)]);
        }
        $m->fill(['nom' => $m->nom ?: $tiers, 'matricule_fiscal' => $d['matricule_fiscal'] ?? $m->matricule_fiscal, 'description' => $d['description'] ?? $m->description,
            'comptes' => $lignes, 'compte' => $lignes[0]['compte'] ?? null, 'tva_taux' => $lignes[0]['tva_taux'] ?? $taux,
            'timbre' => $d['timbre'] ?? 0, 'retenue_taux' => $d['retenue_taux'] ?? 0, 'tva_deductible' => $d['tva_deductible'] ?? true])->save();
    }

    /** Valeurs reprises dans le formulaire de saisie (« comptes » : boutons rapides du modèle). */
    public function valeurs(): array
    {
        $v = ['tva_taux' => $this->tva_taux, 'timbre' => $this->timbre, 'description' => $this->description, 'compte' => $this->compte,
            'matricule_fiscal' => $this->matricule_fiscal, 'comptes' => $this->lignes()];
        return $this->type === 'achat'
            ? $v + ['fournisseur' => $this->tiers, 'retenue_taux' => $this->retenue_taux, 'tva_deductible' => $this->tva_deductible ? '1' : '0']
            : $v + ['tiers' => $this->tiers];
    }
}
