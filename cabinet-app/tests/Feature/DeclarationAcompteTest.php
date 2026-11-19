<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Declaration;
use App\Models\User;
use App\Services\DeclarationAcompte as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Déclaration de l'acompte provisionnel (modèle DGI) : calcul, pré-remplissage, report d'excédent, formulaires. */
class DeclarationAcompteTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'chef', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha SARL', 'forme' => 'SARL', 'type_personne' => 'morale', 'matricule_fiscal' => '7654321/C/A/M/000', 'adresse' => '12 rue de Marseille 1000 Tunis', 'paie_activite' => 'Négoce']);
        session(['dossier' => $this->client->id]);
    }

    public function test_calcul(): void
    {
        // Base théorique = base + moins-values − plus-values ; impôt théorique au taux de l'IS, au moins le minimum ; acompte = 30 %
        $C = P::calcul(['regime' => 'is', 'taux' => 15, 'min' => 500, 'base' => 100000, 'pv' => 20000, 'mv' => 4000, 'rs' => 1000, 'exc_annuel' => 500]);
        $this->assertSame([84000.0, 12600.0, 3780.0, 1500.0, 2280.0, 2280.0], [$C['base_th'], $C['impot_th'], $C['I'], $C['II'], $C['III'], $C['tot']]);
        // Perte : le minimum d'impôt sert de base ; excédent si les déductions dépassent l'acompte
        $C = P::calcul(['regime' => 'is', 'taux' => 15, 'min' => 2380, 'base' => -5000, 'rs' => 1000]);
        $this->assertSame([2380.0, 714.0, -286.0, 286.0, 0.0], [$C['impot_th'], $C['I'], $C['III'], $C['excedent'], $C['tot']]);
        // Personne physique : barème ; montant saisi prioritaire
        $this->assertSame(1875.0, P::calcul(['regime' => 'ir', 'base' => 30000])['I']);
        // IRPP : la fraction de dinar du bénéfice imposable compte pour un dinar entier.
        $this->assertSame(1875.0, P::calcul(['regime' => 'ir', 'base' => 29999.001])['I']);
        $this->assertSame([30000.0, 30000.0, 0.0], array_map([\App\Services\DeclarationAnnuelle::class, 'arrondiImposable'], [29999.001, 30000, -12.5]));
        $this->assertSame(300.0, P::calcul(['regime' => 'ir', 'base' => 30000, 'impot_th' => 1000])['I']);
        $this->assertSame(['2026', '2e acompte provisionnel 2026'], [(string) P::periode('2026-A2')[0], P::libelle('2026-A2')]);
    }

    public function test_pre_remplissage_report_et_formulaires(): void
    {
        $c = $this->client->id;
        Declaration::create(['client_id' => $c, 'type' => 'ann', 'periode' => '2025', 'statut' => 'deposee', 'annexes' => [],
            'champs' => ['modele' => 'is', 'taux' => 15, 'res_fiscal' => 100000, 'benef_net' => 100000, 'ca_min' => 1190000, 'acomptes' => [['montant' => 20000]]]]);

        $this->get(route('declarations.index', ['type' => 'acp', 'periode' => '2026-A1']))->assertOk()->assertSee('Acompte provisionnel')->assertSee('1er acompte provisionnel 2026');
        $this->post(route('declarations.prefill'), ['type' => 'acp', 'periode' => '2026-A1'])->assertSessionHas('ok');
        $f = Declaration::where(['type' => 'acp', 'periode' => '2026-A1'])->sole()->champs;
        $C = P::calcul($f);
        // IS 2025 : 15 000 D dû, 20 000 D d'acomptes → excédent 5 000 D imputé sur le 1er acompte (4 500 D)
        $this->assertSame([15000.0, 4500.0, 5000.0, -500.0, 0.0], [$C['impot_th'], $C['I'], $C['II'], $C['III'], $C['tot']]);

        // Saisie : retenue à la source ; les paramètres repris de l'annuelle sont conservés
        $this->post(route('declarations.save'), ['type' => 'acp', 'periode' => '2026-A1', 'acte' => '0', 'rc' => 'B123456', 'f' => ['base' => '100000', 'exc_annuel' => '5000', 'rs' => '200']]);
        $f = Declaration::where(['type' => 'acp', 'periode' => '2026-A1'])->sole()->champs;
        $this->assertSame(['is', 15000.0], [$f['regime'], P::calcul($f)['impot_th']]);
        $this->assertSame(700.0, P::calcul($f)['excedent']);

        // 2e acompte : excédent du 1er reporté, excédent de l'annuelle non réimputé
        $this->post(route('declarations.prefill'), ['type' => 'acp', 'periode' => '2026-A2']);
        $C = P::calcul(Declaration::where(['type' => 'acp', 'periode' => '2026-A2'])->sole()->champs);
        $this->assertSame([700.0, 3800.0], [$C['II'], $C['tot']]);

        $r = $this->get(route('declarations.arabe', ['type' => 'acp', 'periode' => '2026-A2']))->assertOk()->assertSee('التصريح بالقسط الاحتياطي')->assertSee('3 800,000')->assertSee('4 500,000');
        $r->assertSee('<span class="cases" dir="ltr"><span class="bx">2</span></span>', false);
        $this->get(route('declarations.print', ['type' => 'acp', 'periode' => '2026-A2']))->assertOk()->assertSee('30 % de l’impôt théorique');
        $this->assertStringContainsString('Total à payer";3800,000', $this->get(route('declarations.csv', ['type' => 'acp', 'periode' => '2026-A2']))->getContent());
    }
}
