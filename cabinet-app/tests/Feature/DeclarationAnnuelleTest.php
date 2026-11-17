<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Declaration;
use App\Models\PassageFiscal;
use App\Models\User;
use App\Services\Compta;
use App\Services\DeclarationAnnuelle as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Déclaration annuelle : impôt sur les sociétés (personne morale) et impôt sur le revenu (personne physique), modèles DGI. */
class DeclarationAnnuelleTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'chef', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha SARL', 'forme' => 'SARL', 'type_personne' => 'morale', 'matricule_fiscal' => '7654321/C/A/M/000',
            'adresse' => '12 rue de Marseille 1000 Tunis', 'paie_activite' => 'Négoce', 'capital' => 10000, 'nb_parts' => 100, 'valeur_nominale' => 100]);
        session(['dossier' => $this->client->id]);
    }

    private function q(array $x = []): array
    {
        return ['type' => 'ann', 'periode' => '2025'] + $x;
    }

    public function test_calcul_impot_sur_les_societes(): void
    {
        // Bénéfice de 100 000 D à 15 % : impôt 15 000 D > minimum (0,2 % de 1 190 000 = 2 380 D)
        $C = A::calculIs(['taux' => 15, 'benef_net' => 100000, 'ca_min' => 1190000, 'ded_rs' => 1000, 'ded_exc' => 500,
            'acomptes' => [['montant' => 3000], ['montant' => 3000], ['montant' => 3000]]]);
        $this->assertSame([15000.0, 2380.0, 15000.0, 9000.0, 10500.0, 4500.0], [$C['impot_calc'], $C['min'], $C['is_du'], $C['acomptes'], $C['II'], $C['IV']]);
        $this->assertSame([3, 3000.0], [$C['css_taux'], $C['css_du']]);           // 3 % du bénéfice
        $this->assertSame(7500.0, $C['tot']);                                    // 4 500 + 3 000

        // Perte : minimum d'impôt (500 D), contribution sociale minimale de 400 D, excédent si les acomptes dépassent
        $C = A::calculIs(['taux' => 15, 'benef_net' => 0, 'ca_min' => 100000, 'acomptes' => [['montant' => 2000]]]);
        $this->assertSame([500.0, 500.0, -1500.0, 1500.0, 400.0], [$C['min'], $C['is_du'], $C['IV'], $C['excedent'], $C['css_du']]);

        // Sociétés à 10 % : minimum de 0,1 % (300 D au moins) ; à 35 % : contribution de 4 %
        $this->assertSame(300.0, A::calculIs(['taux' => 10, 'ca_min' => 50000])['min']);
        $C35 = A::calculIs(['taux' => 35, 'benef_net' => 100000]);
        $this->assertSame([4, 4000.0], [$C35['css_taux'], $C35['css_du']]);
        // Banques, assurances… : contribution conjoncturelle de 4 % (minimum 10 000 D) ; fonds des salariés 7 % ; établissements stables
        $C = A::calculIs(['taux' => 35, 'benef_net' => 100000, 'cc_soumis' => true, 'fonds_base' => 1000, 'etab_distr' => 2000, 'etab_paradis' => true, 'exo_min' => true]);
        $this->assertSame([10000.0, 70.0, 25, 500.0, 0.0], [$C['cc_du'], $C['fonds'], $C['etab_taux'], $C['etab'], $C['min']]);
    }

    public function test_calcul_impot_sur_le_revenu(): void
    {
        // Barème 2025 : 30 000 D → 6 250 D ; 70 000 D → 20 750 D (taux effectif 29,64 % du modèle) ; 80 000 D → 24 750 D
        $this->assertSame([0.0, 750.0, 6250.0, 24750.0], [A::bareme(5000), A::bareme(10000), A::bareme(30000), A::bareme(80000)]);

        // Salarié : 40 000 D bruts → frais 10 % plafonnés à 2 000 D ; chef de famille, deux enfants dont un étudiant
        $f = ['sal_brut' => 40000, 'chef' => true, 'enfants' => [['etat' => '1'], ['etat' => '2']], 'ded_rs' => 6000, 'acomptes' => []];
        $C = A::calculIr($f);
        $this->assertSame([38000.0, 300.0, 1100.0, 36600.0], [$C['sal'], $C['ded_chef'], $C['ded_enf'], $C['rni']]);
        $this->assertSame(A::bareme(36600), $C['impot_bareme']);
        $this->assertSame([0.0, 8428.0], [$C['min'], $C['impot']]);              // 6 250 + 6 600 × 33 %, sans minimum (pas d'activité)
        $this->assertSame(2428.0, $C['solde']);                                    // après 6 000 D de retenues
        $this->assertSame(183.0, $C['css_du']);                                    // 0,5 % du revenu net

        // Commerçant au réel : bénéfice 20 000 D, perte antérieure 5 000 D, minimum 0,2 % de 500 000 D = 1 000 D
        $C = A::calculIr(['bic_res_fiscal' => 20000, 'bic_ca_ttc' => 500000, 'min_loc_02' => 500000, 'pertes' => [['annee' => '2023', 'montant' => 5000]]]);
        $this->assertSame([20000.0, 5000.0, 15000.0, 2000.0, 1000.0, 2000.0], [$C['rng'], $C['pertes_imput'], $C['rni'], $C['impot_bareme'], $C['min'], $C['impot']]);
        $this->assertSame(1000.0, A::calculIr(['bic_res_fiscal' => 1000, 'min_loc_02' => 500000])['impot']);   // le minimum l'emporte

        // Profession libérale au forfait : 80 % des recettes TTC ; revenus fonciers bâtis : abattement de 25 %
        $C = A::calculIr(['bnc_rec_ttc' => 10000, 'fon_bati' => 8000, 'fon_bati_tax' => 100]);
        $this->assertSame([8000.0, 5900.0], [$C['bnc'], $C['fon']]);
    }

    public function test_declaration_des_personnes_physiques(): void
    {
        $c = Client::create(['nom' => 'Ben Salah Karim', 'type_personne' => 'physique', 'forme' => 'Libéral (BNC)', 'matricule_fiscal' => '1234567/R/A/P/000', 'adresse' => '5 rue de Rome 1000 Tunis', 'paie_activite' => 'Avocat']);
        session(['dossier' => $c->id]);
        $this->get(route('declarations.index', $this->q()))->assertOk()->assertSee('Déclaration de l’impôt sur le revenu des personnes physiques')->assertSee('Personne physique')->assertSee('Bénéfices des professions non commerciales');
        $this->post(route('declarations.save'), $this->q(['imp_saisie' => 1, 'imp' => ['ir'], 'e' => ['acte' => '0', 'situation' => '2', 'enf1' => '1', 'id_code' => '2', 'id_num' => '01234567'],
            'f' => ['bnc_res_fiscal' => '30000', 'bnc_ca_ttc' => '80000', 'min_loc_02' => '80000', 'chef' => '1', 'ded_rs' => '2000'],
            't' => ['acomptes' => [['montant' => '1000']]]]))->assertSessionHas('ok');
        $f = Declaration::where(['client_id' => $c->id, 'type' => 'ann'])->sole()->champs;
        $this->assertSame('ir', $f['modele']);
        $C = A::calculIr($f);
        $this->assertSame([29600.0, 300.0, 100.0], [$C['rni'], $C['ded_chef'], $C['ded_enf']]);
        $this->assertSame(r3(A::bareme(29600) - 3000), $C['solde']);

        $r = $this->get(route('declarations.arabe', $this->q()))->assertOk()->assertSee('التصريح بالضريبة على دخل الأشخاص الطبيعيين')->assertSee('أرباح المهن غير التجارية')->assertSee('30 000,000')->assertSee('29 600,000');
        $r->assertSee('<span class="cases" dir="ltr"><span class="bx">1</span><span class="bx">2</span><span class="bx">3</span><span class="bx">4</span><span class="bx">5</span><span class="bx">6</span><span class="bx">7</span><span class="bx">R</span></span>', false);
        $this->get(route('declarations.print', $this->q()))->assertOk()->assertSee('impôt sur le revenu des personnes physiques');
        $this->assertStringContainsString('Résultat fiscal";30000,000', $this->get(route('declarations.csv', $this->q()))->assertOk()->getContent());
    }

    public function test_saisie_pre_remplissage_et_formulaires(): void
    {
        PassageFiscal::create(['client_id' => $this->client->id, 'annee' => 2025, 'donnees' => ['rei' => 5000, 'ded' => 1000, 'taux' => 20, 'acp' => 2400, 'rs' => 300]]);
        Compta::passer($this->client->id, '2025-06-30', 'VTE', 'Ventes', [['compte' => '411', 'debit' => 119000, 'credit' => 0], ['compte' => '7061', 'debit' => 0, 'credit' => 100000], ['compte' => '4367', 'debit' => 0, 'credit' => 19000]]);
        Compta::passer($this->client->id, '2025-07-31', 'ACH', 'Achats', [['compte' => '6061', 'debit' => 40000, 'credit' => 0], ['compte' => '401', 'debit' => 0, 'credit' => 40000]]);

        $this->get(route('declarations.index', $this->q()))->assertOk()->assertSee('Déclaration de l’impôt sur les sociétés')->assertSee('Personne morale');
        $this->post(route('declarations.prefill'), $this->q())->assertSessionHas('ok');
        $f = Declaration::where(['type' => 'ann', 'periode' => '2025'])->sole()->champs;
        $this->assertEquals([100000, 119000, 60000, 64000, 64000, 20, 300], [$f['ca_loc'], $f['ca_ttc'], $f['res_compta'], $f['res_fiscal'], $f['benef_net'], $f['taux'], $f['ded_rs']]);
        $this->assertEquals([['montant' => 2400]], $f['acomptes']);

        // Saisie : nature, cases, tableau des acomptes, pénalités
        $this->post(route('declarations.save'), $this->q(['imp_saisie' => 1, 'imp' => ['is', 'fonds'], 'e' => ['acte' => '0', 'nature' => 'd', 'ouv' => '2025-01-01', 'clo' => '2025-12-31', 'activite' => 'Négoce'],
            'f' => ['taux' => '20', 'benef_net' => '64000', 'ca_min' => '119000', 'res_fiscal' => '64000', 'ded_rs' => '300', 'fonds_base' => '1000', 'pen_is' => '50'],
            't' => ['acomptes' => [['recette' => 'Tunis', 'date' => '2025-06-25', 'num' => '123', 'montant' => '2400'], ['recette' => '', 'montant' => '']]]]))->assertSessionHas('ok');
        $f = Declaration::where(['type' => 'ann', 'periode' => '2025'])->sole()->champs;
        $this->assertCount(1, $f['acomptes']);
        $this->assertSame(['is', 'fonds'], A::impotsIs($f));
        $C = A::calculIs($f);
        $this->assertSame([12800.0, 10100.0, 1920.0, 70.0], [$C['is_du'], $C['IV'], $C['css_du'], $C['fonds']]);   // 20 % ; 12 800 − 300 − 2 400 ; 3 %
        $this->assertSame(12140.0, $C['tot']);                                                                 // 10 100 + 50 + 1 920 + 70

        $this->get(route('declarations.index', $this->q()))->assertOk()->assertSee('12 140,000');
        $r = $this->get(route('declarations.arabe', $this->q()))->assertOk()->assertSee('التصريح بالضريبة على الشركات')->assertSee('الخلاصة')->assertSee('12 800,000')->assertSee('10 100,000');
        $r->assertSee('<span class="cases" dir="ltr"><span class="bx">2</span><span class="bx">0</span><span class="bx">2</span><span class="bx">5</span></span>', false);
        $this->get(route('declarations.print', $this->q()))->assertOk()->assertSee('Synthèse')->assertSee('Acomptes provisionnels payés');
    }
}
