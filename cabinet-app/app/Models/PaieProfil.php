<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Profil de paie d'un dossier (menu Profil). Un employé suit le profil dont le nom figure sur sa fiche.
 * Effets sur la paie : taux de CNSS patronale (vide = taux du dossier), TFP et FOPROLOS seulement si « Soumis TFP-FOPROLOS »,
 * cotisation salariale CNSS et déclaration CNSS seulement si « Inclue dans la déclaration CNSS », présence dans la déclaration employeur.
 * « Contrat Dignité » : CNSS prise en charge par l’État jusqu’à 600 DT par mois (taux du régime général au-delà), 400 DT exonérés d’IRPP,
 * ni TFP ni FOPROLOS. « Base de calcul », « Contrat Exo 2 ans » et « Rég. d'IRPP KARAMA » sont enregistrés pour les déclarations.
 */
class PaieProfil extends Model
{
    use AppartientAuCabinet;

    protected $table = 'paie_profils';
    protected $guarded = [];

    public const OPTIONS = [
        'soumis_tfp_fop' => 'Soumis TFP-FOPROLOS', 'decl_cnss' => 'Inclue dans la Déclaration CNSS', 'decl_employeur' => 'Inclue dans la Déclaration Employeur',
        'base_calcul' => 'Base de Calcul', 'contrat_dignite' => 'Contrat Dignité', 'contrat_exo2ans' => 'Contrat Exo 2ans', 'regul_karama' => 'Rég d’IRPP pour « KARAMA »',
    ];

    /** Profils du logiciel de référence, créés dans chaque dossier. Ordinaire suit le taux de CNSS patronale du dossier. */
    public const STANDARD = [
        'Ordinaire' => ['cnss_patronale' => null, 'code_exploitant' => '0000', 'soumis_tfp_fop' => true, 'decl_cnss' => true, 'decl_employeur' => true],
        'CIVP' => ['cnss_patronale' => 0, 'code_exploitant' => '0006'],
        'DIGNITE' => ['cnss_patronale' => null, 'code_exploitant' => '0334', 'decl_cnss' => true, 'decl_employeur' => true, 'contrat_dignite' => true],
        'Exo 2 ans' => ['cnss_patronale' => 1, 'code_exploitant' => '0337', 'soumis_tfp_fop' => true, 'decl_cnss' => true, 'decl_employeur' => true, 'contrat_exo2ans' => true],
        'Gérant' => ['cnss_patronale' => 0, 'code_exploitant' => '0006', 'soumis_tfp_fop' => true, 'decl_employeur' => true],
    ];

    protected $casts = ['cnss_patronale' => 'float'] + [
        'soumis_tfp_fop' => 'boolean', 'decl_cnss' => 'boolean', 'decl_employeur' => 'boolean', 'base_calcul' => 'boolean',
        'contrat_dignite' => 'boolean', 'contrat_exo2ans' => 'boolean', 'regul_karama' => 'boolean'];

    public function client() { return $this->belongsTo(Client::class); }

    public static function duDossier(Client $client): Collection
    {
        return static::where('client_id', $client->id)->orderBy('nom')->get();
    }

    /** Profils indexés par nom en minuscules, pour retrouver celui d'un employé. */
    public static function parNom(Client $client): Collection
    {
        return static::where('client_id', $client->id)->get()->keyBy(fn ($p) => mb_strtolower(trim($p->nom)));
    }

    /** Les profils standard sont créés une seule fois par dossier : un profil supprimé ne revient pas. */
    public static function initialiserStandard(Client $client): int
    {
        $k = $client->paie_config ?? [];
        if (! empty($k['profils_standard'])) return 0;
        $deja = static::parNom($client);
        $n = 0;
        foreach (self::STANDARD as $nom => $v) {
            if ($deja->has(mb_strtolower($nom))) continue;
            static::create(['client_id' => $client->id, 'nom' => $nom] + $v);
            $n++;
        }
        $k['profils_standard'] = true;
        $client->paie_config = $k;
        $client->save();
        return $n;
    }

    /** Ce que la paie et les déclarations retiennent du profil. */
    public function meta(): array
    {
        return ['profil' => $this->nom, 'code_exploitant' => $this->code_exploitant, 'decl_cnss' => $this->decl_cnss, 'decl_employeur' => $this->decl_employeur];
    }
}
