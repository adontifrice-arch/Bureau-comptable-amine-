<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Echeance;
use App\Models\Facture;
use App\Models\JournalOperation;
use App\Models\Sauvegarde;
use App\Models\Setting;
use App\Models\User;
use App\Services\Sauvegardes;
use App\Support\CabinetCourant;
use App\Support\Droits;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use ZipArchive;

/** Paramètres réservés au chef : utilisateurs (droits, dossiers, réseau, blocage, suivi) et sauvegardes automatiques. */
class ParametresDroitsTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;
    private Client $alpha;
    private Client $beta;
    private string $dossierSauvegardes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dossierSauvegardes = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sauvegardes-test-'.uniqid();
        config(['filesystems.sauvegardes' => $this->dossierSauvegardes]);
        $this->chef = User::factory()->create(['role' => 'chef', 'status' => 'active', 'email' => 'chef@cabinet.tn']);
        [$this->alpha, $this->beta] = CabinetCourant::pour($this->chef->cabinet_id, fn () => [
            Client::factory()->create(['nom' => 'Société Alpha']), Client::factory()->create(['nom' => 'Société Bêta'])]);
    }

    protected function tearDown(): void
    {
        CabinetCourant::utiliser(null);
        Droits::oublier();
        if (is_dir($this->dossierSauvegardes)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dossierSauvegardes, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dossierSauvegardes);
        }
        parent::tearDown();
    }

    private function creerCollaborateur(array $champs = []): User
    {
        $this->actingAs($this->chef)->post(route('parametres.utilisateurs.store'), $champs + [
            'name' => 'Sana Saisie', 'email' => 'sana@cabinet.tn', 'fonction' => 'Comptable', 'role' => 'collaborateur',
            'password' => 'MotDePasse1', 'password_confirmation' => 'MotDePasse1',
            'modules' => ['clients', 'echeances', 'compta'], 'dossiers' => [$this->alpha->id], 'acces_distant' => '1',
        ])->assertSessionHasNoErrors();
        Auth::logout();
        Droits::oublier();
        return User::where('email', $champs['email'] ?? 'sana@cabinet.tn')->sole();
    }

    // ---------- Paramètres réservés au chef ----------

    public function test_un_collaborateur_ne_voit_que_mon_compte(): void
    {
        $collab = User::factory()->create(['cabinet_id' => $this->chef->cabinet_id, 'role' => 'collaborateur', 'status' => 'active']);
        $this->actingAs($collab)->get(route('dashboard'))->assertOk()->assertDontSee(route('parametres.index').'"', false);
        $this->actingAs($collab)->get(route('parametres.index', ['tab' => 'users']))->assertOk()
            ->assertSee('Changer mon mot de passe')->assertDontSee('Ajouter un utilisateur')->assertDontSee('Sauvegarde automatique');
        $this->actingAs($collab)->post(route('parametres.utilisateurs.store'), ['name' => 'X', 'email' => 'x@x.tn', 'role' => 'chef', 'password' => 'MotDePasse1', 'password_confirmation' => 'MotDePasse1'])
            ->assertSessionHas('err');
        $this->actingAs($collab)->post(route('parametres.sauvegardes.maintenant'), ['elements' => ['clients']])->assertSessionHas('err');
        $this->assertSame(2, User::count());
        $this->assertSame(0, Sauvegarde::withoutGlobalScopes()->count());
    }

    // ---------- Utilisateurs : création, modules, dossiers ----------

    public function test_creation_d_un_utilisateur_avec_ses_droits(): void
    {
        $u = $this->creerCollaborateur();
        $this->assertSame(['Comptable', 'collaborateur', 'active', ['clients', 'echeances', 'compta'], false, true],
            [$u->fonction, $u->role, $u->status, $u->modules, $u->tous_dossiers, $u->acces_distant]);
        $this->assertSame([$this->alpha->id], CabinetCourant::pour($u->cabinet_id, fn () => $u->dossiers()->pluck('clients.id')->all()));

        $this->post(route('login'), ['email' => 'sana@cabinet.tn', 'password' => 'MotDePasse1'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk()->assertSee('Comptabilité')->assertDontSee('Facturation')->assertDontSee('Paie</a>', false);
    }

    public function test_un_module_non_autorise_est_refuse(): void
    {
        $u = $this->creerCollaborateur();
        $this->actingAs($u)->get(route('factures.index'))->assertRedirect(route('dashboard'))->assertSessionHas('err');
        $this->actingAs($u)->get(route('paie.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($u)->get(route('echeances.index'))->assertOk();
    }

    public function test_un_dossier_non_autorise_est_invisible_et_protege(): void
    {
        $u = $this->creerCollaborateur();
        $this->actingAs($u)->get(route('clients.index'))->assertOk()->assertSee('Société Alpha')->assertDontSee('Société Bêta');
        $this->actingAs($u)->get(route('clients.show', $this->beta))->assertNotFound();

        // Les données rattachées à un autre dossier sont filtrées, et y écrire est refusé
        CabinetCourant::pour($this->chef->cabinet_id, function () {
            Echeance::create(['client_id' => $this->alpha->id, 'intitule' => 'TVA Alpha', 'date_echeance' => now()->addDays(3)]);
            Echeance::create(['client_id' => $this->beta->id, 'intitule' => 'TVA Bêta', 'date_echeance' => now()->addDays(3)]);
        });
        $this->actingAs($u)->get(route('echeances.index'))->assertSee('TVA Alpha')->assertDontSee('TVA Bêta');
        $this->actingAs($u)->post(route('echeances.store'), ['client_id' => $this->beta->id, 'intitule' => 'Intrusion', 'date_echeance' => now()->format('Y-m-d')])
            ->assertForbidden();
        $this->actingAs($u)->post(route('echeances.store'), ['client_id' => $this->alpha->id, 'intitule' => 'Autorisée', 'date_echeance' => now()->format('Y-m-d')])
            ->assertSessionHas('ok');
        $this->assertSame(0, CabinetCourant::pour($this->chef->cabinet_id, fn () => Echeance::where('intitule', 'Intrusion')->count()));
    }

    public function test_un_client_cree_par_un_utilisateur_limite_lui_reste_accessible(): void
    {
        $u = $this->creerCollaborateur();
        $this->actingAs($u);
        $c = Client::factory()->create(['nom' => 'Nouveau dossier']);
        $this->assertTrue(Client::whereKey($c->id)->exists());
        $this->assertContains($c->id, Droits::dossiers());
    }

    public function test_la_numerotation_des_factures_ignore_le_filtre_des_dossiers(): void
    {
        CabinetCourant::pour($this->chef->cabinet_id, fn () => Facture::create(['type' => 'facture', 'numero' => 'FAC-'.now()->year.'-007',
            'client_id' => $this->beta->id, 'date' => now(), 'statut' => 0]));
        $u = $this->creerCollaborateur(['modules' => ['facturation']]);
        $this->actingAs($u);
        $this->assertSame('FAC-'.now()->year.'-008', Facture::prochainNumero());
    }

    public function test_modification_des_droits(): void
    {
        $u = $this->creerCollaborateur();
        $this->assertTrue($u->peutValider());                                     // droit de saisir et valider accordé par défaut
        $this->actingAs($this->chef)->put(route('parametres.utilisateurs.update', $u), ['name' => 'Sana Saisie', 'email' => 'sana@cabinet.tn', 'role' => 'collaborateur',
            'fonction' => 'Assistante paie', 'modules' => ['paie'], 'dossiers_tous' => '1', 'acces_distant' => '0', 'droit_validation' => '0'])->assertSessionHas('ok');
        $u->refresh();
        $this->assertSame(['Assistante paie', ['paie'], true, false, false], [$u->fonction, $u->modules, $u->tous_dossiers, $u->acces_distant, $u->peutValider()]);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('user_dossiers')->where('user_id', $u->id)->count());
    }

    public function test_le_chef_ne_peut_pas_se_retirer_son_role(): void
    {
        $this->actingAs($this->chef)->put(route('parametres.utilisateurs.update', $this->chef), ['name' => 'Chef', 'email' => 'chef@cabinet.tn', 'role' => 'collaborateur'])
            ->assertSessionHas('err');
        $this->assertTrue($this->chef->fresh()->estChef());
    }

    public function test_un_autre_cabinet_ne_voit_pas_les_utilisateurs(): void
    {
        $u = $this->creerCollaborateur();
        $chefB = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        $this->actingAs($chefB)->get(route('parametres.utilisateurs.show', $u))->assertNotFound();
        $this->actingAs($chefB)->post(route('parametres.utilisateurs.bloquer', $u))->assertNotFound();
        $this->assertSame('active', $u->fresh()->status);
    }

    // ---------- Blocage ----------

    public function test_blocage_et_deblocage(): void
    {
        $u = $this->creerCollaborateur();
        $this->actingAs($u)->get(route('dashboard'))->assertOk();
        $this->actingAs($this->chef)->post(route('parametres.utilisateurs.bloquer', $u), ['motif' => 'Fin de contrat'])->assertSessionHas('ok');
        $this->assertSame(['disabled', 'Fin de contrat'], [$u->fresh()->status, $u->fresh()->motif_blocage]);

        $this->actingAs($u->fresh())->get(route('dashboard'))->assertRedirect(route('login'))->assertSessionHas('err', fn ($m) => str_contains($m, 'Fin de contrat'));
        Auth::logout();
        $this->post(route('login'), ['email' => 'sana@cabinet.tn', 'password' => 'MotDePasse1'])->assertSessionHas('err', fn ($m) => str_contains($m, 'bloqué'));

        $this->actingAs($this->chef)->post(route('parametres.utilisateurs.debloquer', $u))->assertSessionHas('ok');
        Auth::logout();
        $this->post(route('login'), ['email' => 'sana@cabinet.tn', 'password' => 'MotDePasse1'])->assertRedirect(route('dashboard'));
    }

    public function test_le_chef_ne_peut_pas_se_bloquer(): void
    {
        $this->actingAs($this->chef)->post(route('parametres.utilisateurs.bloquer', $this->chef))->assertSessionHas('err');
        $this->assertSame('active', $this->chef->fresh()->status);
    }

    // ---------- Réseau du cabinet ----------

    public function test_acces_seulement_depuis_le_reseau_du_cabinet(): void
    {
        $u = $this->creerCollaborateur(['acces_distant' => '0']);
        $this->actingAs($this->chef)->post(route('parametres.reseau'), ['adresses' => "197.15.20.4\n192.168.1.0/24"])->assertSessionHas('ok');
        Auth::logout();

        $this->withServerVariables(['REMOTE_ADDR' => '41.226.5.9'])->post(route('login'), ['email' => 'sana@cabinet.tn', 'password' => 'MotDePasse1'])
            ->assertSessionHas('err', fn ($m) => str_contains($m, 'réseau du cabinet'));
        $this->assertGuest();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.37'])->post(route('login'), ['email' => 'sana@cabinet.tn', 'password' => 'MotDePasse1'])
            ->assertRedirect(route('dashboard'));

        // Session ouverte au cabinet puis utilisée de l'extérieur : déconnexion
        $this->withServerVariables(['REMOTE_ADDR' => '41.226.5.9'])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        // Le chef garde toujours l'accès
        $this->withServerVariables(['REMOTE_ADDR' => '41.226.5.9'])->post(route('login'), ['email' => 'chef@cabinet.tn', 'password' => 'password'])->assertRedirect(route('dashboard'));
    }

    public function test_adresses_reseau_invalides_refusees(): void
    {
        $this->actingAs($this->chef)->post(route('parametres.reseau'), ['adresses' => '197.15.20.4 pas-une-ip 10.0.0.0/99'])->assertSessionHas('err');
        $this->assertSame([], CabinetCourant::pour($this->chef->cabinet_id, fn () => Droits::adressesReseau()));
    }

    // ---------- Suivi des opérations ----------

    public function test_suivi_des_operations_et_des_connexions(): void
    {
        $u = $this->creerCollaborateur();
        $this->post(route('login'), ['email' => 'sana@cabinet.tn', 'password' => 'mauvais']);
        $this->post(route('login'), ['email' => 'sana@cabinet.tn', 'password' => 'MotDePasse1']);
        $this->get(route('echeances.index'))->assertOk();                                       // consultation : non tracée
        $this->post(route('echeances.store'), ['client_id' => $this->alpha->id, 'intitule' => 'Acompte IS', 'date_echeance' => now()->format('Y-m-d')]);

        $ops = CabinetCourant::pour($this->chef->cabinet_id, fn () => JournalOperation::where('user_id', $u->id)->orderBy('id')->get());
        $this->assertSame(['auth.echec', 'auth.connexion', 'echeances.store'], $ops->pluck('action')->all());
        $this->assertSame(['echeances', $this->alpha->id, 'Échéances — Ajout'], [$ops[2]->module, $ops[2]->client_id, $ops[2]->libelle]);
        $this->assertNotNull($u->fresh()->derniere_connexion_at);

        $this->actingAs($this->chef)->get(route('parametres.utilisateurs.show', [$u, 'onglet' => 'suivi']))->assertOk()
            ->assertSee('Échéances — Ajout')->assertSee('Échec de connexion')->assertSee('Société Alpha');
        $this->actingAs($this->chef)->get(route('parametres.utilisateurs.show', [$u, 'onglet' => 'suivi', 'module' => 'connexions']))->assertOk()
            ->assertDontSee('Échéances — Ajout');
    }

    // ---------- Sauvegardes ----------

    public function test_programmation_des_sauvegardes(): void
    {
        $cfg = ['actif' => true, 'frequence' => 'jour', 'heure' => '20:00', 'jour_semaine' => 1, 'jour_mois' => 31, 'date_annee' => '12-31', 'elements' => ['clients'], 'depuis' => null];
        $now = Carbon::parse('2026-11-21 19:00');
        $this->assertSame('2026-11-20 20:00', Sauvegardes::dernierePrevue($cfg, $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-21 20:00', Sauvegardes::prochaine($cfg, $now)->format('Y-m-d H:i'));

        $cfg['frequence'] = 'semaine'; $cfg['jour_semaine'] = 5;       // vendredi
        $this->assertSame('2026-11-20 20:00', Sauvegardes::dernierePrevue($cfg, $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-27 20:00', Sauvegardes::prochaine($cfg, $now)->format('Y-m-d H:i'));

        $cfg['frequence'] = 'mois';                                     // le 31, ou le dernier jour du mois
        $this->assertSame('2026-10-31 20:00', Sauvegardes::dernierePrevue($cfg, $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-30 20:00', Sauvegardes::prochaine($cfg, $now)->format('Y-m-d H:i'));

        $cfg['frequence'] = 'annee';
        $this->assertSame('2025-12-31 20:00', Sauvegardes::dernierePrevue($cfg, $now)->format('Y-m-d H:i'));
        $this->assertSame('2026-12-31 20:00', Sauvegardes::prochaine($cfg, $now)->format('Y-m-d H:i'));

        // Due seulement si prévue depuis l'activation et pas encore faite
        $cfg['frequence'] = 'jour';
        $this->assertTrue(Sauvegardes::echue($cfg, $now, null));
        $this->assertFalse(Sauvegardes::echue($cfg + ['x' => 1], $now, Carbon::parse('2026-11-20 20:01')));
        $this->assertFalse(Sauvegardes::echue(['depuis' => '2026-11-21 08:00'] + $cfg, $now, null));
        $this->assertFalse(Sauvegardes::echue(['actif' => false] + $cfg, $now, null));
    }

    public function test_sauvegarde_manuelle_du_cabinet(): void
    {
        $chefB = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        CabinetCourant::pour($chefB->cabinet_id, fn () => Client::factory()->create(['nom' => 'Client du cabinet B']));
        CabinetCourant::pour($this->chef->cabinet_id, fn () => \App\Models\ClientUser::create(['cabinet_id' => $this->chef->cabinet_id, 'client_id' => $this->alpha->id,
            'name' => 'Gérant', 'email' => 'g@alpha.tn', 'password' => 'Secret123', 'actif' => true]));

        $this->actingAs($this->chef)->post(route('parametres.sauvegardes.maintenant'), ['elements' => ['clients', 'facturation', 'base']])->assertSessionHas('ok');
        $s = CabinetCourant::pour($this->chef->cabinet_id, fn () => Sauvegarde::sole());
        $this->assertSame(['ok', 'manuel', ['base', 'clients', 'facturation']], [$s->statut, $s->declencheur, $s->elements]);
        $this->assertStringContainsString('autres cabinets', (string) $s->message);   // base multi-cabinet : non incluse

        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Sauvegardes::chemin($s)));
        $clients = json_decode($zip->getFromName('clients.json'), true);
        $this->assertEqualsCanonicalizing(['Société Alpha', 'Société Bêta'], array_column($clients['clients'], 'nom'));
        $this->assertArrayNotHasKey('password', $clients['client_users'][0]);
        $this->assertNotFalse($zip->getFromName('facturation.json'));
        $this->assertFalse($zip->getFromName('base.sqlite'));
        $this->assertSame(['base', 'clients', 'facturation'], json_decode($zip->getFromName('manifeste.json'), true)['elements']);
        $zip->close();

        $this->actingAs($this->chef)->get(route('parametres.sauvegardes.telecharger', $s))->assertOk()->assertDownload($s->fichier);
        $this->actingAs($chefB)->get(route('parametres.sauvegardes.telecharger', $s))->assertNotFound();
        $this->actingAs($this->chef)->delete(route('parametres.sauvegardes.destroy', $s))->assertSessionHas('ok');
        $this->assertFileDoesNotExist(Sauvegardes::chemin($s));
    }

    public function test_sauvegarde_automatique_a_l_heure_prevue_et_conservation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 10:00'));
        $this->actingAs($this->chef)->post(route('parametres.sauvegardes'), ['actif' => '1', 'frequence' => 'jour', 'heure' => '20:00',
            'elements' => ['clients'], 'conserver' => 2])->assertSessionHas('ok', fn ($m) => str_contains($m, '21 novembre 2026 à 20:00'));
        Auth::logout();
        $cab = $this->chef->cabinet_id;

        $this->travelTo(Carbon::parse('2026-11-21 19:59'));
        $this->assertNull(Sauvegardes::executerSiEchue($cab));                 // pas encore l'heure
        $this->travelTo(Carbon::parse('2026-11-21 20:00'));
        $this->artisan('sauvegardes:auto')->assertSuccessful();
        $this->assertSame(1, CabinetCourant::pour($cab, fn () => Sauvegarde::count()));
        $this->assertNull(Sauvegardes::executerSiEchue($cab));                 // déjà faite aujourd'hui

        foreach (['2026-11-22 20:05', '2026-11-23 21:00'] as $t) { $this->travelTo(Carbon::parse($t)); $this->assertNotNull(Sauvegardes::executerSiEchue($cab)); }
        // Seules les 2 dernières sauvegardes automatiques sont gardées
        $restantes = CabinetCourant::pour($cab, fn () => Sauvegarde::orderBy('id')->get());
        $this->assertCount(2, $restantes);
        $this->assertSame(['2026-11-22', '2026-11-23'], $restantes->map(fn ($s) => $s->created_at->format('Y-m-d'))->all());
        foreach ($restantes as $s) $this->assertFileExists(Sauvegardes::chemin($s));
    }

    public function test_page_des_sauvegardes(): void
    {
        $this->actingAs($this->chef)->get(route('parametres.index', ['tab' => 'sauvegardes']))->assertOk()
            ->assertSee('Sauvegarde automatique')->assertSee('Dossiers comptables')->assertSee('Sauvegarder maintenant');
        $this->actingAs($this->chef)->get(route('parametres.index', ['tab' => 'users']))->assertOk()
            ->assertSee('Ajouter un utilisateur')->assertSee('Réseau du cabinet');
    }
}
