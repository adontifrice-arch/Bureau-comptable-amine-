<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PassageFiscal;
use App\Models\User;
use App\Services\Compta;
use App\Services\TableauPassage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tableau de passage au résultat fiscal (note commune n° 26/2016, annexes 1 et 2), à partir du résultat comptable après impôt. */
class TableauPassageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'chef', 'status' => 'active']));
    }

    private function dossier(array $attrs): Client
    {
        $c = Client::create($attrs);
        session(['dossier' => $c->id]);
        Compta::passer($c->id, '2025-06-30', 'VTE', 'Ventes', [['compte' => '411', 'debit' => 100000, 'credit' => 0], ['compte' => '7061', 'debit' => 0, 'credit' => 100000]]);
        Compta::passer($c->id, '2025-07-31', 'ACH', 'Achats', [['compte' => '6061', 'debit' => 60000, 'credit' => 0], ['compte' => '401', 'debit' => 0, 'credit' => 60000]]);
        Compta::passer($c->id, '2025-12-31', 'OD', 'Impôt', [['compte' => '691', 'debit' => 8000, 'credit' => 0], ['compte' => '434', 'debit' => 0, 'credit' => 8000]]);
        return $c;
    }

    public function test_annexe_selon_la_nature_du_client(): void
    {
        $this->assertSame(1, TableauPassage::annexe(new Client(['type_personne' => 'physique'])));
        $this->assertSame(1, TableauPassage::annexe(new Client(['type_personne' => 'morale', 'forme' => 'SNC'])));
        $this->assertSame(2, TableauPassage::annexe(new Client(['type_personne' => 'morale', 'forme' => 'SARL'])));
    }

    public function test_personne_physique_annexe_1_depuis_le_resultat_apres_impot(): void
    {
        $this->dossier(['nom' => 'Ben Ali Sami', 'type_personne' => 'physique', 'forme' => 'Commerçant (BIC)']);

        $this->get(route('liasse.index', ['tab' => 'fis', 'annee' => 2025]))->assertOk()
            ->assertSee('annexe 1')->assertSee('Résultat comptable après impôt')->assertSee('32 000,000')
            ->assertSee('IRPP et CSS comptabilisés (comptes 69)')->assertSee('8 000,000')
            ->assertSee('Rémunérations de l’exploitant individuel', false)->assertSee('50 % des salaires servis aux demandeurs d’emploi', false)
            ->assertDontSee('Jetons de présence excédentaires')->assertDontSee('Résultat comptable avant impôt');

        $this->post(route('liasse.passage'), ['annee' => 2025,
            'rei_l' => ['r1_1' => '12000', 'r1_14' => '300.5', 'inconnu' => '999'], 'rei_autres' => [['lib' => 'Charges personnelles', 'm' => '200'], ['lib' => '', 'm' => '']],
            'ded_l' => ['d1' => '1000', 'd6' => ''], 'ded_autres' => [['lib' => 'Autre', 'm' => '500']]])->assertSessionHasNoErrors()->assertSessionHas('ok');

        $L = PassageFiscal::sole()->donnees;
        $this->assertEquals(['r1_1' => 12000, 'r1_14' => 300.5], $L['rei_l']);   // code inconnu ignoré
        $this->assertEquals([['lib' => 'Charges personnelles', 'm' => 200]], $L['rei_autres']);
        $this->assertEquals([12500.5, 1500], [$L['rei'], $L['ded']]);

        // Résultat fiscal = 32 000 (après impôt) + 8 000 (impôt) + 12 500,5 − 1 500
        $this->get(route('liasse.index', ['tab' => 'fis', 'annee' => 2025]))->assertOk()
            ->assertSee('value="300.5"', false)->assertSee('51 000,500')->assertSee('20 500,500');
    }

    public function test_sections_III_a_VII_jusqu_au_resultat_imposable(): void
    {
        // Annexe 1 : avant provisions 40 000 ; provisions 25 000 plafonnées à 20 000 (50 %)
        $c = TableauPassage::calcul(1, 32000, 8000, ['rei_l' => [], 'ded_l' => [], 'suite' => [
            'p1' => 15000, 'p2' => 10000,                   // provisions (F6005 57, 58)
            'a1' => 1000, 'a2' => 3000, 'a3' => 500,         // III (64 à 66)
            'x2' => 1500,                                    // IV : plus-value de cession d'actions cotées (71)
            'v1' => 2000, 'v4' => 500, 'v7' => 100,          // VI : loyers (82), plus-value immobilière (87), autres revenus accessoires (85)
            'e1' => 4000, 'e3' => 1000,                      // VII : exportation (93), revenus réinvestis (99)
        ]]);
        $this->assertSame([40000.0, 25000.0, 20000.0, 20000.0, 0.0, 20000.0], [$c['avant_prov'], $c['prov_total'], $c['prov_limite'], $c['apres_prov'], $c['mv'], $c['avant_deficits']]);
        $this->assertSame([17500.0, 16000.0, 13400.0, 12000.0, 11000.0], [$c['apres_deficits'], $c['V'], $c['VI'], $c['apres_exploitation'], $c['imposable']]);
        $this->assertSame([15000.0, 10000.0, 1500.0, 2000.0, 100.0, 500.0, 4000.0, 1000.0], [$c['F'][57], $c['F'][58], $c['F'][71], $c['F'][82], $c['F'][85], $c['F'][87], $c['F'][93], $c['F'][99]]);

        // Annexe 2 : moins-value de levée d'option limitée à 5 % du résultat après provisions ; réintégration du cinquième (+)
        $c = TableauPassage::calcul(2, 32000, 8000, ['rei_l' => ['r1_8' => 300, 'r1_17' => 200], 'ded_l' => ['d1' => 500], 'suite' => ['mv' => 5000, 'e4' => 600, 'x1' => 1000]]);
        $this->assertSame([300.0, 200.0, 500.0, 500.0], [$c['F'][44], $c['F'][23], $c['F'][45], $c['F'][46]]);   // jetons de présence en « autres » (44)
        $this->assertSame([2000.0, 38000.0, 37000.0, 37600.0], [$c['mv'], $c['avant_deficits'], $c['V'], $c['imposable']]);

        // Cas 1 : déficit fiscal, pas de provisions ; déficit reportable (107)
        $c = TableauPassage::calcul(2, -5000, 0, ['suite' => ['p1' => 800, 'mv' => 100]]);
        $this->assertSame([0.0, 0.0, 0.0, true, -5000.0], [$c['prov'], $c['mv'], $c['imposable'], $c['deficitaire'], $c['deficit_reportable']]);

        // Cas 2 : déficits reportés supérieurs au résultat : lignes 66 à 101 à zéro, 107 = 102 + 103 − 104 − 105 − 106
        $c = TableauPassage::calcul(1, 1000, 0, ['suite' => ['a2' => 3000]]);
        $this->assertSame([0.0, true, -3000.0], [$c['imposable'], $c['deficitaire'], $c['deficit_reportable']]);

        // Cas 5 : déductions au titre de l'exploitation supérieures au bénéfice : résultat imposable nul
        $c = TableauPassage::calcul(1, 1000, 0, ['suite' => ['e1' => 5000, 'e3' => 100]]);
        $this->assertSame([0.0, 0.0, false], [$c['apres_exploitation'], $c['imposable'], $c['deficitaire']]);
    }

    public function test_la_liasse_et_les_declarations_reprennent_le_tableau_de_passage(): void
    {
        $c = $this->dossier(['nom' => 'Alpha SARL', 'type_personne' => 'morale', 'forme' => 'SARL', 'paie_activite' => 'Négoce']);
        $this->post(route('liasse.passage'), ['annee' => 2025, 'taux' => 15, 'rei_l' => ['r1_17' => '200', 'r1_8' => '300'], 'ded_l' => ['d2' => '100'],
            'suite' => ['p1' => '1000', 'a2' => '2000', 'x1' => '400', 'e1' => '3000', 'e3' => '500']])->assertSessionHasNoErrors();

        // Liasse : chaque ligne à sa place dans le F6005
        $F = \App\Services\LiasseFiscale::calculer($c, 2025)['docs']['F6005']['n'];
        $this->assertSame([40000.0, 200.0, 300.0, 500.0, 100.0, 40400.0], [$F[2], $F[23], $F[44], $F[45], $F[47], $F[55]]);
        $this->assertSame([1000.0, 39400.0, 2000.0, 37400.0, 400.0, 37000.0, 3000.0, 34000.0, 500.0, 33500.0],
            [$F[57], $F[61], $F[65], $F[68], $F[69], $F[80], $F[93], $F[98], $F[99], $F[101]]);
        $this->get(route('liasse.index', ['tab' => 'fis', 'annee' => 2025]))->assertSee('33 500,000')->assertSee('5 025,000');   // IS 15 %

        // Déclaration IS : résultat fiscal (55), bénéfice avant avantages (80), bénéfice imposable (101), bénéfices déduits
        $q = ['type' => 'ann', 'periode' => '2025'];
        $this->post(route('declarations.prefill'), $q)->assertSessionHas('ok');
        $f = \App\Models\Declaration::where('client_id', $c->id)->sole()->champs;
        $this->assertEquals([40400, 37000, 33500], [$f['res_fiscal'], $f['benef_av'], $f['benef_net']]);
        $this->assertEquals([['Bénéfices au titre de l’exportation', 3000], ['Revenus réinvestis', 500]], array_map(fn ($l) => [$l['cadre'], $l['deduit']], $f['reinv']));
        $this->post(route('declarations.prefill'), $q);   // nouveau pré-remplissage : pas de doublon
        $this->assertCount(2, \App\Models\Declaration::where('client_id', $c->id)->sole()->champs['reinv']);
    }

    public function test_resultat_imposable_irpp_dans_le_passage(): void
    {
        $c = $this->dossier(['nom' => 'Ben Ali Sami', 'type_personne' => 'physique', 'forme' => 'Commerçant (BIC)', 'paie_activite' => 'Négoce',
            'chef_famille' => true, 'enfants_charge' => [['prenom' => 'Sami', 'type' => 'moins_20']], 'parents_charge' => 1]);
        // Pertes antérieures saisies dans la déclaration IR de l'exercice
        \App\Models\Declaration::create(['client_id' => $c->id, 'type' => 'ann', 'periode' => '2025', 'statut' => 'brouillon', 'annexes' => [],
            'champs' => ['modele' => 'ir', 'pertes' => [['annee' => '2023', 'montant' => 1000]]]]);
        $this->post(route('liasse.passage'), ['annee' => 2025, 'rei_l' => ['r1_14' => '0.4'], 'suite' => ['e5' => '3000', 'e3' => '500']])->assertSessionHasNoErrors();

        // 40 000,4 − 1 000 (pertes) − 3 000 (activité) − 500 (réinvestis) − 850 (communes : 300 + 100 + parent 450 au plus) = 34 650,4 → 34 651
        $fisc = \App\Http\Controllers\LiasseController::fiscal($c, \App\Services\Compta::etats($c->id, 2025)['P'], PassageFiscal::sole()->donnees, 2025);
        $ir = $fisc['ir'];
        $this->assertSame([40000.4, 1000.0, 3000.0, 500.0, 300.0, 100.0, 450.0, 850.0, 34650.4, 34651.0],
            [$ir['activite'], $ir['pertes'], $ir['ded_act'], $ir['reinv'], $ir['ded_chef'], $ir['ded_enf'], $ir['ded_parents'], $ir['ded_communes'], $ir['rni'], $ir['rni_arrondi']]);
        $this->assertSame([34651.0, r3(\App\Services\DeclarationAnnuelle::bareme(34651))], [$fisc['imposable'], $fisc['th']]);

        $this->get(route('liasse.index', ['tab' => 'fis', 'annee' => 2025]))->assertOk()
            ->assertSee('Pertes des années antérieures non encore déduites')->assertSee('Déductions au titre des revenus et bénéfices d’activité', false)
            ->assertSee('Déductions des revenus réinvestis')->assertSee('Déductions communes')->assertSee('Résultat imposable arrondi au dinar supérieur')
            ->assertSee('34 650,400')->assertSee('34 651,000');

        // Rien n'est enregistré dans la déclaration par l'affichage du passage
        $this->assertSame([['annee' => '2023', 'montant' => 1000]], \App\Models\Declaration::sole()->champs['pertes']);
        $this->assertArrayNotHasKey('bic_res_fiscal', \App\Models\Declaration::sole()->champs);
    }

    public function test_declaration_ir_reprend_les_avantages_et_les_deficits(): void
    {
        $c = $this->dossier(['nom' => 'Ben Ali Sami', 'type_personne' => 'physique', 'forme' => 'Commerçant (BIC)', 'paie_activite' => 'Négoce']);
        $this->post(route('liasse.passage'), ['annee' => 2025, 'rei_l' => ['r1_14' => '200'], 'ded_l' => ['d1' => '100'],
            'suite' => ['p1' => '1000', 'a2' => '2000', 'x2' => '400', 'e5' => '3000', 'e3' => '500']])->assertSessionHasNoErrors();
        $this->post(route('declarations.prefill'), ['type' => 'ann', 'periode' => '2025'])->assertSessionHas('ok');
        $f = \App\Models\Declaration::where('client_id', $c->id)->sole()->champs;

        // Résultat fiscal = ligne 80 de la liasse : 40 000 + 200 − 100 − 1 000 (provisions) − 2 000 (déficits) − 400 (plus-value exonérée)
        $this->assertEquals(36700, $f['bic_res_fiscal']);
        $this->assertSame((float) \App\Services\LiasseFiscale::calculer($c, 2025)['docs']['F6005']['n'][80], (float) $f['bic_res_fiscal']);
        $this->assertEquals([['Bénéfices au titre du développement régional', 3000]], array_map(fn ($l) => [$l['cadre'], $l['montant']], $f['ded_act']));
        $this->assertEquals([['Revenus réinvestis', 500]], array_map(fn ($l) => [$l['cadre'], $l['deduit']], $f['reinv']));
        $this->assertEmpty($f['pertes'] ?? []);                                            // déficits déjà déduits dans la ligne 80
        $this->assertEquals(36700 - 3000 - 500, \App\Services\DeclarationAnnuelle::calculIr($f)['rni']);   // = résultat imposable de la liasse (101)

        // Déficit : la déclaration reprend le déficit reportable (107)
        $this->post(route('liasse.passage'), ['annee' => 2025, 'ded_l' => ['d1' => '50000']])->assertSessionHasNoErrors();
        $this->post(route('declarations.prefill'), ['type' => 'ann', 'periode' => '2025'])->assertSessionHas('ok');
        $this->assertEquals(-10000, \App\Models\Declaration::where('client_id', $c->id)->sole()->champs['bic_res_fiscal']);
    }

    public function test_saisie_des_sections_III_a_VII_et_impot_sur_le_resultat_imposable(): void
    {
        $this->dossier(['nom' => 'Alpha SARL', 'type_personne' => 'morale', 'forme' => 'SARL']);
        $this->get(route('liasse.index', ['tab' => 'fis', 'annee' => 2025]))->assertOk()
            ->assertSee('III- Déduction des amortissements différés et déficits reportés')->assertSee('IV- Déduction des bénéfices ou revenus exceptionnels non imposables')
            ->assertSee('V- Résultat fiscal avant déduction des bénéfices provenant de l’exploitation', false)->assertSee('VI- Bénéfices servant de base', false)
            ->assertSee('Résultat imposable')->assertSee('levée de l’option', false)->assertSee('compagnies d’assurance', false)
            ->assertSee('Dividendes et assimilés distribués par des sociétés établies en Tunisie');

        $this->post(route('liasse.passage'), ['annee' => 2025, 'taux' => 15, 'suite' => ['a2' => '10000', 'inconnu' => '5', 'v1' => '']])->assertSessionHasNoErrors();
        $this->assertEquals(['a2' => 10000], PassageFiscal::sole()->donnees['suite']);

        // 32 000 + 8 000 − 10 000 de déficits reportés = 30 000 imposables ; IS 15 % = 4 500
        $this->get(route('liasse.index', ['tab' => 'fis', 'annee' => 2025]))->assertOk()->assertSee('value="10000"', false)->assertSee('30 000,000')->assertSee('4 500,000');
        $this->post(route('liasse.passage'), ['annee' => 2025, 'suite' => ['a2' => '-5']])->assertSessionHasErrors('suite.a2');
    }

    public function test_societe_annexe_2_et_saisie_anterieure_conservee(): void
    {
        $c = $this->dossier(['nom' => 'Alpha SARL', 'type_personne' => 'morale', 'forme' => 'SARL']);
        PassageFiscal::create(['client_id' => $c->id, 'annee' => 2025, 'donnees' => ['rei' => 700, 'ded' => 100, 'taux' => 15]]);   // totaux seuls

        $this->get(route('liasse.index', ['tab' => 'fis', 'annee' => 2025]))->assertOk()
            ->assertSee('annexe 2')->assertSee('Impôt sur les sociétés comptabilisé (comptes 69)')->assertSee('Jetons de présence excédentaires')
            ->assertSee('Produits réalisés par les établissements situés à l’étranger', false)
            ->assertSee('Montant global (saisie antérieure)')->assertSee('value="700"', false)->assertSee('40 600,000');   // 32 000 + 8 000 + 700 − 100

        $this->post(route('liasse.passage'), ['annee' => 2025, 'taux' => 15, 'rei_l' => ['r1_8' => 'abc']])->assertSessionHasErrors('rei_l.r1_8');
    }
}
