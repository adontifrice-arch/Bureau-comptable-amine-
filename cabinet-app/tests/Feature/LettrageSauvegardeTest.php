<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Compte;
use App\Models\Ecriture;
use App\Models\EcritureLigne;
use App\Models\User;
use App\Services\Compta;
use App\Services\Lettrage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Lettrage et lettrages invalides, transfert de compte à compte, sauvegarde et restauration de la comptabilité. */
class LettrageSauvegardeTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;
    private Client $client;
    private Ecriture $facture;
    private Ecriture $reglement;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->chef = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']);
        $this->actingAs($this->chef);
        $this->client = Client::factory()->create(['nom' => 'Société Alpha']);
        session(['dossier' => $this->client->id]);
        $p = fn ($date, $lib, $l, $jnl = 'OD') => Compta::passer($this->client->id, $date, $jnl, $lib, $l, null, $this->chef->id);
        $this->facture = $p('2026-01-10', 'Facture F1', [['compte' => '6061', 'debit' => 500], ['compte' => '401001', 'credit' => 500]], 'ACH');
        $this->reglement = $p('2026-02-10', 'Règlement F1', [['compte' => '401001', 'debit' => 500], ['compte' => '532', 'credit' => 500]], 'BQ');
        $p('2026-03-10', 'Facture F2', [['compte' => '6061', 'debit' => 200], ['compte' => '401001', 'credit' => 200]], 'ACH');
    }

    private function ligne(Ecriture $e, string $compte): EcritureLigne
    {
        return $e->lignes()->where('compte', $compte)->firstOrFail();
    }

    public function test_lettrage_et_lettrages_invalides(): void
    {
        $f = $this->ligne($this->facture, '401001'); $r = $this->ligne($this->reglement, '401001');
        $f2 = Ecriture::where('libelle', 'Facture F2')->first()->lignes()->where('compte', '401001')->first();

        // Déséquilibré : refusé
        $this->post(route('compta.lettrage'), ['lignes' => [$f->id, $f2->id]])->assertSessionHas('err', fn ($m) => str_contains($m, 'débits'));
        $this->post(route('compta.lettrage'), ['lignes' => [$f->id, $r->id]])->assertSessionHas('ok', fn ($m) => str_contains($m, '« A »'));
        $this->assertSame(['A', 'A'], [$f->fresh()->lettre, $r->fresh()->lettre]);
        $this->assertSame('B', Lettrage::suivante($this->client->id, '401001'));
        $this->post(route('compta.lettrage'), ['lignes' => [$f->id, $r->id]])->assertSessionHas('err', fn ($m) => str_contains($m, 'déjà lettrées'));
        $this->assertCount(0, Lettrage::invalides($this->client->id));

        $this->get(route('compta.index', ['tab' => 'gl', 'annee' => 2026, 'compte' => '401001', 'exact' => 1]))->assertOk()
            ->assertSee('Lettrer la sélection')->assertSee('name="lignes[]" value="'.$f2->id.'"', false)->assertDontSee('name="lignes[]" value="'.$f->id.'"', false);
        $this->get(route('compta.index', ['tab' => 'gl', 'annee' => 2026, 'compte' => '401001', 'exact' => 1, 'lettrage' => 'non']))->assertSee('Facture F2')->assertDontSee('Règlement F1');

        // Modification d'une ligne lettrée : la lettre est perdue, le lettrage devient invalide ; une ligne inchangée garde sa lettre
        $this->put(route('compta.ecritures.update', $this->reglement), ['date' => '2026-02-10', 'journal' => 'BQ', 'libelle' => 'Règlement F1',
            'lignes' => [['compte' => '401001', 'debit' => 450, 'credit' => 0], ['compte' => '532', 'debit' => 0, 'credit' => 450]]])->assertSessionHas('ok');
        $inv = Lettrage::invalides($this->client->id);
        $this->assertSame([['401001', 'A', 1]], $inv->map(fn ($g) => [$g['compte'], $g['lettre'], $g['lignes']])->all());
        $this->assertContains('une seule ligne lettrée', $inv[0]['motifs']);
        $this->get(route('compta.index', ['tab' => 'gl', 'annee' => 2026, 'vue' => 'invalides']))->assertOk()->assertSee('Lettrages invalides')->assertSee('non soldé');

        $this->post(route('compta.lettrage.retirer'), ['compte' => '401001', 'lettre' => 'A'])->assertSessionHas('ok');
        $this->assertNull($f->fresh()->lettre);
        $this->assertCount(0, Lettrage::invalides($this->client->id));

        // Une écriture lettrée supprimée rend son lettrage invalide
        $r = $this->ligne($this->reglement->fresh(), '401001');
        $this->put(route('compta.ecritures.update', $this->reglement), ['date' => '2026-02-10', 'journal' => 'BQ', 'libelle' => 'Règlement F1',
            'lignes' => [['compte' => '401001', 'debit' => 500, 'credit' => 0], ['compte' => '532', 'debit' => 0, 'credit' => 500]]]);
        $r = $this->ligne($this->reglement->fresh(), '401001');
        Lettrage::lettrer($this->client->id, [$f->id, $r->id]);
        $this->put(route('compta.ecritures.update', $this->reglement), ['date' => '2026-02-11', 'journal' => 'BQ', 'libelle' => 'Règlement F1 (date corrigée)',
            'lignes' => [['compte' => '401001', 'debit' => 500, 'credit' => 0], ['compte' => '532', 'debit' => 0, 'credit' => 500]]]);
        $this->assertSame('A', $this->ligne($this->reglement->fresh(), '401001')->lettre);       // ligne inchangée : lettre gardée
        $this->post(route('compta.ecritures.annuler', $this->reglement));
        $this->assertStringContainsString('écriture supprimée', implode(' ', Lettrage::invalides($this->client->id)->first()['motifs']));
    }

    public function test_transfert_de_compte_a_compte(): void
    {
        $f = $this->ligne($this->facture, '401001'); $r = $this->ligne($this->reglement, '401001');
        Lettrage::lettrer($this->client->id, [$f->id, $r->id]);
        $this->post(route('compta.transfert'), ['source' => '401001', 'destination' => '401001', 'du' => '2026-01-01', 'au' => '2026-12-31'])->assertSessionHas('err');
        $this->post(route('compta.transfert'), ['source' => '409', 'destination' => '401002', 'du' => '2026-01-01', 'au' => '2026-12-31'])->assertSessionHas('err', fn ($m) => str_contains($m, 'Aucune ligne'));

        // Seul le journal ACH du premier trimestre : la facture F1 (lettrée) et F2 passent sur 401002
        $this->post(route('compta.transfert'), ['source' => '401001', 'destination' => '401002', 'du' => '2026-01-01', 'au' => '2026-03-31', 'journal' => 'ACH'])
            ->assertRedirect(route('compta.index', ['tab' => 'gl', 'annee' => 2026, 'compte' => '401002', 'exact' => 1]))
            ->assertSessionHas('ok', fn ($m) => str_contains($m, '2 ligne(s) transférée(s)'));
        $this->assertSame(['401002', null], [$f->fresh()->compte, $f->fresh()->lettre]);
        $this->assertSame('401001', $r->fresh()->compte);                                   // journal BQ : non transféré
        $B = collect(Compta::balance($this->client->id, 2026))->keyBy('compte');
        $this->assertEquals([700, 0], [$B['401002']['sc'], $B['401001']['sc']]);
        $this->assertEquals(500, $B['401001']['sd']);
        $this->assertSame(['401001', 'A'], [Lettrage::invalides($this->client->id)->first()['compte'], Lettrage::invalides($this->client->id)->first()['lettre']]);

        $collab = User::factory()->create(['cabinet_id' => $this->chef->cabinet_id, 'role' => 'collaborateur', 'status' => 'active']);
        $this->actingAs($collab)->post(route('compta.transfert'), ['source' => '401002', 'destination' => '401003', 'du' => '2026-01-01', 'au' => '2026-12-31'])
            ->assertSessionHas('err', fn ($m) => str_contains($m, 'Seuls'));
    }

    public function test_sauvegarde_et_restauration_du_dossier(): void
    {
        Compte::create(['numero' => '401001', 'libelle' => 'Fournisseur Beta', 'personnalise' => true]);
        \App\Models\Ecriture::enregistrerJournaux($this->client->id, [['code' => 'BQ2', 'libelle' => 'Banque BIAT']]);
        Lettrage::lettrer($this->client->id, [$this->ligne($this->facture, '401001')->id, $this->ligne($this->reglement, '401001')->id]);
        $this->post(route('compta.ecritures.annuler', Ecriture::where('libelle', 'Facture F2')->first()), ['motif' => 'doublon']);

        $json = $this->get(route('compta.sauvegarde'))->assertOk()->streamedContent();
        $data = json_decode($json, true);
        $this->assertSame('cabinet-compta', $data['format']);
        $this->assertCount(3, $data['ecritures']);
        $this->assertSame([['code' => 'BQ2', 'libelle' => 'Banque BIAT']], $data['reglages']['journaux']);

        // Restauration dans un nouveau dossier
        $autre = Client::factory()->create(['nom' => 'Société Copie']);
        session(['dossier' => $autre->id]);
        $fichier = UploadedFile::fake()->createWithContent('sauvegarde.json', $json);
        $this->post(route('compta.sauvegarde.restaurer'), ['fichier' => $fichier])->assertSessionHas('ok', fn ($m) => str_contains($m, '3 écriture(s) restaurée(s)'));
        $this->assertSame(2, Ecriture::where('client_id', $autre->id)->count());
        $this->assertSame('doublon', Ecriture::onlyTrashed()->where('client_id', $autre->id)->value('motif_suppression'));
        $this->assertSame(2, EcritureLigne::whereIn('ecriture_id', Ecriture::where('client_id', $autre->id)->pluck('id'))->where('lettre', 'A')->count());
        $this->assertSame('Banque BIAT', Ecriture::journaux($autre->id)['BQ2']);
        $this->assertEquals(Compta::totaux(Compta::balance($this->client->id, 2026)), Compta::totaux(Compta::balance($autre->id, 2026)));

        // Un dossier qui a des écritures n'est remplacé que sur demande ; l'ancienne comptabilité est d'abord sauvegardée sur le serveur
        \Illuminate\Support\Facades\Storage::fake();
        $this->post(route('compta.sauvegarde.restaurer'), ['fichier' => UploadedFile::fake()->createWithContent('s.json', $json)])->assertSessionHas('err', fn ($m) => str_contains($m, 'Cochez'));
        $this->post(route('compta.sauvegarde.restaurer'), ['fichier' => UploadedFile::fake()->createWithContent('s.json', $json), 'remplacer' => 1])->assertSessionHas('ok');
        $this->assertSame(3, Ecriture::withTrashed()->where('client_id', $autre->id)->count());
        $this->assertCount(1, \Illuminate\Support\Facades\Storage::files('sauvegardes'));
        $this->post(route('compta.sauvegarde.restaurer'), ['fichier' => UploadedFile::fake()->createWithContent('x.json', '{"format":"autre"}')])->assertSessionHas('err');

        // Copie de la base : un seul cabinet ; refusée au collaborateur
        $this->get(route('compta.index', ['tab' => 'jnl']))->assertSee('Télécharger la copie de la base');
        // Base de test en mémoire et dans une transaction : ni VACUUM ni fichier à copier → message, pas d'erreur
        $this->get(route('compta.sauvegarde.base'))->assertSessionHas('err', fn ($m) => str_contains($m, 'n’a pas pu être faite'));
        $collab = User::factory()->create(['cabinet_id' => $this->chef->cabinet_id, 'role' => 'collaborateur', 'status' => 'active']);
        $this->actingAs($collab)->get(route('compta.sauvegarde.base'))->assertStatus(302);
        $this->actingAs($collab)->post(route('compta.sauvegarde.restaurer'), ['fichier' => UploadedFile::fake()->createWithContent('s.json', $json), 'remplacer' => 1]);
        $this->assertSame(3, Ecriture::withTrashed()->where('client_id', $autre->id)->count());
    }

    public function test_copie_de_la_base_refusee_avec_plusieurs_cabinets(): void
    {
        Cabinet::creer('Autre cabinet');
        $this->get(route('compta.sauvegarde.base'))->assertSessionHas('err', fn ($m) => str_contains($m, 'plusieurs cabinets'));
    }
}
