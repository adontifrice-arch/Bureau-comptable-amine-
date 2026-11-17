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


    /** Listes du logiciel de référence, proposées dans chaque dossier (saisie manuelle, cotisables et imposables par défaut). */
    public const STANDARD = ['prime' => self::PRIMES_STANDARD, 'indemnite' => self::INDEMNITES_STANDARD, 'avantage' => self::AVANTAGES_STANDARD, 'autre' => self::AUTRES_STANDARD];

    /**
     * Catégorie « Autre » : nom => réglages d'office. Acompte et prêts sont des retenues sur le net (compte 427) ; les heures
     * supplémentaires se saisissent en heures, au taux horaire majoré (125 % pour 25 %…). Salaire de base, CNSS, IRPP et net à payer
     * de la liste du logiciel de référence ne sont pas repris : ils sont calculés par la paie elle-même.
     */
    public const AUTRES_STANDARD = [
        'Acompte' => ['a_deduire' => true, 'non_cotisable' => true, 'non_imposable' => true],
        'Assistance décès' => [], 'Assistance pour fêtes religieuses' => [], 'Assistance scolaire' => [], 'Assurance groupe' => [],
        'Autres Avantages : lait, savon, vêtement de travail supplémentaire' => [], 'CAVIS' => [], 'Congé de naissance' => [],
        'Frais de repas' => [], 'Frais des vêtements de travail et protection' => [],
        'Heures de nuit' => ['heures_nuit' => true],
        'Heures Supplémentaires 100%' => ['heures_sup' => true, 'mode' => 'pourcentage', 'base' => 'base', 'taux' => 200],
        'Heures Supplémentaires 25%' => ['heures_sup' => true, 'mode' => 'pourcentage', 'base' => 'base', 'taux' => 125],
        'Heures Supplémentaires 50%' => ['heures_sup' => true, 'mode' => 'pourcentage', 'base' => 'base', 'taux' => 150],
        'Heures Supplémentaires 75%' => ['heures_sup' => true, 'mode' => 'pourcentage', 'base' => 'base', 'taux' => 175],
        'Mutation ou changement de résidence' => [], 'Prestation de service pour raison de santé' => [],
        'Prêt' => ['a_deduire' => true, 'non_cotisable' => true, 'non_imposable' => true],
        'PrêtCNSS' => ['a_deduire' => true, 'non_cotisable' => true, 'non_imposable' => true],
        'Ration de lait' => [], 'Ration de savon' => [],
    ];

    /**
     * Bases calculées par la paie (liste « Autre » du logiciel de référence) : total brut et salaire imposable du mois (brut imposable − CNSS salariale).
     * Réservées aux retenues non cotisables, déduites du net : elles ne modifient ni le brut ni l'imposable dont elles dépendent.
     */
    public const BASES_TOTAUX = ['brut', 'imposable'];
    public const BASES = ['base' => 'Salaire de base', 'salaire' => 'Salaire du mois', 'brut' => 'Total brut', 'imposable' => 'Salaire imposable'];

    /** Avantages en nature : nom => cases cochées d'office sur la fiche rubrique. */
    public const AVANTAGES_STANDARD = [
        'Ticket Cadeau' => ['ticket_cadeau' => true],
        'Ticket Restaurant' => ['ticket_restaurant' => true],
    ];

    public const PRIMES_STANDARD = [
        'Prime de douche', 'Prime administration', 'Prime ancienneté', 'Prime de Aid El Idha', 'Prime de Aid esseghir', 'Prime de ajustement des équipes',
        'Prime de Caisse', 'Prime de chaleur', 'Prime de chaleur, fumée, salissure et risque', 'Prime de chargement sur bateaux', 'Prime de conduite sans accidents',
        'Prime de douche et frais de voyage', 'Prime de encouragement', 'Prime de ensachement', 'Prime de fin d’année', 'Prime de fin d’année et 13ème mois',
        'Prime de fonction', 'Prime de froid', 'Prime de informatique', 'Prime de lait', 'Prime de logement', 'Prime de nuit', 'Prime de odeur', 'Prime de Panier',
        'Prime de penibilité', 'Prime de poussière', 'Prime de poussière et de savon', 'Prime de poussière Prime', 'Prime de présence', 'Prime de Productivité',
        'Prime de récolte', 'Prime de rendement', 'Prime de rendement et de fin d’année', 'Prime de rendement mensuelle', 'Prime de responsabilité',
        'Prime de Restauration', 'Prime de risque', 'Prime de risque et d’humidité', 'Prime de risque exceptionnelle', 'Prime de sortie', 'Prime de transport',
        'Prime de travail dans l’eau', 'Prime de travail en hauteur', 'Prime diverse', 'Prime exceptionnelle', 'Prime groupée',
    ];

    public const INDEMNITES_STANDARD = [
        'Indemnité commune', 'Indemnité de 13ème mois', 'Indemnité de accouchement', 'Indemnité de Aid el Fiter', 'Indemnité de Aid el Idha',
        'Indemnité de compensation pour repos hebdomadaire', 'Indemnité de départ à la retraite', 'Indemnité de déplacement', 'Indemnité de embauche',
        'Indemnité de fabrication (mèches)', 'Indemnité de fonction', 'Indemnité de garantie', 'Indemnité de la mise en retraite', 'Indemnité de la rentrée scolaire',
        'Indemnité de logement', 'Indemnité de maladie', 'Indemnité de maladie ou accident de travail', 'Indemnité de manipulation de fonds',
        'Indemnité de mutation ou de réinstallation', 'Indemnité de non commodité de poste', 'Indemnité de presse', 'Indemnité de propreté',
        'Indemnité de représentation', 'Indemnité de salissure', 'Indemnité de standardiste', 'Indemnité de suggestion spéciale', 'Indemnité de sujétion de services',
        'Indemnité de transport', 'Indemnité de utilisation de employé dans une fonction autre que celle de sa catégorie', 'Indemnité du 13ème et 14ème mois',
        'Indemnité du congé Annuel payé',
    ];

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

    /**
     * Ajoute au dossier les rubriques standard d'une catégorie (ou de toutes) qui n'y sont pas encore, rapprochées par nom.
     * Renvoie le nombre de rubriques ajoutées.
     */
    public static function ajouterStandard(Client $client, ?string $categorie = null): int
    {
        $cle = fn ($n) => mb_strtolower(str_replace(['’', "'"], '', trim($n)));
        $deja = static::where('client_id', $client->id)->pluck('nom')->map($cle)->all();
        $code = static::prochainCode($client);
        $n = 0;
        foreach (self::STANDARD as $cat => $noms) {
            if ($categorie && $cat !== $categorie) continue;
            // Liste simple de noms, ou nom => cases cochées d'office.
            foreach ($noms as $k => $v) {
                [$nom, $options] = is_int($k) ? [$v, []] : [$k, $v];
                if (in_array($cle($nom), $deja, true)) continue;
                static::create($options + ['client_id' => $client->id, 'code' => $code++, 'categorie' => $cat, 'nom' => $nom, 'mode' => 'manuelle']);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Listes standard reçues une seule fois par catégorie (mémorisé dans la configuration paie du dossier) :
     * une rubrique standard supprimée ensuite ne revient pas d'elle-même.
     */
    public static function initialiserStandard(Client $client): int
    {
        $k = $client->paie_config ?? [];
        $avant = (array) ($k['rubriques_standard'] ?? []);
        $faites = $avant;
        $n = 0;
        foreach (array_keys(self::STANDARD) as $cat) {
            if (in_array($cat, $faites, true)) continue;
            $n += static::ajouterStandard($client, $cat);
            $faites[] = $cat;
        }
        if ($faites !== $avant) {
            $k['rubriques_standard'] = $faites;
            $client->paie_config = $k;
            $client->save();
        }
        return $n;
    }

    /** Profils proposés : profils du dossier (menu Profil) et profils saisis sur les fiches employés. */
    public static function profilsDisponibles(Client $client): array
    {
        $fiches = Salarie::where('client_id', $client->id)->whereNotNull('profil')->where('profil', '!=', '')->distinct()->pluck('profil')->all();
        return array_values(array_unique([...PaieProfil::duDossier($client)->pluck('nom')->all(), ...$fiches]));
    }

    /** Profils de la rubrique : null = tous les employés ; liste (éventuellement vide) = seulement ces profils. */
    public function pourProfil(string $nom): bool
    {
        return $this->profils === null || in_array(mb_strtolower($nom), array_map('mb_strtolower', $this->profils), true);
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
        return $this->pourProfil((string) $e->profil);
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
            $this->base === 'brut' => 'total brut',
            $this->base === 'imposable' => 'salaire imposable',
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
            // Bases « Total brut » et « Salaire imposable » : connues seulement au second passage du calcul (voir PaieCalc::calcul).
            if (in_array($r->base, self::BASES_TOTAUX, true) && in_array($r->mode, ['pourcentage', 'proportion'], true) && ! isset($ctx[$r->base])) continue;
            $valBase = match (true) {
                $r->base === 'salaire' => (float) $ctx['salaire'],
                $r->base === 'brut' => (float) $ctx['brut'],
                $r->base === 'imposable' => (float) $ctx['imposable'],
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
