<?php

namespace Tests\Feature;

use App\Http\Controllers\PaieController;
use App\Models\Client;
use App\Models\Salarie;
use App\Models\User;
use App\Services\Exports\EtatsPaie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Menu Paie : saisie, bulletin d'un employé, journal, bordereau payeur, ordre de virement, enveloppes, récapitulation, état vierge. */
class PaieMenuPaieTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Salarie $karim;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha Conseil']);
        session(['dossier' => $this->client->id]);
        $this->karim = Salarie::create(['client_id' => $this->client->id, 'nom' => 'Karim', 'salaire_base' => 1000, 'mode_paiement' => 'virement', 'rib' => '12345678901234567890']);
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Amel', 'salaire_base' => 800, 'mode_paiement' => 'especes']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_le_menu_paie_propose_toutes_les_entrees(): void
    {
        $r = $this->get(route('paie.index', ['mois' => '2026-10']))->assertOk();
        foreach (['Saisie Paie', 'Saisie Autre', 'Tous les bulletins', 'Par matricule', 'Par Nom/ Année', 'Par période', 'Générer les bulletins CIN', 'A5 Double reste congé', 'Matricielle', 'Modèle 2', 'Détaillé', 'Par régime salarial', 'Récapitulatif de virement', 'Fichier pour la banque', 'UIB — virement de masse', 'Par Employé', 'BIAT',
            'Bordereau payeur', 'Ordre de virement', 'Enveloppe Paie', 'Récapitulation de paie', 'Etat vierge de saisie',
            'Nb. jrs ouv.', 'Mois non encore enregistré', 'À l’envers', 'Actualiser tout', 'Variable'] as $l) $r->assertSee($l);
        $this->get(route('paie.index', ['tab' => 'variable', 'mois' => '2026-10']))->assertOk()->assertSee('Saisie des éléments variables');
    }

    public function test_saisie_paie_jours_du_mois_conges_feries_et_acompte(): void
    {
        $this->post(route('paie.calcul'), ['mois' => '2026-10', 'parties' => ['saisie'], 'jours_ouvrables' => 25, 'jours_feries' => 1, 'heures_jour' => 8, 'heures_ouvrables' => 200,
            'pointage' => [$this->karim->id => ['jours' => 20, 'conge' => 2, 'acompte' => 100]], 'primes' => []])->assertRedirect();
        $R = collect(\App\Services\PaieCalc::mois($this->client, '2026-10'))->keyBy('nom');
        // 20 jours travaillés + 2 jours de congé + 1 férié du mois = 23 jours payés sur 25.
        $this->assertSame(23.0, (float) $R['Karim']['jours']);
        $this->assertSame(25.0, (float) $R['Karim']['jours_mois']);
        $this->assertSame(920.0, (float) $R['Karim']['salaire']);
        $this->assertSame(100.0, (float) $R['Karim']['acompte']);
        $this->assertSame(800.0, (float) $R['Amel']['salaire']);   // mois complet
        // Net = brut − retenues − acompte ; l'écriture crédite le 425 du net avant acompte et reste équilibrée.
        $x = $R['Karim'];
        $this->assertEqualsWithDelta($x['brut'] - $x['cnssS'] - $x['ras'] - 100, $x['net'], 0.001);
        $L = \App\Services\PaieCalc::lignes(\App\Services\PaieCalc::totaux($R->values()->all()));
        $this->assertEqualsWithDelta(array_sum(array_column($L, 'debit')), array_sum(array_column($L, 'credit')), 0.001);
        // Saisie Autre ne touche pas au pointage.
        $this->post(route('paie.calcul'), ['mois' => '2026-10', 'parties' => ['autre'], 'rubriques' => []])->assertRedirect();
        $this->assertSame(20.0, (float) \App\Models\Paie::first()->pointage[$this->karim->id]['jours']);
        // Supprimer efface la saisie du mois.
        $this->post(route('paie.supprimer'), ['mois' => '2026-10'])->assertRedirect();
        $this->assertSame(0, \App\Models\Paie::count());
    }

    public function test_saisie_autre_paie_speciale_13eme_mois_et_conge(): void
    {
        $amel = Salarie::where('nom', 'Amel')->first();
        $this->get(route('paie.index', ['tab' => 'autre', 'type' => 'm13', 'annee' => 2026, 'decl' => '2026-10']))->assertOk()
            ->assertSee('À déclarer avec')->assertSee('13ème mois')->assertSee('18ème mois')->assertSee('Mois non encore enregistré');

        // 13ème mois déclaré avec octobre : Karim au salaire de base par défaut, Amel exclue (montant 0).
        $this->post(route('paie.calcul'), ['mois' => '2026-10', 'type' => 'm13', 'parties' => ['saisie'], 'pointage' => [$amel->id => ['montant' => 0]]])->assertRedirect();
        $p = \App\Models\Paie::where('type', 'm13')->firstOrFail();
        $RS = \App\Services\PaieCalc::moisSpecial($this->client, $p);
        $this->assertCount(1, $RS);
        $x = $RS[0];
        $this->assertSame('Karim', $x['nom']);
        $this->assertEqualsWithDelta(1000, $x['brut'], 0.001);
        $this->assertGreaterThan(0, $x['ir']);
        $this->assertEqualsWithDelta($x['brut'] - $x['cnssS'] - $x['ir'] - $x['css'], $x['net'], 0.001);
        // IRPP par différence : plus élevé que l'IRPP d'un mois ordinaire de même brut (tranche marginale).
        $normal = collect(\App\Services\PaieCalc::mois($this->client, '2026-10'))->firstWhere('nom', 'Karim');
        $this->assertGreaterThan($normal['ir'], $x['ir']);
        // La paie ordinaire d'octobre n'est pas touchée ; la déclaration CNSS d'octobre reprend le 13ème mois.
        $this->assertCount(2, \App\Services\PaieCalc::mois($this->client, '2026-10'));
        $this->assertNull(\App\Models\Paie::where('type', 'normale')->first());

        // Clôture → comptabilité, libellé de la paie spéciale ; ensuite la déclaration CNSS d'octobre l'inclut.
        $this->post(route('paie.cloturer'), ['mois' => '2026-10', 'type' => 'm13'])->assertSessionHas('ok');
        $this->assertSame('transmise', $p->fresh()->statut);
        $this->assertSame('13ème mois — déclaré avec octobre 2026', $p->fresh()->libelle());
        $dcnss = EtatsPaie::dcnss($this->client, '2026-10');
        $this->assertEqualsWithDelta(1000 + 1000 + 800, end($dcnss->lignes)['cellules'][4], 0.001);
        $this->get(route('paie.bulletins', ['type' => 'm13', 'mois' => '2026-10']))->assertOk()
            ->assertSee('13ème mois — déclaré avec octobre 2026')->assertSee('par différence sur l’année', false);

        // Éditions de la paie spéciale seule, et de la paie du mois avec les paies spéciales.
        foreach (['journal', 'bordereau', 'virement', 'recap'] as $e) {
            foreach (['imprimer', 'pdf', 'xlsx'] as $f) $this->get(route('paie.edition', [$e, $f, 'mois' => '2026-10', 'paie' => 'm13']))->assertOk();
        }
        $j = EtatsPaie::journal($this->client, '2026-10', 'modele1', 'integral', 'm13');
        $this->assertSame('13ème mois — déclaré avec octobre 2026', $j->periode);
        $this->assertCount(2, $j->lignes);   // Karim + total
        $tous = EtatsPaie::journal($this->client, '2026-10', 'modele1', 'integral', 'tous');
        $this->assertSame(['Amel', 'Karim', 'Karim — 13ème mois'], array_slice(array_map(fn ($l) => $l['cellules'][1], $tous->lignes), 0, 3));
        $this->assertSame([], EtatsPaie::aVirer($this->client, '2026-10', null, 'conge'));
        $this->assertSame(['Karim'], array_map(fn ($v) => $v[0]['nom'], EtatsPaie::aVirer($this->client, '2026-10', null, 'm13')));
        $this->assertStringContainsString('13EME MOIS 2026', $this->get(route('paie.virement.fichier', ['txt', 'mois' => '2026-10', 'paie' => 'm13']))->getContent());
        $this->get(route('paie.enveloppes', ['mois' => '2026-10', 'paie' => 'm13']))->assertOk()->assertSee('13ème mois — déclaré avec octobre 2026')->assertDontSee('Amel');
        $this->get(route('paie.virement.employes', ['mois' => '2026-10', 'paie' => 'tous']))->assertOk()->assertSee('Karim — 13ème mois');
        $recap = EtatsPaie::recap($this->client, '2026-10', 'tous');
        $this->assertContains('13ème mois', array_map(fn ($l) => $l['cellules'][1], $recap->lignes));
        $this->get(route('paie.index', ['tab' => 'autre', 'type' => 'm13', 'annee' => 2026, 'decl' => '2026-10']))->assertSee('Éditions — 13ème mois 2026');

        // Congé : 5 jours × salaire de base ÷ jours ouvrables ; les jours comptent dans les congés pris.
        $this->post(route('paie.calcul'), ['mois' => '2026-10', 'type' => 'conge', 'parties' => ['saisie'], 'pointage' => [$this->karim->id => ['jours' => 5]]])->assertRedirect();
        $c = \App\Services\PaieCalc::moisSpecial($this->client, \App\Models\Paie::where('type', 'conge')->first());
        $J = \App\Services\PaieCalc::parametres($this->client)['jours_mois'];
        $this->assertCount(1, $c);
        $this->assertEqualsWithDelta(r3(5 * 1000 / $J), $c[0]['salaire'], 0.001);
        $this->karim->update(['conges_acquis' => 2]);
        $this->get(route('paie.bulletins', ['mois' => '2026-10', 'format' => 'a5c', 'salarie' => $this->karim->id]))->assertSeeInOrder(['Congés pris', '5 j']);
        $this->post(route('paie.supprimer'), ['mois' => '2026-10', 'type' => 'conge'])->assertSessionHas('ok');
        $this->assertSame(1, \App\Models\Paie::count());
    }

    public function test_menu_prime_saisie_ouverture_et_editions(): void
    {
        $amel = Salarie::where('nom', 'Amel')->first();
        $bilan = \App\Models\PaieRubrique::create(['client_id' => $this->client->id, 'code' => 801, 'categorie' => 'prime', 'nom' => 'Prime de bilan', 'mode' => 'pourcentage', 'base' => 'base', 'taux' => 50]);
        \App\Models\PaieRubrique::create(['client_id' => $this->client->id, 'code' => 802, 'categorie' => 'prime', 'nom' => 'Prime de rendement', 'mode' => 'manuelle', 'non_cotisable' => true]);

        // Menu Prime : sans prime ouverte, les éditions sont grisées.
        $this->get(route('paie.index', ['mois' => '2026-10']))->assertOk()
            ->assertSee('Saisie Prime')->assertSee('Ouvrir Prime Saisie')->assertSee('Enveloppe Prime')->assertSee('Récapitulation de Prime')->assertSee('Ouvrez d’abord une prime saisie', false);
        $this->get(route('paie.index', ['tab' => 'prime', 'nouvelle' => 1, 'rubrique' => $bilan->id, 'decl' => '2026-10']))->assertOk()
            ->assertSee('Prime non encore enregistrée')->assertSee('salaire de base × 50 %');

        // Prime de bilan : 50 % du salaire de base, Amel exclue ; IRPP par différence.
        $this->post(route('paie.calcul'), ['type' => 'prime', 'mois' => '2026-10', 'rubrique_id' => $bilan->id, 'parties' => ['saisie'], 'pointage' => [$amel->id => ['montant' => 0]]])->assertRedirect();
        $p = \App\Models\Paie::where('type', 'prime')->firstOrFail();
        $this->assertSame('Prime de bilan', $p->libelle);
        $this->assertSame('Prime de bilan — déclarée avec octobre 2026', $p->libelle());
        $R = \App\Services\PaieCalc::moisSpecial($this->client, $p);
        $this->assertSame(['Karim'], array_column($R, 'nom'));
        $this->assertEqualsWithDelta(500, $R[0]['brut'], 0.001);
        $this->assertGreaterThan(0, $R[0]['cnssS']);

        // Deuxième prime du même mois : non cotisable → pas de CNSS ; saisie manuelle.
        $rend = \App\Models\PaieRubrique::firstWhere('code', 802);
        $this->post(route('paie.calcul'), ['type' => 'prime', 'mois' => '2026-10', 'rubrique_id' => $rend->id, 'parties' => ['saisie'], 'pointage' => [$this->karim->id => ['montant' => 200]]])->assertRedirect();
        $p2 = \App\Models\Paie::where('type', 'prime')->where('libelle', 'Prime de rendement')->firstOrFail();
        $R2 = \App\Services\PaieCalc::moisSpecial($this->client, $p2);
        $this->assertCount(1, $R2);
        $this->assertSame(0.0, $R2[0]['cnssS']);
        $this->assertSame(0.0, $R2[0]['cnssP']);

        // Ouvrir Prime Saisie → la prime devient celle des éditions du menu Prime.
        $this->get(route('paie.index', ['tab' => 'prime', 'prime' => $p->id, 'mois' => '2026-10']))->assertOk()->assertSee('Prime ouverte : Prime de bilan');
        foreach (['journal', 'bordereau', 'virement', 'recap'] as $e) $this->get(route('paie.edition', [$e, 'imprimer', 'mois' => '2026-10', 'paie' => $p->id]))->assertOk()->assertSee('Prime de bilan — déclarée avec octobre 2026');
        $this->get(route('paie.edition', ['journal', 'xlsx', 'mois' => '2026-10', 'paie' => $p->id, 'modele' => 'detaille', 'groupe' => 'departement']))->assertOk();
        $this->get(route('paie.bulletins', ['paie' => $p->id, 'format' => 'a5d']))->assertOk()->assertSee('Prime de bilan')->assertDontSee('Amel');
        $this->get(route('paie.enveloppes', ['mois' => '2026-10', 'paie' => $p->id]))->assertOk()->assertSee('Karim');
        $this->assertStringContainsString('PRIME DE BILAN', $this->get(route('paie.virement.fichier', ['txt', 'mois' => '2026-10', 'paie' => $p->id]))->getContent());

        // Clôture, réouverture et suppression par numéro ; la prime clôturée entre dans la déclaration CNSS d'octobre.
        $this->post(route('paie.cloturer'), ['paie_id' => $p->id])->assertSessionHas('ok');
        $this->assertSame('transmise', $p->fresh()->statut);
        $this->assertEqualsWithDelta(1000 + 800 + 500, end(EtatsPaie::dcnss($this->client, '2026-10')->lignes)['cellules'][4], 0.001);
        // La prime de bilan, payée à part en octobre, ne l'est pas une seconde fois dans la paie d'octobre (elle l'est en novembre, rubrique à 50 %).
        $this->assertEqualsWithDelta(1000, collect(\App\Services\PaieCalc::mois($this->client, '2026-10'))->firstWhere('nom', 'Karim')['brut'], 0.001);
        $this->assertEqualsWithDelta(1500, collect(\App\Services\PaieCalc::mois($this->client, '2026-11'))->firstWhere('nom', 'Karim')['brut'], 0.001);
        $this->post(route('paie.supprimer'), ['paie_id' => $p2->id])->assertSessionHas('ok');
        $this->assertSame(1, \App\Models\Paie::where('type', 'prime')->count());
    }

    public function test_menu_rappel_saisie_calcul_et_editions(): void
    {
        $amel = Salarie::where('nom', 'Amel')->first();
        // Karim : augmentation 1000 → 1100 enregistrée sur la fiche (historique) ; Amel : pas d'historique, augmentation de tête de 5 %.
        $this->karim->update(['salaire_base' => 1100]);
        $this->get(route('paie.index', ['mois' => '2026-10']))->assertOk()->assertSee('Saisie Rappel')->assertSee('Ouvrir rappel Saisi')
            ->assertSee('Enveloppe Rappel')->assertSee('Récapitulation de rappel')->assertSee('Ouvrez d’abord un rappel saisi', false);
        $this->get(route('paie.index', ['tab' => 'rappel', 'nouveau' => 1, 'mois' => '2026-10']))->assertOk()->assertSee('Rappel non encore enregistré');

        $this->post(route('paie.calcul'), ['type' => 'rappel', 'mois' => '2026-10', 'libelle' => 'Rappel augmentation 2026', 'du' => '2026-01', 'au' => '2026-09',
            'augmentation' => 5, 'unite' => '%', 'parties' => ['saisie']])->assertRedirect();
        $p = \App\Models\Paie::where('type', 'rappel')->firstOrFail();
        $this->assertSame('Rappel augmentation 2026 — déclaré avec octobre 2026', $p->libelle());
        $this->assertCount(9, $p->moisRappeles());
        $R = collect(\App\Services\PaieCalc::moisSpecial($this->client, $p))->keyBy('nom');
        $this->assertEqualsWithDelta(100 * 9, $R['Karim']['brut'], 0.001);   // (1100 − 1000) × 9
        $this->assertEqualsWithDelta(40 * 9, $R['Amel']['brut'], 0.001);     // 800 × 5 % × 9
        $this->assertSame('Rappel augmentation 2026 (9 mois × 100,000)', $R['Karim']['special']);
        $this->assertGreaterThan(0, $R['Karim']['ir']);

        // Saisie par salarié : nombre de mois et montant forcé.
        $this->post(route('paie.calcul'), ['paie_id' => $p->id, 'du' => '2026-01', 'au' => '2026-09', 'augmentation' => 5, 'unite' => '%', 'parties' => ['saisie'],
            'pointage' => [$this->karim->id => ['nb' => 6], $amel->id => ['montant' => 0]]])->assertRedirect();
        $R = collect(\App\Services\PaieCalc::moisSpecial($this->client, $p->fresh()))->keyBy('nom');
        $this->assertEqualsWithDelta(600, $R['Karim']['brut'], 0.001);
        $this->assertFalse($R->has('Amel'));

        // Ouvrir rappel Saisi → éditions du menu Rappel ; clôture → déclaration CNSS d'octobre.
        $this->get(route('paie.index', ['tab' => 'rappel', 'rappel' => $p->id, 'mois' => '2026-10']))->assertOk()->assertSee('Rappel ouvert : Rappel augmentation 2026');
        foreach (['journal', 'bordereau', 'virement', 'recap'] as $e) $this->get(route('paie.edition', [$e, 'imprimer', 'mois' => '2026-10', 'paie' => $p->id]))->assertOk()->assertSee('Rappel augmentation 2026 — déclaré avec octobre 2026');
        $this->get(route('paie.bulletins', ['paie' => $p->id, 'format' => 'a5d']))->assertOk()->assertSee('6 mois × 100,000');
        $this->post(route('paie.cloturer'), ['paie_id' => $p->id])->assertSessionHas('ok');
        $this->assertEqualsWithDelta(1100 + 800 + 600, end(EtatsPaie::dcnss($this->client, '2026-10')->lignes)['cellules'][4], 0.001);
        $this->post(route('paie.calcul'), ['type' => 'rappel', 'mois' => '2026-10', 'parties' => ['saisie'], 'du' => '2026-01', 'au' => '2026-02'])->assertSessionHasErrors('libelle');
    }

    public function test_regularisation_irpp_par_salarie_reg_et_regp(): void
    {
        $amel = Salarie::where('nom', 'Amel')->first();
        // Colonnes Reg / RegP de Saisie Paie : Karim régularisé (mensuel et primes), Amel non.
        $this->get(route('paie.index', ['mois' => '2026-10']))->assertOk()->assertSee('RegP');
        $this->post(route('paie.calcul'), ['mois' => '2026-10', 'parties' => ['saisie'], 'regul' => [$this->karim->id => ['irpp' => 1, 'primes' => 1], $amel->id => ['irpp' => 0, 'primes' => 0]]])->assertRedirect();
        $this->assertTrue($this->karim->fresh()->regul_irpp);
        $this->assertTrue($this->karim->fresh()->regul_primes);
        $this->assertFalse($amel->fresh()->regul_primes);

        // 13ème mois déclaré avec octobre : Karim par régularisation, Amel par différence.
        $this->post(route('paie.calcul'), ['mois' => '2026-10', 'type' => 'm13', 'parties' => ['saisie']])->assertRedirect();
        $p = \App\Models\Paie::where('type', 'm13')->firstOrFail();
        $R = collect(\App\Services\PaieCalc::moisSpecial($this->client, $p))->keyBy('nom');
        $this->assertSame('regul', $R['Karim']['methode_ir']);
        $this->assertSame('difference', $R['Amel']['methode_ir']);

        // Attendu : cumul janvier → octobre (10 paies de 1000) + 13ème mois, annualisé × 12 / 10, impôt ramené à 10/12 moins les retenues déjà faites.
        $P = \App\Services\PaieCalc::parametres($this->client);
        $mois = collect(range(1, 10))->map(fn ($i) => collect(\App\Services\PaieCalc::mois($this->client, sprintf('2026-%02d', $i)))->firstWhere('nom', 'Karim'));
        $x = $R['Karim'];
        $bi = \App\Services\PaieCalc::imposable($this->karim->fresh(), $P, ($mois->sum('brut') + 1000) * 12 / 10, ($mois->sum('cnssS') + $x['cnssS']) * 12 / 10)['bi'];
        $this->assertEqualsWithDelta(max(0, \App\Services\PaieCalc::bareme($bi, $P['bar']) * 10 / 12 - $mois->sum('ir')), $x['ir'], 0.002);
        $this->get(route('paie.bulletins', ['type' => 'm13', 'mois' => '2026-10', 'salarie' => $this->karim->id]))->assertSee('régularisation sur le cumul de l’année', false);

        // Le 13ème mois régularisé entre dans le cumul des mois suivants (novembre) ; un 13ème non régularisé (Amel) n'y entre pas.
        $cumul = \App\Services\PaieCalc::cumulAvant($this->client, '2026-11', $P);
        $this->assertSame(10, $cumul[$this->karim->id]['n']);
        $this->assertEqualsWithDelta(11000, $cumul[$this->karim->id]['brut'], 0.001);
        $this->assertEqualsWithDelta(8000, $cumul[$amel->id]['brut'], 0.001);

        // Fiche employé : les deux cases s'enregistrent.
        $this->get(route('paie.employes.edit', $this->karim))->assertOk()->assertSee('Régularisation sur les primes / autres / rappel');
    }

    public function test_menu_solde_de_tout_compte_et_quittance(): void
    {
        $amel = Salarie::where('nom', 'Amel')->first();
        // Sortie le mardi 20 octobre 2026 ; report de 5 jours au 1er janvier 2026, 2 j acquis par mois, 3 jours de congé pris en octobre.
        $amel->update(['conges_acquis' => 2, 'conges_report' => 5, 'conges_report_annee' => 2026, 'date_sortie' => '2026-10-20', 'motif_sortie' => 'demission']);
        $this->post(route('paie.calcul'), ['mois' => '2026-10', 'parties' => ['saisie'], 'pointage' => [$amel->id => ['jours' => 15, 'conge' => 3]]]);
        $this->get(route('paie.index', ['mois' => '2026-10']))->assertOk()->assertSee('Saisie Solde de tout compte')->assertSee('Ouvrir STC Saisi')
            ->assertSee('Enveloppe STC')->assertSee('Récapitulation STC')->assertSee('Quittance de Solde de tout Compte');
        $this->assertSame(['report' => 5.0, 'acquis' => 20.0, 'pris' => 3.0, 'reste' => 22.0], \App\Services\PaieCalc::soldeConges($amel->fresh(), '2026-10'));
        $this->get(route('paie.index', ['tab' => 'stc', 'nouveau' => 1, 'mois' => '2026-11']))->assertOk()->assertSee('Solde non encore enregistré')->assertSee('reste 22 j');

        $this->post(route('paie.calcul'), ['type' => 'stc', 'mois' => '2026-10', 'salarie_id' => $amel->id, 'parties' => ['saisie'],
            'pointage' => [$amel->id => ['preavis' => 200, 'licenciement' => 500, 'retenues' => 50]]])->assertRedirect();
        $p = \App\Models\Paie::where('type', 'stc')->firstOrFail();
        $this->assertSame('STC — Amel — déclaré avec octobre 2026', $p->libelle());
        $x = \App\Services\PaieCalc::moisSpecial($this->client, $p)[0];
        $J = \App\Services\PaieCalc::parametres($this->client)['jours_mois'];
        // Salaire d'octobre : du 1er au 20 octobre hors dimanches (4, 11, 18) = 17 jours ; congés : 22 j (report compris).
        $this->assertSame(17.0, $x['stc']['jours_trav']);
        $salaire = r3(800 * 17 / $J);
        $conge = r3(22 * 800 / $J);
        $this->assertEqualsWithDelta($conge, $x['stc']['conge'], 0.001);
        $this->assertEqualsWithDelta($salaire + $conge + 200 + 500, $x['brut'], 0.001);
        $this->assertEqualsWithDelta($salaire + $conge + 200, $x['base_cnss'], 0.001);   // licenciement exonéré de CNSS
        $this->assertEqualsWithDelta($x['brut'] - $x['cnssS'] - $x['ir'] - $x['css'] - 50, $x['net'], 0.002);
        $lignes = array_column(\App\Services\PaieCalc::lignesBulletin($x, \App\Services\PaieCalc::parametres($this->client)), 0);
        $this->assertContains('Salaire de octobre 2026 (17 j / '.$J.')', $lignes);
        $this->assertContains('Indemnité de licenciement (exonérée)', $lignes);
        $this->equilibre(\App\Services\PaieCalc::lignes(\App\Services\PaieCalc::totaux([$x])));
        // Amel est payée par son STC : elle sort de la paie ordinaire d'octobre.
        $this->assertSame(['Karim'], array_column(\App\Services\PaieCalc::mois($this->client, '2026-10'), 'nom'));

        // Éditions, bulletin et quittance du STC ouvert.
        $this->get(route('paie.index', ['tab' => 'stc', 'stc' => $p->id, 'mois' => '2026-10']))->assertOk()->assertSee('STC ouvert : STC — Amel');
        foreach (['journal', 'bordereau', 'recap'] as $e) $this->get(route('paie.edition', [$e, 'imprimer', 'mois' => '2026-10', 'paie' => $p->id]))->assertOk()->assertSee('STC — Amel');
        $this->get(route('paie.bulletins', ['paie' => $p->id, 'format' => 'a4']))->assertOk()->assertSee('Indemnité de préavis');
        $this->get(route('paie.stc.quittance', $p))->assertOk()->assertSee('REÇU POUR SOLDE DE TOUT COMPTE')->assertSee(tnd($x['net']))->assertSee('démission');
        $this->get(route('paie.index', ['mois' => '2026-10']))->assertSee('Quittance relative à un contrat de travail');
        $this->get(route('paie.stc.quittance', [$p, 'modele' => 'contrat']))->assertOk()->assertSee('RELATIVE A UN CONTRAT DE TRAVAIL')
            ->assertSee(number_format($x['net'], 3, ',', ' '))->assertSee('départ daté au <b>20/10/2026</b>', false)->assertSee(mb_strtoupper($this->client->nom));
        $pdf = $this->get(route('paie.stc.quittance', [$p, 'modele' => 'contrat', 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf));   // une seule page
        // Clôture : la part soumise (salaire du mois compris) entre dans la déclaration CNSS d'octobre.
        $this->post(route('paie.cloturer'), ['paie_id' => $p->id])->assertSessionHas('ok');
        $this->assertEqualsWithDelta(1000 + $x['base_cnss'], end(EtatsPaie::dcnss($this->client, '2026-10')->lignes)['cellules'][4], 0.001);
    }

    public function test_menu_conge_registre_anterieurs_et_etats(): void
    {
        $amel = Salarie::where('nom', 'Amel')->first();
        $this->karim->update(['conges_acquis' => 2]);
        $this->get(route('paie.index', ['mois' => '2026-10']))->assertOk()->assertSee('Saisie congé antérieur')->assertSee('Saisie des congés')->assertSee('Etat des Congés');

        // Saisie congé antérieur : 6 jours au 1er janvier 2026 pour Karim.
        $this->get(route('paie.index', ['tab' => 'conges-ant', 'mois' => '2026-10']))->assertOk()->assertSee('Jours restant dus au 1er janvier');
        $this->post(route('paie.conges.anterieurs'), ['annee' => 2026, 'report' => [$this->karim->id => 6, $amel->id => '']])->assertSessionHas('ok');
        $this->assertSame(6.0, (float) $this->karim->fresh()->conges_report);
        $this->assertSame(2026, (int) $this->karim->fresh()->conges_report_annee);

        // Congé payé du lundi 12 au samedi 17 octobre 2026 : 6 jours ouvrables (régime de 6 jours).
        $this->post(route('paie.conges.store'), ['salarie_id' => $this->karim->id, 'type' => 'paye', 'du' => '2026-10-12', 'au' => '2026-10-17'])->assertSessionHas('ok');
        $c = \App\Models\Conge::firstOrFail();
        $this->assertSame(6.0, $c->jours);
        $this->assertSame(['report' => 6.0, 'acquis' => 20.0, 'pris' => 6.0, 'reste' => 20.0], \App\Services\PaieCalc::soldeConges($this->karim->fresh(), '2026-10'));
        // Congé à cheval sur deux mois : réparti au prorata des jours ouvrables.
        $c2 = \App\Models\Conge::create(['client_id' => $this->client->id, 'salarie_id' => $amel->id, 'type' => 'sans_solde', 'du' => '2026-10-29', 'au' => '2026-11-03', 'jours' => 5]);
        $this->assertSame(3.0, $c2->joursDansMois('2026-10'));   // 29, 30, 31 octobre
        $this->assertSame(2.0, $c2->joursDansMois('2026-11'));   // 2 et 3 novembre

        // Paie d'octobre : le congé payé du registre est payé avec les jours travaillés ; le congé sans solde est retiré des jours payés.
        $this->post(route('paie.calcul'), ['mois' => '2026-10', 'parties' => ['saisie'], 'pointage' => [$this->karim->id => ['jours' => 20]]]);
        $R = collect(\App\Services\PaieCalc::mois($this->client, '2026-10'))->keyBy('nom');
        $J = \App\Services\PaieCalc::parametres($this->client)['jours_mois'];
        $this->assertEqualsWithDelta(26, $R['Karim']['jours'], 0.001);   // 20 travaillés + 6 de congé
        $this->assertEqualsWithDelta(6, $R['Karim']['pt_conge'], 0.001);
        $this->assertEqualsWithDelta($J - 3, $R['Amel']['jours'], 0.001);
        $this->assertEqualsWithDelta(r3(800 * ($J - 3) / $J), $R['Amel']['salaire'], 0.001);
        $this->get(route('paie.index', ['tab' => 'conges', 'mois' => '2026-10']))->assertOk()->assertSee('Registre des congés 2026')->assertSee('Congé sans solde');

        // Etat des Congés.
        foreach (array_keys(EtatsPaie::CONGES) as $e) foreach (['imprimer', 'pdf', 'xlsx'] as $f) $this->get(route('paie.edition', [$e, $f, 'mois' => '2026-10']))->assertOk();
        $solde = collect(EtatsPaie::congesSolde($this->client, '2026-10')->lignes)->firstWhere(fn ($l) => $l['cellules'][1] === 'Karim');
        $this->assertSame([6.0, 20.0, 6.0, 20.0], array_slice($solde['cellules'], 4, 4));
        $this->delete(route('paie.conges.destroy', $c))->assertSessionHas('ok');
        $this->assertSame(1, \App\Models\Conge::count());
    }

    public function test_menu_acompte_saisie_retenue_et_etats(): void
    {
        $this->get(route('paie.index', ['mois' => '2026-10']))->assertOk()->assertSee('Saisie des acomptes')->assertSee('Etat des acomptes');
        $this->get(route('paie.index', ['tab' => 'acomptes', 'mois' => '2026-10']))->assertOk()->assertSee('Nouvel acompte');
        $this->post(route('paie.acomptes.store'), ['salarie_id' => $this->karim->id, 'date' => '2026-10-10', 'montant' => 150, 'mode' => 'especes', 'mois' => '2026-10'])->assertSessionHas('ok');
        $this->post(route('paie.acomptes.store'), ['salarie_id' => $this->karim->id, 'date' => '2026-10-15', 'montant' => 50, 'mode' => 'cheque', 'mois' => '2026-10'])->assertSessionHas('ok');

        // Paie d'octobre : 200 d'acomptes déduits du net (colonne Acom vide) ; une saisie dans la colonne remplace le registre.
        $sans = collect(\App\Services\PaieCalc::calcul($this->karim, 0, \App\Services\PaieCalc::parametres($this->client)))->get('net');
        $x = collect(\App\Services\PaieCalc::mois($this->client, '2026-10'))->firstWhere('nom', 'Karim');
        $this->assertEqualsWithDelta(200, $x['acompte'], 0.001);
        $this->assertEqualsWithDelta($sans - 200, $x['net'], 0.001);
        $this->post(route('paie.calcul'), ['mois' => '2026-10', 'parties' => ['saisie'], 'pointage' => [$this->karim->id => ['acompte' => 120]]]);
        $this->assertEqualsWithDelta(120, collect(\App\Services\PaieCalc::mois($this->client, '2026-10'))->firstWhere('nom', 'Karim')['acompte'], 0.001);

        // États et reçu.
        foreach (array_keys(EtatsPaie::ACOMPTES) as $e) foreach (['imprimer', 'pdf', 'xlsx'] as $f) $this->get(route('paie.edition', [$e, $f, 'mois' => '2026-10']))->assertOk();
        $this->assertSame(200.0, end(EtatsPaie::acomptesMois($this->client, '2026-10')->lignes)['cellules'][5]);
        $a = \App\Models\Acompte::first();
        $this->get(route('paie.acomptes.recu', $a))->assertOk()->assertSee('REÇU D’ACOMPTE SUR SALAIRE', false)->assertSee('octobre 2026');

        // Paie clôturée : plus d'acompte sur ce mois, ni de suppression.
        $this->post(route('paie.cloturer'), ['mois' => '2026-10'])->assertSessionHas('ok');
        $this->post(route('paie.acomptes.store'), ['salarie_id' => $this->karim->id, 'date' => '2026-10-20', 'montant' => 30, 'mode' => 'especes', 'mois' => '2026-10'])->assertSessionHas('err');
        $this->delete(route('paie.acomptes.destroy', $a))->assertSessionHas('err');
        $this->assertSame(2, \App\Models\Acompte::count());
    }

    public function test_menu_pret_societe_et_cnss(): void
    {
        $this->get(route('paie.index', ['mois' => '2026-10']))->assertOk()->assertSee('Prêt Société')->assertSee('Prêt CNSS')->assertSee('Saisie des prêts')->assertSee('Etat des prêts');
        $this->get(route('paie.index', ['tab' => 'prets', 'type' => 'cnss', 'mois' => '2026-10']))->assertOk()->assertSee('Prêt CNSS — saisie des prêts');

        // Prêt société de 1000 en 3 échéances à partir d'octobre : 333,333 × 2 puis 333,334.
        $this->post(route('paie.prets.store'), ['type' => 'societe', 'salarie_id' => $this->karim->id, 'date' => '2026-09-25', 'montant' => 1000, 'nb_echeances' => 3, 'premier_mois' => '2026-10'])->assertSessionHas('ok');
        $p = \App\Models\Pret::firstOrFail();
        $this->assertSame(['2026-10' => 333.333, '2026-11' => 333.333, '2026-12' => 333.334], $p->echeancier());
        $this->assertSame(['rembourse' => 666.666, 'reste' => 333.334], $p->situation('2026-11'));
        // Prêt CNSS de 500 à 120 par mois : 5 échéances, la dernière de 20.
        $this->post(route('paie.prets.store'), ['type' => 'cnss', 'salarie_id' => $this->karim->id, 'date' => '2026-10-01', 'montant' => 500, 'echeance' => 120, 'premier_mois' => '2026-10', 'reference' => 'P-123'])->assertSessionHas('ok');
        $c = \App\Models\Pret::where('type', 'cnss')->firstOrFail();
        $this->assertSame(5, $c->nb_echeances);
        $this->assertSame(20.0, $c->echeanceDuMois('2027-02'));

        // Paie d'octobre : échéances déduites du net ; écriture : 425 (prêt société) et 427 (prêt CNSS), équilibrée.
        $sans = \App\Services\PaieCalc::calcul($this->karim, 0, \App\Services\PaieCalc::parametres($this->client))['net'];
        $x = collect(\App\Services\PaieCalc::mois($this->client, '2026-10'))->firstWhere('nom', 'Karim');
        $this->assertEqualsWithDelta(333.333, $x['pret_societe'], 0.001);
        $this->assertEqualsWithDelta(120, $x['pret_cnss'], 0.001);
        $this->assertEqualsWithDelta($sans - 453.333, $x['net'], 0.001);
        $this->assertContains('Remboursement prêt CNSS', array_column(\App\Services\PaieCalc::lignesBulletin($x, \App\Services\PaieCalc::parametres($this->client)), 0));
        $L = collect(\App\Services\PaieCalc::lignes(\App\Services\PaieCalc::totaux([$x])))->keyBy('compte');
        $this->assertEqualsWithDelta($x['net'], $L['425']['credit'], 0.001);
        $this->assertEqualsWithDelta(333.333, $L['421']['credit'], 0.001);
        $this->assertEqualsWithDelta(120, $L['427']['credit'], 0.001);
        $this->equilibre($L->values()->all());

        // Versement du prêt société comptabilisé : D 421 / C 532 (virement par défaut), à la date du prêt ; pas d'écriture pour le prêt CNSS.
        $ec = \App\Models\Ecriture::with('lignes')->where('source', 'pret:'.$p->id)->firstOrFail();
        $this->assertSame(['BQ', '2026-09-25'], [$ec->journal, $ec->date->format('Y-m-d')]);
        $this->assertSame([['421', 1000.0, 0.0], ['532', 0.0, 1000.0]], $ec->lignes->map(fn ($l) => [rtrim($l->compte, '0') === '421' ? '421' : (rtrim($l->compte, '0') === '532' ? '532' : $l->compte), (float) $l->debit, (float) $l->credit])->all());
        $this->assertFalse(\App\Models\Ecriture::where('source', 'pret:'.$c->id)->exists());
        $this->assertSame('Versement d’un prêt au personnel — Karim', $ec->origine());
        // Prêt en espèces : journal de caisse, compte 54 ; sa suppression retire l'écriture.
        $this->post(route('paie.prets.store'), ['type' => 'societe', 'salarie_id' => $this->karim->id, 'date' => '2026-11-02', 'montant' => 300, 'nb_echeances' => 2, 'premier_mois' => '2026-11', 'mode' => 'especes'])->assertSessionHas('ok');
        $p3 = \App\Models\Pret::latest('id')->first();
        $this->assertSame('CAI', \App\Models\Ecriture::where('source', 'pret:'.$p3->id)->value('journal'));
        $this->delete(route('paie.prets.destroy', $p3))->assertSessionHas('ok');
        $this->assertFalse(\App\Models\Ecriture::where('source', 'pret:'.$p3->id)->exists());

        // États, échéancier ; suppression refusée une fois une échéance retenue sur une paie clôturée.
        foreach (array_keys(EtatsPaie::PRETS) as $e) foreach (['imprimer', 'pdf', 'xlsx'] as $f) $this->get(route('paie.edition', [$e, $f, 'mois' => '2026-10', 'type' => 'cnss']))->assertOk();
        $this->assertSame(380.0, end(EtatsPaie::pretsEtat($this->client, '2026-10', 'cnss')->lignes)['cellules'][10]);
        $this->get(route('paie.prets.echeancier', $p))->assertOk()->assertSee('333,334');
        $this->post(route('paie.cloturer'), ['mois' => '2026-10'])->assertSessionHas('ok');
        $this->delete(route('paie.prets.destroy', $p))->assertSessionHas('err');
        $this->assertSame(2, \App\Models\Pret::count());
    }

    public function test_stc_retient_le_reste_du_des_prets(): void
    {
        // Prêt société de 900 en 9 fois depuis juillet (100/mois) et prêt CNSS de 400 en 4 fois depuis octobre ; départ le 20 octobre, STC déclaré en octobre.
        $this->post(route('paie.prets.store'), ['type' => 'societe', 'salarie_id' => $this->karim->id, 'date' => '2026-06-30', 'montant' => 900, 'nb_echeances' => 9, 'premier_mois' => '2026-07']);
        $this->post(route('paie.prets.store'), ['type' => 'cnss', 'salarie_id' => $this->karim->id, 'date' => '2026-09-30', 'montant' => 400, 'nb_echeances' => 4, 'premier_mois' => '2026-10']);
        $this->karim->update(['date_sortie' => '2026-10-20', 'motif_sortie' => 'demission']);
        // Juillet à septembre retenus (300) : reste 600 sur le prêt société, 400 sur le prêt CNSS.
        $this->assertSame(['societe' => 600.0, 'cnss' => 400.0], \App\Services\PaieCalc::restePrets($this->karim->fresh(), '2026-10'));
        $this->get(route('paie.index', ['tab' => 'stc', 'nouveau' => 1, 'salarie' => $this->karim->id, 'decl' => '2026-10', 'mois' => '2026-10']))->assertOk()->assertSee('Reste dû du prêt société');

        $this->post(route('paie.calcul'), ['type' => 'stc', 'mois' => '2026-10', 'salarie_id' => $this->karim->id, 'parties' => ['saisie']])->assertRedirect();
        $stc = \App\Models\Paie::where('type', 'stc')->firstOrFail();
        $x = \App\Services\PaieCalc::moisSpecial($this->client, $stc)[0];
        $this->assertEqualsWithDelta(600, $x['pret_societe'], 0.001);
        $this->assertEqualsWithDelta(400, $x['pret_cnss'], 0.001);
        $this->assertEqualsWithDelta($x['brut'] - $x['cnssS'] - $x['ir'] - $x['css'] - 1000, $x['net'], 0.002);
        $lignes = array_column(\App\Services\PaieCalc::lignesBulletin($x, \App\Services\PaieCalc::parametres($this->client)), 0);
        $this->assertContains('Remboursement du reste dû du prêt société', $lignes);
        $this->assertContains('Remboursement du reste dû du prêt CNSS', $lignes);
        // Écriture du STC : 600 au crédit du 421, 400 au 427 ; équilibrée.
        $L = collect(\App\Services\PaieCalc::lignes(\App\Services\PaieCalc::totaux([$x])))->keyBy('compte');
        $this->assertEqualsWithDelta(600, $L['421']['credit'], 0.001);
        $this->assertEqualsWithDelta(400, $L['427']['credit'], 0.001);
        $this->equilibre($L->values()->all());
        // Les prêts sont soldés par le STC : plus d'échéance ensuite, reste dû 0 dans l'état des prêts.
        $p = \App\Models\Pret::where('type', 'societe')->first();
        $this->assertSame(['rembourse' => 900.0, 'reste' => 0.0, 'stc' => '2026-10'], $p->situation('2026-10'));
        $this->assertSame([], \App\Services\PaieCalc::pretsDuMois($this->client, '2026-11'));

        // Retenue de prêt mise à 0 sur le STC : le prêt n'est pas soldé.
        $this->post(route('paie.calcul'), ['paie_id' => $stc->id, 'parties' => ['saisie'], 'pointage' => [$this->karim->id => ['pret_cnss' => 0]]]);
        $this->assertEqualsWithDelta(0, \App\Services\PaieCalc::moisSpecial($this->client, $stc->fresh())[0]['pret_cnss'], 0.001);
        $this->assertEqualsWithDelta(400, \App\Models\Pret::where('type', 'cnss')->first()->situation('2026-10')['reste'] + 100, 0.001);
    }

    public function test_menu_edition_global_regime_complementaire_et_envoi_mail(): void
    {
        $r = $this->get(route('paie.index', ['mois' => '2026-10']))->assertOk();
        foreach (['Journal Global', 'Bordereau payeur Global', 'Ordre de virement Global', 'Déclaration CNSS', 'Déclaration du régime complémentaire',
            'Déclaration Employeur', 'Enveloppe Paie', 'Récapitulation Global', 'Déclaration CNSS Patronnal', 'Envoi par mail'] as $l) $r->assertSee($l);

        // Régime complémentaire : Karim affilié, plafond 600, taux 3 % / 4 % ; excédent 400 par mois payé (octobre, novembre ; décembre est futur).
        $this->client->update(['paie_config' => ($this->client->paie_config ?? []) + ['plafond_rc' => 600, 'rc_taux_s' => 3, 'rc_taux_p' => 4]]);
        $this->karim->update(['cnss_rc' => true]);
        $e = EtatsPaie::drc($this->client->fresh(), '2026-10');
        $this->assertCount(2, $e->lignes);
        $this->assertSame([400.0, 400.0, 0.0, 800.0, 24.0, 32.0], array_slice($e->lignes[0]['cellules'], 4));
        foreach (['imprimer', 'pdf', 'xlsx'] as $f) $this->get(route('paie.edition', ['drc', $f, 'mois' => '2026-10']))->assertOk();

        // Fichier RC (tracé CNSS) : 59 caractères — employeur, assuré, salaire déclaré, plafonné et différentiel du trimestre en millimes.
        $this->client->update(['matricule_cnss' => '123456-72']);
        $this->karim->update(['cnss' => '1705988-02']);
        $f = EtatsPaie::fichierRc($this->client->fresh(), '2026-10');
        $this->assertSame('RC0012345672.42026', $f['nom']);
        $this->assertSame('0012345672'.'0170598802'.'0000002000000'.'0000001200000'.'0000000800000'."\r\n", $f['contenu']);
        $this->assertSame(['declare' => 2000.0, 'plafonne' => 1200.0, 'differentiel' => 800.0], $f['totaux']);
        $this->get(route('paie.rc.fichier', ['mois' => '2026-10']))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="RC0012345672.42026"');

        // Envoi par mail : journal global et bulletins en pièces jointes.
        \Illuminate\Support\Facades\Mail::fake();
        $this->post(route('paie.envoi-mail'), ['mois' => '2026-10', 'email' => 'employeur@example.tn', 'pieces' => ['journal', 'bulletins']])->assertSessionHas('ok');
        $this->post(route('paie.envoi-mail'), ['mois' => '2026-10', 'email' => 'pas-un-mail', 'pieces' => []])->assertSessionHasErrors(['email', 'pieces']);
    }

    public function test_menu_bibliographie(): void
    {
        $B = config('bibliographie');
        $sections = collect($B['groupes'])->flatMap(fn ($g) => $g['sections']);
        $this->assertCount(12, $sections);
        // Chaque lien est un chemin du site (ou un PDF du site), chaque entrée a un titre.
        foreach ($sections as $s) foreach ($s['items'] as $l) {
            [$t, $u] = explode('|', $l, 2);
            $this->assertNotSame('', trim($t));
            $this->assertStringStartsWith('/', $u);
        }
        $this->get(route('paie.index', ['mois' => '2026-10']))->assertOk()->assertSee('Bibliographie')->assertSee('Réglementations')->assertSee('Conventions collectives sectorielles')->assertSee('Site paie-tunisie.com');
        $this->get(route('paie.index', ['tab' => 'biblio', 'section' => 'conventions', 'mois' => '2026-10']))->assertOk()
            ->assertSee('Réglementations — Conventions collectives sectorielles')->assertSee('https://www.paie-tunisie.com/387/fr/18/publications/textile', false)
            ->assertSee('target="_blank" rel="noopener noreferrer"', false);
        $this->get(route('paie.index', ['tab' => 'biblio', 'section' => 'inconnue', 'mois' => '2026-10']))->assertOk()->assertSee('Code du travail');
    }

    public function test_report_des_conges_des_annees_precedentes(): void
    {
        // Report de 4 jours au 1er janvier 2025 : 2025 = 4 + 24 acquis − 6 pris (paie de mars 2025) = 22 j reportés sur 2026.
        $this->karim->update(['conges_acquis' => 2, 'conges_report' => 4, 'conges_report_annee' => 2025]);
        \App\Models\Paie::create(['client_id' => $this->client->id, 'mois' => '2025-03', 'pointage' => [$this->karim->id => ['jours' => null, 'hs' => 0, 'conge' => 6]]]);
        $this->assertSame(['report' => 22.0, 'acquis' => 20.0, 'pris' => 0.0, 'reste' => 42.0], \App\Services\PaieCalc::soldeConges($this->karim->fresh(), '2026-10'));
        $this->get(route('paie.employes.edit', $this->karim))->assertOk()->assertSee('Report de congés');
    }

    private function equilibre(array $L): void
    {
        $this->assertEqualsWithDelta(array_sum(array_column($L, 'debit')), array_sum(array_column($L, 'credit')), 0.002);
    }

    public function test_journal_quatre_modeles_et_regroupements(): void
    {
        $this->karim->update(['departement' => 'Ventes']);
        foreach (array_keys(EtatsPaie::JOURNAUX) as $m) {
            foreach (array_keys(EtatsPaie::GROUPES) as $g) {
                $this->get(route('paie.edition', ['journal', 'imprimer', 'mois' => '2026-10', 'modele' => $m, 'groupe' => $g]))->assertOk();
            }
            $this->get(route('paie.edition', ['journal', 'xlsx', 'mois' => '2026-10', 'modele' => $m, 'groupe' => 'departement']))->assertOk();
        }
        $e = EtatsPaie::journal($this->client, '2026-10', 'modele1', 'departement');
        $styles = array_column($e->lignes, 'style');
        $this->assertSame(['groupe', 'normal', 'soustotal', 'groupe', 'normal', 'soustotal', 'total'], $styles);
        $this->assertSame('SANS DÉPARTEMENT', $e->lignes[0]['cellules'][1]);
    }

    public function test_bulletins_par_critere_periode_et_generation_zip(): void
    {
        $this->karim->update(['departement' => 'Ventes', 'cin' => '01234567']);
        $this->get(route('paie.bulletins', ['mois' => '2026-10', 'departement' => 'Ventes']))->assertOk()->assertSee('Karim')->assertDontSee('Amel');
        $this->get(route('paie.bulletins', ['mois' => '2026-10', 'regime' => 'horaire']))->assertOk()->assertDontSee('Karim');
        $this->get(route('paie.bulletins', ['salarie' => $this->karim->id, 'annee' => 2026]))->assertOk()->assertSee('octobre 2026')->assertSee('janvier 2026');
        $this->get(route('paie.bulletins', ['du' => '2026-09', 'au' => '2026-10']))->assertOk()->assertSee('de septembre 2026 à octobre 2026');
        $r = $this->get(route('paie.bulletins.zip', ['du' => '2026-10', 'au' => '2026-10', 'nommage' => 'cin']))->assertOk();
        $fichier = $r->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($fichier) === true);
        $noms = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();
        sort($noms);
        $this->assertSame(['01234567 2026-10.pdf', 'sans-CIN-'.Salarie::where('nom', 'Amel')->value('id').' 2026-10.pdf'], $noms);
    }

    public function test_bulletin_d_un_seul_employe(): void
    {
        $this->get(route('paie.bulletins', ['mois' => '2026-10', 'salarie' => $this->karim->id]))->assertOk()->assertSee('Karim')->assertDontSee('Amel');
    }

    public function test_bordereau_payeur_par_mode_de_paiement(): void
    {
        $e = EtatsPaie::bordereau($this->client, '2026-10', 'especes');
        $this->assertCount(2, $e->lignes);
        $this->assertSame('Amel', $e->lignes[0]['cellules'][2]);
        $this->get(route('paie.edition', ['bordereau', 'pdf', 'mois' => '2026-10', 'mode' => 'cheque']))->assertOk();
        // Par département : un groupe et un sous-total par département.
        $this->karim->update(['departement' => 'Ventes']);
        $e = EtatsPaie::bordereau($this->client, '2026-10', null, true);
        $this->assertSame(['groupe', 'normal', 'soustotal', 'groupe', 'normal', 'soustotal', 'total'], array_column($e->lignes, 'style'));
        $this->get(route('paie.edition', ['bordereau', 'imprimer', 'mois' => '2026-10', 'groupe' => 'departement']))->assertOk()->assertSee('VENTES');
    }

    public function test_ordre_de_virement_par_banque_fichiers_et_par_employe(): void
    {
        // RIB 08… = BIAT ; sans RIB, la banque est reconnue par son nom.
        $this->karim->update(['rib' => '08 006 0000000000000 12']);
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Sami', 'salaire_base' => 900, 'mode_paiement' => 'virement', 'banque' => 'UIB Lac']);
        $this->assertSame(['Karim'], array_map(fn ($v) => $v[0]['nom'], EtatsPaie::aVirer($this->client, '2026-10', 'biat')));
        $this->assertSame(['Sami'], array_map(fn ($v) => $v[0]['nom'], EtatsPaie::aVirer($this->client, '2026-10', 'uib')));
        $this->get(route('paie.edition', ['virement', 'imprimer', 'mois' => '2026-10', 'banque' => 'biat']))->assertOk()->assertSee('Récapitulatif de virement — BIAT')->assertDontSee('Sami');
        $txt = $this->get(route('paie.virement.fichier', ['txt', 'mois' => '2026-10', 'banque' => 'biat']))->assertOk()->getContent();
        $l = explode(';', trim($txt));
        $this->assertSame('08006000000000000012', $l[1]);
        $this->assertSame('KARIM', $l[3]);
        $this->get(route('paie.virement.fichier', ['xls', 'mois' => '2026-10', 'banque' => 'attijari']))->assertOk();
        $masse = $this->get(route('paie.virement.fichier', ['txt', 'mois' => '2026-10', 'masse' => 1]))->assertOk()->getContent();
        $this->assertCount(2, array_filter(explode("\r\n", $masse)));   // Karim et Sami (Amel est payée en espèces)
        $this->get(route('paie.virement.employes', ['mois' => '2026-10']))->assertOk()->assertSee('ORDRE DE VIREMENT')->assertSee('Sami')->assertDontSee('Amel');
    }

    public function test_modes_d_impression_des_bulletins_et_reste_conge(): void
    {
        foreach (array_keys(\App\Http\Controllers\PaieController::FORMATS_BULLETIN) as $f) {
            $this->get(route('paie.bulletins', ['mois' => '2026-10', 'format' => $f]))->assertOk()->assertSee('Karim');
        }
        $this->get(route('paie.bulletins', ['mois' => '2026-10', 'format' => 'mat']))->assertSee('BULLETIN DE PAIE')->assertSee('matricielle', false);
        $this->get(route('paie.bulletins', ['mois' => '2026-10', 'format' => 'a5j']))->assertSee('Taux journalier');
        // Reste congé : 2 j acquis par mois de janvier à octobre, 3 jours pris en octobre.
        $this->karim->update(['conges_acquis' => 2, 'date_embauche' => '2020-01-01']);
        $this->post(route('paie.calcul'), ['mois' => '2026-10', 'parties' => ['saisie'], 'pointage' => [$this->karim->id => ['jours' => 23, 'conge' => 3]]]);
        $this->get(route('paie.bulletins', ['mois' => '2026-10', 'format' => 'a5c', 'salarie' => $this->karim->id]))->assertOk()
            ->assertSeeInOrder(['Congés acquis', '20 j', 'Congés pris', '3 j', 'Reste congé', '17 j']);
    }

    public function test_recapitulation_et_etat_vierge_dans_tous_les_formats(): void
    {
        foreach (['recap', 'vierge', 'annuel'] as $e) {
            foreach (['imprimer', 'pdf', 'xlsx', 'docx'] as $f) $this->get(route('paie.edition', [$e, $f, 'mois' => '2026-10']))->assertOk();
        }
        $recap = EtatsPaie::recap($this->client, '2026-10');
        $brut = collect($recap->lignes)->first(fn ($l) => $l['cellules'][1] === 'SALAIRE BRUT');
        $this->assertSame('2', $brut['cellules'][2]);
        $this->assertSame(1800.0, $brut['cellules'][3]);
        $vierge = EtatsPaie::vierge($this->client, '2026-10');
        $this->assertTrue($vierge->grille);
        $this->assertCount(2, $vierge->lignes);
    }

    public function test_enveloppes_et_billetage_des_especes(): void
    {
        $this->assertSame([50000 => 2, 20000 => 1, 5000 => 1, 2000 => 1, 500 => 1, 200 => 1, 50 => 1], PaieController::billetage(127.75));
        $this->get(route('paie.enveloppes', ['mois' => '2026-10']))->assertOk()->assertSee('ENVELOPPE DE PAIE')->assertSee('Karim')->assertSee('Billetage');
        $this->get(route('paie.enveloppes', ['mois' => '2026-10', 'mode' => 'virement']))->assertOk()->assertSee('Karim')->assertDontSee('Amel');
    }
}
