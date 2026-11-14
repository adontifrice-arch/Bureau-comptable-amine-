<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\ModeleSaisie;
use App\Models\ReleveLigne;
use App\Models\User;
use App\Services\Saisie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Paramétrage de la saisie, modèles, factures de vente et import des relevés bancaires. */
class SaisieRapideTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;
    private User $collab;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        config(['services.anthropic.key' => null]);
        $cabinet = Cabinet::creer('Cabinet test');
        $this->chef = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']);
        $this->collab = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'collaborateur', 'status' => 'active']);
        $this->actingAs($this->chef);
        $this->client = Client::factory()->create(['nom' => 'Alpha Conseil']);
        session(['dossier' => $this->client->id]);
    }

    private function lignes(Ecriture $e): array
    {
        return $e->lignes->map(fn ($l) => [$l->compte, (float) $l->debit, (float) $l->credit])->all();
    }

    private function parametres(array $banque = []): array
    {
        return ['achat' => ['compte_tiers' => '4011', 'compte' => '606', 'tva_taux' => 19, 'timbre' => 1, 'retenue_taux' => 0, 'tva_deductible' => '1'],
            'vente' => ['compte_tiers' => '4111', 'compte' => '705', 'tva_taux' => 19, 'timbre' => 1, 'compte_timbre' => '437'],
            'banque' => $banque + ['compte' => '5321', 'attente' => '461', 'regles' => [['mot_cle' => 'frais', 'compte' => '6278'], ['mot_cle' => 'CNSS', 'compte' => '45311'], ['mot_cle' => '', 'compte' => '']]]];
    }

    public function test_tous_les_onglets_s_affichent(): void
    {
        foreach (['ach', 'vte', 'bq', 'param', 'jnl', 'plan'] as $tab) {
            $this->get(route('compta.index', ['tab' => $tab]))->assertOk()->assertSee('Importer un relevé bancaire')->assertSee('Paramétrage');
        }
    }

    public function test_parametrage_reserve_au_chef_et_utilise_par_les_ecritures(): void
    {
        $this->actingAs($this->collab)->post(route('compta.parametres'), $this->parametres())->assertSessionHas('err', 'Action réservée au chef comptable.');
        $this->actingAs($this->chef)->post(route('compta.parametres'), $this->parametres())->assertSessionHas('ok');

        $p = Saisie::parametres($this->client->id);
        $this->assertSame(['4011', '4111', '5321'], [$p['achat']['compte_tiers'], $p['vente']['compte_tiers'], $p['banque']['compte']]);
        $this->assertSame([['mot_cle' => 'frais', 'compte' => '6278'], ['mot_cle' => 'CNSS', 'compte' => '45311']], $p['banque']['regles']);      // règle vide écartée

        $f = FactureRecue::create(['client_id' => $this->client->id, 'date' => '2026-03-01', 'fournisseur' => 'STEG', 'ht' => 100, 'tva' => 19, 'timbre' => 1, 'compte' => '6061', 'statut' => 'prop']);
        $this->assertSame('4011', collect($f->lignesEcriture())->firstWhere('credit', '>', 0)['compte']);
        $this->post(route('compta.parametres'), $this->parametres(['compte' => 'abc']))->assertSessionHasErrors('banque.compte');
    }

    public function test_modeles_et_memorisation_depuis_une_facture(): void
    {
        $this->post(route('compta.modeles.store'), ['type' => 'achat', 'nom' => 'STEG électricité', 'tiers' => 'STEG', 'compte' => '6061', 'tva_taux' => 19, 'timbre' => 1])->assertSessionHas('ok');
        $m = ModeleSaisie::sole();
        $this->assertEquals(['fournisseur' => 'STEG', 'compte' => '6061', 'tva_taux' => 19.0, 'timbre' => 1.0], array_intersect_key($m->valeurs(), array_flip(['fournisseur', 'compte', 'tva_taux', 'timbre'])));
        $this->get(route('compta.index', ['tab' => 'ach']))->assertSee('Saisie rapide : partir d’un modèle', false)->assertSee('STEG électricité');

        // Facture d'achat mémorisée comme modèle
        $this->post(route('compta.factures.store'), ['date' => '2026-03-02', 'fournisseur' => 'Tunisie Telecom', 'ht' => 80, 'tva_taux' => 19, 'compte' => '626', 'memoriser' => '1'])->assertSessionHas('ok');
        $this->assertSame('626', ModeleSaisie::where('tiers', 'Tunisie Telecom')->value('compte'));

        $this->put(route('compta.modeles.update', $m), ['type' => 'achat', 'nom' => 'STEG', 'tiers' => 'STEG', 'compte' => '6062', 'tva_taux' => 19])->assertSessionHas('ok');
        $this->assertSame('6062', $m->fresh()->compte);
        $this->delete(route('compta.modeles.destroy', $m))->assertSessionHas('ok');
        $this->assertSame(1, ModeleSaisie::count());
    }

    public function test_facture_de_vente_validee_puis_annulee(): void
    {
        $this->actingAs($this->collab)->post(route('compta.ventes.store'), ['date' => '2026-03-05', 'tiers' => 'Société Beta', 'reference' => 'FV-001',
            'ht' => 1000, 'tva_taux' => 19, 'timbre' => 1, 'compte' => '705', 'memoriser' => '1'])->assertSessionHas('ok');
        $fv = FactureVente::sole();
        $this->assertSame([190.0, 'prop'], [$fv->tva, $fv->statut]);
        $this->assertSame('vente', ModeleSaisie::sole()->type);
        $this->post(route('compta.ventes.valider', $fv))->assertSessionHas('err', 'Action réservée au chef comptable.');                         // collaborateur

        $this->actingAs($this->chef)->post(route('compta.ventes.valider', $fv))->assertSessionHas('ok');
        $e = Ecriture::where('source', 'fv:'.$fv->id)->sole();
        $this->assertSame('VTE', $e->journal);
        $this->assertSame([['411', 1191.0, 0.0], ['705', 0.0, 1000.0], ['4367', 0.0, 190.0], ['437', 0.0, 1.0]], $this->lignes($e));
        $this->delete(route('compta.ventes.destroy', $fv))->assertSessionHas('err');                   // comptabilisée

        $this->post(route('compta.ecritures.annuler', $e))->assertSessionHas('ok');
        $this->assertSame('prop', $fv->fresh()->statut);
        $this->post(route('compta.ventes.store'), ['date' => '2026-03-05', 'tiers' => 'X', 'ht' => 10, 'compte' => '606'])->assertSessionHasErrors('compte');
    }

    public function test_import_csv_contreparties_proposees_doublons_et_comptabilisation(): void
    {
        $this->post(route('compta.parametres'), $this->parametres());
        ModeleSaisie::create(['client_id' => $this->client->id, 'type' => 'achat', 'nom' => 'STEG', 'tiers' => 'STEG', 'compte' => '6061', 'tva_taux' => 19]);
        $csv = "Date;Libellé;Débit;Crédit\n02/03/2026;VIR RECU SOCIETE BETA;;1 191,000\n05/03/2026;Frais tenue de compte;12,500;\n"
            ."10/03/2026;CHEQUE 4521 STEG;120,000;\n15/03/2026;PRLV CNSS;300,000;\n20/03/2026;OPERATION INCONNUE;50,000;\n";
        $fichier = fn () => UploadedFile::fake()->createWithContent('releve-mars.csv', $csv);

        $this->actingAs($this->collab)->post(route('compta.releves.importer'), ['fichier' => $fichier()])->assertRedirect()->assertSessionHas('ok');
        $l = ReleveLigne::orderBy('date')->get();
        $this->assertSame(['461', '6278', '4011', '45311', '461'], $l->pluck('compte')->all());       // règle « frais » (casse ignorée), fournisseur STEG du modèle, règle CNSS, sinon attente
        $this->assertSame(1191.0, $l[0]->credit);

        // Réimport : tout est écarté
        $this->post(route('compta.releves.importer'), ['fichier' => $fichier()])->assertSessionHas('ok');
        $this->assertSame(5, ReleveLigne::count());

        // Le collaborateur affecte, le chef comptabilise
        $sel = $l->take(2)->pluck('id')->all();
        $this->post(route('compta.releves.lignes'), ['action' => 'comptabiliser', 'sel' => $sel])->assertSessionHas('err', 'Action réservée au chef comptable.');
        $this->post(route('compta.releves.lignes'), ['action' => 'enregistrer', 'comptes' => [$l[0]->id => '4111']])->assertSessionHas('ok');
        $this->actingAs($this->chef)->post(route('compta.releves.lignes'), ['action' => 'comptabiliser', 'sel' => $sel])->assertSessionHas('ok');

        $e1 = Ecriture::where('source', 'rb:'.$l[0]->id)->sole();
        $this->assertSame(['BQ', [['5321', 1191.0, 0.0], ['4111', 0.0, 1191.0]]], [$e1->journal, $this->lignes($e1)]);
        $this->assertSame([['6278', 12.5, 0.0], ['5321', 0.0, 12.5]], $this->lignes(Ecriture::where('source', 'rb:'.$l[1]->id)->sole()));
        $this->assertSame(['cpt', 'cpt', 'att'], ReleveLigne::orderBy('date')->take(3)->pluck('statut')->all());

        // Annuler l'écriture remet la ligne à traiter ; on ne supprime pas un relevé comptabilisé
        $this->delete(route('compta.releves.destroy', $l[0]->releve_id))->assertSessionHas('err');
        $this->post(route('compta.ecritures.annuler', $e1))->assertSessionHas('ok');
        $this->assertSame(['att', null], [$l[0]->fresh()->statut, $l[0]->fresh()->ecriture_id]);

        // Ignorer, rétablir, inverser
        $this->post(route('compta.releves.lignes'), ['action' => 'ignorer', 'sel' => [$l[4]->id]]);
        $this->assertSame('ign', $l[4]->fresh()->statut);
        $this->post(route('compta.releves.lignes'), ['action' => 'retablir', 'sel' => [$l[4]->id]]);
        $this->post(route('compta.releves.lignes').'?ligne='.$l[4]->id, ['action' => 'inverser']);
        $this->assertSame([0.0, 50.0, 'att'], [$l[4]->fresh()->debit, $l[4]->fresh()->credit, $l[4]->fresh()->statut]);
        $this->get(route('compta.index', ['tab' => 'bq']))->assertOk()->assertSee('OPERATION INCONNUE');
    }

    public function test_ligne_au_sens_incertain_a_confirmer_avant_comptabilisation(): void
    {
        $f = UploadedFile::fake()->createWithContent('releve.txt', "Relevé\n12/03/2026 OPERATION 42 75,000\n13/03/2026 VERSEMENT ESPECES 500,000\n");
        $this->post(route('compta.releves.importer'), ['fichier' => $f])->assertSessionHas('ok');
        $l = ReleveLigne::orderBy('date')->get();
        $this->assertSame([true, false], $l->pluck('sens_incertain')->all());

        $this->post(route('compta.releves.lignes'), ['action' => 'comptabiliser', 'sel' => [$l[0]->id], 'comptes' => [$l[0]->id => '6278']])->assertSessionHas('err');
        $this->assertSame('att', $l[0]->fresh()->statut);
        $this->post(route('compta.releves.lignes'), ['action' => 'confirmer', 'sel' => [$l[0]->id]]);
        $this->post(route('compta.releves.lignes'), ['action' => 'comptabiliser', 'sel' => [$l[0]->id]])->assertSessionHas('ok');
        $this->assertSame('cpt', $l[0]->fresh()->statut);
    }

    public function test_operations_identiques_conservees_et_comptabilisation_de_tout_le_releve(): void
    {
        $csv = "Date;Libellé;Débit;Crédit\n02/01/2026;REM TPE;;7,000\n02/01/2026;REM TPE;;7,000\n02/01/2026;COMM REM TPE 123;0,780;\n";
        $this->post(route('compta.releves.importer'), ['fichier' => UploadedFile::fake()->createWithContent('janvier.csv', $csv)])->assertSessionHas('ok');
        $this->assertSame(3, ReleveLigne::count());                                    // deux encaissements identiques : deux lignes
        $this->assertSame('6278', ReleveLigne::where('debit', '>', 0)->value('compte'));   // règle par défaut « COMM »
        $this->assertSame(['411000'], ReleveLigne::where('credit', '>', 0)->distinct()->pluck('compte')->all());   // règle par défaut « REM TPE »

        // Réimport du même relevé avec une opération de plus : seule la nouvelle entre
        $this->post(route('compta.releves.importer'), ['fichier' => UploadedFile::fake()->createWithContent('janvier-bis.csv', $csv."03/01/2026;REM TPE;;9,000\n")]);
        $this->assertSame(4, ReleveLigne::count());

        $this->post(route('compta.releves.lignes'), ['action' => 'comptabiliser_tout'])->assertSessionHas('ok');
        $this->assertSame(4, ReleveLigne::where('statut', 'cpt')->count());
        $this->assertSame(4, Ecriture::where('journal', 'BQ')->count());
        $this->post(route('compta.releves.lignes'), ['action' => 'comptabiliser_tout'])->assertSessionHas('err');
    }

    public function test_fichier_illisible_ou_format_refuse(): void
    {
        $this->post(route('compta.releves.importer'), ['fichier' => UploadedFile::fake()->createWithContent('vide.csv', "a;b\n1;2\n")])->assertSessionHas('err');
        $this->post(route('compta.releves.importer'), ['fichier' => UploadedFile::fake()->create('releve.exe', 10)])->assertSessionHasErrors('fichier');
        $this->assertSame(0, ReleveLigne::count());
    }
}
