<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Compte;
use App\Models\FactureRecue;
use App\Models\User;
use App\Services\Compta;
use App\Services\PaieCalc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Plan comptable tunisien (classes 1 à 7) et comptes utilisés par les écritures automatiques. */
class PlanComptableTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');          // comme à l'inscription : plan comptable installé
        $this->actingAs(User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']));
        $this->client = Client::factory()->create();
    }

    public function test_un_nouveau_cabinet_recoit_le_plan_officiel(): void
    {
        $plan = Compta::plan();

        $this->assertCount(count(config('cabinet.PCN')), $plan);
        $this->assertGreaterThan(600, count($plan));
        foreach (['1', '2', '3', '4', '5', '6', '7'] as $classe) $this->assertArrayHasKey($classe, $plan);
        $this->assertSame("Taxes sur le chiffre d'affaires déductibles", $plan['4366']);
        $this->assertSame("Taxes sur le chiffre d'affaires collectées par l'entreprise", $plan['4367']);
        $this->assertSame('CNSS', $plan['45311']);
        $this->assertSame('Autres charges sociales', $plan['6495']);                    // coquille « Au tres » de la source corrigée
        $this->assertSame('Variation des stocks de marchandises', $plan['6037']);      // second « 6032 » de la source
        $this->assertSame('Logiciels', Compta::libelle('213'));
        $this->assertStringStartsWith('Amortissements des immobilisations incorporelles', Compta::libelle('2813'));   // ventilation du 281
    }

    public function test_installation_sans_doublon_et_comptes_personnalises_conserves(): void
    {
        $cab = auth()->user()->cabinet_id;
        Compte::where('numero', '532')->update(['libelle' => 'Banque BIAT', 'personnalise' => true]);

        $this->assertSame(0, Compte::installerPlan($cab));
        $this->assertSame('Banque BIAT', Compta::plan()['532']);
    }

    public function test_ecriture_de_paie_sur_les_comptes_officiels(): void
    {
        $l = PaieCalc::lignes(['brut' => 1000, 'cnssS' => 91.8, 'cnssP' => 165.7, 'ras' => 80, 'net' => 828.2, 'tfp' => 20, 'fop' => 10]);

        $this->assertSame(['640', '647', '661', '425', '45311', '432', '437'], array_column($l, 'compte'));
        $this->assertEquals(array_sum(array_column($l, 'debit')), array_sum(array_column($l, 'credit')));
    }

    public function test_facture_recue_tva_deductible_timbre_et_retenue(): void
    {
        $f = new FactureRecue(['ht' => 1000, 'tva' => 190, 'timbre' => 1, 'compte' => '622', 'tva_deductible' => true, 'retenue_taux' => 3]);

        $this->assertSame([['622', 1000.0, 0], ['4366', 190.0, 0], ['6654', 1.0, 0], ['401', 0, 1155.3], ['432', 0, 35.7]],
            array_map(fn ($x) => [$x['compte'], $x['debit'], $x['credit']], $f->lignesEcriture()));
    }

    public function test_etat_de_resultat_selon_les_classes_officielles(): void
    {
        Compta::passer($this->client->id, '2026-06-30', 'OD', 'Test', [
            ['compte' => '532', 'debit' => 10000], ['compte' => '705', 'credit' => 10000],
            ['compte' => '640', 'debit' => 3000], ['compte' => '6654', 'debit' => 100], ['compte' => '6811', 'debit' => 500],
            ['compte' => '6511', 'debit' => 200], ['compte' => '756', 'credit' => 50],
            ['compte' => '67', 'debit' => 400], ['compte' => '77', 'credit' => 150],
            ['compte' => '532', 'credit' => 3950],
        ]);
        $P = Compta::etats($this->client->id, 2026)['P'];

        $this->assertSame([10000.0, 3000.0, 100.0, 500.0, 200.0, 50.0, -250.0], [$P['ca'], $P['per'], $P['imp'], $P['dot'], $P['fin'], $P['pfi'], $P['ext']]);
        $this->assertSame(6400.0 - 150.0 - 250.0, $P['rav']);    // exploitation 6 400, financier −150, extraordinaire −250
    }
}
