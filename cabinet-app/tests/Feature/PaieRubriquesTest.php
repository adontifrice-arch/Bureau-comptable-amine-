<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Paie;
use App\Models\PaieRubrique;
use App\Models\Salarie;
use App\Models\User;
use App\Services\PaieCalc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Paie, menu Rubrique : fiche rubrique, effet sur le calcul, saisie mensuelle, bulletin, écriture et sauvegarde. */
class PaieRubriquesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Salarie $karim;
    private array $P;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'chef', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha Conseil']);
        session(['dossier' => $this->client->id]);
        $this->karim = Salarie::create(['client_id' => $this->client->id, 'nom' => 'Karim Haddad', 'salaire_base' => 1300, 'profil' => 'Ordinaire']);
        $this->P = PaieCalc::parametres($this->client);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rub(array $a): PaieRubrique
    {
        static $code = 900;
        return PaieRubrique::create($a + ['client_id' => $this->client->id, 'code' => ++$code, 'categorie' => 'prime', 'nom' => 'Rubrique '.$code, 'mode' => 'montant']);
    }

    private function calc(array $saisie = [], ?float $jours = null, ?Salarie $e = null): array
    {
        return PaieCalc::calcul($e ?? $this->karim, 0, $this->P, null, $jours, 0, PaieRubrique::duDossier($this->client), $saisie);
    }

    private function equilibree(array $lignes): void
    {
        $this->assertEqualsWithDelta(array_sum(array_column($lignes, 'debit')), array_sum(array_column($lignes, 'credit')), 0.0011);
    }

    public function test_sans_rubrique_le_calcul_ne_change_pas(): void
    {
        $avant = PaieCalc::calcul($this->karim, 0, $this->P);
        $apres = $this->calc();
        foreach (['brut', 'cnssS', 'ir', 'css', 'net', 'cnssP', 'cout'] as $k) $this->assertEquals($avant[$k], $apres[$k], $k);
    }

    public function test_prime_fixe_pourcentage_et_proportion(): void
    {
        $this->rub(['montant' => 100]);
        $this->rub(['mode' => 'pourcentage', 'base' => 'base', 'taux' => 10]);
        $this->rub(['mode' => 'proportion', 'base' => 'base', 'numerateur' => 1, 'denominateur' => 26]);
        $x = $this->calc();
        $this->assertEqualsWithDelta(100 + 130 + 50, $x['gains'], 0.001);
        $this->assertEqualsWithDelta(1300 + 280, $x['brut'], 0.001);
        $this->assertEqualsWithDelta(r3(1580 * $this->P['cnssS'] / 100), $x['cnssS'], 0.001);
    }

    public function test_rubrique_calculee_sur_une_autre_rubrique(): void
    {
        $a = $this->rub(['montant' => 200]);
        $this->rub(['mode' => 'pourcentage', 'base' => 'r:'.$a->id, 'taux' => 50]);
        $this->assertEqualsWithDelta(300, $this->calc()['gains'], 0.001);
    }

    public function test_non_cotisable_et_non_imposable(): void
    {
        $sans = $this->calc();
        $this->rub(['montant' => 100, 'non_cotisable' => true, 'non_imposable' => true, 'categorie' => 'indemnite']);
        $x = $this->calc();
        $this->assertEqualsWithDelta($sans['brut'] + 100, $x['brut'], 0.001);
        $this->assertEquals($sans['cnssS'], $x['cnssS']);
        $this->assertEquals($sans['ir'], $x['ir']);
        $this->assertEquals($sans['css'], $x['css']);
        $this->assertEqualsWithDelta($sans['net'] + 100, $x['net'], 0.001);
    }

    public function test_prorata_et_prime_conditionnee(): void
    {
        $this->rub(['montant' => 260, 'prorata_travail' => true]);
        $this->rub(['montant' => 50, 'conditionnee' => true]);
        $x = $this->calc([], 13);                 // 13 jours sur 26
        $this->assertEqualsWithDelta(130, $x['gains'], 0.001);
        $this->assertEqualsWithDelta(310, $this->calc()['gains'], 0.001);
    }

    public function test_saisie_manuelle_heures_sup_note_et_dependance(): void
    {
        $m = $this->rub(['mode' => 'manuelle']);
        $hs = $this->rub(['mode' => 'manuelle', 'heures_sup' => true]);
        $note = $this->rub(['mode' => 'manuelle', 'note' => true, 'categorie' => 'autre', 'nom' => 'Repas']);
        $this->rub(['montant' => 5, 'depend_note' => true, 'note_id' => $note->id]);
        $x = $this->calc([$m->id => 75, $hs->id => 4, $note->id => 20]);
        $tauxH = 1300 / $this->P['heures_mois'];
        $this->assertEqualsWithDelta(75 + r3(4 * $tauxH) + 100, $x['gains'], 0.002);
        $l = collect($x['rubriques'])->firstWhere('id', $note->id);
        $this->assertSame('note', $l['sens']);
        $this->assertEquals(20, $l['quantite']);
        // Sans saisie, une rubrique manuelle n'apparaît pas.
        $this->assertEqualsWithDelta(0, $this->calc()['gains'], 0.001);
    }

    public function test_retenues_et_avantage_en_nature_dans_l_ecriture(): void
    {
        $sans = $this->calc();
        $this->rub(['montant' => 50, 'a_deduire' => true]);                                     // cotisable : déduite du brut
        $this->rub(['montant' => 30, 'a_deduire' => true, 'non_cotisable' => true]);            // déduite du net
        $this->rub(['montant' => 120, 'categorie' => 'avantage', 'nom' => 'Logement']);         // en nature
        $x = $this->calc();
        $this->assertEqualsWithDelta($sans['brut'] - 50 + 120, $x['brut'], 0.001);
        $this->assertEqualsWithDelta(30, $x['retenues'], 0.001);
        $this->assertEqualsWithDelta(120, $x['nature'], 0.001);
        $this->assertEqualsWithDelta($x['brut'] - $x['cnssS'] - $x['ras'] - 30 - 120, $x['net'], 0.001);
        $L = PaieCalc::lignes(PaieCalc::totaux([$x]));
        $this->assertEqualsWithDelta(30, collect($L)->firstWhere('compte', '427')['credit'], 0.001);
        $this->assertEqualsWithDelta(120, collect($L)->firstWhere('compte', '79')['credit'], 0.001);
        $this->equilibree($L);
    }

    public function test_retenue_sur_total_brut_et_salaire_imposable(): void
    {
        $prime = $this->rub(['montant' => 200, 'nom' => 'Prime']);
        $avant = $this->calc();
        $this->rub(['categorie' => 'autre', 'nom' => 'Mutuelle', 'mode' => 'pourcentage', 'base' => 'brut', 'taux' => 1, 'a_deduire' => true, 'non_cotisable' => true]);
        $this->rub(['categorie' => 'autre', 'nom' => 'Cotisation', 'mode' => 'proportion', 'base' => 'imposable', 'numerateur' => 1, 'denominateur' => 200, 'a_deduire' => true, 'non_cotisable' => true]);
        $x = $this->calc();
        // Brut 1300 + 200 = 1500 ; imposable = brut − CNSS salariale ; brut, CNSS et IRPP sont inchangés, seul le net baisse.
        $this->assertEqualsWithDelta(1500, $x['brut'], 0.001);
        $this->assertSame([$avant['brut'], $avant['cnssS'], $avant['ir']], [$x['brut'], $x['cnssS'], $x['ir']]);
        $L = collect($x['rubriques'])->keyBy('nom');
        $this->assertEqualsWithDelta(15, $L['Mutuelle']['montant'], 0.001);
        $this->assertEqualsWithDelta(r3((1500 - $x['cnssS']) / 200), $L['Cotisation']['montant'], 0.001);
        $this->assertEqualsWithDelta($avant['net'] - 15 - $L['Cotisation']['montant'], $x['net'], 0.001);
        $this->equilibree(PaieCalc::lignes(PaieCalc::totaux([$x])));

        // Fiche rubrique : ces bases sont refusées pour une prime (elle changerait son propre brut).
        $this->post(route('paie.rubriques.store'), ['code' => 600, 'categorie' => 'prime', 'nom' => 'Prime sur brut', 'mode' => 'pourcentage', 'base' => 'brut', 'taux' => 5])
            ->assertSessionHasErrors('base');
        $this->post(route('paie.rubriques.store'), ['code' => 601, 'categorie' => 'autre', 'nom' => 'Retenue sur imposable', 'mode' => 'pourcentage', 'base' => 'imposable', 'taux' => 2,
            'a_deduire' => 1, 'non_cotisable' => 1])->assertSessionHasNoErrors();
        $this->assertSame('salaire imposable × 2 %', PaieRubrique::firstWhere('code', 601)->libelleValeur(PaieRubrique::duDossier($this->client)));
    }

    public function test_valeur_net(): void
    {
        $sans = $this->calc();
        $this->rub(['montant' => 200, 'valeur_net' => true]);
        $x = $this->calc();
        $this->assertEqualsWithDelta($sans['net'] + 200, $x['net'], 0.001);
        $this->assertGreaterThan(200, $x['gains']);
    }

    public function test_rubrique_reservee_a_un_profil(): void
    {
        $sivp = Salarie::create(['client_id' => $this->client->id, 'nom' => 'Sami SIVP', 'salaire_base' => 500, 'profil' => 'SIVP']);
        $this->rub(['montant' => 100, 'profils' => ['SIVP']]);
        $this->assertEqualsWithDelta(0, $this->calc()['gains'], 0.001);
        $this->assertEqualsWithDelta(100, $this->calc([], null, $sivp)['gains'], 0.001);
    }

    public function test_fiche_rubrique_creation_validation_et_suppression(): void
    {
        $this->get(route('paie.rubriques'))->assertOk()->assertSee('Les primes')->assertSee('Les avantages en nature')->assertSee('Valeur de la rubrique');

        $this->post(route('paie.rubriques.store'), ['code' => 500, 'categorie' => 'prime', 'nom' => 'Prime de rendement spéciale', 'mode' => 'pourcentage', 'base' => 'base', 'taux' => 10,
            'non_imposable' => '1', 'profils' => ['Ordinaire']])->assertRedirect()->assertSessionHasNoErrors();
        $r = PaieRubrique::firstWhere('code', 500);
        $this->assertTrue($r->non_imposable);
        $this->assertFalse($r->non_cotisable);
        $this->assertSame(['Ordinaire'], $r->profils);
        $this->assertNull($r->montant);

        // Code déjà pris, valeur manquante pour le mode choisi.
        $this->post(route('paie.rubriques.store'), ['code' => 500, 'categorie' => 'prime', 'nom' => 'Doublon', 'mode' => 'montant'])
            ->assertSessionHasErrors(['code', 'montant']);

        $this->get(route('paie.rubriques', ['r' => $r->id]))->assertOk()->assertSee('Prime de rendement spéciale');
        $this->put(route('paie.rubriques.update', $r), ['code' => 500, 'categorie' => 'prime', 'nom' => 'Prime de rendement spéciale', 'mode' => 'montant', 'montant' => 80])
            ->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertSame('montant', $r->mode);
        $this->assertNull($r->base);
        $this->assertFalse($r->non_imposable);

        // Suppression refusée tant qu'une autre rubrique est calculée dessus.
        $dep = $this->rub(['mode' => 'pourcentage', 'base' => 'r:'.$r->id, 'taux' => 10, 'nom' => 'Complément']);
        $this->delete(route('paie.rubriques.destroy', $r))->assertSessionHas('err');
        $this->assertModelExists($r);
        $dep->delete();
        $this->delete(route('paie.rubriques.destroy', $r))->assertSessionHas('ok');
        $this->assertModelMissing($r);
    }

    public function test_saisie_du_mois_cloture_et_bulletin(): void
    {
        $hs = $this->rub(['mode' => 'manuelle', 'nom' => 'Prime exceptionnelle']);
        $this->rub(['montant' => 40, 'a_deduire' => true, 'non_cotisable' => true, 'nom' => 'Retenue mutuelle']);

        $this->get(route('paie.index', ['tab' => 'variable', 'mois' => '2026-11']))->assertOk()->assertSee('Saisie des éléments variables')->assertSee('Prime exceptionnelle');
        $this->post(route('paie.calcul'), ['mois' => '2026-11', 'rubriques' => [$this->karim->id => [$hs->id => '150', 999 => 'abc']]])->assertSessionHas('ok');
        $paie = Paie::firstWhere('mois', '2026-11');
        $this->assertEquals([$this->karim->id => [$hs->id => 150.0]], $paie->rubriques);

        $x = PaieCalc::mois($this->client, '2026-11')[0];
        $this->assertEqualsWithDelta(1450, $x['brut'], 0.001);
        $this->assertEqualsWithDelta(40, $x['retenues'], 0.001);

        $this->post(route('paie.cloturer'), ['mois' => '2026-11'])->assertSessionHas('ok');
        $this->assertEqualsWithDelta(40, $paie->fresh()->totaux['retenues'], 0.001);
        $this->get(route('paie.bulletins', ['mois' => '2026-11']))->assertOk()->assertSee('Prime exceptionnelle')->assertSee('Retenue mutuelle');
        $this->get(route('paie.bulletins', ['mois' => '2026-11', 'format' => 'a5']))->assertOk()->assertSee('Retenue mutuelle');
    }

    public function test_sauvegarde_et_restauration_des_rubriques(): void
    {
        $note = $this->rub(['mode' => 'manuelle', 'note' => true, 'categorie' => 'autre', 'nom' => 'Repas']);
        $dep = $this->rub(['montant' => 5, 'depend_note' => true, 'note_id' => $note->id, 'nom' => 'Panier']);
        Paie::create(['client_id' => $this->client->id, 'mois' => '2026-10', 'statut' => 'brouillon', 'rubriques' => [$this->karim->id => [$note->id => 12]]]);

        $json = $this->get(route('paie.sauvegarde'))->assertOk()->streamedContent();
        $data = json_decode($json, true);
        $this->assertCount(2, $data['rubriques']);

        $autre = Client::create(['nom' => 'Beta']);
        session(['dossier' => $autre->id]);
        $f = UploadedFile::fake()->createWithContent('sauvegarde.json', $json);
        $this->post(route('paie.restaurer'), ['fichier' => $f])->assertSessionHas('ok');

        $rubs = PaieRubrique::duDossier($autre);
        $this->assertCount(2, $rubs);
        $n = $rubs->firstWhere('nom', 'Repas');
        $this->assertSame($n->id, $rubs->firstWhere('nom', 'Panier')->note_id);
        $this->assertNotSame($note->id, $n->id);
        $sal = Salarie::where('client_id', $autre->id)->first();
        $this->assertEquals([$sal->id => [$n->id => 12.0]], Paie::where('client_id', $autre->id)->first()->rubriques);
        $this->assertEqualsWithDelta(60, PaieCalc::mois($autre, '2026-10')[0]['gains'], 0.001);
    }
    public function test_listes_standard_primes_indemnites_et_avantages(): void
    {
        $this->get(route('paie.rubriques'))->assertOk()->assertSee('Prime de travail en hauteur')->assertSee('Indemnité du congé Annuel payé');
        $this->assertSame(46, PaieRubrique::where('client_id', $this->client->id)->where('categorie', 'prime')->count());
        $this->assertSame(31, PaieRubrique::where('client_id', $this->client->id)->where('categorie', 'indemnite')->count());
        $p = PaieRubrique::firstWhere('nom', 'Prime de douche');
        $this->assertSame(101, $p->code);
        $this->assertSame(147, PaieRubrique::firstWhere('nom', 'Indemnité commune')->code);
        $this->assertSame('manuelle', $p->mode);
        $this->assertFalse($p->non_cotisable || $p->non_imposable);
        // Avantages en nature : tickets avec leur case cochée d'office, hors net versé.
        $tr = PaieRubrique::firstWhere('nom', 'Ticket Restaurant');
        $this->assertSame('avantage', $tr->categorie);
        $this->assertTrue($tr->ticket_restaurant);
        $this->assertFalse($tr->ticket_cadeau);
        $this->assertTrue(PaieRubrique::firstWhere('nom', 'Ticket Cadeau')->ticket_cadeau);
        $this->assertTrue($tr->enNature());
        // Autre : 21 rubriques (salaire de base, CNSS, IRPP et net à payer sont calculés, pas repris).
        $this->assertSame(21, PaieRubrique::where('client_id', $this->client->id)->where('categorie', 'autre')->count());
        $this->assertNull(PaieRubrique::firstWhere('nom', 'IRPP'));
        $acompte = PaieRubrique::firstWhere('nom', 'Acompte');
        $hs50 = PaieRubrique::firstWhere('nom', 'Heures Supplémentaires 50%');
        $this->assertTrue($acompte->a_deduire && $acompte->non_cotisable);
        $this->assertTrue($hs50->aSaisir() && $hs50->enQuantite());
        $sans = $this->calc();
        $x = $this->calc([$acompte->id => 200, $hs50->id => 2]);
        $this->assertEqualsWithDelta(r3(2 * 1300 / $this->P['heures_mois'] * 1.5), $x['gains'], 0.002);
        $this->assertEqualsWithDelta(200, $x['retenues'], 0.001);
        $this->assertLessThan($sans['net'] + $x['gains'] - 200 + 0.001, $x['net']);
        // Pas de doublon au passage suivant.
        $this->get(route('paie.rubriques'))->assertOk();
        $this->assertSame(100, PaieRubrique::where('client_id', $this->client->id)->count());
        // Une rubrique standard supprimée ne revient pas d'elle-même, seulement avec le bouton de sa catégorie.
        PaieRubrique::firstWhere('nom', 'Prime groupée')->delete();
        PaieRubrique::firstWhere('nom', 'Indemnité de presse')->delete();
        $this->get(route('paie.rubriques'))->assertOk();
        $this->assertSame(98, PaieRubrique::where('client_id', $this->client->id)->count());
        $this->post(route('paie.rubriques.standard'), ['categorie' => 'indemnite'])->assertSessionHas('ok', '1 rubrique(s) standard ajoutée(s) dans « Les indemnités ».');
        $this->assertSame(99, PaieRubrique::where('client_id', $this->client->id)->count());
        $this->assertNull(PaieRubrique::firstWhere('nom', 'Prime groupée'));
        // Les primes standard n'ont aucun effet tant qu'elles ne sont pas saisies.
        $this->assertEqualsWithDelta(0, $this->calc()['gains'], 0.001);
        // Saisie d'une prime standard pour le mois.
        $this->post(route('paie.calcul'), ['mois' => '2026-11', 'rubriques' => [$this->karim->id => [$p->id => '35']]])->assertSessionHas('ok');
        $this->assertEqualsWithDelta(35, PaieCalc::mois($this->client, '2026-11')[0]['gains'], 0.001);
        $this->get(route('paie.index', ['tab' => 'variable', 'mois' => '2026-11']))->assertOk()->assertSee('Ajouter une rubrique')->assertSee('Prime de douche');
    }
}
