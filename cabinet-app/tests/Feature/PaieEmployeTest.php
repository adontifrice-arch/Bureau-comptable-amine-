<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Salarie;
use App\Models\SalarieDocument;
use App\Models\User;
use App\Services\Exports\EtatsEmployes;
use App\Services\PaieCalc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Paie — menu Employé : fiche en six onglets, pièces jointes, historique des changements, états du personnel et documents RH. */
class PaieEmployeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Excellia Créance', 'matricule_fiscal' => '1311620LAM000', 'paie_config' => ['ville' => 'Ariana', 'responsable_nom' => 'Sami Ben Ali', 'responsable_qualification' => 'Gérant']]);
        session(['dossier' => $this->client->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fiche(array $extra = []): array
    {
        return array_merge([
            'matricule' => '0033', 'titre' => 'M.', 'nom' => 'JELASSI', 'prenom' => 'Ridha',
            'adresse' => '12 rue de Marseille', 'ville' => 'Tunis', 'cin' => '01234567', 'date_naissance' => '1985-04-10', 'nationalite' => 'Tunisienne',
            'situation_familiale' => 'marie', 'chef_famille' => '1', 'enfants_detail' => [['a_charge' => '1'], ['etudiant' => '1'], [], ['infirme' => '1']], 'parents_charge' => 1,
            'poste' => 'Comptable', 'regime' => 'mensuel', 'conges_acquis' => 2.5, 'categorie' => 'B', 'echelon' => '4', 'salaire_base' => 1500, 'nb_bulletin' => 2,
            'cnss_assujetti' => '1', 'cnss' => '12345678-01',
            'type_contrat' => 'CDD', 'contrat_debut' => '2026-01-01', 'contrat_fin' => '2026-12-31', 'departement' => 'Recouvrement', 'etat_presence' => 'actif',
            'mode_paiement' => 'virement', 'banque' => 'BIAT', 'agence' => 'Ariana', 'rib' => '08000000000000000011',
        ], $extra);
    }

    public function test_creation_de_la_fiche_complete_avec_pieces_jointes(): void
    {
        Storage::fake('local');
        $this->get(route('paie.employes.create'))->assertOk()->assertSee('État civil')->assertSee('Pièces jointes')->assertSee('Détail contrats')->assertSee('value="0001"', false);
        $rep = $this->post(route('paie.salaries.store'), $this->fiche([
            'photo' => UploadedFile::fake()->image('photo.jpg'), 'cin1' => UploadedFile::fake()->image('recto.jpg'),
            'contrat_fichier' => UploadedFile::fake()->create('cdd.pdf', 50, 'application/pdf'), 'contrat_nom' => 'Contrat CDD 2026',
            'diplome_fichier' => UploadedFile::fake()->create('licence.pdf', 50, 'application/pdf'), 'diplome_nom' => 'Licence en gestion',
        ]))->assertSessionHasNoErrors();
        $e = Salarie::sole();
        $rep->assertRedirect(route('paie.employes.edit', $e));
        $this->assertSame('JELASSI Ridha', $e->nomComplet());
        $this->assertSame(3, $e->enfants);   // le 3e enfant n'est pas coché
        $this->assertSame('2026-01-01', $e->date_embauche->format('Y-m-d'));   // date d'embauche = début du contrat par défaut
        $this->assertEqualsCanonicalizing(['photo', 'cin1', 'contrat', 'diplome'], $e->documents()->pluck('type')->all());
        $this->assertSame('Contrat CDD 2026', $e->documents()->where('type', 'contrat')->value('nom'));

        $doc = $e->documents()->where('type', 'diplome')->first();
        $this->get(route('paie.employes.piece', $doc))->assertOk();
        $this->delete(route('paie.employes.piece.destroy', $doc))->assertRedirect();
        Storage::disk('local')->assertMissing($doc->chemin);

        $this->get(route('paie.employes.edit', $e))->assertOk()->assertSee('Contrat CDD 2026')->assertSee('Création de la fiche');
        $this->get(route('paie.employes.imprimer', $e))->assertOk()->assertSee('Fiche employé')->assertSee('Recouvrement');
        $this->get(route('paie.index', ['tab' => 'sal']))->assertOk()->assertSee('JELASSI Ridha');
    }

    public function test_mentions_obligatoires_et_matricule_unique(): void
    {
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Autre', 'matricule' => '0033', 'salaire_base' => 900]);
        $this->post(route('paie.salaries.store'), $this->fiche(['prenom' => '', 'ville' => '', 'categorie' => '', 'contrat_debut' => '']))
            ->assertSessionHasErrors(['matricule', 'prenom', 'ville', 'categorie', 'contrat_debut']);
        $this->post(route('paie.salaries.store'), $this->fiche(['matricule' => '0034', 'etat_presence' => 'sorti']))->assertSessionHasErrors('date_sortie');
    }

    public function test_changements_de_situation(): void
    {
        $this->post(route('paie.salaries.store'), $this->fiche());
        $e = Salarie::sole();
        $this->put(route('paie.salaries.update', $e), $this->fiche(['salaire_base' => 1650, 'echelon' => '5', 'etat_presence' => 'sorti', 'date_sortie' => '2026-12-31', 'motif_sortie' => 'demission']))
            ->assertSessionHasNoErrors();
        $h = $e->historique()->pluck('nouveau', 'champ');
        $this->assertSame('1 650,000', $h['salaire_base']);
        $this->assertSame('5', $h['echelon']);
        $this->assertSame('Sorti', $h['etat_presence']);
        $this->assertCount(3, EtatsEmployes::etat('historique-contrat', $this->client, 'tous', '2026-11')->lignes);   // création, état de présence, date de sortie (pas le salaire ni l'échelon)
        $this->get(route('paie.employes.etat', ['modifications', 'imprimer']))->assertOk()->assertSee('Échelon');
    }

    public function test_calcul_avec_la_fiche_complete(): void
    {
        $P = PaieCalc::parametres($this->client);
        $e = new Salarie(['nom' => 'X', 'salaire_base' => 1500, 'chef_famille' => true, 'enfants_detail' => [['a_charge' => true], ['etudiant' => true], ['infirme' => true]], 'enfants' => 3]);
        $this->assertEquals($P['enf'] + $P['enfEtu'] + $P['enfInf'], PaieCalc::deductionEnfants($e, $P));

        $sansDetail = new Salarie(['nom' => 'Y', 'salaire_base' => 1500, 'enfants' => 2]);
        $this->assertEquals(2 * $P['enf'], PaieCalc::deductionEnfants($sansDetail, $P));

        // Non assujetti à la CNSS : ni part salariale ni part patronale.
        $x = PaieCalc::calcul(new Salarie(['nom' => 'Z', 'salaire_base' => 1000, 'cnss_assujetti' => false]), 0, $P);
        $this->assertSame(0.0, $x['cnssS']);
        $this->assertSame(0.0, $x['cnssP']);

        // Régime horaire : taux × heures (mois complet = 208 h au régime de 48 h), ou heures pointées.
        $h = new Salarie(['nom' => 'H', 'salaire_base' => 5, 'regime' => 'horaire']);
        $this->assertEqualsWithDelta(1040, PaieCalc::calcul($h, 0, $P)['brut'], 0.001);
        $this->assertEqualsWithDelta(500, PaieCalc::calcul($h, 0, $P, null, 100)['brut'], 0.001);

        // Déductions annuelles : l'assiette baisse du montant déclaré.
        $base = PaieCalc::calcul(new Salarie(['nom' => 'A', 'salaire_base' => 2000]), 0, $P);
        $av = PaieCalc::calcul(new Salarie(['nom' => 'A', 'salaire_base' => 2000, 'assurance_vie' => 1200]), 0, $P);
        $this->assertEqualsWithDelta($base['bi'] - 1200, $av['bi'], 0.001);
    }

    public function test_employes_sortis_ou_suspendus_hors_de_la_paie(): void
    {
        $mk = fn ($nom, $d) => Salarie::create($d + ['client_id' => $this->client->id, 'nom' => $nom, 'salaire_base' => 1000]);
        $mk('Actif', []);
        $mk('Sorti en octobre', ['etat_presence' => 'sorti', 'date_sortie' => '2026-10-20']);
        $mk('Suspendu', ['etat_presence' => 'suspendu']);
        $this->assertSame(['Actif', 'Sorti en octobre'], array_column(PaieCalc::mois($this->client, '2026-10'), 'nom'));
        $this->assertSame(['Actif'], array_column(PaieCalc::mois($this->client, '2026-11'), 'nom'));
    }

    public function test_nombre_de_bulletins(): void
    {
        $this->post(route('paie.salaries.store'), $this->fiche(['nb_bulletin' => 2]));
        $html = $this->get(route('paie.bulletins', ['mois' => '2026-11']))->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'BULLETIN DE PAIE'));
    }

    public function test_etats_du_personnel(): void
    {
        $this->post(route('paie.salaries.store'), $this->fiche());
        $this->post(route('paie.salaries.store'), $this->fiche(['matricule' => '0040', 'nom' => 'HADDAD', 'prenom' => 'Amel', 'cin' => '07654321', 'departement' => 'Comptabilité',
            'etat_presence' => 'sorti', 'date_sortie' => '2026-06-30', 'motif_sortie' => 'fin_contrat', 'contrat_fin' => '2026-06-30']));
        foreach (array_keys(EtatsEmployes::ETATS) as $etat) {
            foreach (['imprimer', 'pdf', 'xlsx', 'docx'] as $f) $this->get(route('paie.employes.etat', [$etat, $f, 'statut' => 'tous', 'mois' => '2026-11']))->assertOk();
        }
        $actifs = EtatsEmployes::etat('liste', $this->client, 'actifs', '2026-11');
        $this->assertSame('JELASSI Ridha', $actifs->lignes[0]['cellules'][1]);
        $this->assertCount(2, $actifs->lignes);   // un employé + total
        $dep = EtatsEmployes::etat('par-departement', $this->client, 'tous', '2026-11');
        $this->assertSame(['Comptabilité', 'Recouvrement'], array_values(array_map(fn ($l) => $l['cellules'][0], array_filter($dep->lignes, fn ($l) => $l['style'] === 'groupe'))));
        $sorties = EtatsEmployes::etat('sorties', $this->client, 'tous', '2026-11');
        $this->assertSame('Fin de contrat', $sorties->lignes[0]['cellules'][7]);
        $this->get(route('paie.employes.etat', ['inconnu', 'pdf']))->assertNotFound();
    }

    public function test_documents_rh(): void
    {
        $this->post(route('paie.salaries.store'), $this->fiche(['etat_presence' => 'sorti', 'date_sortie' => '2026-12-31']));
        $e = Salarie::sole();
        $this->get(route('paie.employes.rh', [$e, 'attestation-travail']))->assertOk()->assertSee('Attestation de travail')->assertSee('Sami Ben Ali')->assertSee('employé au sein de notre société');
        $this->get(route('paie.employes.rh', [$e, 'attestation-salaire', 'mois' => '2026-10']))->assertOk()->assertSee('1 500,000 TND');
        $this->get(route('paie.employes.rh', [$e, 'certificat-travail']))->assertOk()->assertSee('31/12/2026')->assertSee('libre de tout engagement');
        $this->get(route('paie.employes.rh', [$e, 'certificat-retenue', 'annee' => 2026]))->assertOk()->assertSee('Certificat de retenue')->assertSee('16 500,000');   // janvier à novembre
        $this->get(route('paie.employes.rh', [$e, 'certificat-retenue', 'annee' => 2026, 'pdf' => 1]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('paie.employes.rh', [$e, 'contrat']))->assertOk()->assertSee('Contrat de travail à durée déterminée')->assertSee('31/12/2026');
        $this->get(route('paie.employes.rh', [$e, 'demission']))->assertOk()->assertSee('Objet : démission');
        $this->get(route('paie.employes.rh', [$e, 'inconnu']))->assertNotFound();
    }

    public function test_suppression_de_la_fiche(): void
    {
        Storage::fake('local');
        $this->post(route('paie.salaries.store'), $this->fiche(['photo' => UploadedFile::fake()->image('p.jpg')]));
        $e = Salarie::sole();
        $chemin = $e->documents()->value('chemin');
        $this->delete(route('paie.salaries.destroy', $e))->assertRedirect(route('paie.index', ['tab' => 'sal']));
        $this->assertSame(0, Salarie::count());
        $this->assertSame(0, SalarieDocument::count());
        Storage::disk('local')->assertMissing($chemin);
    }

    public function test_menu_employe(): void
    {
        $this->get(route('paie.index'))->assertOk()
            ->assertSee('Afficher employé')->assertSee('Historique des modifications')->assertSee('État des catégories et échelons')
            ->assertSee('Attestation de salaire')->assertSee('Certificat de retenue PDF')->assertSee('Démission');
    }
}
