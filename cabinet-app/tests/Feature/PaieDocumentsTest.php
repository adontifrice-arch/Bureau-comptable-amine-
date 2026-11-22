<?php

namespace Tests\Feature;

use App\Models\Acompte;
use App\Models\Client;
use App\Models\Conge;
use App\Models\PaieProfil;
use App\Models\Pret;
use App\Models\Salarie;
use App\Models\User;
use App\Services\PaieAlertes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Paie : n° CNSS facultatif des CIVP et gérants, simulateur net → brut, documents des menus Prêt, Acompte, Congé et Employé
 * (attestations de salaire mensuelle et annuelle, salaires déclarés à la CNSS, reçu de vêtements, certificats de retenue de tous les employés).
 */
class PaieDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Salarie $e;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Excellia Créance', 'matricule_fiscal' => '1311620LAM000', 'matricule_cnss' => '12345678',
            'paie_config' => ['ville' => 'Ariana', 'responsable_nom' => 'Sami Ben Ali', 'responsable_qualification' => 'Gérant']]);
        session(['dossier' => $this->client->id]);
        PaieProfil::initialiserStandard($this->client);
        $this->e = $this->salarie(['nom' => 'JELASSI', 'prenom' => 'Ridha', 'cnss' => '1122334455', 'salaire_base' => 1500]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function salarie(array $d): Salarie
    {
        return Salarie::create($d + ['client_id' => $this->client->id, 'matricule' => (string) (Salarie::count() + 1), 'cin' => '0'.(Salarie::count() + 1234567),
            'poste' => 'Agent de recouvrement', 'regime' => 'mensuel', 'mode_paiement' => 'especes', 'date_embauche' => '2025-01-01', 'contrat_debut' => '2025-01-01',
            'etat_presence' => 'actif', 'cnss_assujetti' => true]);
    }

    public function test_numero_cnss_facultatif_pour_civp_et_gerant(): void
    {
        $civp = $this->salarie(['nom' => 'CIVP', 'prenom' => 'Amel', 'profil' => 'CIVP', 'salaire_base' => 600]);
        $gerant = $this->salarie(['nom' => 'GERANT', 'prenom' => 'Karim', 'profil' => 'Gérant', 'salaire_base' => 3000]);
        $ordinaire = $this->salarie(['nom' => 'SANSCNSS', 'prenom' => 'Ali', 'profil' => 'Ordinaire', 'salaire_base' => 1200]);
        $this->assertTrue($civp->cnssFacultatif());
        $this->assertTrue($gerant->cnssFacultatif());
        $this->assertFalse($ordinaire->cnssFacultatif());

        $textes = collect(PaieAlertes::pour($this->client))->pluck('texte')->implode("\n");
        $this->assertStringContainsString('SANSCNSS Ali : n° CNSS manquant(s).', $textes);
        $this->assertStringNotContainsString('CIVP Amel', $textes);
        $this->assertStringNotContainsString('GERANT Karim', $textes);
    }

    public function test_simulateur_net_vers_brut(): void
    {
        $r = $this->getJson(route('paie.simulation', ['montant' => 1000, 'sens' => 'net']))->assertOk()->json();
        $this->assertEqualsWithDelta(1000, $r['net'], 0.002);
        $this->assertGreaterThan(1000, $r['brut']);
        $this->assertGreaterThan(0, $r['cnssS']);
        // Profil CIVP : pas de CNSS salariale, le brut nécessaire est plus faible.
        $civp = $this->getJson(route('paie.simulation', ['montant' => 1000, 'sens' => 'net', 'profil' => 'CIVP']))->assertOk()->json();
        $this->assertEqualsWithDelta(1000, $civp['net'], 0.002);
        $this->assertSame(0.0, (float) $civp['cnssS']);
        $this->assertLessThan($r['brut'], $civp['brut']);
        $this->get(route('paie.index'))->assertOk()->assertSee('Simulateur net → brut');
    }

    public function test_documents_employe(): void
    {
        $e = $this->e;
        $this->get(route('paie.employes.rh', [$e, 'attestation-salaire', 'mois' => '2026-10']))->assertOk()->assertSee('Attestation de salaire mensuelle')->assertSee('1 500,000 TND');
        // Annuelle : dernier salaire × 12
        $this->get(route('paie.employes.rh', [$e, 'attestation-salaire-annuelle', 'mois' => '2026-10']))->assertOk()
            ->assertSee('Attestation de salaire annuelle')->assertSee('18 000,000')->assertSee('octobre 2026');
        // Salaires déclarés à la CNSS : 4 trimestres, janvier à novembre déclarés
        $this->get(route('paie.employes.rh', [$e, 'attestation-cnss', 'annee' => 2026]))->assertOk()
            ->assertSee('ATTESTATION DE SALAIRES')->assertSee('12345678')->assertSee('1122334455')->assertSee('4 500,000')->assertSee('16 500,000');
        $this->get(route('paie.employes.rh', [$e, 'attestation-cnss', 'annee' => 2026, 'pdf' => 1]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('paie.employes.rh', [$e, 'recu-vetements', 'annee' => 2026]))->assertOk()->assertSee('Reçu de vêtements de travail')->assertSee('Chaussures de sécurité');

        // Certificat de retenue de tous les employés : un certificat par employé payé dans l'année
        $this->salarie(['nom' => 'BEN SALAH', 'prenom' => 'Nour', 'salaire_base' => 1000]);
        $this->get(route('paie.employes.rh-tous', ['certificat-retenue', 'annee' => 2026]))->assertOk()
            ->assertSee('JELASSI Ridha')->assertSee('BEN SALAH Nour')->assertSee('2 documents, un par page');
        $this->get(route('paie.employes.rh-tous', ['certificat-retenue', 'annee' => 2026, 'pdf' => 1]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('paie.employes.rh-tous', ['certificat-retenue', 'annee' => 2010]))->assertRedirect()->assertSessionHas('err');
        $this->get(route('paie.employes.rh-tous', ['contrat']))->assertNotFound();
    }

    public function test_documents_pret_acompte_et_conge(): void
    {
        $e = $this->e;
        // Prêt : échéancier du salarié et état des prêts
        $this->post(route('paie.prets.store'), ['type' => 'societe', 'salarie_id' => $e->id, 'date' => '2026-11-02', 'montant' => 900, 'nb_echeances' => 3, 'premier_mois' => '2026-11', 'mode' => 'especes'])->assertSessionHas('ok');
        $this->post(route('paie.prets.store'), ['type' => 'cnss', 'salarie_id' => $e->id, 'date' => '2026-11-02', 'montant' => 200, 'nb_echeances' => 2, 'premier_mois' => '2026-11', 'reference' => 'CN-77'])->assertSessionHas('ok');
        $this->get(route('paie.prets.echeancier-salarie', ['salarie' => $e->id, 'mois' => '2026-11']))->assertOk()
            ->assertSee('Échéancier de prêt')->assertSee('2 prêts')->assertSee('CN-77')->assertSee('janvier 2027')->assertSee('Total des prêts');
        $this->get(route('paie.prets.echeancier', [Pret::where('type', 'cnss')->sole(), 'mois' => '2026-11']))->assertOk()->assertSee('Prêt CNSS');
        $autre = $this->salarie(['nom' => 'SANS', 'prenom' => 'Pret', 'salaire_base' => 900]);
        $this->get(route('paie.prets.echeancier-salarie', ['salarie' => $autre->id]))->assertRedirect()->assertSessionHas('err');
        $this->get(route('paie.index', ['tab' => 'prets', 'mois' => '2026-11']))->assertOk()->assertSee('Échéancier (salarié)')->assertSee('Etat des prêts');

        // Acompte : reçu d'avance sur salaire proposé après la saisie
        $this->post(route('paie.acomptes.store'), ['salarie_id' => $e->id, 'date' => '2026-11-10', 'montant' => 250, 'mode' => 'especes', 'mois' => '2026-11', 'retour' => '2026-11'])
            ->assertSessionHas('recu_acompte');
        $a = Acompte::sole();
        $this->get(route('paie.index', ['tab' => 'acomptes', 'mois' => '2026-11']))->assertOk()->assertSee('Reçu d’avance sur salaire')->assertSee(route('paie.acomptes.recu', $a), false);
        $this->get(route('paie.acomptes.recu', $a))->assertOk()->assertSee('Reçu d’avance sur salaire')->assertSee('Exemplaire employeur')->assertSee('Exemplaire salarié')
            ->assertSee('DEUX CENT CINQUANTE DINARS');

        // Congé : demande depuis le registre et demande en blanc
        $this->post(route('paie.conges.store'), ['salarie_id' => $e->id, 'type' => 'paye', 'du' => '2026-12-07', 'au' => '2026-12-12', 'mois' => '2026-11'])->assertSessionHas('ok');
        $c = Conge::sole();
        $this->get(route('paie.conges.demande', ['conge' => $c->id]))->assertOk()->assertSee('Demande de congé')->assertSee('07/12/2026')->assertSee('14/12/2026')   // reprise le lundi
            ->assertSee('Solde disponible à fin novembre 2026');
        $this->get(route('paie.conges.demande', ['salarie_id' => $e->id, 'type' => 'sans_solde']))->assertOk()->assertSee('JELASSI Ridha')->assertDontSee('Solde disponible');
        $this->get(route('paie.index', ['tab' => 'conges', 'mois' => '2026-11']))->assertOk()->assertSee(route('paie.conges.demande', ['conge' => $c->id, 'mois' => '2026-11']));
    }
}
