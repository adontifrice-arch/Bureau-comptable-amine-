<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Paie;
use App\Models\Salarie;
use App\Models\Setting;
use App\Models\User;
use App\Services\Exports\EtatsPaie;
use App\Services\PaieCalc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Paie, Fichier → Renseignements de l'employeur : fiche en quatre onglets et paramètres de paie propres au dossier. */
class PaieEmployeurTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Excellia Créance']);
        session(['dossier' => $this->client->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fiche(array $extra = []): array
    {
        return array_merge([
            'nom' => 'SOCIETE EXCELLIA CREANCE', 'adresse' => '14 avenue des Lumières', 'ville' => 'Ariana', 'code_postal' => '2080',
            'cnss_mat' => '537997', 'cnss_cle' => '35', 'paie_activite' => 'Recouvrement', 'nb_employes' => 3, 'heures_semaine' => 48,
            'responsable_nom' => 'Sami Ben Ali', 'responsable_qualification' => 'Gérant', 'forme' => 'SARL', 'rne' => 'B03157682013', 'matricule_fiscal' => '1311620LAM000',
            'paie_banque' => 'BIAT', 'agence' => 'Ariana', 'paie_rib' => '08000000000000000011', 'cabinet_paie' => 'Cabinet Boudaya',
            'fop_actif' => '1', 'fop' => 1, 'tfp_actif' => '1', 'tfp' => 2, 'arrondi' => 3, 'regul_irpp' => '0', 'regul_primes' => 'mois',
            'cnssS' => 9.68, 'at' => 0.5, 'tri' => 'nom', 'format_fiche' => 'a5', 'smig_mensuel' => 528.32, 'smig_horaire' => 2.54,
        ], $extra);
    }

    public function test_enregistrement_des_quatre_onglets(): void
    {
        $this->get(route('paie.index'))->assertOk()->assertSee('Informations spécifiques')->assertSee('Mention obligatoire');
        $this->put(route('paie.employeur'), $this->fiche())->assertSessionHasNoErrors();
        $c = $this->client->fresh();
        $this->assertSame('537997-35', $c->matricule_cnss);
        $this->assertSame('SARL', $c->forme);
        $this->assertSame('Ariana', $c->paie_config['ville']);
        $this->assertSame('Gérant', $c->paie_config['responsable_qualification']);
        $this->assertFalse($c->paie_config['notif_demarrage']);
        $this->assertSame(528.32, $c->paie_config['smig_mensuel']);
        // Le format de la fiche de paie choisi est celui des bulletins par défaut.
        $this->get(route('paie.bulletins', ['mois' => '2026-10']))->assertOk()->assertSee('Bulletins de paie A5');
    }

    public function test_mentions_obligatoires(): void
    {
        $this->put(route('paie.employeur'), $this->fiche(['ville' => '', 'smig_mensuel' => '', 'responsable_qualification' => '']))
            ->assertSessionHasErrors(['ville', 'smig_mensuel', 'responsable_qualification']);
    }

    public function test_taux_propres_a_l_employeur(): void
    {
        $this->put(route('paie.employeur'), $this->fiche(['tfp_actif' => null, 'fop' => 1, 'at' => 1.0, 'cnssS' => 9.18]));
        $P = PaieCalc::parametres($this->client->fresh());
        $G = Setting::paie();
        $this->assertSame(0.0, $P['tfp']);
        $this->assertSame(9.18, $P['cnssS']);
        $this->assertEqualsWithDelta($G['cnssP'] - $G['at'] + 1.0, $P['cnssP'], 0.0001);

        $e = Salarie::create(['client_id' => $this->client->id, 'nom' => 'Karim', 'salaire_base' => 1000]);
        $x = PaieCalc::mois($this->client->fresh(), '2026-10')[0];
        $this->assertSame(0.0, $x['tfp']);
        $this->assertEqualsWithDelta(91.8, $x['cnssS'], 0.0001);
    }

    public function test_arrondi_des_retenues(): void
    {
        $this->put(route('paie.employeur'), $this->fiche(['arrondi' => 0]));
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Karim', 'salaire_base' => 1234.567]);
        $x = PaieCalc::mois($this->client->fresh(), '2026-10')[0];
        foreach (['cnssS', 'ir', 'css', 'cnssP', 'tfp', 'fop'] as $k) $this->assertSame(round($x[$k]), $x[$k], $k);
        $this->assertEqualsWithDelta($x['brut'] - $x['cnssS'] - $x['ir'] - $x['css'], $x['net'], 0.0001);
    }

    public function test_regularisation_mensuelle_de_l_irpp(): void
    {
        Carbon::setTestNow('2027-01-10 09:00:00');
        $this->put(route('paie.employeur'), $this->fiche(['regul_irpp' => '1']));
        $client = $this->client->fresh();
        $e = Salarie::create(['client_id' => $client->id, 'nom' => 'Karim', 'salaire_base' => 1000]);
        // Janvier à juin clôturés à 1 000 TND, sans régularisation.
        $P = PaieCalc::parametres(null);
        foreach (range(1, 6) as $m) {
            $R = [PaieCalc::calcul($e, 0, $P)];
            Paie::create(['client_id' => $client->id, 'mois' => sprintf('2026-%02d', $m), 'statut' => 'val', 'resultats' => $R, 'totaux' => PaieCalc::totaux($R)]);
        }
        // Augmentation à 2 000 TND à partir de juillet : l'impôt de décembre régularise l'année.
        $e->update(['salaire_base' => 2000]);
        $total = 0.0;
        foreach (range(1, 12) as $m) $total += PaieCalc::mois($client, sprintf('2026-%02d', $m))[0]['ir'];

        $G = Setting::paie();
        $cAn = 6 * r3(1000 * $G['cnssS'] / 100) + 6 * r3(2000 * $G['cnssS'] / 100);
        $bi = 18000 - $cAn - min($G['frais'] / 100 * (18000 - $cAn), $G['fraisMax']);
        $this->assertEqualsWithDelta(PaieCalc::bareme($bi, $G['bar']), $total, 0.01);
    }

    public function test_tri_par_matricule(): void
    {
        $this->put(route('paie.employeur'), $this->fiche(['tri' => 'matricule']));
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Amel', 'matricule' => 'E02', 'salaire_base' => 900]);
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Zied', 'matricule' => 'E01', 'salaire_base' => 900]);
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Bilel', 'salaire_base' => 900]);
        $this->assertSame(['Zied', 'Amel', 'Bilel'], array_column(PaieCalc::mois($this->client->fresh(), '2026-10'), 'nom'));
    }

    public function test_logo_de_l_employeur_sur_les_etats(): void
    {
        Storage::fake('public');
        $this->put(route('paie.employeur'), $this->fiche(['afficher_logo' => '1', 'logo' => UploadedFile::fake()->image('logo.png')]))->assertSessionHasNoErrors();
        $logo = $this->client->fresh()->paie_config['logo'];
        Storage::disk('public')->assertExists($logo);
        $this->assertSame($logo, EtatsPaie::etat('journal', $this->client->fresh(), '2026-10')->logo);

        $this->put(route('paie.employeur'), $this->fiche(['retirer_logo' => '1']));
        Storage::disk('public')->assertMissing($logo);
        $this->assertNull(EtatsPaie::etat('journal', $this->client->fresh(), '2026-10')->logo);
    }

    public function test_notification_au_demarrage_une_fois_par_session(): void
    {
        $this->put(route('paie.employeur'), $this->fiche(['notif_demarrage' => '1', 'smig_mensuel' => 1000]));
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Karim', 'salaire_base' => 800]);
        $this->get(route('paie.index'))->assertSee('inférieur au SMIG mensuel')->assertSee("open-modal', 'paie-notifications'", false);
        $this->get(route('paie.index'))->assertDontSee("open-modal', 'paie-notifications'), 300", false);
    }
}
