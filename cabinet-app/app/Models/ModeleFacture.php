<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use App\Services\Saisie;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle de facture d'achat ou de vente d'un dossier (« paramètres facture achat / vente ») :
 * journal ; comptes TTC, FODEC, avance et timbre, chacun avec son sens (débiteur ou créditeur) ; montant du timbre ;
 * lignes « objet · compte assiette · taux TVA · compte TVA · compte assiette majorée · taux TVA majoré · compte TVA majorée · débiteur ».
 * La saisie d'une facture selon le modèle reprend ses lignes : on n'y tape que l'assiette et la TVA de chacune.
 */
class ModeleFacture extends Model
{
    use AppartientAuCabinet;

    public const TYPES = ['achat' => 'Facture achat', 'vente' => 'Facture vente'];

    protected $table = 'modeles_factures';
    protected $guarded = [];
    protected $casts = ['lignes' => 'array', 'montant_timbre' => 'float', 'ttc_debit' => 'boolean', 'fodec_debit' => 'boolean',
        'avance_debit' => 'boolean', 'timbre_debit' => 'boolean'];

    public function client() { return $this->belongsTo(Client::class); }

    /** Numéro du prochain modèle du dossier pour ce type (00000001, 00000002…). */
    public static function prochainNumero(int $client, string $type): string
    {
        $max = static::where('client_id', $client)->where('type', $type)->max('numero');
        return str_pad((string) ((int) $max + 1), 8, '0', STR_PAD_LEFT);
    }

    /** Ligne de modèle complète (valeurs par défaut pour les clés absentes). */
    public static function ligne(array $l, bool $debitParDefaut): array
    {
        $c = fn ($k) => trim((string) ($l[$k] ?? ''));
        return ['objet' => $c('objet'), 'compte' => $c('compte'), 'tva_taux' => (float) ($l['tva_taux'] ?? 0), 'compte_tva' => $c('compte_tva'),
            'compte_majore' => $c('compte_majore'), 'taux_majore' => (float) ($l['taux_majore'] ?? 0), 'compte_tva_majore' => $c('compte_tva_majore'),
            'debit' => array_key_exists('debit', $l) ? filter_var($l['debit'], FILTER_VALIDATE_BOOLEAN) : $debitParDefaut];
    }

    /** Lignes du modèle. */
    public function lignesModele(): array
    {
        return array_map(fn ($l) => self::ligne((array) $l, $this->type === 'achat'), array_values((array) $this->lignes));
    }

    /** Copie du paramétrage gardée avec chaque facture saisie : l'écriture ne dépend plus du modèle ensuite. */
    public function parametres(): array
    {
        return ['modele' => $this->numero.' '.$this->libelle, 'journal' => $this->journal,
            'ttc' => ['compte' => (string) $this->compte_ttc, 'debit' => $this->ttc_debit],
            'fodec' => ['compte' => (string) $this->compte_fodec, 'debit' => $this->fodec_debit],
            'avance' => ['compte' => (string) $this->compte_avance, 'debit' => $this->avance_debit],
            'timbre' => ['compte' => (string) $this->compte_timbre, 'debit' => $this->timbre_debit]];
    }

    /** Valeurs d'un nouveau modèle : celles du paramétrage du dossier, avec les lignes usuelles. */
    public static function nouveau(int $client, string $type): self
    {
        $achat = $type === 'achat';
        $p = $achat ? Saisie::achat($client) : Saisie::vente($client);
        $base = $achat ? ($p['compte'] ?: '607') : ($p['compte'] ?: '707');
        $tva = $achat ? '4366' : '4367';
        $objet = $achat ? 'ACHAT' : 'VENTE';
        $lignes = [self::ligne(['objet' => $objet.($achat ? ' EXONERE' : ' EXONEREE'), 'compte' => $base, 'tva_taux' => 0], $achat)];
        foreach ([7, 13, 19] as $t) $lignes[] = self::ligne(['objet' => $objet.' '.$t.' %', 'compte' => $base, 'tva_taux' => $t, 'compte_tva' => $tva], $achat);
        return new self(['client_id' => $client, 'type' => $type, 'numero' => self::prochainNumero($client, $type), 'libelle' => '',
            'journal' => $achat ? 'ACH' : 'VTE', 'ttc_debit' => ! $achat, 'fodec_debit' => $achat, 'avance_debit' => $achat,
            'compte_timbre' => $achat ? '6654' : ($p['compte_timbre'] ?? '437'), 'timbre_debit' => $achat, 'montant_timbre' => (float) ($p['timbre'] ?? 1),
            'lignes' => $lignes]);
    }
}
