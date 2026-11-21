<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use AppartientAuCabinet;

    /** Catégories proposées au client quand il envoie des documents au cabinet (toutes présentes dans la GED). */
    public const CATEGORIES_ENVOI_CLIENT = ['Factures d’achat', 'Factures de vente', 'Relevés bancaires', 'Documents de paie',
        'Courrier et correspondance', 'Statuts', 'Patente', 'Extrait RNE', 'CIN du gérant', 'Autres documents'];

    /** Fichiers acceptés en GED comme dans l'espace client. */
    public const REGLE_FICHIER = 'file|max:20480|mimes:pdf,jpg,jpeg,png,webp,txt,csv,doc,docx,xls,xlsx,odt,ods,zip';

    protected $table = 'documents';
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'partage' => 'boolean', 'partage_at' => 'datetime', 'consulte_at' => 'datetime', 'traite_at' => 'datetime'];

    public function client() { return $this->belongsTo(Client::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function deposant() { return $this->belongsTo(ClientUser::class, 'depose_par'); }
    public function traitePar() { return $this->belongsTo(User::class, 'traite_par'); }

    public function envoiClient(): bool { return $this->origine === 'client'; }

    /** Envois des clients que le cabinet n'a pas encore pris en charge. */
    public function scopeATraiter(Builder $q): Builder
    {
        return $q->where('origine', 'client')->whereNull('traite_at');
    }

    /** Documents visibles par le client dans son espace : ceux que le cabinet partage et ses propres envois. */
    public function scopeVisiblesPar(Builder $q, ClientUser $u): Builder
    {
        return $q->where('client_id', $u->client_id)->where(fn ($w) => $w->where('partage', true)->orWhere('origine', 'client'));
    }

    public function taillePourAffichage(): string
    {
        $n = (int) $this->taille;
        return $n > 1048576 ? number_format($n / 1048576, 1, ',', ' ').' Mo' : max(1, (int) round($n / 1024)).' Ko';
    }
}
