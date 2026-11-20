<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Declaration;
use App\Models\Echeance;
use App\Models\Setting;
use App\Models\User;
use App\Services\EcheancesFiscales as E;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Échéances fiscales et sociales générées à l'entrée de chaque mois, avec report au jour ouvrable. */
class EcheancesFiscalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 08:00:00');
        $this->actingAs(User::factory()->create(['role' => 'chef', 'status' => 'active']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function client(array $a): Client
    {
        return Client::create($a + ['nom' => 'Client '.uniqid()]);
    }

    /** Échéances du mois : [code => [date, intitulé]]. */
    private function du(Client $c, int $y, int $m): array
    {
        return collect(E::duMois($c, $y, $m))->mapWithKeys(fn ($e) => [$e[0] => [$e[1]->format('Y-m-d'), $e[2]]])->all();
    }

    public function test_report_au_jour_ouvrable(): void
    {
        $this->assertSame('2026-11-16', E::jourOuvrable(Carbon::parse('2026-11-15'))->format('Y-m-d'));   // dimanche → lundi
        $this->assertSame('2026-03-23', E::jourOuvrable(Carbon::parse('2026-03-20'))->format('Y-m-d'));   // fête de l'indépendance (vendredi) → lundi
        $this->assertSame('2026-11-20', E::jourOuvrable(Carbon::parse('2026-11-20'))->format('Y-m-d'));   // jour ouvrable : inchangé

        // Jour férié saisi au paramétrage
        Setting::put('fiscal', ['css' => 0.5, 'feries' => "16/11/2026\n2026-11-17"]);
        $this->assertSame('2026-11-18', E::jourOuvrable(Carbon::parse('2026-11-15'))->format('Y-m-d'));
    }

    public function test_declarations_mensuelles_le_15_le_20_ou_le_28(): void
    {
        $pp = $this->client(['type_personne' => 'physique']);
        $tele = $this->client(['type_personne' => 'morale', 'mode_depot' => 'teledeclaration']);
        $rec = $this->client(['type_personne' => 'morale', 'mode_depot' => 'recette']);

        $this->assertSame(['2026-11-16', 'Déclaration mensuelle d’octobre 2026'], $this->du($pp, 2026, 11)['dm']);   // 15 = dimanche
        $this->assertSame(['2026-11-20', 'Déclaration mensuelle d’octobre 2026'], $this->du($tele, 2026, 11)['dm']);
        $this->assertSame(['2026-11-30', 'Déclaration mensuelle d’octobre 2026'], $this->du($rec, 2026, 11)['dm']);   // 28 = samedi
        $this->assertSame(['2027-01-15', 'Déclaration mensuelle de décembre 2026'], $this->du($pp, 2027, 1)['dm']);
    }

    public function test_declarations_annuelles_employeur_cnss_et_rne(): void
    {
        $pm = $this->client(['type_personne' => 'morale', 'matricule_cnss' => '123']);
        $bic = $this->client(['type_personne' => 'physique', 'categories_revenus' => ['bic'], 'matricule_independant' => 'IND-1']);
        $bnc = $this->client(['type_personne' => 'physique', 'categories_revenus' => ['bnc', 'fonciers']]);

        $this->assertSame(['2027-03-25', 'Déclaration annuelle de l’impôt sur les sociétés 2026 (initiale ou définitive)'], $this->du($pm, 2027, 3)['da']);
        $this->assertArrayNotHasKey('dad', $this->du($pm, 2027, 6));
        Declaration::create(['client_id' => $pm->id, 'type' => 'ann', 'periode' => '2026', 'statut' => 'deposee', 'annexes' => [], 'champs' => ['nature' => 'p']]);
        $this->assertSame(['2027-06-25', 'Déclaration annuelle définitive de l’impôt sur les sociétés 2026'], $this->du($pm, 2027, 6)['dad']);

        $this->assertSame(['2027-04-26', 'Déclaration annuelle de l’impôt sur le revenu 2026 (BIC)'], $this->du($bic, 2027, 4)['da']);   // 25/04/2027 = dimanche
        $this->assertArrayNotHasKey('da', $this->du($bic, 2027, 5));
        $this->assertSame(['2027-05-25', 'Déclaration annuelle de l’impôt sur le revenu 2026'], $this->du($bnc, 2027, 5)['da']);
        $this->assertArrayNotHasKey('da', $this->du($bnc, 2027, 4));

        // Déclaration d'employeur : tous les clients, avec ou sans salariés ni matricule CNSS
        $this->assertSame(['2027-04-30', 'Déclaration d’employeur 2026'], $this->du($pm, 2027, 4)['de']);
        $this->assertSame(['2027-04-30', 'Déclaration d’employeur 2026'], $this->du($bnc, 2027, 4)['de']);
        $this->assertSame(['2027-04-30', 'Déclaration d’employeur 2026'], $this->du($this->client(['type_personne' => 'morale']), 2027, 4)['de']);
        $this->assertArrayNotHasKey('de', $this->du($bnc, 2027, 5));

        // CNSS : dans les 15 jours qui suivent le trimestre
        $this->assertSame(['2027-01-15', 'Déclaration CNSS des salaires – 4e trimestre 2026'], $this->du($pm, 2027, 1)['cn']);
        $this->assertSame(['2026-10-16', 'Cotisations CNSS du travailleur indépendant – 3e trimestre 2026'], $this->du($bic, 2026, 10)['ci']);   // 15/10 férié
        $this->assertSame(['2027-04-15', 'Déclaration CNSS des salaires – 1er trimestre 2027'], $this->du($pm, 2027, 4)['cn']);
        $this->assertArrayNotHasKey('cn', $this->du($bnc, 2027, 1));
        $this->assertArrayNotHasKey('cn', $this->du($pm, 2027, 2));

        // États financiers au RNE : personnes morales (31/07/2027 = samedi → lundi)
        $this->assertSame(['2027-08-02', 'Dépôt des états financiers 2026 au RNE'], $this->du($pm, 2027, 7)['rne']);
        $this->assertArrayNotHasKey('rne', $this->du($bic, 2027, 7));
    }

    public function test_generation_a_l_entree_du_mois_sans_doublon_ni_recreation(): void
    {
        $c = $this->client(['type_personne' => 'morale', 'mode_depot' => 'teledeclaration', 'matricule_cnss' => '123']);

        // Première requête du mois : déclaration mensuelle et CNSS d'octobre
        $this->get(route('echeances.index'))->assertOk()->assertSee('Déclaration mensuelle de septembre 2026')->assertSee('Déclaration CNSS des salaires – 3e trimestre 2026');
        $this->assertSame('2026-10', Setting::get('echeances_auto'));
        $mois = fn () => Echeance::where('client_id', $c->id)->whereIn('auto', ['dm', 'cn'])->count();
        $this->assertSame(2, $mois());
        $this->get(route('echeances.index'));
        $this->assertSame(2, $mois());

        // Supprimée : jamais recréée ; mode de dépôt modifié : échéance non cochée déplacée au 28
        Echeance::where('auto', 'cn')->sole()->delete();
        E::genererMois($c, 2026, 10);
        $this->assertSame(1, $mois());
        $c->update(['mode_depot' => 'recette']);
        E::genererMois($c->fresh(), 2026, 10);
        $this->assertSame('2026-10-28', Echeance::where('auto', 'dm')->sole()->date_echeance->format('Y-m-d'));

        // Mois suivant : nouvelle génération
        Carbon::setTestNow('2026-11-02 08:00:00');
        $this->get(route('echeances.index'));
        $this->assertNotNull(Echeance::where('intitule', 'Déclaration mensuelle d’octobre 2026')->first());

        // Client créé en cours de mois : pas d'échéance antérieure à sa création
        Carbon::setTestNow('2026-11-18 08:00:00');
        $this->post(route('clients.store'), ['nom' => 'Nouveau', 'type_personne' => 'physique', 'categories_revenus' => ['bnc']])->assertSessionHasNoErrors();
        $this->assertNull(Echeance::where('client_id', Client::where('nom', 'Nouveau')->sole()->id)->where('auto', 'dm')->first());   // le 16/11 est passé
    }

    public function test_acompte_exigible_seulement_dans_son_mois_de_paiement(): void
    {
        $pp = $this->client(['type_personne' => 'physique']);
        $pm = $this->client(['type_personne' => 'morale']);

        $this->assertSame(['2027-12-27', '3e acompte provisionnel 2027'], $this->du($pp, 2027, 12)['ap']);   // 25/12/2027 = samedi
        $this->assertSame(['2026-09-28', '2e acompte provisionnel 2026'], $this->du($pm, 2026, 9)['ap']);
        $this->assertSame(['2026-06-29', '1er acompte provisionnel 2026'], $this->du($pm, 2026, 6)['ap']);   // 28/06/2026 = dimanche
        foreach ([1, 2, 3, 4, 5, 7, 8, 10, 11] as $m) $this->assertArrayNotHasKey('ap', $this->du($pp, 2026, $m));

        // Acomptes futurs créés à l'avance (ancienne génération sur 5 ans) : retirés s'ils ne sont pas cochés
        $creer = fn ($i, $d, $fait = null) => Echeance::create(['client_id' => $pp->id, 'auto' => 'ap', 'intitule' => $i, 'date_echeance' => $d, 'categorie' => E::CATEGORIE_FISCALE, 'accomplie_le' => $fait]);
        $creer('3e acompte provisionnel 2026', '2026-12-25');
        $creer('1er acompte provisionnel 2027', '2027-06-25', '2026-09-30');
        E::genererMois($pp, 2026, 10);
        $this->assertSame(['1er acompte provisionnel 2027'], Echeance::where('client_id', $pp->id)->where('auto', 'ap')->pluck('intitule')->all());

        // Entrée de décembre : l'acompte est ajouté, reporté si le 25 est un jour férié saisi au paramétrage
        Setting::put('fiscal', ['css' => 0.5, 'feries' => '25/12/2026']);
        E::genererMois($pp, 2026, 12);
        $this->assertSame('2026-12-28', Echeance::where(['client_id' => $pp->id, 'intitule' => '3e acompte provisionnel 2026'])->sole()->date_echeance->format('Y-m-d'));
    }

    public function test_commande_mensuelle(): void
    {
        $this->client(['type_personne' => 'physique']);
        $this->artisan('echeances:generer', ['--mois' => '2026-12'])->assertSuccessful();
        $this->assertSame(1, Echeance::where('intitule', 'Déclaration mensuelle de novembre 2026')->count());
    }
}
