<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Declaration;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\Paie;
use App\Models\User;
use App\Services\DeclarationMensuelle as M;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Déclaration mensuelle au modèle DGI (bases, taux, montants) et récapitulatif fiscal de l'année. */
class DeclarationMensuelleTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'chef', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha Export', 'matricule_fiscal' => '7654321/C/A/M/000', 'adresse' => '12 rue de Marseille 1000 Tunis']);
        session(['dossier' => $this->client->id]);
    }

    private function q(string $mo, array $x = []): array
    {
        return ['type' => 'men', 'periode' => $mo] + $x;
    }

    public function test_pre_remplissage_depuis_les_factures_et_la_paie(): void
    {
        $c = $this->client->id;
        FactureVente::create(['client_id' => $c, 'date' => '2026-10-05', 'tiers' => 'Client A', 'ht' => 1000, 'tva_taux' => 19, 'tva' => 190, 'timbre' => 1, 'compte' => '7061', 'statut' => 'val']);
        FactureVente::create(['client_id' => $c, 'date' => '2026-10-06', 'tiers' => 'Client B', 'ht' => 200, 'tva_taux' => 7, 'tva' => 14, 'timbre' => 1, 'compte' => '7061', 'statut' => 'val']);
        FactureVente::create(['client_id' => $c, 'date' => '2026-10-07', 'tiers' => 'Brouillon', 'ht' => 999, 'tva_taux' => 19, 'tva' => 189.81, 'compte' => '7061', 'statut' => 'prop']);
        FactureRecue::create(['client_id' => $c, 'date' => '2026-10-10', 'fournisseur' => 'Matériaux', 'ht' => 500, 'tva_taux' => 19, 'tva' => 95, 'compte' => '6061', 'tva_deductible' => true, 'statut' => 'val']);
        FactureRecue::create(['client_id' => $c, 'date' => '2026-10-11', 'fournisseur' => 'Cabinet Lex', 'matricule_fiscal' => '1234567RAM000', 'description' => 'Honoraires', 'ht' => 1000, 'tva_taux' => 19, 'tva' => 190,
            'compte' => '6226', 'tva_deductible' => true, 'retenue_taux' => 3, 'statut' => 'val']);
        FactureRecue::create(['client_id' => $c, 'date' => '2026-10-12', 'fournisseur' => 'Info', 'ht' => 3000, 'tva_taux' => 19, 'tva' => 570, 'compte' => '2280', 'tva_deductible' => true, 'statut' => 'val']);
        Paie::create(['client_id' => $c, 'mois' => '2026-10', 'statut' => 'val', 'resultats' => [['salarie_id' => 1, 'brut' => 2000, 'cnssS' => 183.6, 'ir' => 200, 'css' => 10, 'net' => 1606.4]],
            'totaux' => ['brut' => 2000, 'ir' => 200, 'css' => 10, 'tfp' => 40, 'fop' => 20]]);
        // Septembre : crédit de TVA de 50 D reporté
        Declaration::create(['client_id' => $c, 'type' => 'men', 'periode' => '2026-09', 'statut' => 'deposee', 'annexes' => [], 'champs' => ['tvd_al' => ['b' => 263.158, 'm' => 50]]]);

        $this->post(route('declarations.prefill'), $this->q('2026-10'))->assertSessionHas('ok');
        $f = Declaration::where(['type' => 'men', 'periode' => '2026-10'])->sole()->champs;
        $this->assertEquals([1000, 200, 2], [$f['tva19']['b'], $f['tva7']['b'], $f['tim4']['b']]);
        $this->assertEquals([3000, 570], [$f['tvd_eql']['b'], $f['tvd_eql']['m']]);
        $this->assertEqualsWithDelta(285, $f['tvd_al']['m'], 0.001);
        $this->assertEquals(1190, $f['rs6']['b']);
        $this->assertArrayNotHasKey('m', $f['rs6']);                 // 3 % de 1 190 : montant égal au calcul, non figé
        $this->assertEquals([1816.4, 200, 10, 2000], [$f['rs1']['b'], $f['rs1']['m'], $f['rs3']['m'], $f['tfp2']['b']]);
        $this->assertEqualsWithDelta(1404, $f['tcl_loc']['b'], 0.001);                 // CA TTC local : 1 200 + 204
        $this->assertEquals(50, $f['tva_prec']['m']);

        $C = M::calcul($f);
        $this->assertSame(204.0, $C['tva_I']);                       // 190 + 14
        $this->assertSame(855.0, $C['tva_II']);                      // 95 + 190 + 570
        $this->assertSame(-701.0, $C['tvap']);                       // 204 − 855 − 50 de crédit
        $this->assertSame(701.0, $C['credit']);
        $this->assertSame([245.7, 40.0, 20.0, 0.0, 2.0, 2.808], [$C['sections']['rs']['du'], $C['sections']['tfp']['du'], $C['sections']['fop']['du'], $C['sections']['tva']['du'], $C['sections']['tim']['du'], $C['sections']['tcl']['du']]);
        $this->assertSame(310.508, $C['tot']);

        $this->get(route('declarations.index', $this->q('2026-10')))->assertOk()->assertSee('Taxe sur la valeur ajoutée')->assertSee('Crédit de TVA à reporter')->assertSee('310,508');
        $this->get(route('declarations.print', $this->q('2026-10')))->assertOk()->assertSee('Synthèse des impôts et taxes à payer')->assertSee('Honoraires des personnes morales');
    }

    public function test_saisie_taux_montant_force_et_penalites(): void
    {
        $this->post(route('declarations.save'), $this->q('2026-11', ['acte' => '1', 'f' => [
            'tva19' => ['b' => '10 000', 'm' => ''],
            'rs17d' => ['b' => '2000', 't' => '1,5'],                 // taux par défaut : non conservé
            'rs20' => ['b' => '1000', 't' => '5'],                    // taux saisi
            'rs28' => ['m' => '12,5'],
            'tvd_al' => ['b' => '1000', 'm' => '190'],
            'au4' => ['b' => '100', 'm' => '4'],                      // montant forcé (5 % donnerait 5)
        ], 'pen' => ['tva' => '18', 'rs' => '']]));
        $f = Declaration::where(['type' => 'men', 'periode' => '2026-11'])->sole()->champs;
        $this->assertSame('1', $f['acte']);
        $this->assertEquals(['b' => 2000], $f['rs17d']);
        $this->assertEquals(['b' => 1000, 't' => 5], $f['rs20']);
        $this->assertEquals(['tva' => 18], $f['pen']);
        $C = M::calcul($f);
        $this->assertSame(1710.0, $C['tvap']);                       // 1 900 − 190
        $this->assertSame(92.5, $C['sections']['rs']['du']);          // 30 + 50 + 12,5
        $this->assertSame(4.0, $C['sections']['aut']['du']);
        $this->assertSame(1824.5, $C['tot']);                         // 1 710 + 18 + 92,5 + 4

        // Ancien formulaire simplifié : converti à la lecture
        $old = M::calcul(['ca19' => 1000, 'tvad1' => 50, 'tvacr' => 20, 'rsal' => 100, 'css' => 5, 'tfp' => 10, 'fop' => 5]);
        $this->assertSame([120.0, 105.0, 10.0, 5.0], [$old['tvap'], $old['sections']['rs']['du'], $old['sections']['tfp']['du'], $old['sections']['fop']['du']]);
    }

    public function test_cases_des_impots_concernes_et_formulaire_arabe(): void
    {
        // Sans choix enregistré : cases déduites des montants (TVA, retenue, taxe sur les établissements)
        $f = ['tva19' => ['b' => 1000], 'rs1' => ['b' => 3000, 'm' => 150], 'tcl_loc' => ['b' => 1190]];
        $this->assertSame(['rs', 'tva', 'tcl_ent'], M::impots($f));
        $this->assertSame(['rs' => 3000.0, 'tva' => 1000.0, 'tcl' => 1190.0], array_filter(array_map(fn ($x) => $x['base'], M::calcul($f)['sections'])));

        // Cases cochées à l'écran : enregistrées telles quelles, même sans montant
        $this->post(route('declarations.save'), $this->q('2026-10', ['imp_saisie' => 1, 'imp' => ['tva', 'tim', 'inconnu'], 'f' => ['tva19' => ['b' => '1000'], 'rs1' => ['b' => '3000', 'm' => '150']]]));
        $d = Declaration::where(['type' => 'men', 'periode' => '2026-10'])->sole();
        $this->assertSame(['tva', 'tim'], M::impots($d->champs));
        $this->get(route('declarations.index', $this->q('2026-10')))->assertOk()->assertSee('Impôts et taxes concernés')->assertSee('Formulaire arabe');

        // Formulaire officiel en arabe : entête, cases cochées, bases et montants
        $r = $this->get(route('declarations.arabe', $this->q('2026-10')))->assertOk();
        $r->assertSee('التصريح الشهري بالأداءات')->assertSee('الخصم من المورد')->assertSee('خلاصة الأداءات والمعاليم الواجب دفعها')
            ->assertSee('3 000,000')->assertSee('150,000')->assertSee('1 000,000')->assertSee('190,000')->assertSee('Alpha Export');
        $this->assertSame(2, substr_count($r->getContent(), '<td class="coche"><span class="bx">X</span></td>'));
        $r->assertSee('<span class="cases" dir="ltr"><span class="bx">2</span><span class="bx">0</span><span class="bx">2</span><span class="bx">6</span></span>', false);
        $this->get(route('declarations.arabe', ['type' => 'emp', 'periode' => '2026']))->assertNotFound();
    }

    public function test_recapitulatif_fiscal_de_l_annee(): void
    {
        $c = $this->client->id;
        Declaration::create(['client_id' => $c, 'type' => 'men', 'periode' => '2026-01', 'statut' => 'deposee', 'annexes' => [], 'champs' => ['tva19' => ['b' => 1000], 'rs1' => ['m' => 100], 'pen' => ['rs' => 5]]]);
        Declaration::create(['client_id' => $c, 'type' => 'men', 'periode' => '2026-02', 'statut' => 'brouillon', 'annexes' => [], 'champs' => ['tvd_al' => ['m' => 300], 'fop' => ['b' => 1000]]]);
        Declaration::create(['client_id' => $c, 'type' => 'men', 'periode' => '2025-12', 'statut' => 'deposee', 'annexes' => [], 'champs' => ['rs1' => ['m' => 999]]]);

        $this->get(route('declarations.recap', ['annee' => 2026]))->assertOk()->assertSee('Récapitulatif fiscal 2026')
            ->assertSee('Non établie')->assertSee('300,000')->assertSee('295,000')->assertDontSee('999,000');
        $csv = $this->get(route('declarations.recap', ['annee' => 2026, 'format' => 'csv']))->assertOk()->getContent();
        // Colonnes base / montant par impôt : retenues, TFP, FOPROLOS, droit de consommation, TVA…
        $this->assertStringContainsString('2026-01;0,000;100,000;0,000;0,000;0,000;0,000;0,000;0,000;1000,000;190,000;', $csv);
        $this->assertStringContainsString('2026-02;0,000;0,000;0,000;0,000;1000,000;10,000;', $csv);
        $this->assertStringContainsString(';5,000;305,000', $csv);
        $this->get(route('declarations.recap', ['annee' => 2026]))->assertSee('base 1 000,000');
        $this->get(route('declarations.index', ['type' => 'men', 'periode' => '2026-03']))->assertOk()->assertSee('Récapitulatif fiscal');
    }
}
