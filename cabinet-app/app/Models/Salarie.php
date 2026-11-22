<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;

class Salarie extends Model
{
    use AppartientAuCabinet;

    protected $table = 'salaries';
    protected $guarded = [];
    protected $casts = ['chef_famille' => 'boolean', 'date_embauche' => 'date', 'date_naissance' => 'date', 'contrat_debut' => 'date', 'contrat_fin' => 'date',
        'profil_debut' => 'date', 'profil_fin' => 'date', 'categorie_debut' => 'date', 'categorie_fin' => 'date', 'echelon_debut' => 'date', 'echelon_fin' => 'date',
        'cin_date' => 'date', 'date_sortie' => 'date', 'date_declaration_cnss' => 'date', 'carte_sejour' => 'boolean', 'cnss_assujetti' => 'boolean', 'cnss_rc' => 'boolean', 'regul_irpp' => 'boolean', 'regul_primes' => 'boolean',
        'retenue_etranger' => 'boolean', 'stc_solde_total' => 'boolean', 'enfants_detail' => 'array'];

    public const CONTRATS = ['CDI' => 'CDI', 'CDD' => 'CDD', 'SIVP' => 'SIVP', 'KARAMA' => 'Karama', 'stage' => 'Stage', 'autre' => 'Autre'];
    public const TITRES = ['M.' => 'M.', 'Mme' => 'Mme', 'Mlle' => 'Mlle'];
    public const SITUATIONS = ['celibataire' => 'Célibataire', 'marie' => 'Marié(e)', 'divorce' => 'Divorcé(e)', 'veuf' => 'Veuf / veuve'];
    public const ETATS = ['actif' => 'En activité', 'suspendu' => 'Suspendu', 'sorti' => 'Sorti'];
    public const MOTIFS_SORTIE = ['demission' => 'Démission', 'fin_contrat' => 'Fin de contrat', 'licenciement' => 'Licenciement', 'retraite' => 'Retraite', 'deces' => 'Décès', 'autre' => 'Autre'];
    public const MODES = ['virement' => 'Virement', 'cheque' => 'Chèque', 'especes' => 'Espèces'];

    /** Champs de carrière (contrat, profil, catégorie, échelon) et leurs dates. */
    public const CARRIERE = ['date_naissance', 'type_contrat', 'contrat_debut', 'contrat_fin', 'profil', 'profil_debut', 'profil_fin', 'categorie', 'categorie_debut', 'categorie_fin', 'echelon', 'echelon_debut', 'echelon_fin'];

    /** Champs de la fiche employé complète (hors identité de base) : repris par la sauvegarde. */
    public const FICHE = ['titre', 'prenom', 'prenom_pere', 'matricule_pointeuse', 'adresse', 'ville', 'code_postal', 'tel_fixe', 'tel_mobile', 'cin_date', 'carte_sejour', 'nationalite',
        'lieu_naissance', 'situation_familiale', 'enfants_detail', 'parents_charge', 'regime', 'conges_acquis', 'conges_report', 'conges_report_annee', 'cnss_assujetti', 'cnss_rc', 'retenue_etranger', 'regul_irpp', 'regul_primes', 'nb_bulletin',
        'assurance_vie', 'cea', 'interets_bancaires', 'stc_solde_total', 'departement', 'etat_presence', 'date_sortie', 'motif_sortie', 'date_declaration_cnss', 'agence'];

    /** Changements de situation suivis dans l'onglet Détail contrats et l'historique des modifications. */
    public const SUIVIS = ['type_contrat' => 'Type de contrat', 'contrat_debut' => 'Début du contrat', 'contrat_fin' => 'Fin du contrat', 'poste' => 'Qualification',
        'salaire_base' => 'Salaire de base', 'regime' => 'Régime salarial', 'categorie' => 'Catégorie', 'echelon' => 'Échelon', 'profil' => 'Profil',
        'departement' => 'Département', 'etat_presence' => 'État de présence', 'date_sortie' => 'Date de sortie', 'situation_familiale' => 'Situation familiale',
        'chef_famille' => 'Chef de famille', 'enfants' => 'Enfants à charge', 'mode_paiement' => 'Mode de paiement', 'rib' => 'RIB'];

    /** Champs relatifs au contrat (État historique contrat). */
    public const SUIVIS_CONTRAT = ['type_contrat', 'contrat_debut', 'contrat_fin', 'etat_presence', 'date_sortie'];

    protected static function booted(): void
    {
        static::created(fn (Salarie $e) => $e->historique()->create(['user_id' => auth()->id(), 'champ' => 'creation', 'libelle' => 'Création de la fiche', 'nouveau' => $e->nomComplet()]));
        static::updated(function (Salarie $e) {
            foreach (array_intersect_key($e->getChanges(), self::SUIVIS) as $champ => $_) {
                $avant = $e->valeurLisible($champ, $e->getOriginal($champ));
                $apres = $e->valeurLisible($champ, $e->$champ);
                if ($avant !== $apres) $e->historique()->create(['user_id' => auth()->id(), 'champ' => $champ, 'libelle' => self::SUIVIS[$champ], 'ancien' => $avant, 'nouveau' => $apres]);
            }
        });
    }

    public function client() { return $this->belongsTo(Client::class); }
    public function documents() { return $this->hasMany(SalarieDocument::class); }
    public function historique() { return $this->hasMany(SalarieHistorique::class)->latest('id'); }

    /** Nom et prénom (les fiches anciennes ont le nom complet dans « nom »). */
    public function nomComplet(): string
    {
        return trim($this->nom.' '.$this->prenom);
    }

    /**
     * Le n° d'affiliation CNSS n'est pas exigé des employés non déclarés à la CNSS : profil CIVP, gérant, ou tout profil
     * non « Inclue dans la Déclaration CNSS » ; ni des employés non assujettis. $profils : PaieProfil::parNom() du dossier.
     */
    public function cnssFacultatif(?\Illuminate\Support\Collection $profils = null): bool
    {
        if (! $this->cnss_assujetti && $this->cnss_assujetti !== null) return true;
        $nom = mb_strtolower(trim((string) $this->profil));
        if ($nom === '') return false;
        if (in_array(\Illuminate\Support\Str::ascii($nom), ['civp', 'gerant', 'sivp'], true)) return true;
        $p = ($profils ?? PaieProfil::parNom($this->client))->get($nom);
        return $p !== null && ! $p->decl_cnss;
    }

    public function estActif(): bool
    {
        return $this->etat_presence !== 'sorti';
    }

    public function photo(): ?SalarieDocument
    {
        return $this->documents->firstWhere('type', 'photo');
    }

    public function valeurLisible(string $champ, $v): ?string
    {
        if ($v === null || $v === '') return null;
        if ($v instanceof \DateTimeInterface) return $v->format('d/m/Y');
        return match ($champ) {
            'type_contrat' => self::CONTRATS[$v] ?? $v,
            'regime' => $v === 'horaire' ? 'Horaire' : 'Mensuel',
            'etat_presence' => self::ETATS[$v] ?? $v,
            'situation_familiale' => self::SITUATIONS[$v] ?? $v,
            'mode_paiement' => self::MODES[$v] ?? $v,
            'chef_famille' => $v ? 'Oui' : 'Non',
            'salaire_base' => number_format((float) $v, 3, ',', ' '),
            'contrat_debut', 'contrat_fin', 'date_sortie' => \Carbon\Carbon::parse($v)->format('d/m/Y'),
            default => (string) $v,
        };
    }
}
