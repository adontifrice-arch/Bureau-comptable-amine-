<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Paie;
use App\Models\PaieRubrique;
use App\Models\Salarie;
use App\Models\User;
use App\Services\PaieCalc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Saisie Paie : nombre de tickets restaurant du mois, valorisé à la valeur d'un ticket de la rubrique. */
class PaieTicketsRestaurantTest extends TestCase
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

    public function test_nombre_de_tickets_dans_la_saisie_paie(): void
    {
        $e = Salarie::create(['client_id' => $this->client->id, 'nom' => 'Olfa', 'salaire_base' => 1000]);
        $tk = PaieRubrique::create(['client_id' => $this->client->id, 'code' => 101, 'categorie' => 'avantage', 'nom' => 'Ticket Restaurant',
            'mode' => 'montant', 'montant' => 6.5, 'ticket_restaurant' => true]);

        // Sans ticket saisi : la rubrique n'est pas payée ; la colonne est proposée dans la grille.
        $this->assertEmpty(collect(PaieCalc::mois($this->client, '2026-11', true))->first()['rubriques']);
        $this->get(route('paie.index', ['tab' => 'calc', 'mois' => '2026-11']))->assertOk()->assertSee('Tickets resto')->assertSee('tickets['.$e->id.']['.$tk->id.']', false);

        $this->post(route('paie.calcul'), ['mois' => '2026-11', 'parties' => ['saisie'], 'tab' => 'calc', 'jours_ouvrables' => 26, 'heures_ouvrables' => 208,
            'tickets' => [$e->id => [$tk->id => 22]]])->assertRedirect();
        $this->assertEquals(22, Paie::where('client_id', $this->client->id)->first()->rubriques[$e->id][$tk->id]);

        $l = collect(collect(PaieCalc::mois($this->client, '2026-11', true))->first()['rubriques'])->firstWhere('id', $tk->id);
        $this->assertEquals(22, $l['quantite']);
        $this->assertEqualsWithDelta(143.0, $l['montant'], 0.001);
        $this->assertTrue($l['nature']);

        // Vider la case retire les tickets du mois.
        $this->post(route('paie.calcul'), ['mois' => '2026-11', 'parties' => ['saisie'], 'tab' => 'calc', 'jours_ouvrables' => 26, 'heures_ouvrables' => 208,
            'tickets' => [$e->id => [$tk->id => '']]])->assertRedirect();
        $this->assertEmpty(collect(PaieCalc::mois($this->client, '2026-11', true))->first()['rubriques']);
    }

    public function test_sans_valeur_de_ticket_pas_de_colonne(): void
    {
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Olfa', 'salaire_base' => 1000]);
        // Rubrique standard sans valeur de ticket : pas de colonne.
        PaieRubrique::create(['client_id' => $this->client->id, 'code' => 101, 'categorie' => 'avantage', 'nom' => 'Ticket Restaurant', 'mode' => 'manuelle', 'ticket_restaurant' => true]);
        $this->get(route('paie.index', ['tab' => 'calc', 'mois' => '2026-11']))->assertOk()->assertDontSee('Tickets resto');
    }
}
