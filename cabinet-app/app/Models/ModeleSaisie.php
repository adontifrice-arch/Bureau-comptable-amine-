<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

/** Modèle de saisie rapide d'une facture d'achat ou de vente (fournisseur / client habituel). */
class ModeleSaisie extends Model
{
    use AppartientAuCabinet;

    public const TYPES = ['achat' => 'Facture d’achat', 'vente' => 'Facture de vente'];

    protected $table = 'modeles_saisie';
    protected $guarded = [];
    protected $casts = ['tva_taux' => 'float', 'timbre' => 'float', 'retenue_taux' => 'float', 'tva_deductible' => 'boolean'];

    /** « Mémoriser comme modèle » coché lors de la saisie d'une facture : crée ou met à jour le modèle de ce tiers. */
    public static function memoriser(int $client, string $type, array $d, string $tiers): void
    {
        if (trim($tiers) === '') return;
        static::updateOrCreate(['client_id' => $client, 'type' => $type, 'tiers' => $tiers], [
            'nom' => $tiers, 'matricule_fiscal' => $d['matricule_fiscal'] ?? null, 'description' => $d['description'] ?? null, 'compte' => $d['compte'] ?? null,
            'tva_taux' => $d['tva_taux'] ?? 19, 'timbre' => $d['timbre'] ?? 0, 'retenue_taux' => $d['retenue_taux'] ?? 0, 'tva_deductible' => $d['tva_deductible'] ?? true,
        ]);
    }

    /** Valeurs reprises dans le formulaire de saisie. */
    public function valeurs(): array
    {
        $v = ['tva_taux' => $this->tva_taux, 'timbre' => $this->timbre, 'description' => $this->description, 'compte' => $this->compte,
            'matricule_fiscal' => $this->matricule_fiscal];
        return $this->type === 'achat'
            ? $v + ['fournisseur' => $this->tiers, 'retenue_taux' => $this->retenue_taux, 'tva_deductible' => $this->tva_deductible ? '1' : '0']
            : $v + ['tiers' => $this->tiers];
    }
}
