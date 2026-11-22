<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Ecriture;
use App\Models\User;
use App\Services\Compta;
use App\Support\Formule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Comptabilité → Journal & écritures → Pièce comptable : saisie par pièce, numérotation, calcul direct dans les montants. */
class PieceComptableTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->chef = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']);
        $this->actingAs($this->chef);
        $this->client = Client::factory()->create();
        session(['dossier' => $this->client->id]);
    }

    public function test_formule_de_calcul_des_montants(): void
    {
        $this->assertSame(1082.0, Formule::evaluer('450+632'));
        $this->assertSame(5203.0, Formule::evaluer('101+5102'));
        $this->assertSame(20.0, Formule::evaluer('100/5'));
        $this->assertSame(6432.0, Formule::evaluer('201*32'));
        $this->assertSame(1190.0, Formule::evaluer('(1200 - 200)*1,19'));
        $this->assertSame(1234.5, Formule::evaluer('1 234,500'));
        $this->assertSame(7.0, Formule::evaluer('1+2*3'));
        $this->assertSame(0.333, Formule::evaluer('1/3'));
        foreach (['', '10/0', '2+', 'abc', '1+(2', 'system(1)', '3..5'] as $x) $this->assertNull(Formule::evaluer($x), $x);
    }

    public function test_saisie_d_une_piece_avec_calculs_libelles_et_references(): void
    {
        $this->get(route('compta.piece', ['journal' => 'ACH']))->assertOk()->assertSee('Pièce comptable')->assertSee('Enregistrer (F4)')->assertSee('00000001');
        $this->post(route('compta.piece.store'), ['journal' => 'ACH', 'date' => '2026-01-31', 'reference' => 'F632', 'lignes' => [
            ['libelle' => 'FACTURE N°632 ALLALA', 'compte' => '601000', 'debit' => '450+632', 'credit' => '', 'reference' => '632'],
            ['libelle' => 'FACTURE N°632 ALLALA', 'compte' => '4366', 'debit' => '1082*19/100', 'credit' => ''],
            ['libelle' => 'FACTURE N°632 ALLALA', 'compte' => '401000', 'debit' => '', 'credit' => '1 287,580'],
            ['libelle' => '', 'compte' => '', 'debit' => '', 'credit' => ''],
        ]])->assertRedirect(route('compta.piece', ['journal' => 'ACH', 'enregistree' => Ecriture::max('id')]))->assertSessionHas('ok');
        $e = Ecriture::with('lignes')->sole();
        $this->assertSame(['ACH', '00000001', 'F632', 'FACTURE N°632 ALLALA'], [$e->journal, $e->numero, $e->reference, $e->libelle]);
        $this->assertSame([1082.0, 205.58, 0.0], $e->lignes->map(fn ($l) => (float) $l->debit)->all());
        $this->assertSame(1287.58, (float) $e->lignes[2]->credit);
        $this->assertSame(['632', 'FACTURE N°632 ALLALA'], [$e->lignes[0]->reference, $e->lignes[1]->libelle]);
        // Le numéro suivant du journal est proposé
        $this->get(route('compta.piece', ['journal' => 'ACH']))->assertSee('00000002');
    }

    public function test_piece_refusee_si_calcul_invalide_ou_desequilibree(): void
    {
        $this->post(route('compta.piece.store'), ['journal' => 'OD', 'date' => '2026-02-01', 'lignes' => [
            ['compte' => '6061', 'debit' => '100/0'], ['compte' => '401', 'credit' => '100']]])->assertSessionHasErrors('lignes.0.debit');
        $this->post(route('compta.piece.store'), ['journal' => 'OD', 'date' => '2026-02-01', 'lignes' => [
            ['compte' => '6061', 'debit' => '100+1'], ['compte' => '401', 'credit' => '100']]])->assertSessionHasErrors('lignes');
        $this->assertSame(0, Ecriture::count());
    }

    public function test_numerotation_par_journal_navigation_et_modification(): void
    {
        $p = fn ($j, $lib, $m) => Compta::passer($this->client->id, '2026-03-01', $j, $lib, [['compte' => '6061', 'debit' => $m], ['compte' => '401', 'credit' => $m]], null, $this->chef->id);
        $a1 = $p('ACH', 'Achat 1', 10); $o1 = $p('OD', 'OD 1', 5); $a2 = $p('ACH', 'Achat 2', 20); $a3 = $p('ACH', 'Achat 3', 30);
        $this->assertSame(['00000001', '00000001', '00000002', '00000003'], [$a1->numero, $o1->numero, $a2->numero, $a3->numero]);

        $r = $this->get(route('compta.piece', ['ecriture' => $a2->id]))->assertOk()->assertSee('Achat 2');
        foreach ([$a1->id, $a3->id] as $id) $r->assertSee(route('compta.piece', ['ecriture' => $id]), false);

        $this->put(route('compta.piece.update', $a2), ['journal' => 'ACH', 'date' => '2026-03-05', 'reference' => 'R2', 'lignes' => [
            ['libelle' => 'Achat 2 corrigé', 'compte' => '6061', 'debit' => '20+5', 'reference' => 'BL9'], ['libelle' => 'Achat 2 corrigé', 'compte' => '401', 'credit' => '25']]])
            ->assertSessionHasNoErrors()->assertSessionMissing('err')->assertRedirect(route('compta.piece', ['ecriture' => $a3->id]));   // passe à la pièce suivante
        // Après la dernière pièce du journal : nouvelle pièce dans ce journal
        $this->put(route('compta.piece.update', $a3), ['journal' => 'ACH', 'date' => '2026-03-01', 'lignes' => [['compte' => '6061', 'debit' => '30'], ['compte' => '401', 'credit' => '30']]])
            ->assertRedirect(route('compta.piece', ['journal' => 'ACH']));
        $this->get(route('compta.piece', ['ecriture' => $a3->id]))->assertSee('fleches($event, i, \'debit\')', false)->assertSee('(F6) Solder la pièce');
        $a2->refresh()->load('lignes');
        $this->assertSame(['Achat 2 corrigé', 'R2', '00000002', 25.0, 'BL9'], [$a2->libelle, $a2->reference, $a2->numero, (float) $a2->lignes[0]->debit, $a2->lignes[0]->reference]);
        // Changée de journal, la pièce prend le numéro suivant de son nouveau journal
        $this->put(route('compta.piece.update', $a2), ['journal' => 'OD', 'date' => '2026-03-05', 'lignes' => [['compte' => '6061', 'debit' => '25'], ['compte' => '401', 'credit' => '25']]]);
        $this->assertSame(['OD', '00000002'], [$a2->refresh()->journal, $a2->numero]);
    }

    public function test_le_journal_ouvre_la_saisie_par_piece(): void
    {
        Compta::passer($this->client->id, '2026-03-01', 'OD', 'Régul', [['compte' => '6061', 'debit' => 1], ['compte' => '401', 'credit' => 1]], null, $this->chef->id);
        $this->get(route('compta.index', ['tab' => 'jnl', 'annee' => 2026]))->assertOk()->assertSee('Saisir une pièce')->assertSee(route('compta.piece'), false)->assertSee('00000001');
    }
}
