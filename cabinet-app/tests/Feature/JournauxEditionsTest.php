<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Releve;
use App\Models\ReleveLigne;
use App\Models\User;
use App\Services\Compta;
use App\Services\Journaux;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Journal auxiliaire, journal centralisé, journal par lot et centralisation globale : écran, impression, export. */
class JournauxEditionsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->chef = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active', 'name' => 'Chef Test']);
        $this->actingAs($this->chef);
        $this->client = Client::factory()->create();
        session(['dossier' => $this->client->id]);

        $p = fn ($date, $jnl, $lib, $l, $src = null) => Compta::passer($this->client->id, $date, $jnl, $lib, $l, $src, $this->chef->id);
        // Saisi le 10 mars : deux factures d'achat validées et une saisie manuelle
        Carbon::setTestNow('2026-03-10 09:00:00');
        $p('2026-01-15', 'ACH', 'Facture A', [['compte' => '6061', 'debit' => 100], ['compte' => '401001', 'credit' => 100]], 'fr:1');
        $p('2026-02-20', 'ACH', 'Facture B', [['compte' => '6061', 'debit' => 50], ['compte' => '4366', 'debit' => 9.5], ['compte' => '401002', 'credit' => 59.5]], 'fr:2');
        $p('2026-02-28', 'OD', 'Régularisation', [['compte' => '6061', 'debit' => 10], ['compte' => '461', 'credit' => 10]]);
        // Saisi le 12 mars : relevé bancaire importé (deux lignes comptabilisées)
        Carbon::setTestNow('2026-03-12 15:00:00');
        $releve = Releve::create(['client_id' => $this->client->id, 'nom_fichier' => 'releve-fevrier.pdf', 'methode' => 'pdf']);
        foreach ([['2026-02-05', 100, '401001'], ['2026-02-25', 59.5, '401002']] as [$d, $m, $c]) {
            $rl = ReleveLigne::create(['client_id' => $this->client->id, 'releve_id' => $releve->id, 'date' => $d, 'libelle' => 'VIR '.$c, 'debit' => $m, 'credit' => 0, 'statut' => 'cpt']);
            $p($d, 'BQ', 'Virement '.$c, [['compte' => $c, 'debit' => $m], ['compte' => '532', 'credit' => $m]], 'rb:'.$rl->id);
        }
        Carbon::setTestNow();
    }

    public function test_journal_auxiliaire_et_centralise(): void
    {
        $A = Journaux::auxiliaire($this->client->id, 2026);
        $this->assertSame(['OD', 'ACH', 'BQ'], array_keys($A['journaux']));            // ordre de la liste des journaux
        $this->assertEquals(['debit' => 159.5, 'credit' => 159.5], $A['journaux']['ACH']['T']);
        $this->assertEquals(['debit' => 329, 'credit' => 329], $A['T']);
        $this->assertSame(['ACH'], array_keys(Journaux::auxiliaire($this->client->id, 2026, 1)['journaux']));   // janvier seul
        $this->assertSame(['BQ'], array_keys(Journaux::auxiliaire($this->client->id, 2026, null, 'BQ')['journaux']));

        $C = Journaux::centralise($this->client->id, 2026);
        $this->assertSame(['2026-01', '2026-02'], array_keys($C['journaux']['ACH']['mois']));
        $fev = collect($C['journaux']['ACH']['mois']['2026-02']['comptes'])->keyBy('compte');
        $this->assertEquals([50, 9.5, 59.5], [$fev['6061']['debit'], $fev['4366']['debit'], $fev['401002']['credit']]);
        $bq = collect($C['journaux']['BQ']['mois']['2026-02']['comptes'])->keyBy('compte');
        $this->assertEquals(159.5, $bq['532']['credit']);                               // deux virements centralisés sur la banque
        $this->assertEquals($A['T'], $C['T']);

        $this->get(route('compta.index', ['tab' => 'jnl', 'annee' => 2026, 'vue' => 'auxiliaire']))->assertOk()
            ->assertSee('ACH — Achats')->assertSee('Facture B')->assertSee('Total du journal');
        $this->get(route('compta.index', ['tab' => 'jnl', 'annee' => 2026, 'vue' => 'centralise']))->assertOk()->assertSee('Février 2026')->assertSee('Total BQ — Banque');
        $this->get(route('compta.index', ['tab' => 'jnl', 'annee' => 2026]))->assertOk()->assertSee('Saisir une pièce');   // liste des écritures inchangée
    }

    public function test_journal_par_lot(): void
    {
        $L = Journaux::lots($this->client->id, 2026)['lots'];
        $this->assertSame([
            'Factures d’achat validées le 10/03/2026',
            'Saisies manuelles du 10/03/2026 — Chef Test',
            'Relevé bancaire « releve-fevrier.pdf » importé le 12/03/2026',
        ], array_column($L, 'titre'));
        $this->assertSame([1, 2, 3], array_column($L, 'numero'));
        $this->assertCount(2, $L[0]['ecritures']);
        $this->assertCount(2, $L[2]['ecritures']);
        $this->assertEquals(['debit' => 159.5, 'credit' => 159.5], $L[2]['T']);
        $this->get(route('compta.index', ['tab' => 'jnl', 'annee' => 2026, 'vue' => 'lots']))->assertOk()
            ->assertSee('Lot n° 3 — Relevé bancaire « releve-fevrier.pdf » importé le 12/03/2026')->assertSee('Total du lot');
    }

    public function test_centralisation_globale(): void
    {
        $G = Journaux::global($this->client->id, 2026);
        $this->assertSame(['2026-01', '2026-02'], array_column($G['mois'], 'mois'));
        $this->assertEquals(['debit' => 100, 'credit' => 100], $G['mois'][0]['T']);
        $this->assertSame(['OD', 'ACH', 'BQ'], array_keys($G['mois'][1]['journaux']));
        $this->assertEquals(['debit' => 329, 'credit' => 329], $G['mois'][1]['cumul']);
        $this->assertEquals(['debit' => 159.5, 'credit' => 159.5], $G['journaux']['BQ']);
        // Total général = mouvements de la balance
        $T = Compta::totaux(Compta::balance($this->client->id, 2026));
        $this->assertEquals([$T['md'], $T['mc']], [$G['T']['debit'], $G['T']['credit']]);
        $this->get(route('compta.index', ['tab' => 'jnl', 'annee' => 2026, 'vue' => 'global']))->assertOk()->assertSee('Récapitulation par journal')->assertSee('Équilibré');
    }

    public function test_impression_et_export(): void
    {
        foreach (['auxiliaire' => 'JOURNAL AUXILIAIRE', 'centralise' => 'JOURNAL CENTRALISÉ', 'lots' => 'JOURNAL PAR LOT', 'global' => 'CENTRALISATION GLOBALE'] as $v => $t) {
            $this->get(route('compta.journaux.print', ['annee' => 2026, 'vue' => $v]))->assertOk()->assertSee($t.' — EXERCICE 2026');
        }
        $this->get(route('compta.journaux.print', ['annee' => 2026, 'vue' => 'auxiliaire', 'mois' => 2, 'journal' => 'BQ']))->assertOk()
            ->assertSee('JOURNAL AUXILIAIRE — FÉVRIER 2026')->assertSee('journal BQ — Banque')->assertDontSee('Facture A');

        $r = $this->get(route('compta.journaux.csv', ['annee' => 2026, 'vue' => 'centralise', 'mois' => 2]))->assertOk();
        $this->assertStringContainsString('journal-centralise-2026-02', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('BQ;"février 2026";532;', $r->getContent());
        $this->assertStringContainsString('"Total général";;229,000;229,000', $r->getContent());
        $csv = $this->get(route('compta.journaux.csv', ['annee' => 2026, 'vue' => 'lots']))->getContent();
        $this->assertStringContainsString('"Lot 1 — Factures d’achat validées le 10/03/2026";15/01/2026;ACH;"Facture A";6061;100,000;0,000', $csv);
        $this->assertStringContainsString('"Total du mois";100,000;100,000;100,000;100,000', $this->get(route('compta.journaux.csv', ['annee' => 2026, 'vue' => 'global']))->getContent());
    }
}
