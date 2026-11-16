<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Paie;
use App\Models\Salarie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Module Paie — menu Fichier : renseignements employeur, sauvegarde / restauration, import, mise à jour, sécurité, calculatrice, notifications. */
class PaieFichierTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'chef', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha Conseil']);
        session(['dossier' => $this->client->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_menu_fichier_et_notifications(): void
    {
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Karim', 'salaire_base' => 1000, 'mode_paiement' => 'virement']);
        $this->get(route('paie.index'))->assertOk()
            ->assertSee('Renseignements employeur')->assertSee('Chemin du dossier')->assertSee('Calculatrice')
            ->assertSee('Renseignements employeur incomplets')->assertSee('Karim : CIN, n° CNSS, RIB manquant(s).');
    }

    public function test_sauvegarde_puis_restauration_dans_un_autre_dossier(): void
    {
        $k = Salarie::create(['client_id' => $this->client->id, 'nom' => 'Karim', 'cin' => '01234567', 'salaire_base' => 1000]);
        Paie::create(['client_id' => $this->client->id, 'mois' => '2026-10', 'primes' => [$k->id => 150], 'statut' => 'brouillon']);
        $json = $this->get(route('paie.sauvegarde'))->assertOk()->streamedContent();

        $autre = Client::create(['nom' => 'Beta']);
        session(['dossier' => $autre->id]);
        $this->post(route('paie.restaurer'), ['fichier' => UploadedFile::fake()->createWithContent('s.json', $json)])->assertSessionHas('ok');
        $nouveau = Salarie::where('client_id', $autre->id)->sole();
        $this->assertSame('Karim', $nouveau->nom);
        $this->assertEquals([$nouveau->id => 150], Paie::where('client_id', $autre->id)->sole()->primes);

        // Une seconde restauration n'ajoute rien.
        $this->post(route('paie.restaurer'), ['fichier' => UploadedFile::fake()->createWithContent('s.json', $json)]);
        $this->assertSame(1, Salarie::where('client_id', $autre->id)->count());
        $this->assertSame(1, Paie::where('client_id', $autre->id)->count());
    }

    public function test_import_des_employes_depuis_excel(): void
    {
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Ancien nom', 'cin' => '01234567', 'salaire_base' => 500]);
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->fromArray([['Nom et prénom', 'CIN', 'N° CNSS', 'Salaire de base', 'Chef de famille (O/N)', 'Enfants à charge', 'Date d’embauche', 'Mode de paiement', 'RIB'],
            ['Sami Ben Ali', null, '111', 1500, 'O', 2, '01/03/2024', 'Espèces', null],
            ['Karim Haddad', null, '222', '1 200,500', 'N', 0, null, 'Virement', null]]);
        $ws->setCellValueExplicit('B2', '09876543', 's');
        $ws->setCellValueExplicit('B3', '01234567', 's');
        $ws->setCellValueExplicit('I3', '08000000000000000011', 's');
        $tmp = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($ss))->save($tmp);

        $this->post(route('paie.import.employes'), ['fichier' => new UploadedFile($tmp, 'employes.xlsx', null, null, true)])->assertSessionHas('ok');
        $this->assertDatabaseHas('salaries', ['nom' => 'Sami Ben Ali', 'cin' => '09876543', 'chef_famille' => true, 'enfants' => 2, 'mode_paiement' => 'especes', 'date_embauche' => '2024-03-01 00:00:00']);
        $this->assertDatabaseHas('salaries', ['nom' => 'Karim Haddad', 'cin' => '01234567', 'salaire_base' => 1200.5, 'rib' => '08000000000000000011']);
        $this->assertSame(2, Salarie::count());   // l'employé au même CIN a été mis à jour
        $this->get(route('paie.import.modele'))->assertOk();
    }

    public function test_rouvrir_une_paie_transmise_mais_pas_une_paie_comptabilisee(): void
    {
        Paie::create(['client_id' => $this->client->id, 'mois' => '2026-09', 'statut' => 'transmise', 'resultats' => [], 'totaux' => []]);
        Paie::create(['client_id' => $this->client->id, 'mois' => '2026-08', 'statut' => 'val']);
        $this->post(route('paie.rouvrir'), ['mois' => '2026-09'])->assertSessionHas('ok');
        $this->assertSame('brouillon', Paie::where('mois', '2026-09')->value('statut'));
        $this->post(route('paie.rouvrir'), ['mois' => '2026-08'])->assertSessionHas('err');

        // Un collaborateur du même cabinet ne peut pas rouvrir une paie.
        Paie::where('mois', '2026-09')->update(['statut' => 'transmise']);
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active', 'cabinet_id' => auth()->user()->cabinet_id]));
        $this->post(route('paie.rouvrir'), ['mois' => '2026-09'])->assertRedirect();
        $this->assertSame('transmise', Paie::where('mois', '2026-09')->value('statut'));
    }

    public function test_mettre_a_jour_retire_les_primes_des_employes_supprimes(): void
    {
        $k = Salarie::create(['client_id' => $this->client->id, 'nom' => 'Karim', 'salaire_base' => 1000]);
        Paie::create(['client_id' => $this->client->id, 'mois' => '2026-11', 'primes' => [$k->id => 100, 9999 => 50], 'statut' => 'brouillon']);
        $this->post(route('paie.mettre-a-jour'))->assertSessionHas('ok');
        $this->assertEquals([$k->id => 100], Paie::sole()->primes);
    }

    public function test_calculatrice_brut_vers_net_et_net_vers_brut(): void
    {
        $net = $this->getJson(route('paie.simulation', ['montant' => 1500, 'sens' => 'brut', 'chef' => 1, 'enfants' => 2]))->assertOk()->json('net');
        $brut = $this->getJson(route('paie.simulation', ['montant' => $net, 'sens' => 'net', 'chef' => 1, 'enfants' => 2]))->assertOk()->json('brut');
        $this->assertEqualsWithDelta(1500, $brut, 0.01);
    }
}
