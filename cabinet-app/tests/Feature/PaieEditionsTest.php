<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Salarie;
use App\Models\User;
use App\Services\Exports\EtatsPaie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Module Paie : barre de menus et éditions (bulletins A4/A5, journal, CNSS, déclaration employeur, virement, bordereau). */
class PaieEditionsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha Conseil']);
        session(['dossier' => $this->client->id]);
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Karim', 'salaire_base' => 1000, 'mode_paiement' => 'virement', 'rib' => '12345678901234567890']);
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Amel', 'salaire_base' => 800, 'mode_paiement' => 'especes']);
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Nouveau', 'salaire_base' => 500, 'date_embauche' => '2026-11-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_toutes_les_editions_dans_tous_les_formats(): void
    {
        $this->get(route('paie.index'))->assertOk()->assertSee('Solde de tout compte')->assertSee('Journal global');
        foreach (['a4', 'a5'] as $f) $this->get(route('paie.bulletins', ['mois' => '2026-10', 'format' => $f]))->assertOk()->assertSee('BULLETIN DE PAIE');
        foreach (array_keys(EtatsPaie::EDITIONS) as $e) {
            foreach (['imprimer', 'pdf', 'xlsx', 'docx'] as $f) $this->get(route('paie.edition', [$e, $f, 'mois' => '2026-10']))->assertOk();
        }
        $this->get(route('paie.edition', ['inconnu', 'pdf']))->assertNotFound();
    }

    public function test_declaration_cnss_trimestrielle_et_embauche_en_cours_de_trimestre(): void
    {
        $e = EtatsPaie::dcnss($this->client, '2026-10');
        $total = end($e->lignes)['cellules'];
        // Octobre : 1000 + 800 ; novembre : + l'embauché du 1er novembre ; décembre : mois futur sans paie.
        $this->assertSame([1800.0, 2300.0, 0.0, 4100.0], array_slice($total, 4));
    }

    public function test_ordre_de_virement_ne_reprend_que_les_salaries_payes_par_virement(): void
    {
        $e = EtatsPaie::virement($this->client, '2026-10');
        $this->assertCount(2, $e->lignes);   // Karim + total
        $this->assertSame('Karim', $e->lignes[0]['cellules'][1]);
    }
}
