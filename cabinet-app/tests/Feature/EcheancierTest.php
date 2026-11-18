<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\User;
use App\Services\Compta;
use App\Services\Echeancier;
use App\Services\Lettrage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Échéancier au 30/06/2026, délais de 30 jours :
 * - fournisseur 401001 : F1 du 10/01 (500, échue le 09/02 → plus de 90 j), F2 du 10/03 (200, échue le 09/04 → 61 à 90 j),
 *   F3 du 20/06 (100, échéance 20/07 → à échoir sous 30 j), acompte versé non lettré 150 ;
 * - client 411001 : facture du 10/06 (300, échéance 10/07), facture du 15/01 lettrée avec son règlement (absente).
 */
class EcheancierTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->actingAs(User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']));
        $this->client = Client::factory()->create();
        session(['dossier' => $this->client->id]);
        $p = fn ($d, $lib, $l) => Compta::passer($this->client->id, $d, 'OD', $lib, $l);
        $p('2026-01-10', 'Facture F1', [['compte' => '6061', 'debit' => 500], ['compte' => '401001', 'credit' => 500]]);
        $p('2026-03-10', 'Facture F2', [['compte' => '6061', 'debit' => 200], ['compte' => '401001', 'credit' => 200]]);
        $p('2026-06-20', 'Facture F3', [['compte' => '6061', 'debit' => 100], ['compte' => '401001', 'credit' => 100]]);
        $p('2026-05-02', 'Acompte fournisseur', [['compte' => '401001', 'debit' => 150], ['compte' => '532', 'credit' => 150]]);
        $p('2026-06-10', 'Facture client V2', [['compte' => '411001', 'debit' => 300], ['compte' => '706', 'credit' => 300]]);
        $v1 = $p('2026-01-15', 'Facture client V1', [['compte' => '411001', 'debit' => 80], ['compte' => '706', 'credit' => 80]]);
        $r1 = $p('2026-02-15', 'Règlement V1', [['compte' => '532', 'debit' => 80], ['compte' => '411001', 'credit' => 80]]);
        \Illuminate\Support\Carbon::setTestNow('2026-02-20');           // lettrée en février : avant l'arrêté du 30/06
        Lettrage::lettrer($this->client->id, [$v1->lignes()->where('compte', '411001')->value('id'), $r1->lignes()->where('compte', '411001')->value('id')]);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_tranches_et_non_affecte(): void
    {
        $E = Echeancier::calculer($this->client->id, '2026-06-30');
        $f = $E['sections']['40']['tiers'][0];
        $this->assertEquals(['echu90p' => 500, 'echu90' => 200, 'echoir30' => 100, 'echu30' => 0, 'non_affecte' => -150, 'total' => 650],
            array_intersect_key($f, array_flip(['echu90p', 'echu90', 'echoir30', 'echu30', 'non_affecte', 'total'])));
        $f1 = collect($f['pieces'])->firstWhere('libelle', 'Facture F1');
        $this->assertSame(['2026-02-09', 141], [$f1['echeance'], $f1['retard']]);

        $c = $E['sections']['41']['tiers'][0];
        $this->assertEquals([300, 300, 0], [$c['echoir30'], $c['total'], $c['echu90p']]);     // la facture V1 lettrée n'y est plus
        $this->assertSame(['Facture client V2'], array_column($c['pieces'], 'libelle'));
        $this->assertEquals(650, $E['sections']['40']['T']['total']);

        // Délai fournisseurs porté à 60 jours : F1 échoit le 11/03 (plus de 90 j), F2 le 09/05 (31 à 60 j), F3 le 19/08 (à plus de 30 j)
        $this->post(route('compta.echeancier.delais'), ['delais' => ['40' => 60, '41' => 30, '42' => 0]])->assertSessionHas('ok');
        $f = Echeancier::calculer($this->client->id, '2026-06-30')['sections']['40']['tiers'][0];
        $this->assertEquals([500, 0, 200, 0, 100], [$f['echu90p'], $f['echu90'], $f['echu60'], $f['echoir30'], $f['echoir30p']]);
        $this->assertSame(['40' => 60, '41' => 30, '42' => 0], Echeancier::delais($this->client->id));

        $this->assertSame('echoir30p', Echeancier::tranche('2026-08-15', '2026-06-30'));
        $this->assertSame('echoir30', Echeancier::tranche('2026-06-30', '2026-06-30'));
        $this->assertSame('echu30', Echeancier::tranche('2026-06-29', '2026-06-30'));
    }

    public function test_ecran_impression_et_export(): void
    {
        $this->get(route('compta.index', ['tab' => 'gl', 'annee' => 2026, 'vue' => 'echeancier', 'date' => '2026-06-30']))->assertOk()
            ->assertSee('Échéancier clients / fournisseurs')->assertSee('Délais de paiement du dossier')->assertSee('Échu &gt; 90 j', false)->assertDontSee('Facture F1');
        $this->get(route('compta.index', ['tab' => 'gl', 'annee' => 2026, 'vue' => 'echeancier', 'date' => '2026-06-30', 'detail' => 1]))->assertOk()
            ->assertSee('Facture F1')->assertSee('+141 j')->assertDontSee('Facture client V1');
        $this->get(route('compta.echeancier.print', ['date' => '2026-06-30', 'section' => '41']))->assertOk()
            ->assertSee('ÉCHÉANCIER CLIENTS ET FOURNISSEURS AU 30/06/2026')->assertSee('411001')->assertDontSee('401001');
        $csv = $this->get(route('compta.echeancier.csv', ['date' => '2026-06-30', 'detail' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('401001;"Fournisseurs d\'exploitation";"Facture F1";10/01/2026;09/02/2026;141;;;;;;500,000;;500,000', $csv);
        $this->assertStringContainsString(';"Total 40 — Fournisseurs et comptes rattachés";;;;;0,000;100,000;0,000;0,000;200,000;500,000;-150,000;650,000', $csv);

        $collab = User::factory()->create(['cabinet_id' => auth()->user()->cabinet_id, 'role' => 'collaborateur', 'status' => 'active']);
        $this->actingAs($collab)->post(route('compta.echeancier.delais'), ['delais' => ['40' => 90]])->assertSessionHas('err');
        $this->assertSame(30, Echeancier::delais($this->client->id)['40']);
    }
}
