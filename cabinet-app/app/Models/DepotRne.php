<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/** Dépôt des états financiers d'un exercice au RNE : pièces classées en GED, approbation, dépôt. */
class DepotRne extends Model
{
    use AppartientAuCabinet;

    protected $table = 'depots_rne';
    protected $guarded = [];
    protected $casts = ['pieces' => 'array', 'approbation' => 'boolean', 'date_approbation' => 'date', 'date_depot' => 'date', 'exercice' => 'integer'];

    /** Pièces du dépôt : clé => [libellé, générée par l'application ?, condition]. */
    public const PIECES = [
        'bilan' => ['Bilan', true, null],
        'resultat' => ['État de résultat', true, null],
        'flux' => ['État des flux de trésorerie', true, null],
        'notes' => ['Notes aux états financiers', true, null],
        'associes' => ['Liste des associés', true, 'morale'],
        'pv_ago' => ['PV de l’AGO approuvant les états financiers (scanné)', false, 'approbation'],
        'rapport_cac' => ['Rapport du commissaire aux comptes', false, 'facultatif'],
    ];

    public function client() { return $this->belongsTo(Client::class); }

    /** Pièces attendues pour ce dépôt (selon le client et l'approbation). */
    public function piecesAttendues(Client $c): array
    {
        return array_keys(array_filter(self::PIECES, fn ($p) => match ($p[2]) {
            'morale' => ! $c->estPhysique(), 'approbation' => $this->approbation, 'facultatif' => $this->approbation, default => true,
        }));
    }

    public function document(string $piece): ?Document
    {
        $id = $this->pieces[$piece] ?? null;
        return $id ? Document::find($id) : null;
    }

    /** Délai légal : fin du septième mois suivant la clôture de l'exercice (31/07/N+1 pour une clôture au 31/12). */
    public function echeance(): Carbon
    {
        return Carbon::create($this->exercice, 12, 31)->addMonthsNoOverflow(7)->endOfMonth()->startOfDay();
    }
}
