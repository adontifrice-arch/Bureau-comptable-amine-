<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\Immobilisation;
use App\Models\Releve;
use App\Models\ReleveLigne;
use App\Models\User;
use App\Services\Compta;
use App\Services\ComptabilisationAmortissements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Balance → grand livre → écriture (consulter, modifier, supprimer vers « Pièces supprimées », restaurer) et droits. */
class EcritureTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;
    private User $collab;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->chef = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']);
        $this->collab = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'collaborateur', 'status' => 'active']);
        $this->actingAs($this->chef);
        $this->client = Client::factory()->create();
        session(['dossier' => $this->client->id]);
    }

    private function od(string $date, string $libelle, array $lignes, ?string $source = null): Ecriture
    {
        return Compta::passer($this->client->id, $date, 'OD', $libelle, $lignes, $source, $this->chef->id);
    }

    private function soldes(): array
    {
        return collect(Compta::balance($this->client->id, 2026))->mapWithKeys(fn ($b) => [$b['compte'] => round($b['sd'] - $b['sc'], 3)])->all();
    }

    public function test_balance_vers_grand_livre_vers_ecriture(): void
    {
        $e = $this->od('2026-03-01', 'Loyer mars', [['compte' => '613', 'debit' => 900], ['compte' => '401', 'credit' => 900]]);
        $this->od('2026-03-02', 'Achat fournisseur', [['compte' => '6061', 'debit' => 50], ['compte' => '4011', 'credit' => 50]]);

        $gl = route('compta.index', ['tab' => 'gl', 'annee' => 2026, 'compte' => '401', 'exact' => 1]);
        $this->get(route('compta.index', ['tab' => 'bal', 'annee' => 2026]))->assertOk()->assertSee('ondblclick', false)->assertSee(e($gl), false);

        // Compte exact depuis la balance ; racine depuis la saisie
        $this->assertSame(['401'], $this->get($gl)->assertOk()->viewData('mouvements')->pluck('compte')->all());
        $this->assertSame(['401', '4011'], $this->get(route('compta.index', ['tab' => 'gl', 'annee' => 2026, 'compte' => '401']))->viewData('mouvements')->pluck('compte')->sort()->values()->all());

        $this->get($gl)->assertSee(e(route('compta.ecritures.show', $e->id)).'?retour=', false);       // chaque ligne ouvre son écriture
        $this->get(route('compta.ecritures.show', [$e, 'retour' => $gl]))->assertOk()->assertSee('Loyer mars')->assertSee('Modifier')->assertSee(e($gl), false);
    }

    public function test_droits_du_collaborateur_accordes_par_le_chef(): void
    {
        $e = $this->od('2026-03-01', 'Loyer mars', [['compte' => '613', 'debit' => 900], ['compte' => '401', 'credit' => 900]]);

        $this->actingAs($this->collab);
        $this->get(route('compta.ecritures.show', $e))->assertOk()->assertDontSee('Enregistrer les modifications')->assertSee('Modification et suppression réservées');
        $this->post(route('compta.ecritures.annuler', $e))->assertSessionHas('err');
        $this->put(route('compta.ecritures.update', $e), ['date' => '2026-03-01', 'journal' => 'OD', 'libelle' => 'X', 'lignes' => []])->assertSessionHas('err');
        $this->assertFalse($e->fresh()->trashed());

        // Le chef autorise le collaborateur (et lui seul peut le faire)
        $this->patch(route('parametres.user', $this->collab), ['action' => 'droit_ecritures'])->assertSessionHas('err');
        $this->actingAs($this->chef)->patch(route('parametres.user', $this->collab), ['action' => 'droit_ecritures'])->assertSessionHas('ok');
        $this->get(route('parametres.index', ['tab' => 'users']))->assertSee('Modifie / supprime les écritures');

        $this->actingAs($this->collab->fresh())->post(route('compta.ecritures.annuler', $e), ['motif' => 'Doublon'])->assertSessionHas('ok');
        $this->assertTrue($e->fresh()->trashed());
        $this->assertSame([$this->collab->id, 'Doublon'], [Ecriture::withTrashed()->find($e->id)->supprime_par, Ecriture::withTrashed()->find($e->id)->motif_suppression]);
    }

    public function test_modification_d_une_ecriture(): void
    {
        $e = $this->od('2026-03-01', 'Loyer mars', [['compte' => '613', 'debit' => 900], ['compte' => '401', 'credit' => 900]]);

        $this->put(route('compta.ecritures.update', $e), ['date' => '2026-03-05', 'journal' => 'OD', 'libelle' => 'Loyer mars',
            'lignes' => [['compte' => '613', 'debit' => 900], ['compte' => '401', 'credit' => 800]]])->assertSessionHas('err');      // déséquilibrée
        $this->put(route('compta.ecritures.update', $e), ['date' => '2026-03-05', 'journal' => 'BQ', 'libelle' => 'Loyer mars (corrigé)',
            'lignes' => [['compte' => '613', 'debit' => 950], ['compte' => '532', 'credit' => 950], ['compte' => '', 'debit' => '', 'credit' => '']]])->assertSessionHas('ok');

        $e->refresh();
        $this->assertSame(['2026-03-05', 'BQ', 'Loyer mars (corrigé)'], [$e->date->format('Y-m-d'), $e->journal, $e->libelle]);
        $this->assertSame(['532' => -950.0, '613' => 950.0], $this->soldes());
    }

    public function test_suppression_puis_restauration_d_une_ecriture_de_facture(): void
    {
        $f = FactureRecue::create(['client_id' => $this->client->id, 'date' => '2026-03-10', 'fournisseur' => 'STEG', 'ht' => 100, 'tva' => 19, 'timbre' => 1, 'compte' => '6062', 'statut' => 'prop']);
        $this->post(route('compta.factures.valider', $f));
        $e = Ecriture::where('source', 'fr:'.$f->id)->sole();
        $this->assertArrayHasKey('6062', $this->soldes());

        // Suppression depuis la fiche : retour au grand livre, facture à nouveau à valider, écriture hors des états
        $retour = route('compta.index', ['tab' => 'gl', 'annee' => 2026, 'compte' => '6062', 'exact' => 1]);
        $this->post(route('compta.ecritures.annuler', $e), ['motif' => 'Erreur de compte', 'depuis_fiche' => 1, 'retour' => $retour])->assertRedirect($retour);
        $this->assertSame('prop', $f->fresh()->statut);
        $this->assertSame([], $this->soldes());
        $this->assertSame(0, $this->get($retour)->viewData('mouvements')->count());
        $this->get(route('compta.index', ['tab' => 'jnl', 'annee' => 2026]))->assertDontSee('Facture  – STEG');

        // « Pièces supprimées »
        $this->get(route('compta.index', ['tab' => 'corb']))->assertOk()->assertSee('Pièces supprimées (1)')->assertSee('Erreur de compte')->assertSee('Facture d’achat STEG', false);
        $this->get(route('compta.ecritures.show', $e))->assertOk()->assertSee('Écriture supprimée')->assertSee('Restaurer l’écriture', false);

        // Restauration : l'écriture revient et la facture redevient comptabilisée
        $this->post(route('compta.ecritures.restaurer', $e))->assertSessionHas('ok');
        $this->assertFalse($e->fresh()->trashed());
        $this->assertSame('val', $f->fresh()->statut);
        $this->assertArrayHasKey('6062', $this->soldes());
    }

    public function test_restauration_refusee_si_la_piece_a_ete_comptabilisee_a_nouveau(): void
    {
        $f = FactureRecue::create(['client_id' => $this->client->id, 'date' => '2026-03-10', 'fournisseur' => 'STEG', 'ht' => 100, 'tva' => 19, 'compte' => '6062', 'statut' => 'prop']);
        $this->post(route('compta.factures.valider', $f));
        $ancienne = Ecriture::where('source', 'fr:'.$f->id)->sole();
        $this->post(route('compta.ecritures.annuler', $ancienne));
        $this->post(route('compta.factures.valider', $f))->assertSessionHas('ok');                                   // revalidée

        $this->post(route('compta.ecritures.restaurer', $ancienne))->assertSessionHas('err');
        $this->assertTrue(Ecriture::withTrashed()->find($ancienne->id)->trashed());
        $this->assertSame(1, Ecriture::where('source', 'fr:'.$f->id)->count());
    }

    public function test_ligne_de_banque_et_dotations_suivent_leur_ecriture(): void
    {
        $rel = Releve::create(['client_id' => $this->client->id, 'nom_fichier' => 'r.csv', 'methode' => 'csv']);
        $l = ReleveLigne::create(['client_id' => $this->client->id, 'releve_id' => $rel->id, 'date' => '2026-03-01', 'libelle' => 'FRAIS', 'debit' => 10, 'credit' => 0, 'compte' => '6278']);
        $this->post(route('compta.releves.lignes'), ['action' => 'comptabiliser', 'sel' => [$l->id]]);
        $e = Ecriture::where('source', 'rb:'.$l->id)->sole();
        $this->post(route('compta.ecritures.annuler', $e));
        $this->assertSame(['att', null], [$l->fresh()->statut, $l->fresh()->ecriture_id]);
        $this->post(route('compta.ecritures.restaurer', $e));
        $this->assertSame(['cpt', $e->id], [$l->fresh()->statut, $l->fresh()->ecriture_id]);

        // Dotations : l'écriture supprimée ne compte plus comme comptabilisée, restaurée elle compte de nouveau
        $i = Immobilisation::create(['client_id' => $this->client->id, 'nature' => 'materiel', 'designation' => 'Machine', 'date_mise_service' => '2026-01-01',
            'valeur_origine' => 10000, 'methode' => 'lineaire', 'taux' => 10, 'prorata' => 'jour']);
        $amo = ComptabilisationAmortissements::comptabiliser($this->client, 2026, $this->chef->id);
        $this->assertSame(1000.0, $i->fresh()->dejaComptabilise(2026));
        $this->post(route('compta.ecritures.annuler', $amo));
        $this->assertSame(0.0, $i->fresh()->dejaComptabilise(2026));
        $this->post(route('compta.ecritures.restaurer', $amo));
        $this->assertSame(1000.0, $i->fresh()->dejaComptabilise(2026));
    }
}
