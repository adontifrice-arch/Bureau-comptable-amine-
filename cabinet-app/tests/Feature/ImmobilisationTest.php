<?php

namespace Tests\Feature;

use App\Models\AmortissementComptabilise;
use App\Models\Client;
use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\Immobilisation;
use App\Models\User;
use App\Support\CabinetCourant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/** Module Immobilisations et amortissements (spécification §3.8, §5.2, §5.5, §8). */
class ImmobilisationTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;
    private User $collab;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chef = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        $this->collab = User::factory()->create(['cabinet_id' => $this->chef->cabinet_id, 'role' => 'collaborateur', 'status' => 'active']);
        $this->actingAs($this->chef);
        $this->client = Client::factory()->create(['nom' => 'Alpha Conseil']);
        session(['dossier' => $this->client->id]);
    }

    private function ajouter(array $d = []): Immobilisation
    {
        $this->post(route('immobilisations.store'), $d + ['nature' => 'informatique', 'designation' => 'Serveur Dell', 'date_mise_service' => '2026-03-15',
            'valeur_origine' => 3000, 'methode' => 'lineaire', 'taux' => 33.33, 'prorata' => 'mois'])->assertSessionHasNoErrors();
        return Immobilisation::latest('id')->first();
    }

    private function lignes(Ecriture $e): array
    {
        return $e->lignes->map(fn ($l) => [$l->compte, (float) $l->debit, (float) $l->credit])->all();
    }

    public function test_saisie_par_un_collaborateur_et_registre(): void
    {
        $this->actingAs($this->collab);
        $i = $this->ajouter();
        $this->assertSame([3000.0, 33.33, 'mois'], [$i->valeur_origine, $i->taux, $i->prorata]);

        $this->get(route('immobilisations.index'))->assertOk()->assertSee('Serveur Dell')->assertSee('Matériel informatique')->assertSee('2 166,750');   // VNC fin 2026
        $this->get(route('immobilisations.index', ['tab' => 'dotations', 'exercice' => 2026]))->assertOk()->assertSee('833,250');

        // Un collaborateur sans le droit de valider ne comptabilise pas.
        $this->collab->forceFill(['droit_validation' => false])->save();
        $this->post(route('immobilisations.comptabiliser'), ['exercice' => 2026])->assertSessionHas('err');
        $this->assertSame(0, Ecriture::count());
    }

    public function test_controles_de_saisie(): void
    {
        $this->post(route('immobilisations.store'), ['nature' => 'informatique', 'designation' => 'X', 'date_mise_service' => '2026-01-01', 'valeur_origine' => 1000,
            'valeur_residuelle' => 1000, 'methode' => 'lineaire', 'prorata' => 'mois'])->assertSessionHasErrors('valeur_residuelle');
        $this->post(route('immobilisations.store'), ['nature' => 'informatique', 'designation' => 'X', 'date_mise_service' => '2026-05-01', 'valeur_origine' => 1000,
            'date_cession' => '2026-01-01', 'methode' => 'lineaire', 'prorata' => 'mois'])->assertSessionHasErrors('date_cession');

        $terrain = $this->ajouter(['nature' => 'terrain', 'designation' => 'Terrain Ariana', 'valeur_origine' => 80000, 'taux' => 12]);
        $this->assertSame(0.0, $terrain->taux);
        $this->assertFalse($terrain->amortissable());
        $defaut = $this->ajouter(['nature' => 'transport', 'designation' => 'Camionnette', 'taux' => '']);
        $this->assertSame(20.0, $defaut->taux);                          // taux par défaut de la nature
    }

    public function test_comptabilisation_idempotente_par_nature_puis_complement(): void
    {
        $serveur = $this->ajouter();
        $this->ajouter(['nature' => 'transport', 'designation' => 'Camionnette', 'date_mise_service' => '2026-01-10', 'valeur_origine' => 6000, 'taux' => 20]);
        $this->ajouter(['nature' => 'terrain', 'designation' => 'Terrain', 'valeur_origine' => 50000]);

        $this->post(route('immobilisations.comptabiliser'), ['exercice' => 2026])->assertSessionHas('ok');
        $e = Ecriture::with('lignes')->sole();
        $this->assertSame(['AMO', '2026-12-31', 'amo:2026', 'Dotations aux amortissements 2026', $this->chef->id], [$e->journal, $e->date->format('Y-m-d'), $e->source, $e->libelle, $e->valide_par]);
        $this->assertEqualsCanonicalizing([['6811', 2033.25, 0.0], ['2824', 0.0, 1200.0], ['28282', 0.0, 833.25]], $this->lignes($e));   // 833,250 + 1 200

        // Deuxième clic : rien de nouveau.
        $this->post(route('immobilisations.comptabiliser'), ['exercice' => 2026])->assertSessionHas('err');
        $this->assertSame(1, Ecriture::count());

        // Valeur d'origine corrigée : seul le complément est passé.
        $this->put(route('immobilisations.update', $serveur), ['nature' => 'informatique', 'designation' => 'Serveur Dell', 'date_mise_service' => '2026-03-15',
            'valeur_origine' => 3600, 'methode' => 'lineaire', 'taux' => 33.33, 'prorata' => 'mois'])->assertSessionHas('ok');
        $this->post(route('immobilisations.comptabiliser'), ['exercice' => 2026])->assertSessionHas('ok');
        $c = Ecriture::with('lignes')->latest('id')->first();
        $this->assertSame('Complément de dotations aux amortissements 2026', $c->libelle);
        $this->assertEqualsCanonicalizing([['6811', 166.65, 0.0], ['28282', 0.0, 166.65]], $this->lignes($c));
        $this->assertSame(999.9, $serveur->fresh()->load('dotationsComptabilisees')->dejaComptabilise(2026));

        // Suppression bloquée tant que des dotations sont comptabilisées.
        $this->delete(route('immobilisations.destroy', $serveur))->assertSessionHas('err');
        $this->assertNotNull($serveur->fresh());
    }

    public function test_annulation_en_deux_temps(): void
    {
        $this->ajouter();
        $this->post(route('immobilisations.comptabiliser'), ['exercice' => 2026]);

        $this->get(route('immobilisations.index', ['tab' => 'dotations', 'exercice' => 2026]))->assertSee('Pour confirmer, saisissez l’exercice', false);
        $this->post(route('immobilisations.annuler'), ['exercice' => 2026])->assertSessionHasErrors('confirmation');
        $this->post(route('immobilisations.annuler'), ['exercice' => 2026, 'confirmation' => '2025'])->assertSessionHasErrors('confirmation');
        $this->assertSame(1, Ecriture::count());

        $this->post(route('immobilisations.annuler'), ['exercice' => 2026, 'confirmation' => '2026'])->assertSessionHas('ok');
        $this->assertSame([0, 0], [Ecriture::count(), AmortissementComptabilise::count()]);

        $this->post(route('immobilisations.comptabiliser'), ['exercice' => 2026])->assertSessionHas('ok');     // à nouveau possible
        $this->assertSame(833.25, (float) Ecriture::sole()->lignes->sum('debit'));
    }

    public function test_ecriture_d_acquisition_d_un_bien_saisi_a_la_main(): void
    {
        $i = $this->ajouter(['nature' => 'materiel', 'designation' => 'Presse', 'valeur_origine' => 10000, 'tva' => 1900, 'fournisseur' => 'Sotumach', 'taux' => 10]);

        $this->post(route('immobilisations.acquisition', $i))->assertSessionHas('ok');
        $e = Ecriture::with('lignes')->sole();
        $this->assertSame(['ACH', '2026-03-15', 'immo:'.$i->id], [$e->journal, $e->date->format('Y-m-d'), $e->source]);
        $this->assertEqualsCanonicalizing([['223', 10000.0, 0.0], ['4366', 1900.0, 0.0], ['401', 0.0, 11900.0]], $this->lignes($e));

        $this->post(route('immobilisations.acquisition', $i))->assertSessionHas('err');                   // pas deux fois
        $this->delete(route('immobilisations.destroy', $i))->assertSessionHas('err');

        // Annuler l'écriture depuis le journal rend l'acquisition de nouveau comptabilisable.
        $this->post(route('compta.ecritures.annuler', $e))->assertSessionHas('ok');
        $this->assertFalse($i->fresh()->acquisitionComptabilisee());
    }

    public function test_import_des_factures_recues_validees_de_classe_2(): void
    {
        $f = FactureRecue::create(['client_id' => $this->client->id, 'date' => '2026-02-01', 'fournisseur' => 'Tunisie Informatique', 'reference' => 'F-12',
            'description' => 'Ordinateurs portables', 'ht' => 4500, 'tva' => 855, 'compte' => '2282', 'tva_deductible' => true, 'statut' => 'val']);
        FactureRecue::create(['client_id' => $this->client->id, 'date' => '2026-02-01', 'fournisseur' => 'Papeterie', 'ht' => 100, 'tva' => 19, 'compte' => '6061', 'statut' => 'val']);
        FactureRecue::create(['client_id' => $this->client->id, 'date' => '2026-02-01', 'fournisseur' => 'Brouillon', 'ht' => 900, 'tva' => 0, 'compte' => '224', 'statut' => 'prop']);

        $this->get(route('immobilisations.index'))->assertSee('Importer des factures reçues (1)');
        $this->post(route('immobilisations.importer'), ['factures' => [$f->id]])->assertSessionHas('ok');

        $i = Immobilisation::sole();
        $this->assertSame(['informatique', 'Ordinateurs portables', 4500.0, 855.0, 33.33, $f->id], [$i->nature, $i->designation, $i->valeur_origine, $i->tva, $i->taux, $i->facture_recue_id]);
        $this->assertTrue($i->acquisitionComptabilisee());
        $this->get(route('immobilisations.index'))->assertDontSee('Importer des factures reçues');
        $this->post(route('immobilisations.importer'), ['factures' => [$f->id]])->assertSessionHas('err');   // pas de doublon
    }

    public function test_etats_imprimables_et_exports(): void
    {
        $this->ajouter();
        $this->ajouter(['nature' => 'materiel', 'designation' => 'Machine', 'date_mise_service' => '2026-01-01', 'valeur_origine' => 10000, 'methode' => 'degressif', 'taux' => 20]);
        $p = ['exercice' => 2026];

        $this->get(route('immobilisations.etat', ['tableau', 'imprimer'] + $p))->assertOk()
            ->assertSee('Tableau des immobilisations et amortissements')->assertSee('Serveur Dell')->assertSee('833,250')->assertSee('4 833,250');  // total dotations
        $this->get(route('immobilisations.etat', ['plan', 'imprimer'] + $p))->assertOk()
            ->assertSee('Dotation 2030')->assertSee('2 400,000')->assertSee('1 080,000');                  // dégressif 2027 et 2029-2030

        $pdf = $this->get(route('immobilisations.etat', ['tableau', 'pdf'] + $p))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $xlsx = $this->get(route('immobilisations.etat', ['tableau', 'xlsx'] + $p))->assertOk()->assertDownload('tableau-des-immobilisations-et-amortissements-alpha-conseil-exercice-2026.xlsx');
        $ws = IOFactory::load($xlsx->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('A6', $ws->getFreezePane());
        $ligne = collect($ws->toArray(null, false, false))->search(fn ($r) => $r[0] === 'Serveur Dell') + 1;
        $this->assertSame(833.25, $ws->getCell('F'.$ligne)->getValue());                                  // nombre, pas texte
        $this->assertSame('#,##0.000', $ws->getStyle('F'.$ligne)->getNumberFormat()->getFormatCode());
        $this->assertSame('dd/mm/yyyy', $ws->getStyle('B'.$ligne)->getNumberFormat()->getFormatCode());
        $this->assertIsFloat($ws->getCell('B'.$ligne)->getValue());                                       // date réelle

        $docx = $this->get(route('immobilisations.etat', ['plan', 'docx'] + $p))->assertOk();
        $zip = new ZipArchive();
        $zip->open($docx->baseResponse->getFile()->getPathname());
        $xml = $zip->getFromName('word/document.xml');
        $this->assertStringContainsString('Machine', $xml);
        $this->assertStringContainsString('<w:tblHeader', $xml);                                        // en-tête répété
        $this->assertStringContainsString('w:orient="landscape"', $xml);

        $this->get(route('immobilisations.etat', ['bilan', 'pdf'] + $p))->assertNotFound();
    }

    public function test_cloisonnement_entre_cabinets(): void
    {
        $i = $this->ajouter();
        $autre = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        $this->actingAs($autre);
        CabinetCourant::pour($autre->cabinet_id, fn () => session(['dossier' => Client::factory()->create()->id]));

        $this->put(route('immobilisations.update', $i), ['nature' => 'informatique', 'designation' => 'Piraté', 'date_mise_service' => '2026-01-01',
            'valeur_origine' => 1, 'methode' => 'lineaire', 'prorata' => 'mois'])->assertNotFound();
        $this->post(route('immobilisations.acquisition', $i))->assertNotFound();
        $this->get(route('immobilisations.index'))->assertOk()->assertDontSee('Serveur Dell');
    }
}
