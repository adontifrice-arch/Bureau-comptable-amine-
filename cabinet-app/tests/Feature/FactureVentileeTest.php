<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\Immobilisation;
use App\Models\ModeleSaisie;
use App\Models\User;
use App\Services\ComptabilisationAmortissements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Factures d'achat et de vente ventilées sur plusieurs comptes, chacun avec son montant HT et son taux de TVA. */
class FactureVentileeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->actingAs(User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']));
        $this->client = Client::factory()->create();
        session(['dossier' => $this->client->id]);
    }

    private function ecriture(string $source): array
    {
        return Ecriture::where('source', $source)->sole()->lignes->map(fn ($l) => [$l->compte, (float) $l->debit, (float) $l->credit])->all();
    }

    private function achat(array $lignes, array $autres = []): FactureRecue
    {
        $this->post(route('compta.factures.store'), ['date' => '2026-03-10', 'fournisseur' => 'MONOPRIX', 'timbre' => 1, 'lignes' => $lignes] + $autres)->assertSessionHasNoErrors();
        return FactureRecue::latest('id')->first();
    }

    public function test_facture_d_achat_ventilee_et_son_ecriture(): void
    {
        $f = $this->achat([['compte' => '6061', 'ht' => 100, 'tva_taux' => 7], ['compte' => '', 'ht' => '', 'tva_taux' => 19], ['compte' => '6063', 'ht' => 200, 'tva_taux' => 19, 'tva' => '38.000']]);

        $this->assertSame([300.0, 45.0, '6061', 7.0], [(float) $f->ht, (float) $f->tva, $f->compte, (float) $f->tva_taux]);   // totaux, ligne vide écartée
        $this->assertSame([['compte' => '6061', 'ht' => 100.0, 'tva_taux' => 7.0, 'tva' => 7.0], ['compte' => '6063', 'ht' => 200.0, 'tva_taux' => 19.0, 'tva' => 38.0]], $f->lignesSaisie());

        $this->post(route('compta.factures.valider', $f))->assertSessionHas('ok');
        $this->assertSame([['6061', 100.0, 0.0], ['6063', 200.0, 0.0], ['4366', 45.0, 0.0], ['6654', 1.0, 0.0], ['401', 0.0, 346.0]], $this->ecriture('fr:'.$f->id));
    }

    public function test_tva_non_recuperable_incluse_dans_chaque_charge_et_retenue_sur_le_total(): void
    {
        $f = $this->achat([['compte' => '6061', 'ht' => 100, 'tva_taux' => 7], ['compte' => '6063', 'ht' => 200, 'tva_taux' => 19]], ['tva_deductible' => '0', 'retenue_taux' => 1]);
        $this->post(route('compta.factures.valider', $f));
        $this->assertSame([['6061', 107.0, 0.0], ['6063', 238.0, 0.0], ['6654', 1.0, 0.0], ['401', 0.0, 342.55], ['432', 0.0, 3.45]], $this->ecriture('fr:'.$f->id));
    }

    public function test_controles_de_saisie(): void
    {
        // Une seule ligne : facture « simple » (pas de ventilation enregistrée)
        $f = $this->achat([['compte' => '622', 'ht' => 500, 'tva_taux' => 19]]);
        $this->assertNull($f->lignes);
        $this->assertSame([500.0, 95.0, '622'], [(float) $f->ht, (float) $f->tva, $f->compte]);

        // Une ligne sans compte bloque la validation
        $g = $this->achat([['compte' => '6061', 'ht' => 10, 'tva_taux' => 7], ['compte' => '', 'ht' => 20, 'tva_taux' => 19]]);
        $this->post(route('compta.factures.valider', $g))->assertSessionHas('err');
        $this->assertSame('prop', $g->fresh()->statut);

        // Aucun montant : refusé ; compte non numérique : refusé
        $this->post(route('compta.factures.store'), ['date' => '2026-03-10', 'fournisseur' => 'X', 'lignes' => [['compte' => '6061', 'ht' => '']]])->assertSessionHasErrors('lignes');
        $this->post(route('compta.factures.store'), ['date' => '2026-03-10', 'fournisseur' => 'X', 'lignes' => [['compte' => '60A', 'ht' => 5]]])->assertSessionHasErrors('lignes.0.compte');

        // Modification : la ventilation est remplacée
        $this->put(route('compta.factures.update', $g), ['date' => '2026-03-10', 'fournisseur' => 'MONOPRIX', 'lignes' => [['compte' => '6064', 'ht' => 30, 'tva_taux' => 13]]])->assertSessionHas('ok');
        $this->assertSame([null, '6064', 30.0, 3.9], [$g->fresh()->lignes, $g->fresh()->compte, (float) $g->fresh()->ht, (float) $g->fresh()->tva]);
    }

    public function test_facture_de_vente_ventilee(): void
    {
        $this->post(route('compta.ventes.store'), ['date' => '2026-03-12', 'tiers' => 'Club Alpha', 'timbre' => 1,
            'lignes' => [['compte' => '705', 'ht' => 1000, 'tva_taux' => 19], ['compte' => '706', 'ht' => 100, 'tva_taux' => 7]]])->assertSessionHasNoErrors();
        $fv = FactureVente::sole();
        $this->post(route('compta.ventes.valider', $fv))->assertSessionHas('ok');
        $this->assertSame([['411', 1298.0, 0.0], ['705', 0.0, 1000.0], ['706', 0.0, 100.0], ['4367', 0.0, 197.0], ['437', 0.0, 1.0]], $this->ecriture('fv:'.$fv->id));

        $this->post(route('compta.ventes.store'), ['date' => '2026-03-12', 'tiers' => 'X', 'lignes' => [['compte' => '606', 'ht' => 10]]])->assertSessionHasErrors('lignes.0.compte');
    }

    public function test_memoriser_une_facture_ventilee(): void
    {
        $this->achat([['compte' => '6061', 'ht' => 100, 'tva_taux' => 7], ['compte' => '6063', 'ht' => 200, 'tva_taux' => 19]], ['memoriser' => '1']);
        $this->assertSame([['compte' => '6061', 'tva_taux' => 7.0], ['compte' => '6063', 'tva_taux' => 19.0]], ModeleSaisie::sole()->lignes());
    }

    public function test_seules_les_lignes_d_immobilisation_sont_importees_au_registre(): void
    {
        $f = $this->achat([['compte' => '6061', 'ht' => 50, 'tva_taux' => 7], ['compte' => '2282', 'ht' => 2400, 'tva_taux' => 19]], ['description' => 'Ordinateur portable et fournitures']);
        $f->update(['statut' => 'val']);

        $this->assertSame([$f->id], ComptabilisationAmortissements::facturesAImporter($this->client)->pluck('id')->all());
        $this->assertSame(1, ComptabilisationAmortissements::importer($this->client, [$f->id]));
        $i = Immobilisation::sole();
        $this->assertSame([2400.0, 456.0, 'informatique', '2282'], [$i->valeur_origine, $i->tva, $i->nature, $i->compteImmobilisation()]);
    }

    public function test_les_formulaires_s_affichent(): void
    {
        $this->achat([['compte' => '6061', 'ht' => 100, 'tva_taux' => 7], ['compte' => '6063', 'ht' => 200, 'tva_taux' => 19]]);
        $this->get(route('compta.index', ['tab' => 'ach']))->assertOk()->assertSee('factureVentilee(', false)->assertSee('+ Ligne')->assertSee('6063</span>', false);
        $this->get(route('compta.index', ['tab' => 'vte']))->assertOk()->assertSee('factureVentilee(', false);
    }
}
