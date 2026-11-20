<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PaieProfil;
use App\Models\PaieRubrique;
use App\Models\Salarie;
use App\Models\User;
use App\Services\PaieCalc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Paie, menu Profil : profils standard, effet sur la paie et les déclarations, fiche profil et rubriques du profil. */
class PaieProfilsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'chef', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha Conseil']);
        session(['dossier' => $this->client->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employe(string $nom, ?string $profil): Salarie
    {
        return Salarie::create(['client_id' => $this->client->id, 'nom' => $nom, 'salaire_base' => 1000, 'profil' => $profil]);
    }

    private function resultat(Salarie $e): array
    {
        return collect(PaieCalc::mois($this->client, '2026-11', true))->firstWhere('salarie_id', $e->id);
    }

    public function test_profils_standard(): void
    {
        $this->get(route('paie.profils'))->assertOk()->assertSee('Exo 2 ans')->assertSee('DIGNITE')->assertSee('Rubriques');
        $p = PaieProfil::where('client_id', $this->client->id)->get()->keyBy('nom');
        $this->assertSame(['CIVP', 'DIGNITE', 'Exo 2 ans', 'Gérant', 'Ordinaire'], $p->keys()->sort()->values()->all());
        $this->assertNull($p['Ordinaire']->cnss_patronale);
        $this->assertSame('0000', $p['Ordinaire']->code_exploitant);
        $this->assertNull($p['DIGNITE']->cnss_patronale);
        $this->assertTrue($p['DIGNITE']->contrat_dignite && $p['DIGNITE']->decl_cnss && ! $p['DIGNITE']->soumis_tfp_fop);
        $this->assertTrue($p['Exo 2 ans']->contrat_exo2ans && $p['Exo 2 ans']->soumis_tfp_fop);
        $this->assertSame('0337', $p['Exo 2 ans']->code_exploitant);
        $this->assertFalse($p['Gérant']->decl_cnss);
        $this->assertTrue($p['Gérant']->decl_employeur);
        $this->assertFalse($p['CIVP']->decl_cnss || $p['CIVP']->decl_employeur || $p['CIVP']->soumis_tfp_fop);
        // Un profil standard supprimé ne revient pas.
        $p['CIVP']->delete();
        $this->get(route('paie.profils'))->assertOk();
        $this->assertSame(4, PaieProfil::where('client_id', $this->client->id)->count());
    }

    public function test_effet_du_profil_sur_la_paie(): void
    {
        PaieProfil::initialiserStandard($this->client);
        $P = PaieCalc::parametres($this->client);
        $sans = $this->employe('Sans Profil', null);
        $ord = $this->employe('Olfa Ordinaire', 'Ordinaire');
        $ger = $this->employe('Ghazi Gerant', 'gérant');                 // casse indifférente
        $civp = $this->employe('Chiraz Civp', 'CIVP');
        $dig = $this->employe('Dali Dignite', 'DIGNITE');
        $exo = $this->employe('Emna Exo', 'Exo 2 ans');

        $x0 = $this->resultat($sans);
        $xo = $this->resultat($ord);
        foreach (['brut', 'cnssS', 'cnssP', 'tfp', 'fop', 'net'] as $k) $this->assertEquals($x0[$k], $xo[$k], $k);
        $this->assertSame('Ordinaire', $xo['profil']);
        $this->assertSame('0000', $xo['code_exploitant']);

        $x = $this->resultat($ger);
        $this->assertEquals(0, $x['cnssS']);
        $this->assertEquals(0, $x['cnssP']);
        $this->assertGreaterThan(0, $x['tfp']);
        $this->assertGreaterThan($x0['net'], $x['net']);

        $x = $this->resultat($civp);
        $this->assertEquals(0, $x['cnssS'] + $x['cnssP'] + $x['tfp'] + $x['fop']);

        // Contrat Dignité : CNSS sur la fraction au-delà de 600 DT, aux taux du régime général ; 400 DT exonérés d'IRPP.
        $x = $this->resultat($dig);
        $this->assertEqualsWithDelta(r3(400 * $P['cnssS'] / 100), $x['cnssS'], 0.001);
        $this->assertEqualsWithDelta(r3(400 * $P['cnssP'] / 100), $x['cnssP'], 0.001);
        $this->assertEquals(0, $x['tfp'] + $x['fop']);
        $this->assertEquals(600.0, $x['brut_imp']);
        $this->assertEquals(400.0, $x['base_cotisee']);

        $x = $this->resultat($exo);
        $this->assertEqualsWithDelta(10, $x['cnssP'], 0.001);
        $this->assertGreaterThan(0, $x['tfp']);

        // Écriture de paie toujours équilibrée.
        $L = PaieCalc::lignes(PaieCalc::totaux(PaieCalc::mois($this->client, '2026-11', true)));
        $this->assertEqualsWithDelta(array_sum(array_column($L, 'debit')), array_sum(array_column($L, 'credit')), 0.002);
    }

    public function test_declarations_selon_le_profil(): void
    {
        PaieProfil::initialiserStandard($this->client);
        $this->employe('Olfa Ordinaire', 'Ordinaire');
        $this->employe('Ghazi Gerant', 'Gérant');
        $this->employe('Chiraz Civp', 'CIVP');
        $this->post(route('paie.cloturer'), ['mois' => '2026-11'])->assertSessionHas('ok');

        $this->get(route('paie.edition', ['dcnss', 'imprimer', 'mois' => '2026-11']))->assertOk()
            ->assertSee('OLFA ORDINAIRE')->assertDontSee('GHAZI GERANT')->assertDontSee('CHIRAZ CIVP');
        $this->get(route('paie.edition', ['demp', 'imprimer', 'mois' => '2026-11']))->assertOk()
            ->assertSee('Olfa Ordinaire')->assertSee('Ghazi Gerant')->assertDontSee('Chiraz Civp');
        $this->get(route('paie.edition', ['journal', 'imprimer', 'mois' => '2026-11']))->assertOk()
            ->assertSee('Olfa Ordinaire')->assertSee('Ghazi Gerant')->assertSee('Chiraz Civp');
    }

    public function test_fiche_profil_rubriques_renommage_et_suppression(): void
    {
        $this->get(route('paie.rubriques'))->assertOk();                 // rubriques et profils standard
        $aid = PaieRubrique::firstWhere('nom', 'Prime de Aid El Idha');
        $douche = PaieRubrique::firstWhere('nom', 'Prime de douche');
        $ticket = PaieRubrique::firstWhere('nom', 'Ticket Restaurant');
        $toutes = PaieRubrique::where('client_id', $this->client->id)->pluck('id')->all();

        // Nouveau profil : seulement la prime de l'Aïd.
        $this->post(route('paie.profils.store'), ['nom' => 'Saisonnier', 'cnss_patronale' => '8.5', 'code_exploitant' => '0101', 'decl_cnss' => '1', 'rubriques' => [$aid->id]])
            ->assertSessionHasNoErrors();
        $s = PaieProfil::firstWhere('nom', 'Saisonnier');
        $this->assertEquals(8.5, $s->cnss_patronale);
        $this->assertTrue($s->decl_cnss);
        $this->assertFalse($s->soumis_tfp_fop);
        $this->assertTrue($aid->fresh()->pourProfil('Saisonnier'));
        $this->assertTrue($aid->fresh()->pourProfil('Ordinaire'));        // reste « tous les autres profils »
        $this->assertFalse($douche->fresh()->pourProfil('Saisonnier'));
        $this->assertTrue($douche->fresh()->pourProfil('Gérant'));

        $e = $this->employe('Sami Saison', 'Saisonnier');
        $this->assertTrue($aid->fresh()->concerne($e));
        $this->assertFalse($douche->fresh()->concerne($e));

        // Doublon de nom refusé.
        $this->post(route('paie.profils.store'), ['nom' => 'Saisonnier'])->assertSessionHasErrors('nom');

        // Ordinaire : toutes les rubriques sauf le ticket restaurant.
        $ord = PaieProfil::firstWhere('nom', 'Ordinaire');
        $this->put(route('paie.profils.update', $ord), ['nom' => 'Ordinaire', 'decl_cnss' => '1', 'decl_employeur' => '1', 'soumis_tfp_fop' => '1',
            'rubriques' => array_values(array_diff($toutes, [$ticket->id]))])->assertSessionHasNoErrors();
        $this->assertFalse($ticket->fresh()->pourProfil('Ordinaire'));
        $this->assertTrue($ticket->fresh()->pourProfil('CIVP'));
        $this->get(route('paie.profils', ['p' => $ord->id]))->assertOk()->assertSee('Ticket Restaurant');

        // Renommage : suit sur les fiches et dans les rubriques.
        $this->put(route('paie.profils.update', $s), ['nom' => 'Saisonnier agricole', 'cnss_patronale' => '8.5', 'rubriques' => [$aid->id]])->assertSessionHasNoErrors();
        $this->assertSame('Saisonnier agricole', $e->fresh()->profil);
        $this->assertTrue($aid->fresh()->pourProfil('Saisonnier agricole'));
        $this->assertFalse($douche->fresh()->pourProfil('Saisonnier agricole'));
        $this->assertNotContains('Saisonnier', (array) $douche->fresh()->profils);

        // Suppression refusée tant qu'un employé a le profil.
        $s->refresh();
        $this->delete(route('paie.profils.destroy', $s))->assertSessionHas('err');
        $e->update(['profil' => 'Ordinaire']);
        $this->delete(route('paie.profils.destroy', $s))->assertSessionHas('ok');
        $this->assertModelMissing($s);
        $this->assertNotContains('Saisonnier agricole', (array) $aid->fresh()->profils);
    }

    public function test_sauvegarde_et_restauration_des_profils(): void
    {
        PaieProfil::initialiserStandard($this->client);
        PaieProfil::create(['client_id' => $this->client->id, 'nom' => 'Jeune Ouvrier', 'cnss_patronale' => 3, 'code_exploitant' => '0222', 'decl_cnss' => true]);
        $json = $this->get(route('paie.sauvegarde'))->assertOk()->streamedContent();
        $this->assertCount(6, json_decode($json, true)['profils']);

        $autre = Client::create(['nom' => 'Beta']);
        session(['dossier' => $autre->id]);
        $f = \Illuminate\Http\UploadedFile::fake()->createWithContent('sauvegarde.json', $json);
        $this->post(route('paie.restaurer'), ['fichier' => $f])->assertSessionHas('ok');
        $jo = PaieProfil::where('client_id', $autre->id)->firstWhere('nom', 'Jeune Ouvrier');
        $this->assertEquals(3, $jo->cnss_patronale);
        $this->assertTrue($jo->decl_cnss);
        $this->assertSame(6, PaieProfil::where('client_id', $autre->id)->count());
    }
}
