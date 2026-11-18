<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\User;
use App\Services\Balances;
use App\Services\Compta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Balance générale, auxiliaire (tiers), centralisée et comparative : écran, impression et export. */
class BalancesVuesTest extends TestCase
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
        $p = fn ($date, $lib, $l) => Compta::passer($this->client->id, $date, 'OD', $lib, $l);
        // 2025 : clients 411001 (1 000) et 411002 (500), fournisseur 401001 (300), banque
        $p('2025-06-30', 'Ventes 2025', [['compte' => '411001', 'debit' => 1000], ['compte' => '411002', 'debit' => 500], ['compte' => '706', 'credit' => 1500]]);
        $p('2025-07-31', 'Achat 2025', [['compte' => '6061', 'debit' => 300], ['compte' => '401001', 'credit' => 300]]);
        // 2026 : le client 411001 règle 1 000 ; nouvelle vente au client 411003 ; fournisseur 401002
        $p('2026-02-10', 'Règlement', [['compte' => '532', 'debit' => 1000], ['compte' => '411001', 'credit' => 1000]]);
        $p('2026-03-15', 'Vente 2026', [['compte' => '411003', 'debit' => 2000], ['compte' => '706', 'credit' => 2000]]);
        $p('2026-04-20', 'Achat 2026', [['compte' => '6061', 'debit' => 400], ['compte' => '401002', 'credit' => 400]]);
        // 2026 : salaires du mois, net à payer au personnel 425001
        $p('2026-04-30', 'Paie avril', [['compte' => '640', 'debit' => 900], ['compte' => '425001', 'credit' => 900]]);
    }

    public function test_balance_auxiliaire(): void
    {
        $S = Balances::auxiliaire($this->client->id, 2026);
        $this->assertSame(['40', '41', '42'], array_map('strval', array_keys($S)));
        $this->assertSame(['425001'], array_column($S['42']['lignes'], 'compte'));
        $this->assertEquals(900, $S['42']['T']['sc']);
        $this->assertEquals(700 + 900, Balances::donnees($this->client->id, 2026, 'auxiliaire')['T']['sc']);
        $this->assertSame(['401001', '401002'], array_column($S['40']['lignes'], 'compte'));
        $this->assertSame(['411001', '411002', '411003'], array_column($S['41']['lignes'], 'compte'));
        $this->assertEquals([1500, 2500, 1000], [$S['41']['T']['rd'], $S['41']['T']['sd'], $S['41']['T']['mc']]);
        $this->assertEquals(700, $S['40']['T']['sc']);
        $this->get(route('compta.index', ['tab' => 'bal', 'annee' => 2026, 'vue' => 'auxiliaire']))->assertOk()
            ->assertSee('41 — Clients et comptes rattachés')->assertSee('Total 41 — clients et comptes rattachés')->assertSee('42 — Personnel et comptes rattachés')->assertDontSee('>706<', false);
    }

    public function test_balance_centralisee(): void
    {
        $B = collect(Balances::centralisee($this->client->id, 2026, 3))->keyBy('compte');
        $this->assertEquals(['rd' => 1500, 'mc' => 1000, 'md' => 2000, 'sd' => 2500, 'sc' => 0], \Illuminate\Support\Arr::only($B['411'], ['rd', 'mc', 'md', 'sd', 'sc']));
        $this->assertEquals([0, 700], [$B['401']['sd'], $B['401']['sc']]);
        $this->assertArrayNotHasKey('411001', $B->all());
        $this->assertSame(3, collect(Balances::centralisee($this->client->id, 2026, 2))->firstWhere('compte', '41')['n']);   // 411001, 411002, 411003
        $this->get(route('compta.index', ['tab' => 'bal', 'annee' => 2026, 'vue' => 'centralisee', 'niveau' => 3]))->assertOk()
            ->assertSee('Regroupement')->assertSee('compte=411&amp;exact=0', false);
    }

    public function test_balance_comparative(): void
    {
        $C = collect(Balances::comparative($this->client->id, 2026)['C'])->keyBy('compte');
        $this->assertEquals([0, 1000, -1000, -100.0], [$C['411001']['nd'], $C['411001']['pd'], $C['411001']['var'], $C['411001']['pct']]);
        $this->assertEquals([2000, 0, 2000, null], [$C['411003']['nd'], $C['411003']['pd'], $C['411003']['var'], $C['411003']['pct']]);
        $this->assertEquals([400, 300], [$C['6061']['nd'], $C['6061']['pd']]);       // charges : exercice 2026 contre exercice 2025
        $this->assertEquals([2000, 1500], [$C['706']['nc'], $C['706']['pc']]);
        $this->get(route('compta.index', ['tab' => 'bal', 'annee' => 2026, 'vue' => 'comparative']))->assertOk()->assertSee('Solde 2025')->assertSee('-100,0 %');
    }

    public function test_grand_livre_auxiliaire(): void
    {
        $G = \App\Services\GrandLivre::auxiliaire($this->client->id, 2026);
        $this->assertSame(['40', '41', '42'], array_map('strval', array_keys($G['sections'])));
        $c = collect($G['sections']['41']['comptes'])->keyBy('compte');
        $this->assertSame(['411001', '411002', '411003'], $c->keys()->map(fn ($k) => (string) $k)->all());
        // 411001 : report 1 000 débiteur, réglé en février → soldé ; 411002 : report seul, sans mouvement en 2026
        $this->assertEquals([1000, 0, 1000, 0], [$c['411001']['report'], $c['411001']['debit'], $c['411001']['credit'], $c['411001']['solde']]);
        $this->assertEquals([0.0], array_column($c['411001']['mouvements'], 'solde'));
        $this->assertEquals([500, [], 500], [$c['411002']['report'], $c['411002']['mouvements'], $c['411002']['solde']]);
        $this->assertEquals(['report' => 1500, 'debit' => 2000, 'credit' => 1000, 'solde' => 2500], $G['sections']['41']['T']);
        $this->assertEquals(-700, $G['sections']['40']['T']['solde']);
        $this->assertEquals(2500 - 700 - 900, $G['T']['solde']);

        // Filtres : section, puis compte
        $this->assertSame(['42'], array_map('strval', array_keys(\App\Services\GrandLivre::auxiliaire($this->client->id, 2026, '42')['sections'])));
        $f = \App\Services\GrandLivre::auxiliaire($this->client->id, 2026, null, '411003')['sections'];
        $this->assertSame(['411003'], array_column($f['41']['comptes'], 'compte'));
        $this->assertSame(['41'], array_map('strval', array_keys($f)));

        $this->get(route('compta.index', ['tab' => 'gl', 'annee' => 2026, 'vue' => 'auxiliaire']))->assertOk()
            ->assertSee('Grand livre auxiliaire')->assertSee('Report à nouveau')->assertSee('Total 411001')->assertSee('1 000,000 D', false);
        $this->get(route('compta.index', ['tab' => 'gl', 'annee' => 2026, 'compte' => '6061', 'exact' => 1]))->assertOk()->assertSee('Achat 2026');
        $this->get(route('compta.grand-livre.print', ['annee' => 2026, 'section' => '41']))->assertOk()->assertSee('GRAND LIVRE AUXILIAIRE 2026')->assertDontSee('425001');
        $csv = $this->get(route('compta.grand-livre.csv', ['annee' => 2026]))->assertOk()->getContent();
        $this->assertStringContainsString('411001;10/02/2026;OD;"Règlement";0,000;1000,000;0,000', $csv);
        $this->assertStringContainsString('"Total général";2000,000;2300,000;900,000', $csv);
    }

    public function test_impression_et_export_selon_la_vue(): void
    {
        $this->get(route('compta.balance.print', ['annee' => 2026, 'vue' => 'comparative']))->assertOk()->assertSee('BALANCE COMPARATIVE 2026 / 2025');
        $this->get(route('compta.balance.print', ['annee' => 2026, 'vue' => 'centralisee', 'niveau' => 2]))->assertOk()->assertSee('BALANCE CENTRALISÉE 2026 — 2 CHIFFRES');
        $this->get(route('compta.balance.print', ['annee' => 2026]))->assertOk()->assertSee('BALANCE 2026');

        $r = $this->get(route('compta.balance.csv', ['annee' => 2026, 'vue' => 'auxiliaire']))->assertOk();
        $this->assertStringContainsString('balance-auxiliaire-2026', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('"Total 41 — Clients et comptes rattachés"', $r->getContent());
        $csv = $this->get(route('compta.balance.csv', ['annee' => 2026, 'vue' => 'comparative']))->getContent();
        $this->assertStringContainsString("411001;\"", $csv);
        $this->assertStringContainsString(';0,000;0,000;1000,000;0,000;-1000,000;-100,0', $csv);
        $this->assertStringContainsString("411;\"", $this->get(route('compta.balance.csv', ['annee' => 2026, 'vue' => 'centralisee']))->getContent());
    }
}
