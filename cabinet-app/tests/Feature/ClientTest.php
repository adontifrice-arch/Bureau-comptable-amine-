<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Echeance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Module Clients — spécification §3.2 (fiche, gérant, associés) et §3.3 (acomptes provisionnels). */
class ClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-01-10 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fiche(array $extra = []): array
    {
        return array_merge([
            'nom' => 'Alpha Conseil', 'forme' => 'SARL', 'type_personne' => 'morale', 'matricule_fiscal' => '1234567/a/m/000', 'compte_comptable' => '411001',
            'nb_parts' => 100, 'valeur_nominale' => 10, 'capital' => '',
            'gerant_nom' => 'Sami Ben Ali', 'gerant_associe' => '1', 'gerant_parts' => 60, 'gerant_parts_de' => 1, 'gerant_parts_a' => 60, 'gerant_apport' => 'numeraire',
            'associes' => [
                ['nom' => 'Leila Trabelsi', 'cin' => '01234567', 'carte_sejour' => '', 'passeport' => 'X123', 'passeport_date' => '2020-01-01', 'passeport_validite' => '2030-01-01',
                    'parts' => '', 'parts_de' => 61, 'parts_a' => 100, 'apport' => 'nature'],
                ['nom' => '   ', 'parts' => ''],   // ligne vide du formulaire : ignorée
            ],
        ], $extra);
    }

    public function test_un_visiteur_non_connecte_est_redirige(): void
    {
        auth()->logout();
        $this->get(route('clients.index'))->assertRedirect(route('login'));
    }

    public function test_creation_complete_avec_valeurs_deduites(): void
    {
        $this->post(route('clients.store'), $this->fiche())->assertSessionHasNoErrors()->assertRedirect();

        $c = Client::with('associes')->sole();
        $this->assertSame('1234567/A/M/000', $c->matricule_fiscal);
        $this->assertEquals(1000, $c->capital);                       // 100 parts × 10 TND
        $this->assertTrue($c->gerant_associe);
        $this->assertCount(1, $c->associes);
        $a = $c->associes->first();
        $this->assertSame(40, $a->parts);                             // déduit de la plage 61 à 100
        $this->assertSame('nature', $a->apport);
        $this->assertSame('2030-01-01', $a->passeport_validite->format('Y-m-d'));

        $this->get(route('clients.show', $c))->assertOk()->assertSee('Leila Trabelsi')->assertSee('Personne morale');
        $this->get(route('clients.edit', $c))->assertOk()->assertSee('Passeport valable jusqu’au');
        $this->get(route('clients.index', ['q' => 'Alpha']))->assertOk()->assertSee('Alpha Conseil');
    }

    public function test_acomptes_provisionnels_sur_cinq_ans_le_28_pour_une_personne_morale(): void
    {
        $this->post(route('clients.store'), $this->fiche())->assertRedirect();
        $ap = Echeance::where('auto', 'ap')->orderBy('date_echeance')->get();

        $this->assertCount(15, $ap);                                  // 3 par an, 2026 à 2030
        $this->assertSame('2026-06-28', $ap->first()->date_echeance->format('Y-m-d'));
        $this->assertSame('2030-12-28', $ap->last()->date_echeance->format('Y-m-d'));
    }

    public function test_le_type_de_personne_est_obligatoire(): void
    {
        $this->post(route('clients.store'), $this->fiche(['type_personne' => '']))
            ->assertSessionHasErrors(['type_personne' => 'Cochez « Personne physique » ou « Personne morale ».']);
        $this->assertSame(0, Client::count());
    }

    public function test_le_formulaire_demande_d_abord_la_nature_du_client(): void
    {
        $this->get(route('clients.create'))->assertOk()
            ->assertSee('Nouveau client : personne physique ou personne morale ?')
            ->assertSee('Catégories de revenus')->assertSee('Revenus fonciers');
    }

    public function test_une_personne_physique_a_des_categories_de_revenus_sans_capital_ni_gerant_ni_associes(): void
    {
        $this->post(route('clients.store'), $this->fiche(['type_personne' => 'physique', 'forme' => '']))
            ->assertSessionHasErrors(['categories_revenus' => 'Cochez au moins une catégorie de revenus.']);

        // Les rubriques de société restées dans le formulaire sont ignorées (même incohérentes).
        $this->post(route('clients.store'), $this->fiche(['type_personne' => 'physique', 'forme' => '', 'nom' => 'Ben Ali Sami', 'activite' => 'Pharmacien',
            'categories_revenus' => ['bic', 'fonciers'], 'regime_imposition' => 'reel', 'capital' => 5000, 'gerant_cin' => '01234567']))
            ->assertSessionHasNoErrors();

        $c = Client::sole();
        $this->assertSame(['bic', 'fonciers'], $c->categories_revenus);
        $this->assertSame(['Pharmacien', 'reel', 'Personne physique', '01234567'], [$c->activite, $c->regime_imposition, $c->forme, $c->gerant_cin]);
        $this->assertNull($c->gerant_nom);
        $this->assertNull($c->nb_parts);
        $this->assertEquals(0, $c->capital);
        $this->assertSame(0, $c->associes()->count());
        $this->get(route('clients.show', $c))->assertSee('Bénéfices industriels et commerciaux (BIC)')->assertSee('Revenus fonciers')->assertDontSee('Aucun associé renseigné');
    }

    public function test_pieces_d_identite_selon_la_nationalite(): void
    {
        $f = $this->fiche(['gerant_nationalite' => 'Tunisienne', 'gerant_cin' => '07654321', 'gerant_passeport' => 'P999', 'gerant_carte_sejour' => 'CS1']);
        $f['associes'] = [
            ['nom' => 'Jean Martin', 'nationalite' => 'Française', 'cin' => '01234567', 'passeport' => '19AB123', 'carte_sejour' => 'CS2', 'parts_de' => 61, 'parts_a' => 100],
        ];
        $this->post(route('clients.store'), $f)->assertSessionHasNoErrors();

        $c = Client::sole();
        $this->assertSame(['07654321', null, null], [$c->gerant_cin, $c->gerant_passeport, $c->gerant_carte_sejour]);   // Tunisien : CIN seule
        $a = $c->associes()->sole();
        $this->assertSame([null, '19AB123', 'CS2'], [$a->cin, $a->passeport, $a->carte_sejour]);                      // étranger : passeport et carte de séjour
        $this->get(route('clients.create'))->assertSee('Tunisienne : carte d’identité', false);
    }

    public function test_cadre_legal_de_l_activite(): void
    {
        $vides = array_fill(0, 3, ['cadre' => '', 'organisme' => '', 'numero' => '', 'date_declaration' => '', 'date_entree' => '']);
        $cadres = [
            ['cadre' => '01', 'organisme' => 'API', 'numero' => ' 2024-0457 ', 'date_declaration' => '2024-02-15', 'date_entree' => '2024-06-01'],
            ...$vides,
            ['cadre' => '13', 'organisme' => '', 'numero' => '', 'date_declaration' => '', 'date_entree' => '2020-01-01'],
        ];
        $this->post(route('clients.store'), $this->fiche(['cadres_legaux' => $cadres]))->assertSessionHasNoErrors();

        $c = Client::sole();
        $this->assertCount(2, $c->cadres_legaux);   // lignes vides ignorées
        $this->assertSame(['API', '2024-0457', '2024-02-15'], [$c->cadres_legaux[0]['organisme'], $c->cadres_legaux[0]['numero'], $c->cadres_legaux[0]['date_declaration']]);
        $this->get(route('clients.show', $c))->assertSee('API n° 2024-0457')->assertSee('15/02/2024')->assertSee('01 - Exportation totale')->assertSee('13 - Encouragement des petites et moyennes entreprises');
        $this->get(route('clients.edit', $c))->assertSee('Cadre légal de l’activité de l’entreprise', false)->assertSee('value="2024-0457"', false);

        // Une ligne renseignée doit indiquer son cadre légal ; l'organisme est contrôlé ; 5 lignes au plus.
        $this->put(route('clients.update', $c), $this->fiche(['cadres_legaux' => [['cadre' => '', 'numero' => '12']]]))
            ->assertSessionHasErrors(['cadres_legaux.0.cadre' => 'Choisissez le cadre légal de chaque ligne renseignée.']);
        $this->put(route('clients.update', $c), $this->fiche(['cadres_legaux' => [['cadre' => 'X', 'organisme' => 'CNSS']]]))->assertSessionHasErrors('cadres_legaux.0.organisme');
        $this->put(route('clients.update', $c), $this->fiche(['cadres_legaux' => array_fill(0, 6, ['cadre' => '05'])]))->assertSessionHasErrors('cadres_legaux');
    }

    public function test_une_personne_morale_n_a_pas_de_categories_de_revenus(): void
    {
        $this->post(route('clients.store'), $this->fiche(['categories_revenus' => ['bnc'], 'regime_imposition' => 'reel']))->assertSessionHasNoErrors();
        $this->assertNull(Client::sole()->categories_revenus);
        $this->assertNull(Client::sole()->regime_imposition);
    }

    public function test_acomptes_au_25_pour_une_personne_physique(): void
    {
        $this->post(route('clients.store'), ['nom' => 'Dr Karim Jaziri', 'forme' => 'Libéral (BNC)', 'type_personne' => 'physique', 'categories_revenus' => ['bnc']])->assertSessionHasNoErrors();

        $this->assertTrue(Client::sole()->estPhysique());
        $this->assertSame([25], Echeance::where('auto', 'ap')->get()->map(fn ($e) => $e->date_echeance->day)->unique()->values()->all());
    }

    public function test_changement_de_nature_regenere_les_acomptes_non_coches_sans_recreer_les_supprimes(): void
    {
        $this->post(route('clients.store'), $this->fiche())->assertRedirect();
        $c = Client::sole();
        $fait = Echeance::where('intitule', '1er acompte provisionnel 2026')->sole();
        $fait->update(['accomplie_le' => '2026-06-20']);
        Echeance::where('intitule', '2e acompte provisionnel 2026')->sole()->delete();

        $this->put(route('clients.update', $c), $this->fiche(['type_personne' => 'physique', 'categories_revenus' => ['bic']]))->assertSessionHasNoErrors();

        $this->assertSame('2026-06-28', $fait->fresh()->date_echeance->format('Y-m-d'));      // cochée : inchangée
        $this->assertSame('2026-12-25', Echeance::where('intitule', '3e acompte provisionnel 2026')->sole()->date_echeance->format('Y-m-d'));
        $this->assertNull(Echeance::where('intitule', '2e acompte provisionnel 2026')->first()); // suppression définitive
        $this->assertSame(14, Echeance::where('auto', 'ap')->count());
    }

    public function test_capital_incoherent_refuse(): void
    {
        $this->post(route('clients.store'), $this->fiche(['capital' => 5000]))->assertSessionHasErrors('capital');
        $this->assertSame(0, Client::count());
    }

    public function test_plages_de_parts_qui_se_chevauchent_refusees(): void
    {
        $f = $this->fiche();
        $f['associes'][0]['parts_de'] = 50;
        $this->post(route('clients.store'), $f)->assertSessionHasErrors('associes');
    }

    public function test_nombre_de_parts_incoherent_avec_la_plage(): void
    {
        $f = $this->fiche();
        $f['associes'][0]['parts'] = 30;
        $this->post(route('clients.store'), $f)->assertSessionHasErrors('associes.0.parts');
    }

    public function test_plage_inversee_ou_hors_capital_refusee(): void
    {
        $this->post(route('clients.store'), $this->fiche(['gerant_parts_de' => 60, 'gerant_parts_a' => 1, 'gerant_parts' => '']))->assertSessionHasErrors('gerant_parts_a');

        $f = $this->fiche();
        $f['associes'][0]['parts_a'] = 120;
        $f['associes'][0]['parts_de'] = 61;
        $this->post(route('clients.store'), $f)->assertSessionHasErrors('associes.0.parts_a');
    }

    public function test_total_des_parts_superieur_au_capital_refuse(): void
    {
        $f = $this->fiche();
        $f['associes'][0] = ['nom' => 'Leila Trabelsi', 'parts' => 50, 'apport' => 'numeraire'];
        $this->post(route('clients.store'), $f)->assertSessionHasErrors('nb_parts');
    }

    public function test_matricule_fiscal_et_compte_comptable_controles(): void
    {
        $this->post(route('clients.store'), $this->fiche(['matricule_fiscal' => '12345A']))->assertSessionHasErrors('matricule_fiscal');
        $this->post(route('clients.store'), $this->fiche(['compte_comptable' => '401001']))->assertSessionHasErrors('compte_comptable');
        $this->post(route('clients.store'), $this->fiche(['matricule_fiscal' => '1234567A']))->assertSessionHasNoErrors();
    }

    public function test_gerant_non_associe_efface_ses_parts(): void
    {
        $f = $this->fiche(['gerant_associe' => '0']);
        $f['associes'][0]['parts_de'] = 1;
        $f['associes'][0]['parts_a'] = 100;
        $this->post(route('clients.store'), $f)->assertSessionHasNoErrors();

        $c = Client::sole();
        $this->assertFalse($c->gerant_associe);
        $this->assertNull($c->gerant_parts);
        $this->assertSame(100, $c->associes->first()->parts);
    }

    public function test_modification_remplace_les_associes(): void
    {
        $this->post(route('clients.store'), $this->fiche())->assertRedirect();
        $c = Client::sole();
        $f = $this->fiche(['nom' => 'Alpha Conseil SARL']);
        $f['associes'] = [['nom' => 'Nadia Gharbi', 'parts_de' => 61, 'parts_a' => 100]];

        $this->put(route('clients.update', $c), $f)->assertSessionHasNoErrors()->assertRedirect(route('clients.show', $c));

        $c->refresh();
        $this->assertSame('Alpha Conseil SARL', $c->nom);
        $this->assertSame(['Nadia Gharbi'], $c->associes->pluck('nom')->all());
    }

    public function test_suppression_du_client_et_de_ses_donnees(): void
    {
        $this->post(route('clients.store'), $this->fiche())->assertRedirect();
        $c = Client::sole();

        $this->delete(route('clients.destroy', $c))->assertRedirect(route('clients.index'));

        $this->assertSame(0, Client::count());
        $this->assertSame(0, Echeance::withTrashed()->count());
        $this->assertDatabaseCount('associes', 0);
    }
}
