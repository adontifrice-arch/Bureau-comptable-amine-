<?php

namespace Tests\Feature;

use App\Mail\EspaceClientMail;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Models\User;
use App\Support\CabinetCourant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Espace client : invitation, échange de documents dans les deux sens, cloisonnement par client et par cabinet. */
class EspaceClientTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;
    private User $collab;
    private Client $client;
    private Client $autreClient;
    private User $chefB;
    private Client $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Mail::fake();
        $this->chef = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        $this->collab = User::factory()->create(['cabinet_id' => $this->chef->cabinet_id, 'role' => 'collaborateur', 'status' => 'active']);
        [$this->client, $this->autreClient] = CabinetCourant::pour($this->chef->cabinet_id, fn () => [
            Client::factory()->create(['nom' => 'Société Alpha']), Client::factory()->create(['nom' => 'Société Bêta'])]);
        $this->chefB = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        $this->clientB = CabinetCourant::pour($this->chefB->cabinet_id, fn () => Client::factory()->create(['nom' => 'Client du cabinet B']));
    }

    protected function tearDown(): void
    {
        CabinetCourant::utiliser(null);
        parent::tearDown();
    }

    private function acces(Client $client, string $email = 'gerant@alpha.tn', bool $active = true): ClientUser
    {
        return ClientUser::create(['cabinet_id' => $client->cabinet_id, 'client_id' => $client->id, 'name' => 'Karim Alpha',
            'email' => $email, 'password' => $active ? 'MotDePasse1' : null, 'actif' => true]);
    }

    private function document(Client $client, array $attrs = []): Document
    {
        return CabinetCourant::pour($client->cabinet_id, function () use ($client, $attrs) {
            Storage::put('ged/'.$client->cabinet_id.'/'.$client->id.'/f.pdf', 'contenu');
            return Document::create($attrs + ['client_id' => $client->id, 'categorie' => 'Autres documents', 'nom' => 'bilan.pdf',
                'chemin' => 'ged/'.$client->cabinet_id.'/'.$client->id.'/f.pdf', 'taille' => 7, 'date' => now()]);
        });
    }

    // ---------- Invitation et connexion ----------

    public function test_invitation_puis_activation_et_connexion(): void
    {
        $this->actingAs($this->chef)->post(route('echanges.acces.store', $this->client), ['name' => 'Karim Alpha', 'email' => 'Gerant@Alpha.tn'])
            ->assertSessionHas('ok');

        $u = ClientUser::sole();
        $this->assertSame(['gerant@alpha.tn', $this->client->id, $this->chef->cabinet_id, null], [$u->email, $u->client_id, $u->cabinet_id, $u->password]);
        $lien = null;
        Mail::assertSent(EspaceClientMail::class, function (EspaceClientMail $m) use (&$lien) {
            $lien = $m->donnees['lien'];
            return $m->hasTo('gerant@alpha.tn') && $m->modele === 'invitation';
        });

        Auth::logout();
        $this->get($lien)->assertOk()->assertSee('Activer votre espace client');
        $this->post($lien, ['password' => 'MotDePasse1', 'password_confirmation' => 'MotDePasse1'])->assertRedirect(route('espace.index'));
        $this->assertAuthenticatedAs($u->fresh(), 'client');
        $this->get(route('espace.index'))->assertOk()->assertSee('Société Alpha');

        // Le lien ne sert qu'une fois
        $this->post(route('espace.logout'));
        $this->get($lien)->assertRedirect(route('espace.login'));

        $this->post(route('espace.login'), ['email' => 'GERANT@alpha.tn', 'password' => 'MotDePasse1'])->assertRedirect(route('espace.index'));
        $this->assertNotNull($u->fresh()->derniere_connexion_at);
    }

    public function test_lien_expire_refuse(): void
    {
        $u = $this->acces($this->client, active: false);
        $jeton = $u->nouveauJeton();
        $this->travel(ClientUser::VALIDITE_JETON_JOURS + 1)->days();
        $this->get(route('espace.activer', $jeton))->assertRedirect(route('espace.login'));
        $this->post(route('espace.activer', $jeton), ['password' => 'MotDePasse1', 'password_confirmation' => 'MotDePasse1'])->assertRedirect(route('espace.login'));
        $this->assertNull($u->fresh()->password);
    }

    public function test_seul_le_chef_gere_les_acces(): void
    {
        $this->actingAs($this->collab)->post(route('echanges.acces.store', $this->client), ['name' => 'X', 'email' => 'x@alpha.tn']);
        $this->assertSame(0, ClientUser::count());
    }

    public function test_un_cabinet_ne_touche_pas_aux_acces_d_un_autre(): void
    {
        $u = $this->acces($this->client);
        $this->actingAs($this->chefB)->patch(route('echanges.acces.basculer', $u))->assertNotFound();
        $this->actingAs($this->chefB)->delete(route('echanges.acces.destroy', $u))->assertNotFound();
        $this->actingAs($this->chefB)->post(route('echanges.acces.store', $this->client), ['name' => 'X', 'email' => 'x@alpha.tn'])->assertNotFound();
        $this->assertTrue($u->fresh()->actif);
    }

    public function test_mot_de_passe_oublie_sans_divulguer_les_comptes(): void
    {
        $u = $this->acces($this->client);
        $this->post(route('espace.oubli'), ['email' => 'inconnu@nulle-part.tn'])->assertRedirect(route('espace.login'))->assertSessionHas('ok');
        Mail::assertNothingSent();
        $this->post(route('espace.oubli'), ['email' => $u->email])->assertRedirect(route('espace.login'))->assertSessionHas('ok');
        Mail::assertSent(EspaceClientMail::class, fn ($m) => $m->hasTo($u->email) && $m->modele === 'reinitialisation');
    }

    public function test_acces_desactive_deconnecte(): void
    {
        $u = $this->acces($this->client);
        $this->actingAs($u, 'client')->get(route('espace.index'))->assertOk();
        $this->actingAs($this->chef)->patch(route('echanges.acces.basculer', $u));
        $this->actingAs($u->fresh(), 'client')->get(route('espace.index'))->assertRedirect(route('espace.login'));
        $this->assertGuest('client');
        $this->post(route('espace.login'), ['email' => $u->email, 'password' => 'MotDePasse1'])->assertSessionHas('err');
    }

    // ---------- Séparation des deux espaces ----------

    public function test_un_compte_client_n_ouvre_pas_l_application_du_cabinet(): void
    {
        $u = $this->acces($this->client);
        $this->actingAs($u, 'client');
        $this->get(route('clients.index'))->assertRedirect('/connexion');
        $this->get(route('ged.index'))->assertRedirect('/connexion');
    }

    public function test_un_utilisateur_du_cabinet_n_ouvre_pas_l_espace_client(): void
    {
        $this->actingAs($this->chef)->get(route('espace.index'))->assertRedirect(route('espace.login'));
    }

    // ---------- Documents ----------

    public function test_le_client_envoie_des_documents_au_cabinet(): void
    {
        $u = $this->acces($this->client);
        $this->actingAs($u, 'client')->post(route('espace.envoyer'), ['categorie' => 'Relevés bancaires', 'message' => 'Janvier à mars',
            'fichiers' => [UploadedFile::fake()->create('releve-janvier.pdf', 100, 'application/pdf'), UploadedFile::fake()->create('releve-fevrier.pdf', 100, 'application/pdf')]])
            ->assertSessionHas('ok');

        $docs = CabinetCourant::pour($this->chef->cabinet_id, fn () => Document::orderBy('id')->get());
        $this->assertCount(2, $docs);
        $this->assertSame(['client', $this->client->id, $u->id, 'Relevés bancaires', 'Janvier à mars', false], [$docs[0]->origine, $docs[0]->client_id, $docs[0]->depose_par, $docs[0]->categorie, $docs[0]->description, $docs[0]->partage]);
        Storage::assertExists($docs[0]->chemin);
        Mail::assertSent(EspaceClientMail::class, fn ($m) => $m->hasTo($this->chef->email) && $m->modele === 'envoi');

        // Côté cabinet : à traiter, puis traité
        $this->actingAs($this->chef)->get(route('echanges.index'))->assertOk()->assertSee('releve-janvier.pdf')->assertSee('Janvier à mars');
        $this->actingAs($this->collab)->patch(route('echanges.traiter', $docs[0]))->assertSessionHas('ok');
        $this->assertSame($this->collab->id, $docs[0]->fresh()->traite_par);
        $this->assertSame(1, CabinetCourant::pour($this->chef->cabinet_id, fn () => Document::aTraiter()->count()));

        // Le client ne peut plus retirer un envoi traité, mais peut retirer l'autre
        $this->actingAs($u, 'client')->delete(route('espace.retirer', $docs[0]->id))->assertNotFound();
        $this->actingAs($u, 'client')->delete(route('espace.retirer', $docs[1]->id))->assertSessionHas('ok');
        Storage::assertMissing($docs[1]->chemin);
    }

    public function test_l_envoi_refuse_une_categorie_ou_un_fichier_non_autorise(): void
    {
        $u = $this->acces($this->client);
        $this->actingAs($u, 'client')->post(route('espace.envoyer'), ['categorie' => 'Statuts', 'fichiers' => [UploadedFile::fake()->create('virus.exe', 10)]])
            ->assertSessionHasErrors('fichiers.0');
        $this->actingAs($u, 'client')->post(route('espace.envoyer'), ['categorie' => 'PV d’AGE', 'fichiers' => [UploadedFile::fake()->create('a.pdf', 10)]])
            ->assertSessionHasErrors('categorie');
        $this->assertSame(0, CabinetCourant::pour($this->chef->cabinet_id, fn () => Document::count()));
    }

    public function test_le_cabinet_partage_et_le_client_est_prevenu(): void
    {
        $u = $this->acces($this->client);
        $this->acces($this->client, 'jamais-active@alpha.tn', active: false);
        $this->actingAs($this->collab)->post(route('echanges.partager', $this->client), ['categorie' => 'États financiers et déclarations',
            'fichiers' => [UploadedFile::fake()->create('etats-financiers-2025.pdf', 200, 'application/pdf')]])->assertSessionHas('ok');

        $doc = CabinetCourant::pour($this->chef->cabinet_id, fn () => Document::sole());
        $this->assertTrue($doc->partage);
        Mail::assertSent(EspaceClientMail::class, 1);
        Mail::assertSent(EspaceClientMail::class, fn ($m) => $m->hasTo($u->email) && $m->modele === 'documents');

        $this->actingAs($u, 'client')->get(route('espace.index'))->assertSee('etats-financiers-2025.pdf')->assertSee('Nouveau');
        $this->actingAs($u, 'client')->get(route('espace.telecharger', $doc->id))->assertOk()->assertDownload('etats-financiers-2025.pdf');
        $this->assertNotNull($doc->fresh()->consulte_at);
    }

    public function test_partage_depuis_la_ged_et_retrait(): void
    {
        $u = $this->acces($this->client);
        $doc = $this->document($this->client);
        $this->actingAs($u, 'client')->get(route('espace.telecharger', $doc->id))->assertNotFound();   // pas encore partagé

        $this->actingAs($this->chef)->patch(route('echanges.partage', $doc))->assertSessionHas('ok');
        $this->actingAs($u, 'client')->get(route('espace.telecharger', $doc->id))->assertOk();

        $this->actingAs($this->chef)->patch(route('echanges.partage', $doc));
        $this->assertFalse($doc->fresh()->partage);
        $this->actingAs($u, 'client')->get(route('espace.telecharger', $doc->id))->assertNotFound();
    }

    public function test_un_document_interne_ne_peut_pas_etre_partage(): void
    {
        $doc = $this->document($this->client, ['client_id' => null]);
        $this->actingAs($this->chef)->patch(route('echanges.partage', $doc))->assertStatus(422);
    }

    public function test_ajout_en_ged_avec_partage(): void
    {
        $u = $this->acces($this->client);
        $this->actingAs($this->chef)->post(route('ged.store'), ['client_id' => $this->client->id, 'categorie' => 'PV d’AGO', 'partager' => '1',
            'fichiers' => [UploadedFile::fake()->create('pv-ago.pdf', 50, 'application/pdf')]])->assertSessionHas('ok');
        $this->assertTrue(CabinetCourant::pour($this->chef->cabinet_id, fn () => Document::sole()->partage));
        Mail::assertSent(EspaceClientMail::class, fn ($m) => $m->hasTo($u->email));
    }

    public function test_un_client_ne_voit_que_ses_propres_documents(): void
    {
        $u = $this->acces($this->client);
        $chezBeta = $this->document($this->autreClient, ['nom' => 'secret-beta.pdf', 'partage' => true]);
        $envoiBeta = $this->document($this->autreClient, ['nom' => 'envoi-beta.pdf', 'origine' => 'client']);
        $autreCabinet = $this->document($this->clientB, ['nom' => 'secret-cabinet-b.pdf', 'partage' => true]);
        $interne = $this->document($this->client, ['nom' => 'note-interne.pdf']);   // du dossier Alpha, mais non partagé

        $this->actingAs($u, 'client')->get(route('espace.index'))->assertOk()
            ->assertDontSee('secret-beta.pdf')->assertDontSee('envoi-beta.pdf')->assertDontSee('secret-cabinet-b.pdf')->assertDontSee('note-interne.pdf');
        foreach ([$chezBeta, $envoiBeta, $autreCabinet, $interne] as $d) {
            $this->actingAs($u, 'client')->get(route('espace.telecharger', $d->id))->assertNotFound();
            $this->actingAs($u, 'client')->delete(route('espace.retirer', $d->id))->assertNotFound();
        }
        $this->assertSame(4, CabinetCourant::pour($this->chef->cabinet_id, fn () => Document::count()) + CabinetCourant::pour($this->chefB->cabinet_id, fn () => Document::count()));
    }

    public function test_contenu_des_e_mails(): void
    {
        $u = $this->acces($this->client);
        $doc = $this->document($this->client, ['nom' => 'liasse-2025.pdf']);
        CabinetCourant::pour($this->chef->cabinet_id, function () use ($u, $doc) {
            $html = (new EspaceClientMail('invitation', 'Invitation', ['u' => $u, 'client' => $this->client, 'lien' => 'https://exemple.tn/lien-secret', 'jours' => 7]))->render();
            $this->assertStringContainsString('https://exemple.tn/lien-secret', $html);
            $this->assertStringContainsString('Société Alpha', $html);
            $html = (new EspaceClientMail('reinitialisation', 'Lien', ['u' => $u, 'client' => $this->client, 'lien' => 'https://exemple.tn/x', 'jours' => 7]))->render();
            $this->assertStringContainsString('nouveau mot de passe', $html);
            $html = (new EspaceClientMail('documents', 'Docs', ['u' => $u, 'client' => $this->client, 'docs' => collect([$doc]), 'lien' => route('espace.index')]))->render();
            $this->assertStringContainsString('liasse-2025.pdf', $html);
            $html = (new EspaceClientMail('envoi', 'Envoi', ['u' => $u, 'client' => $this->client, 'docs' => collect([$doc]), 'commentaire' => 'Voici <b>mes</b> pièces', 'lien' => route('echanges.index')]))->render();
            $this->assertStringContainsString('Voici &lt;b&gt;mes&lt;/b&gt; pièces', $html);
        });
    }

    public function test_pages_du_cabinet(): void
    {
        $this->acces($this->client);
        $this->document($this->client, ['nom' => 'recu.pdf', 'origine' => 'client']);
        $this->actingAs($this->chef)->get(route('clients.show', [$this->client, 'tab' => 'espace']))->assertOk()
            ->assertSee('Accès à l’espace client')->assertSee('gerant@alpha.tn')->assertSee('recu.pdf')->assertSee('Envoyer l’invitation');
        $this->actingAs($this->collab)->get(route('clients.show', [$this->client, 'tab' => 'espace']))->assertOk()->assertDontSee('Envoyer l’invitation');
        $this->actingAs($this->chef)->get(route('ged.index'))->assertOk()->assertSee('Envoyé par le client');
        $this->actingAs($this->chef)->get(route('dashboard'))->assertOk()->assertSee('Échanges clients');
        // Le cabinet B ne voit rien des échanges du cabinet A
        $this->actingAs($this->chefB)->get(route('echanges.index'))->assertOk()->assertDontSee('recu.pdf');
    }
}
