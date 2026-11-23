<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\ModeleFacture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Paramètres facture achat / vente (modèles) et saisie d'une facture selon le modèle. */
class FactureModeleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $controleComptes = true;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->actingAs(User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']));
        $this->client = Client::factory()->create();
        session(['dossier' => $this->client->id]);
        // Sous-comptes du dossier utilisés par les modèles (le plan installé n'a que les comptes généraux)
        foreach (['607007', '607013', '607019', '436607', '436613', '436619', '401100', '411200', '707019', '707119', '436719', '436799', '437500'] as $n) {
            \App\Models\Compte::create(['numero' => $n, 'libelle' => 'Compte '.$n, 'personnalise' => true]);
        }
    }

    private function lignes(): array
    {
        return array_map(fn ($l) => array_combine(['objet', 'compte', 'tva_taux', 'compte_tva', 'debit'], $l), [
            ['ACHAT EXONERE', '607000', 0, '', 1], ['ACHAT DE MARCHANDISE 7%', '607007', 0, '436607', 1],
            ['ACHAT DE MARCHANDISE 13%', '607013', 0, '436613', 1], ['ACHAT DE MARCHANDISE 19%', '607019', 19, '436619', 1]]);
    }

    private function modeleAchat(): ModeleFacture
    {
        $this->get(route('compta.modeles-factures', 'achat'))->assertOk()->assertSee('paramètres facture achat')->assertSee('00000001');
        $this->post(route('compta.modeles-factures.store', 'achat'), ['libelle' => 'ACHAT HIDRI', 'journal' => 'ACH', 'compte_timbre' => '665400', 'timbre_debit' => 1,
            'montant_timbre' => 1, 'lignes' => $this->lignes()])->assertSessionHasNoErrors();
        return ModeleFacture::sole();
    }

    public function test_parametrage_facture_achat(): void
    {
        $m = $this->modeleAchat();
        $this->assertSame(['00000001', 'ACHAT HIDRI', 'ACH', true, false], [$m->numero, $m->libelle, $m->journal, $m->timbre_debit, $m->ttc_debit]);
        $this->assertCount(4, $m->lignesModele());
        $this->assertSame(['objet' => 'ACHAT DE MARCHANDISE 7%', 'compte' => '607007', 'tva_taux' => 0.0, 'compte_tva' => '436607', 'compte_majore' => '', 'taux_majore' => 0.0,
            'compte_tva_majore' => '', 'debit' => true], $m->lignesModele()[1]);
        $this->get(route('compta.modeles-factures', ['type' => 'achat', 'modele' => $m->id]))->assertOk()->assertSee('ACHAT HIDRI');
        $this->get(route('compta.index', ['tab' => 'param']))->assertOk()->assertSee('ACHAT HIDRI');

        // Modification : une ligne retirée
        $this->put(route('compta.modeles-factures.update', $m), ['libelle' => 'ACHAT HIDRI', 'journal' => 'ACH', 'lignes' => array_slice($this->lignes(), 0, 3)])->assertSessionHasNoErrors();
        $this->assertCount(3, $m->fresh()->lignesModele());
        $this->assertFalse($m->fresh()->timbre_debit);
    }

    public function test_saisie_facture_achat_selon_modele_et_ecriture(): void
    {
        $m = $this->modeleAchat();
        $this->get(route('compta.factures-modele', 'achat'))->assertOk()->assertSee('Facture achat')->assertSee('ACHAT HIDRI');
        $this->post(route('compta.factures-modele.store', 'achat'), ['modele_id' => $m->id, 'compte_tiers' => '401100', 'tiers' => 'SOTUMAG', 'reference' => 'F-12',
            'date' => '2026-11-20', 'timbre' => 1, 'avance' => 0, 'fodec' => 0,
            'lignes' => [['ht' => 50, 'tva' => 0], ['ht' => 100, 'tva' => 7], ['ht' => 0, 'tva' => 0], ['ht' => 200, 'tva' => 38]]])->assertSessionHasNoErrors();

        $f = FactureRecue::sole();
        $this->assertSame([350.0, 45.0, 396.0, 'SOTUMAG', 'prop'], [(float) $f->ht, (float) $f->tva, $f->ttc(), $f->fournisseur, $f->statut]);
        $this->assertSame([0.0, 7.0, 19.0], array_column($f->lignesSaisie(), 'tva_taux'));   // taux déduit des montants pour la 7 %

        $this->post(route('compta.factures.valider', $f))->assertSessionHas('ok');
        $e = Ecriture::where('source', 'fr:'.$f->id)->sole();
        $this->assertSame('ACH', $e->journal);
        // Libellé de la pièce et de chaque ligne : N° de facture originale et nom du fournisseur
        $this->assertSame('F-12 SOTUMAG', $e->libelle);
        $this->assertSame(['F-12 SOTUMAG'], $e->lignes->pluck('libelle')->unique()->values()->all());
        $this->assertEqualsCanonicalizing([['401100', 0.0, 396.0], ['607000', 50.0, 0.0], ['607007', 100.0, 0.0], ['436607', 7.0, 0.0], ['607019', 200.0, 0.0], ['436619', 38.0, 0.0], ['665400', 1.0, 0.0]],
            $e->lignes->map(fn ($l) => [$l->compte, (float) $l->debit, (float) $l->credit])->all());
    }

    public function test_facture_vente_avec_tva_majoree_et_modification(): void
    {
        $this->post(route('compta.modeles-factures.store', 'vente'), ['libelle' => 'VENTE HIDRI', 'journal' => 'VTE', 'ttc_debit' => 1, 'compte_timbre' => '437500', 'montant_timbre' => 1,
            'lignes' => [['objet' => 'VENTE EXONEREE', 'compte' => '707000', 'tva_taux' => 0, 'debit' => 0],
                ['objet' => 'VENTE 19%', 'compte' => '707019', 'tva_taux' => 19, 'compte_tva' => '436719', 'compte_majore' => '707119', 'taux_majore' => 1, 'compte_tva_majore' => '436799', 'debit' => 0]]])
            ->assertSessionHasNoErrors();
        $m = ModeleFacture::where('type', 'vente')->sole();
        $this->post(route('compta.factures-modele.store', 'vente'), ['modele_id' => $m->id, 'compte_tiers' => '411200', 'date' => '2026-11-21', 'timbre' => 1,
            'lignes' => [['ht' => 10], ['ht' => 100, 'tva' => 19]]])->assertSessionHasNoErrors();
        $f = FactureVente::sole();
        $this->assertSame([110.0, 20.0, 131.0], [(float) $f->ht, (float) $f->tva, $f->ttc()]);
        $this->assertEqualsCanonicalizing([['411200', 131.0, 0.0], ['707000', 0.0, 10.0], ['707119', 0.0, 100.0], ['436719', 0.0, 19.0], ['436799', 0.0, 1.0], ['437500', 0.0, 1.0]],
            array_map(fn ($l) => [$l['compte'], (float) $l['debit'], (float) $l['credit']], $f->lignesEcriture()));

        // Modification depuis l'écran de saisie
        $this->get(route('compta.factures-modele', ['type' => 'vente', 'facture' => $f->id]))->assertOk();
        $this->put(route('compta.factures-modele.update', ['vente', $f->id]), ['modele_id' => $m->id, 'compte_tiers' => '411200', 'date' => '2026-11-21', 'timbre' => 0,
            'lignes' => [['ht' => 20]]])->assertSessionHasNoErrors();
        $this->assertSame(20.0, $f->fresh()->ttc());
        $this->post(route('compta.ventes.valider', $f))->assertSessionHas('ok');
        $ev = Ecriture::where('source', 'fv:'.$f->id)->sole();
        $this->assertSame('VTE', $ev->journal);
        $this->assertSame('Compte 411200', $ev->libelle);      // sans N° de facture : nom du client (libellé de son compte)
    }

    public function test_compte_manquant_refuse(): void
    {
        $m = $this->modeleAchat();
        // Ni compte fournisseur saisi ni compte TTC dans le modèle
        $this->post(route('compta.factures-modele.store', 'achat'), ['modele_id' => $m->id, 'date' => '2026-11-20', 'lignes' => [['ht' => 10]]])->assertSessionHasErrors('compte_tiers');
        $this->assertSame(0, FactureRecue::count());
    }

    public function test_aucune_piece_avec_un_compte_absent_du_plan(): void
    {
        $this->assertTrue(\App\Services\Compta::existe('401000'));          // 401 complété par des zéros
        $this->assertTrue(\App\Services\Compta::existe('665400'));          // 6654
        $this->assertFalse(\App\Services\Compta::existe('401999'));

        // Modèle avec un compte inexistant : refusé
        $this->post(route('compta.modeles-factures.store', 'achat'), ['libelle' => 'X', 'journal' => 'ACH',
            'lignes' => [['objet' => 'ACHAT', 'compte' => '607555', 'debit' => 1]]])->assertSessionHas('err', fn ($m) => str_contains($m, '607555'));
        $this->assertSame(0, ModeleFacture::count());

        // Facture saisie avec un fournisseur inexistant : refusée
        $m = $this->modeleAchat();
        $this->post(route('compta.factures-modele.store', 'achat'), ['modele_id' => $m->id, 'compte_tiers' => '401999', 'date' => '2026-11-20', 'lignes' => [['ht' => 10]]])
            ->assertSessionHas('err', fn ($x) => str_contains($x, '401999'));
        $this->assertSame(0, FactureRecue::count());

        // Facture libre au compte inexistant : enregistrée, mais sa validation est refusée et rien n'est comptabilisé
        $this->post(route('compta.factures.store'), ['date' => '2026-11-20', 'fournisseur' => 'X', 'lignes' => [['compte' => '6069999', 'ht' => 100, 'tva_taux' => 19]]])->assertSessionHasNoErrors();
        $f = FactureRecue::sole();
        $this->post(route('compta.factures.valider', $f))->assertSessionHas('err', fn ($x) => str_contains($x, '6069999'));
        $this->assertSame('prop', $f->fresh()->statut);
        $this->assertSame(0, Ecriture::count());

        // Pièce manuelle : refusée, puis acceptée une fois le compte créé
        $piece = ['journal' => 'OD', 'date' => '2026-11-20', 'libelle' => 'Test', 'lignes' => [['libelle' => 'a', 'compte' => '6069999', 'debit' => '100'], ['libelle' => 'b', 'compte' => '401', 'credit' => '100']]];
        $this->post(route('compta.piece.store'), $piece)->assertSessionHas('err', fn ($x) => str_contains($x, '6069999'));
        $this->assertSame(0, Ecriture::count());
        $this->postJson(route('compta.comptes.store'), ['numero' => '6069999', 'libelle' => 'Achats divers'])->assertCreated();
        $this->post(route('compta.factures.valider', $f))->assertSessionHas('ok');
        $this->assertSame(1, Ecriture::count());
    }
}