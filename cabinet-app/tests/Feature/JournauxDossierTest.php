<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Ecriture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Journal et écritures → Journaux : journaux ajoutés au dossier, saisie, filtre, modification et retrait. */
class JournauxDossierTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->chef = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']);
        $this->actingAs($this->chef);
        $this->client = Client::factory()->create();
        session(['dossier' => $this->client->id]);
    }

    private function od(string $journal)
    {
        return $this->post(route('compta.od.store'), ['date' => '2026-03-10', 'journal' => $journal, 'libelle' => 'Remise BIAT',
            'lignes' => [['compte' => '5322', 'debit' => 100, 'credit' => ''], ['compte' => '411', 'debit' => '', 'credit' => 100]]]);
    }

    public function test_ajout_saisie_filtre_et_retrait_d_un_journal(): void
    {
        $this->od('BQ2')->assertSessionHasErrors('journal');   // journal inconnu du dossier

        $this->post(route('compta.journaux'), ['code' => ' bq2 ', 'libelle' => 'Banque BIAT'])->assertSessionHas('ok');
        $this->post(route('compta.journaux'), ['code' => 'BQ2', 'libelle' => 'Doublon'])->assertSessionHasErrors('code');
        $this->post(route('compta.journaux'), ['code' => 'OD', 'libelle' => 'Doublon standard'])->assertSessionHasErrors('code');
        $this->post(route('compta.journaux'), ['code' => 'B Q', 'libelle' => 'Espace'])->assertSessionHasErrors('code');
        $this->assertSame('Banque BIAT', Ecriture::journaux($this->client->id)['BQ2']);
        $this->assertArrayNotHasKey('BQ2', Ecriture::journaux(Client::factory()->create()->id));   // propre au dossier
        $this->assertArrayNotHasKey('PAI', Ecriture::journauxSaisie($this->client->id));

        $this->get(route('compta.index', ['tab' => 'jnl', 'annee' => 2026]))->assertOk()->assertSee('Journaux du dossier')
            ->assertSee('Banque BIAT')->assertSee('BQ2 — Banque BIAT')->assertSee('Amortissements');

        $this->od('BQ2')->assertSessionHas('ok');
        $e = Ecriture::where('journal', 'BQ2')->firstOrFail();
        $this->get(route('compta.index', ['tab' => 'jnl', 'annee' => 2026, 'journal' => 'BQ2']))->assertSee('Remise BIAT');
        $this->get(route('compta.index', ['tab' => 'jnl', 'annee' => 2026, 'journal' => 'OD']))->assertDontSee('Remise BIAT');
        $this->get(route('compta.ecritures.show', $e))->assertOk()->assertSee('journal Banque BIAT');

        // Un journal qui a des écritures ne peut pas être retiré ; l'écriture peut passer dans un autre journal.
        $this->post(route('compta.journaux'), ['retirer' => 'BQ2'])->assertSessionHas('err');
        $this->put(route('compta.ecritures.update', $e), ['date' => '2026-03-10', 'journal' => 'BQ', 'libelle' => 'Remise BIAT',
            'lignes' => [['compte' => '5322', 'debit' => 100, 'credit' => 0], ['compte' => '411', 'debit' => 0, 'credit' => 100]]])->assertSessionHas('ok');
        $this->assertSame('BQ', $e->fresh()->journal);
        $this->post(route('compta.journaux'), ['retirer' => 'BQ2'])->assertSessionHas('ok');
        $this->assertArrayNotHasKey('BQ2', Ecriture::journaux($this->client->id));
    }

    public function test_collaborateur_ne_gere_pas_les_journaux(): void
    {
        $collab = User::factory()->create(['cabinet_id' => $this->chef->cabinet_id, 'role' => 'collaborateur', 'status' => 'active']);
        $this->actingAs($collab)->post(route('compta.journaux'), ['code' => 'BQ2', 'libelle' => 'Banque BIAT']);
        $this->assertSame([], Ecriture::journauxAjoutes($this->client->id));
    }
}
