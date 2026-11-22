<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\ChecklistRne;
use App\Models\Client;
use App\Models\DepotRne;
use App\Models\Document;
use App\Models\User;
use App\Services\Rne\Formalites;
use App\Support\CabinetCourant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Module RNE : dépôt des états financiers (pièces, approbation, ZIP, suivi) et check-lists des formalités. */
class RneTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->chef = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']);
        $this->actingAs($this->chef);
        $this->client = Client::create(['nom' => 'Alpha SARL', 'type_personne' => 'morale', 'forme' => 'SARL', 'matricule_fiscal' => '1234567/A/M/000',
            'capital' => 10000, 'nb_parts' => 1000, 'valeur_nominale' => 10]);
        $this->client->associes()->create(['civilite' => 'M', 'nom' => 'Karim Ben Salah', 'cin' => '01234567', 'parts' => 1000, 'apport' => 'numeraire']);
        session(['dossier' => $this->client->id]);
    }

    public function test_generation_des_pieces_du_depot(): void
    {
        $this->get(route('rne.index', ['exercice' => 2025]))->assertOk()->assertSee('Pièces du dépôt — exercice 2025')->assertSee('31/07/2026')->assertSee('Manquante');

        $this->post(route('rne.generer', [$this->client, 2025]))->assertSessionHas('ok');
        $depot = DepotRne::sole();
        $this->assertSame(['bilan', 'resultat', 'flux', 'notes', 'associes'], array_keys($depot->pieces));
        foreach ($depot->pieces as $k => $id) {
            $doc = Document::find($id);
            $this->assertStringStartsWith('%PDF', Storage::get($doc->chemin), $k);
            $this->assertStringContainsString('Dépôt RNE 2025', $doc->description);
        }
        $this->assertSame('Liste des associés', Document::find($depot->pieces['associes'])->categorie);

        // Une nouvelle génération remplace les pièces précédentes
        $this->post(route('rne.generer', [$this->client, 2025]))->assertSessionHas('ok');
        $this->assertSame(5, Document::count());

        // Dossier ZIP à déposer
        $r = $this->get(route('rne.zip', [$this->client, 2025]))->assertOk();
        $zip = new \ZipArchive();
        $zip->open($r->baseResponse->getFile()->getPathname());
        $this->assertSame(5, $zip->numFiles);
        $this->assertSame('1 - Bilan.pdf', $zip->getNameIndex(0));
        $zip->close();
    }

    public function test_les_parties_des_etats_financiers_sont_separees(): void
    {
        $bilan = $this->get(route('liasse.etats-financiers', ['annee' => 2025, 'partie' => 'bilan']))->assertOk();
        $bilan->assertSee('BILAN')->assertDontSee('Sommaire')->assertDontSee('ÉTAT DE RÉSULTAT</h2>', false);
        $this->get(route('liasse.etats-financiers', ['annee' => 2025, 'partie' => 'notes']))->assertOk()->assertSee('NOTES AUX ÉTATS FINANCIERS')->assertDontSee('<h2>BILAN</h2>', false);
        $this->get(route('liasse.etats-financiers', ['annee' => 2025]))->assertOk()->assertSee('Sommaire')->assertSee('ÉTAT DES FLUX DE TRÉSORERIE');
    }

    public function test_approbation_pv_et_rapport_du_commissaire(): void
    {
        // Avant l'approbation, le PV ne peut pas être importé
        $this->post(route('rne.importer', [$this->client, 2025, 'pv_ago']), ['fichier' => UploadedFile::fake()->create('pv.pdf', 30, 'application/pdf')])->assertStatus(422);

        $this->post(route('rne.enregistrer', [$this->client, 2025]), ['approbation' => '1', 'statut' => 'preparation'])->assertSessionHasErrors('date_approbation');
        $this->post(route('rne.enregistrer', [$this->client, 2025]), ['approbation' => '1', 'date_approbation' => '2026-06-20', 'statut' => 'preparation'])->assertSessionHas('ok');
        $this->post(route('rne.importer', [$this->client, 2025, 'pv_ago']), ['fichier' => UploadedFile::fake()->create('pv-ago.pdf', 30, 'application/pdf')])->assertSessionHas('ok');
        $this->post(route('rne.importer', [$this->client, 2025, 'rapport_cac']), ['fichier' => UploadedFile::fake()->create('rapport.jpg', 30, 'image/jpeg')])->assertSessionHas('ok');
        $this->post(route('rne.importer', [$this->client, 2025, 'bilan']), ['fichier' => UploadedFile::fake()->create('x.pdf', 3, 'application/pdf')])->assertNotFound();

        $depot = DepotRne::sole();
        $this->assertSame('PV d’AGO', $depot->document('pv_ago')->categorie);
        $this->assertContains('pv_ago', $depot->piecesAttendues($this->client));
        $this->get(route('rne.index', ['exercice' => 2025]))->assertOk()->assertSee('PV de l’AGO approuvant les états financiers (scanné)')->assertSee('Remplacer');

        // Retrait d'une pièce
        $this->delete(route('rne.retirer', [$this->client, 2025, 'rapport_cac']))->assertSessionHas('ok');
        $this->assertNull($depot->fresh()->document('rapport_cac'));

        // Dépôt effectué
        $this->post(route('rne.enregistrer', [$this->client, 2025]), ['approbation' => '1', 'date_approbation' => '2026-06-20', 'statut' => 'depose'])->assertSessionHasErrors('date_depot');
        $this->post(route('rne.enregistrer', [$this->client, 2025]), ['approbation' => '1', 'date_approbation' => '2026-06-20', 'statut' => 'depose',
            'date_depot' => '2026-07-10', 'reference' => 'DEM-2026-55'])->assertSessionHas('ok');
        $this->assertSame(['depose', '2026-07-10', 'DEM-2026-55'], [$depot->fresh()->statut, $depot->fresh()->date_depot->format('Y-m-d'), $depot->fresh()->reference]);
        $this->get(route('rne.index', ['exercice' => 2025]))->assertSee('Déposé')->assertSee('DEM-2026-55');
    }

    public function test_personne_physique_sans_liste_des_associes(): void
    {
        $pp = Client::create(['nom' => 'Ben Ali Sami', 'type_personne' => 'physique', 'forme' => 'Entreprise individuelle']);
        $this->post(route('rne.generer', [$pp, 2025]))->assertSessionHas('ok');
        $depot = DepotRne::where('client_id', $pp->id)->sole();
        $this->assertSame(['bilan', 'resultat', 'flux', 'notes'], array_keys($depot->pieces));
        $this->assertNotContains('associes', $depot->piecesAttendues($pp));
    }

    public function test_check_list_des_formalites(): void
    {
        $this->assertGreaterThan(80, count(Formalites::toutes()));
        $depot = Formalites::trouver('RNE M 004.09');
        $this->assertSame(['Dépôt des états financiers', 'Personne Morale', 'Mise à jour'], [$depot['procedure'], $depot['genre'], Formalites::operation($depot['code'])]);
        $this->assertContains('Les états financiers', $depot['documents']);

        // Par défaut : formalités du genre du client ; recherche sans accents
        $this->get(route('rne.index', ['tab' => 'checklist']))->assertOk()->assertSee('RNE M 004.09')->assertDontSee('RNE M 001.06');
        $this->get(route('rne.index', ['tab' => 'checklist', 'q' => 'etats financiers']))->assertOk()->assertSee('RNE M 004.09')->assertDontSee('RNE M 004.03');
        $this->get(route('rne.index', ['tab' => 'checklist', 'operation' => 'Création']))->assertOk()->assertSee('RNE C 012')->assertDontSee('RNE M 004.09');

        $this->post(route('rne.cocher', $this->client), ['code' => 'RNE M 004.09', 'coches' => [0, 3, 99], 'notes' => 'Liste à faire signer'])
            ->assertRedirect()->assertSessionHas('ok', fn ($m) => str_contains($m, '2/4'));
        $s = ChecklistRne::sole();
        $this->assertSame([[0, 3], 'Liste à faire signer'], [$s->coches, $s->notes]);
        $this->get(route('rne.index', ['tab' => 'checklist', 'code' => 'RNE M 004.09']))->assertSee('2/4');
        $this->post(route('rne.cocher', $this->client), ['code' => 'RNE X 999'])->assertNotFound();
    }

    public function test_cloisonnement_et_droits(): void
    {
        $chefB = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        $this->actingAs($chefB)->post(route('rne.generer', [$this->client, 2025]))->assertNotFound();
        $this->actingAs($chefB)->post(route('rne.cocher', $this->client), ['code' => 'RNE M 004.09'])->assertNotFound();

        $collab = User::factory()->create(['cabinet_id' => $this->chef->cabinet_id, 'role' => 'collaborateur', 'status' => 'active']);
        $collab->forceFill(['modules' => ['compta']])->save();
        $this->actingAs($collab)->get(route('rne.index'))->assertRedirect(route('dashboard'));
        $this->assertSame(0, CabinetCourant::pour($this->chef->cabinet_id, fn () => DepotRne::count()));
    }
}
