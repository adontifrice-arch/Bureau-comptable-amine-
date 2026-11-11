<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Compte;
use App\Models\Echeance;
use App\Models\Facture;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Actes\OrdreDuJour;
use App\Support\CabinetCourant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** Multi-cabinet : inscription, invitations et cloisonnement des données (spécification §2, §4, §9). */
class MultiCabinetTest extends TestCase
{
    use RefreshDatabase;

    private User $chefA;
    private User $chefB;
    private Client $clientA;
    private Client $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chefA = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        $this->chefB = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        $this->clientA = CabinetCourant::pour($this->chefA->cabinet_id, fn () => Client::factory()->create(['nom' => 'Client du cabinet A']));
        $this->clientB = CabinetCourant::pour($this->chefB->cabinet_id, fn () => Client::factory()->create(['nom' => 'Client du cabinet B']));
    }

    // ---------- Inscription ----------

    public function test_inscription_cree_un_cabinet_dont_on_est_chef(): void
    {
        $this->post(route('register'), ['cabinet' => 'Cabinet Nouveau', 'name' => 'Nadia Gharbi', 'email' => 'nadia@exemple.tn',
            'password' => 'MotDePasse1', 'password_confirmation' => 'MotDePasse1'])->assertRedirect(route('login'));

        $u = User::where('email', 'nadia@exemple.tn')->sole();
        $this->assertSame(['chef', 'active', 'Cabinet Nouveau'], [$u->role, $u->status, $u->cabinet->nom]);
        CabinetCourant::pour($u->cabinet_id, function () {
            $this->assertSame(count(config('cabinet.PCN')), Compte::count());            // plan comptable du cabinet
            $this->assertSame(count(config('cabinet.SVC0')), Service::count());
            $this->assertSame('Cabinet Nouveau', Setting::cabinet()['nom']);
        });

        $this->post(route('login'), ['email' => 'nadia@exemple.tn', 'password' => 'MotDePasse1'])->assertRedirect(route('dashboard'));
        $this->get(route('clients.index'))->assertOk()->assertSee('Cabinet Nouveau')->assertDontSee('Client du cabinet A');
    }

    public function test_inscription_par_invitation_en_attente_de_validation(): void
    {
        $lien = route('register.invitation', $this->chefA->cabinet->code_invitation);
        $this->get($lien)->assertOk()->assertSee('Rejoindre '.$this->chefA->cabinet->nom);
        $this->post($lien, ['name' => 'Sami', 'email' => 'sami@exemple.tn', 'password' => 'MotDePasse1', 'password_confirmation' => 'MotDePasse1'])
            ->assertRedirect(route('login'));

        $u = User::where('email', 'sami@exemple.tn')->sole();
        $this->assertSame([$this->chefA->cabinet_id, 'collaborateur', 'pending'], [$u->cabinet_id, $u->role, $u->status]);

        $this->post(route('login'), ['email' => 'sami@exemple.tn', 'password' => 'MotDePasse1'])->assertSessionHas('err');
        $this->assertGuest();
    }

    public function test_lien_d_invitation_invalide_ou_regenere(): void
    {
        $this->get(route('register.invitation', str_repeat('x', 40)))->assertNotFound();

        $ancien = $this->chefA->cabinet->code_invitation;
        $this->actingAs($this->chefA)->post(route('parametres.invitation'))->assertSessionHas('ok');
        $this->assertNotSame($ancien, $this->chefA->cabinet->fresh()->code_invitation);

        auth()->logout();
        $this->get(route('register.invitation', $ancien))->assertNotFound();
        $this->post(route('register.invitation', $this->chefA->cabinet->fresh()->code_invitation),
            ['cabinet' => 'Pirate', 'name' => 'X', 'email' => 'x@exemple.tn', 'password' => 'MotDePasse1', 'password_confirmation' => 'MotDePasse1'])
            ->assertSessionHasErrors('cabinet');
    }

    // ---------- Cloisonnement ----------

    public function test_un_cabinet_ne_voit_pas_les_clients_d_un_autre(): void
    {
        $this->actingAs($this->chefA);

        $this->get(route('clients.index'))->assertOk()->assertSee('Client du cabinet A')->assertDontSee('Client du cabinet B');
        $this->get(route('clients.show', $this->clientB))->assertNotFound();
        $this->get(route('clients.edit', $this->clientB))->assertNotFound();
        $this->put(route('clients.update', $this->clientB), ['nom' => 'Piraté'])->assertNotFound();
        $this->delete(route('clients.destroy', $this->clientB))->assertNotFound();

        $this->assertSame(['Client du cabinet A'], Client::pluck('nom')->all());
        $this->assertSame('Client du cabinet B', CabinetCourant::pour($this->chefB->cabinet_id, fn () => $this->clientB->fresh()->nom));
    }

    public function test_un_identifiant_d_un_autre_cabinet_est_refuse_a_la_saisie(): void
    {
        $this->actingAs($this->chefA)
            ->post(route('echeances.store'), ['client_id' => $this->clientB->id, 'intitule' => 'TVA', 'date_echeance' => '2026-12-28'])
            ->assertSessionHasErrors('client_id');
        $this->assertSame(0, Echeance::where('intitule', 'TVA')->count());
    }

    public function test_creation_pour_un_autre_cabinet_impossible(): void
    {
        $this->actingAs($this->chefA);
        $this->expectException(RuntimeException::class);
        Client::create(['nom' => 'Intrus', 'cabinet_id' => $this->chefB->cabinet_id]);
    }

    public function test_sans_cabinet_courant_aucune_donnee_n_est_visible(): void
    {
        $this->assertNull(CabinetCourant::id());
        $this->assertSame(0, Client::count());
        $this->assertSame(2, Client::withoutGlobalScope('cabinet')->count());
    }

    public function test_numerotation_parametres_plan_comptable_et_ordre_du_jour_propres_a_chaque_cabinet(): void
    {
        $annee = now()->year;
        foreach ([[$this->chefA, $this->clientA, 'Cabinet Alpha'], [$this->chefB, $this->clientB, 'Cabinet Beta']] as [$chef, $client, $nom]) {
            $this->actingAs($chef);
            Facture::create(['type' => 'facture', 'numero' => Facture::prochainNumero('facture'), 'client_id' => $client->id, 'date' => now()]);
            Setting::put('cabinet', ['nom' => $nom]);
            $this->post(route('compta.comptes.store'), ['numero' => '6288', 'libelle' => 'Frais divers'])->assertSessionHasNoErrors();
            OrdreDuJour::ajouter('ago', 'Point de '.$nom);
        }

        foreach ([[$this->chefA, 'Cabinet Alpha', 'Cabinet Beta'], [$this->chefB, 'Cabinet Beta', 'Cabinet Alpha']] as [$chef, $nom, $autre]) {
            $this->actingAs($chef);
            $this->assertSame(["FAC-$annee-001"], Facture::pluck('numero')->all());        // numérotation indépendante
            $this->assertSame($nom, Setting::cabinet()['nom']);
            $this->assertSame(1, Compte::where('numero', '6288')->count());
            $this->assertSame(['Point de '.$nom], OrdreDuJour::perso()['ago']);
            $this->get(route('dashboard'))->assertOk()->assertSee($nom)->assertDontSee($autre);
        }
    }

    // ---------- Utilisateurs ----------

    public function test_un_chef_ne_gere_que_les_utilisateurs_de_son_cabinet(): void
    {
        $collegueB = User::factory()->create(['cabinet_id' => $this->chefB->cabinet_id, 'status' => 'pending']);
        $this->actingAs($this->chefA);

        $this->get(route('parametres.index', ['tab' => 'users']))->assertOk()->assertSee($this->chefA->email)
            ->assertDontSee($collegueB->email)->assertDontSee($this->chefB->email)
            ->assertSee(route('register.invitation', $this->chefA->cabinet->code_invitation));
        $this->patch(route('parametres.user', $collegueB), ['action' => 'activer'])->assertNotFound();
        $this->delete(route('parametres.user.destroy', $collegueB))->assertNotFound();
        $this->assertSame('pending', $collegueB->fresh()->status);
    }

    public function test_un_chef_ne_peut_ni_se_modifier_ni_se_supprimer(): void
    {
        // Un chef agit toujours sur un autre compte : le cabinet garde donc au moins un chef actif (lui-même).
        $chef2 = User::factory()->create(['cabinet_id' => $this->chefA->cabinet_id, 'role' => 'chef', 'status' => 'active']);
        $this->actingAs($this->chefA);

        $this->patch(route('parametres.user', $this->chefA), ['action' => 'role'])->assertSessionHas('err');
        $this->delete(route('parametres.user.destroy', $this->chefA))->assertSessionHas('err');
        $this->patch(route('parametres.user', $chef2), ['action' => 'role'])->assertSessionHas('ok');

        $this->assertSame(['chef', 'active'], [$this->chefA->fresh()->role, $this->chefA->fresh()->status]);
        $this->assertSame('collaborateur', $chef2->fresh()->role);
    }
}
