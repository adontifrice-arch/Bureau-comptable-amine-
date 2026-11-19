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
