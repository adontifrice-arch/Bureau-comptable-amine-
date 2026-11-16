<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Rubrique de paie d'un dossier (menu Rubrique) : prime, indemnité, avantage en nature ou autre.
 *
 * Valeur : montant fixe, saisie manuelle chaque mois, pourcentage d'une base (salaire de base, salaire du mois ou autre rubrique)
 * ou proportion d'une base (base × a / b). Les rubriques d'heures supplémentaires, de jours supplémentaires et d'heures de nuit
 * se saisissent en quantité, valorisée au taux horaire ou journalier (ou au montant unitaire / pourcentage de la rubrique).
 * Une rubrique « note » ne porte qu'une quantité (sans valeur) ; une rubrique « dépend d'une note » est multipliée par cette quantité.
 * Une rubrique « à déduire » est une retenue : déduite du brut si elle est cotisable, sinon du net (retenue diverse, compte 427).
 * Les avantages en nature et les tickets (cadeau, restaurant) entrent dans le brut mais ne sont pas versés en espèces.
 */
class PaieRubrique extends Model
{
    use AppartientAuCabinet;

    protected $table = 'paie_rubriques';
    protected $guarded = [];

    public const CATEGORIES = ['prime' => 'Les primes', 'indemnite' => 'Les indemnités', 'avantage' => 'Les avantages en nature', 'autre' => 'Autre'];
    public const MODES = ['montant' => 'Montant', 'manuelle' => 'Saisie manuelle', 'pourcentage' => 'Pourcentage', 'proportion' => 'Proportion'];

    /** Cases à cocher de la fiche rubrique, dans l'ordre de l'écran. */
    public const OPTIONS = [
        'prorata_travail' => 'Dépend de nombre de j/h travaillés', 'prorata_jours_pris' => 'Dépend de nombre de jours pris',
        'prorata_heures_pris' => 'Dépend du nombre des heures pris', 'conditionnee' => 'Prime conditionnée / j.h trav',
        'non_cotisable' => 'Non cotisable', 'non_imposable' => 'Non imposable', 'ticket_cadeau' => 'Ticket cadeau', 'ticket_restaurant' => 'Ticket restaurant',
        'a_deduire' => 'À déduire', 'heures_sup' => 'Heures supplémentaires', 'jours_sup' => 'Jours supplémentaires', 'heures_nuit' => 'Heures de nuit',
        'note' => 'Note sans calculer sa valeur', 'valeur_net' => 'Valeur net', 'depend_note' => 'Dépend d’une note',
    ];

    /** Profils proposés d'office (complétés par les profils saisis sur les fiches employés). */
    public const PROFILS = ['Ordinaire', 'SIVP', 'Jeune Ouvrier', 'Gérant', 'Exo 2 ans', 'DIGNITE', 'CEF', '7ans(7ème Année)'];

    protected $casts = ['profils' => 'array', 'montant' => 'float', 'taux' => 'float', 'numerateur' => 'float', 'denominateur' => 'float',
        'nb_jours_ouv' => 'float', 'nb_heures_ouv' => 'float'] + [
        'prorata_travail' => 'boolean', 'prorata_jours_pris' => 'boolean', 'prorata_heures_pris' => 'boolean', 'conditionnee' => 'boolean',
        'non_cotisable' => 'boolean', 'non_imposable' => 'boolean', 'ticket_cadeau' => 'boolean', 'ticket_restaurant' => 'boolean', 'a_deduire' => 'boolean',
        'heures_sup' => 'boolean', 'jours_sup' => 'boolean', 'heures_nuit' => 'boolean', 'note' => 'boolean', 'valeur_net' => 'boolean', 'depend_note' => 'boolean'];

    public function client() { return $this->belongsTo(Client::class); }

    public static function duDossier(Client $client): Collection
    {
        return static::where('client_id', $client->id)->orderBy('code')->get();
    }

    public static function prochainCode(Client $client): int
    {
        return max(100, (int) static::where('client_id', $client->id)->max('code')) + 1;
    }

    /** Profils proposés : liste d'office et profils des fiches employés du dossier. */
    public static function profilsDisponibles(Client $client): array
    {
        $fiches = Salarie::where('client_id', $client->id)->whereNotNull('profil')->where('profil', '!=', '')->distinct()->pluck('profil')->all();
        return array_values(array_unique([...self::PROFILS, ...$fiches]));
    }

    /** La rubrique se saisit chaque mois (montant ou quantité). */
    public function aSaisir(): bool
    {
        return $this->mode === 'manuelle' || $this->note || $this->heures_sup || $this->jours_sup || $this->heures_nuit;
    }

    /** Quantité (heures, jours, note) plutôt que montant. */
    public function enQuantite(): bool
    {
        return $this->note || $this->heures_sup || $this->jours_sup || $this->heures_nuit;
    }

    public function enNature(): bool
    {
        return $this->categorie === 'avantage' || $this->ticket_cadeau || $this->ticket_restaurant;
    }

    public function concerne(Salarie $e): bool
    {
        return ! $this->profils || in_array((string) $e->profil, $this->profils, true);
    }

    /** Référence à une autre rubrique (base « r:id » ou note). */
    public function dependances(): array
    {
        return array_values(array_filter([
            str_starts_with((string) $this->base, 'r:') ? (int) substr($this->base, 2) : null,
            $this->depend_note ? $this->note_id : null,
        ]));
    }

    public function libelleValeur(Collection $toutes): string
    {
        $base = fn () => match (true) {
            $this->base === 'salaire' => 'salaire du mois',
            str_starts_with((string) $this->base, 'r:') => ($toutes->firstWhere('id', (int) substr($this->base, 2))?->nom ?? 'rubrique supprimée'),
            default => 'salaire de base',
        };
        return match ($this->mode) {
            'montant' => n3($this->montant),
            'pourcentage' => $base().' × '.rtrim(rtrim(number_format((float) $this->taux, 3, ',', ''), '0'), ',').' %',
            'proportion' => $base().' × '.(float) $this->numerateur.' / '.(float) $this->denominateur,
            default => 'Saisie manuelle',
        };
    }

    /**
     * Lignes de rubriques d'un salarié pour le mois.
     * $ctx : base, salaire, jours (ou heures en régime horaire), plein (jours ou heures du mois complet), horaire, taux_h, taux_j.
     * $saisie : {rubrique_id: valeur saisie}. Renvoie [{id, code, nom, categorie, sens, quantite, montant, cotisable, imposable, nature, net}].
     */
    public static function evaluer(Collection $rubriques, Salarie $e, array $ctx, array $saisie): array
    {
        $lignes = [];
        $montants = [];
        $qte = fn ($id) => isset($saisie[$id]) && $saisie[$id] !== '' && is_numeric($saisie[$id]) ? (float) $saisie[$id] : null;
        // Les rubriques calculées sur une autre rubrique passent après celles dont elles dépendent.
        $ordre = $rubriques->filter->concerne($e)->sortBy(fn ($r) => [str_starts_with((string) $r->base, 'r:') ? 1 : 0, $r->code]);
        foreach ($ordre as $r) {
            $q = $qte($r->id);
            if ($r->note) {
                if ($q !== null) $lignes[] = $r->ligne($q, 0.0);
                continue;
            }
            $valBase = match (true) {
                $r->base === 'salaire' => (float) $ctx['salaire'],
                str_starts_with((string) $r->base, 'r:') => (float) ($montants[(int) substr($r->base, 2)] ?? 0),
                default => (float) $ctx['base'],
            };
            if ($r->heures_sup || $r->heures_nuit || $r->jours_sup) {
                if ($q === null) continue;
                $taux = $r->jours_sup ? $ctx['taux_j'] : $ctx['taux_h'];
                $unite = match ($r->mode) { 'montant' => (float) $r->montant, 'pourcentage' => $taux * (float) $r->taux / 100, default => $taux };
                $m = $q * $unite;
            } else {
                $m = match ($r->mode) {
                    'montant' => (float) $r->montant,
                    'pourcentage' => $valBase * (float) $r->taux / 100,
                    'proportion' => (float) $r->denominateur ? $valBase * (float) $r->numerateur / (float) $r->denominateur : 0.0,
                    default => $q,
                };
                if ($m === null) continue;
            }
            if ($r->depend_note) $m *= (float) ($qte($r->note_id) ?? 0);
            $plein = $ctx['horaire'] ? ((float) $r->nb_heures_ouv ?: $ctx['plein']) : ((float) $r->nb_jours_ouv ?: $ctx['plein']);
            if ($r->prorata_travail && $plein > 0) $m *= min(1, $ctx['jours'] / $plein);
            if ($r->conditionnee && $ctx['jours'] < $ctx['plein']) $m = 0.0;
            $m = r3($m);
            $montants[$r->id] = $m;
            if ($m == 0 && $q === null) continue;
            $lignes[] = $r->ligne($r->enQuantite() ? $q : null, $m);
        }
        return $lignes;
    }

    private function ligne(?float $quantite, float $montant): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'nom' => $this->nom, 'categorie' => $this->categorie,
            'sens' => $this->note ? 'note' : ($this->a_deduire ? 'retenue' : 'gain'), 'quantite' => $quantite, 'montant' => $montant,
            'cotisable' => ! $this->non_cotisable, 'imposable' => ! $this->non_imposable, 'nature' => $this->enNature(), 'net' => $this->valeur_net && ! $this->a_deduire];
    }
}
