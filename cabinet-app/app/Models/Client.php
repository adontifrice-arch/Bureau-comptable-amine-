<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use AppartientAuCabinet, HasFactory;

    protected $table = 'clients';
    protected $guarded = [];

    protected static function booted(): void
    {
        // Un utilisateur limité à certains dossiers garde l'accès au client qu'il crée
        static::created(function (Client $c) {
            $u = \App\Support\Droits::utilisateur();
            if ($u && \App\Support\Droits::dossiers() !== null) {
                \Illuminate\Support\Facades\DB::table('user_dossiers')->insertOrIgnore(['user_id' => $u->id, 'client_id' => $c->id]);
                \App\Support\Droits::oublier();
            }
        });
    }

    /** Catégories de revenus de l'impôt sur le revenu des personnes physiques. */
    public const CATEGORIES_REVENUS = [
        'bic' => 'Bénéfices industriels et commerciaux (BIC)',
        'bnc' => 'Bénéfices des professions non commerciales (BNC)',
        'agricole' => 'Bénéfices des exploitations agricoles et de pêche',
        'salaires' => 'Traitements, salaires, pensions et rentes viagères',
        'fonciers' => 'Revenus fonciers',
        'capitaux_mobiliers' => 'Revenus des valeurs mobilières et des capitaux mobiliers',
        'exoneres' => 'Revenus exonérés',
        'non_soumis' => 'Revenus non soumis à l’impôt',
        'autres' => 'Autres revenus',
    ];

    /** Cadre légal de l'activité de l'entreprise : liste et codes de la déclaration CNSS (code enregistré). */
    public const CADRES_LEGAUX = [
        '01' => 'Exportation totale',
        '02' => 'Exerçant dans le secteur du développement régional',
        '03' => 'Exerçant dans le secteur du développement agricole',
        '04' => 'Exerçant dans le parc d’activités économiques',
        '05' => 'Exportation',
        '13' => 'Encouragement des petites et moyennes entreprises',
        '14' => 'Les établissements de santé prêtant la totalité de leurs services au profit des non résidents',
        '17' => 'Activités de soutien et de lutte contre la pollution',
    ];

    /** Libellé d'un cadre légal, précédé de son code CNSS (« 01 - Exportation totale »). */
    public static function libelleCadre(?string $code): string
    {
        return isset(self::CADRES_LEGAUX[$code]) ? $code.' - '.self::CADRES_LEGAUX[$code] : (string) $code;
    }

    /** Organismes recevant la déclaration d'investissement. */
    public const ORGANISMES_INVESTISSEMENT = ['API' => 'API', 'APIA' => 'APIA', 'autre' => 'Autre'];

    /** Nombre de lignes du cadre légal (déclaration CNSS). */
    public const CADRES_LEGAUX_MAX = 5;

    public const REGIMES_IMPOSITION =['reel' => 'Régime réel', 'forfaitaire' => 'Régime forfaitaire'];

    /** Champs propres à une personne morale, vidés pour une personne physique. */
    public const CHAMPS_MORALE = ['capital', 'nb_parts', 'valeur_nominale', 'gerant_nomination_date', 'gerant_nomination_duree',
        'gerant_associe', 'gerant_parts', 'gerant_parts_de', 'gerant_parts_a', 'gerant_apport', 'mode_depot'];

    /** Personne morale : mode de dépôt des déclarations mensuelles (échéance le 20 ou le 28 du mois suivant). */
    /** Civilité du gérant, de la personne physique et des associés (accords dans les actes). */
    public const CIVILITES = ['M' => 'Monsieur', 'Mme' => 'Madame'];

    public const MODES_DEPOT = ['teledeclaration' => 'Télé-déclaration et télé-paiement (le 20)', 'recette' => 'Dépôt à la recette des finances (le 28)'];

    /** Champs propres à une personne physique, vidés pour une personne morale. */
    public const CHAMPS_PHYSIQUE = ['activite', 'categories_revenus', 'regime_imposition', 'situation_familiale', 'chef_famille', 'enfants_charge', 'parents_charge'];

    public const SITUATIONS_FAMILIALES = ['celibataire' => 'Célibataire', 'marie' => 'Marié(e)', 'divorce' => 'Divorcé(e)', 'veuf' => 'Veuf / veuve'];

    /** Enfants à charge du chef de famille : type => [libellé, déduction annuelle en dinars]. */
    public const TYPES_ENFANT = [
        'moins_20' => ['Enfant âgé de moins de 20 ans', 100],
        'etudiant' => ['Étudiant âgé de 25 ans au plus', 1000],
        'handicape' => ['Enfant handicapé', 2000],
    ];

    /** Nombre d'enfants à charge ouvrant droit à déduction (les premiers). */
    public const ENFANTS_DEDUCTIBLES = 4;

    /** Déduction pour enfants à charge (dinars), dans la limite des premiers enfants. */
    public function deductionEnfants(): int
    {
        if (! $this->chef_famille) return 0;
        return (int) collect($this->enfants_charge ?? [])->take(self::ENFANTS_DEDUCTIBLES)->sum(fn ($e) => self::TYPES_ENFANT[$e['type'] ?? ''][1] ?? 0);
    }

    protected $casts = ['enfants_charge'=>'array','chef_famille'=>'boolean','cadres_legaux'=>'array','categories_revenus'=>'array','paie_config'=>'array','gerant_cin_date'=>'date','gerant_passeport_date'=>'date','gerant_passeport_validite'=>'date','gerant_naissance_date'=>'date','gerant_nomination_date'=>'date','gerant_associe'=>'boolean'];

    public function associes() { return $this->hasMany(Associe::class); }
    public function echeances() { return $this->hasMany(Echeance::class); }
    public function exigences() { return $this->hasMany(Exigence::class); }
    public function factures() { return $this->hasMany(Facture::class); }
    public function documents() { return $this->hasMany(Document::class); }
    /** Comptes d'accès du client à l'espace client. */
    public function accesEspace() { return $this->hasMany(ClientUser::class)->orderBy('name'); }
    public function salaries() { return $this->hasMany(Salarie::class); }
    public function ecritures() { return $this->hasMany(Ecriture::class); }

    public function estPhysique(): bool
    {
        if ($this->type_personne) return $this->type_personne === 'physique';
        return (bool) preg_match('/lib[ée]ral|bnc|bic|individu|physique|artisan|commer[cç]ant/iu', (string) $this->forme);
    }

    /** Pièces conditionnelles (personnes physiques et morales) : catégorie GED => condition affichée. */
    public const PIECES_CONDITIONNELLES = [
        'Affiliation CNSS' => 'si affilié comme employeur',
        'Affiliation CNSS (travailleur indépendant)' => 'si affilié comme indépendant',
        'Déclaration d’investissement API' => 's’il y en a une',
        'Code en douane' => 'si un régime d’export est indiqué',
    ];

    /**
     * Pièces de base du dossier : [catégorie GED => condition], la condition étant null pour une pièce toujours requise.
     * Personne morale : statuts, patente, déclaration d'existence, extrait RNE, déclaration du bénéficiaire effectif, liste des associés,
     * et, si elle est non résidente, fiche d'investissement BCT ; personne physique : patente, déclaration d'existence, extrait RNE.
     * Dans les deux cas, s'il y a lieu : affiliations CNSS (employeur, travailleur indépendant), déclaration d'investissement,
     * code en douane.
     */
    public function piecesDeBase(): array
    {
        $c = self::PIECES_CONDITIONNELLES;
        if ($this->estPhysique()) return ['Patente' => null, 'Déclaration d’existence' => null, 'Extrait RNE' => null] + $c;
        return ['Statuts' => null, 'Patente' => null, 'Déclaration d’existence' => null, 'Extrait RNE' => null,
            'Affiliation CNSS' => $c['Affiliation CNSS'], 'Affiliation CNSS (travailleur indépendant)' => $c['Affiliation CNSS (travailleur indépendant)'],
            'Déclaration du bénéficiaire effectif' => null, 'Liste des associés' => null, 'Déclaration d’investissement API' => $c['Déclaration d’investissement API'],
            'Fiche d’investissement BCT' => 'si la société est non résidente', 'Code en douane' => $c['Code en douane']];
    }

    /**
     * Pièces de base requises pour ce client : les pièces sans condition, et les pièces conditionnelles
     * dont la condition est remplie d'après la fiche (matricule CNSS, déclaration d'investissement, régime d'export).
     */
    public function piecesRequises(): array
    {
        $investissement = collect((array) $this->cadres_legaux)->contains(fn ($l) => ! empty($l['numero']) || ! empty($l['organisme']));
        $applicable = ['Affiliation CNSS' => (bool) $this->matricule_cnss, 'Affiliation CNSS (travailleur indépendant)' => (bool) $this->matricule_independant,
            'Déclaration d’investissement API' => $investissement, 'Code en douane' => (bool) $this->regime_export,
            'Fiche d’investissement BCT' => $this->residence === 'non_residente'];
        return array_keys(array_filter($this->piecesDeBase(), fn ($cond, $p) => $cond === null || ($applicable[$p] ?? false), ARRAY_FILTER_USE_BOTH));
    }

    public function initiales(): string
    {
        $w = preg_split('/\s+/u', trim($this->nom));
        return mb_strtoupper(mb_substr($w[0] ?? '', 0, 1).mb_substr($w[1] ?? '', 0, 1));
    }
}
