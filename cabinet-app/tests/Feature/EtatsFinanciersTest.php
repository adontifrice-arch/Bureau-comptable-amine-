<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\User;
use App\Services\Compta;
use App\Services\EtatsFinanciers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** États financiers complets (bilan, état de résultat, flux de trésorerie, notes) établis depuis la balance, sur deux exercices. */
class EtatsFinanciersTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->actingAs(User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']));
        $this->client = Client::factory()->create(['nom' => 'ASJB TENNIS CLUB', 'forme' => 'SARL', 'capital' => 5000, 'matricule_fiscal' => '1874290/R/A/M/000']);
        session(['dossier' => $this->client->id]);

        $e = fn ($date, $lignes) => Compta::passer($this->client->id, $date, 'OD', 'Test', $lignes);
        // 2023 : apport, immobilisation à crédit, dotation, vente non encaissée → résultat 1 400
        $e('2023-01-02', [['compte' => '532', 'debit' => 5000], ['compte' => '101', 'credit' => 5000]]);
        $e('2023-02-01', [['compte' => '2282', 'debit' => 3000], ['compte' => '404', 'credit' => 3000]]);
        $e('2023-12-31', [['compte' => '6811', 'debit' => 600], ['compte' => '28282', 'credit' => 600]]);
        $e('2023-06-30', [['compte' => '411', 'debit' => 2000], ['compte' => '705', 'credit' => 2000]]);
        // 2024
        $e('2024-01-10', [['compte' => '532', 'debit' => 2000], ['compte' => '411', 'credit' => 2000]]);
        $e('2024-01-15', [['compte' => '404', 'debit' => 3000], ['compte' => '532', 'credit' => 3000]]);
        $e('2024-03-01', [['compte' => '601', 'debit' => 1000], ['compte' => '401', 'credit' => 1000]]);
        $e('2024-12-31', [['compte' => '31', 'debit' => 300], ['compte' => '6031', 'credit' => 300]]);
        $e('2024-06-30', [['compte' => '411', 'debit' => 4200], ['compte' => '705', 'credit' => 4200]]);
        $e('2024-12-31', [['compte' => '6811', 'debit' => 600], ['compte' => '28282', 'credit' => 600]]);
        $e('2024-11-30', [['compte' => '640', 'debit' => 500], ['compte' => '425', 'credit' => 500]]);
        $e('2024-12-31', [['compte' => '6511', 'debit' => 50], ['compte' => '532', 'credit' => 50]]);
        $e('2024-12-31', [['compte' => '691', 'debit' => 200], ['compte' => '4343', 'credit' => 200]]);
        $e('2024-09-01', [['compte' => '67', 'debit' => 100], ['compte' => '532', 'credit' => 100]]);
        $e('2024-04-01', [['compte' => '54', 'debit' => 1000], ['compte' => '1621', 'credit' => 1000]]);
    }

    public function test_les_ecritures_du_dernier_jour_de_l_exercice_sont_dans_la_balance(): void
    {
        // Dates stockées avec une heure : un filtre « date <= 31/12 » excluait les écritures du 31/12 (dotations, stocks…)
        $soldes = collect(Compta::balance($this->client->id, 2024))->mapWithKeys(fn ($b) => [$b['compte'] => round($b['sd'] - $b['sc'], 3)]);
        $this->assertSame([-1200.0, 300.0, 600.0, 50.0], [$soldes['28282'], $soldes['31'], $soldes['6811'], $soldes['6511']]);
        $this->assertSame(600.0, Compta::somme($this->client->id, '2024-12-01', '2024-12-31', '6811', 'debit'));
    }

    public function test_bilan_et_etat_de_resultat(): void
    {
        $ef = EtatsFinanciers::calculer($this->client, 2024);
        [$A, $P, $R] = [$ef['N']['A'], $ef['N']['P'], $ef['N']['R']];

        $this->assertTrue($ef['equilibre']);
        $this->assertSame([3000.0, 1200.0, 1800.0], [$A['A2']['brut'], $A['A2']['amort'], $A['A2']['net']]);
        $this->assertSame([300.0, 4200.0, 4850.0, 11150.0], [$A['A5']['net'], $A['A6']['net'], $A['A9']['net'], $A['total']]);
        $this->assertSame([5000.0, 1400.0, 2050.0, 1000.0, 1000.0, 700.0, 11150.0], [$P['capital'], $P['report'], $P['resultat'], $P['emprunts'], $P['CP4'], $P['CP5'], $P['total']]);

        // Modèle autorisé : 4 200 − (700 + 500 + 600) = 2 400 ; − 50 = 2 350 ; − IS 200 = 2 150 ; − 100 extraordinaire = 2 050
        $this->assertSame([4200.0, 700.0, 500.0, 600.0], [$R['R1'], $R['R5'], $R['R6'], $R['R7']]);
        $this->assertSame([2400.0, 2350.0, 2150.0, -100.0, 2050.0], [$R['rex'], $R['rav'], $R['rap'], $R['ext'], $R['rn']]);

        // N-1
        $this->assertSame([9400.0, 1400.0, 2400.0], [$ef['N1']['A']['total'], $ef['N1']['R']['rn'], $ef['N1']['A']['A2']['net']]);
    }

    public function test_flux_de_tresorerie_n_et_n_moins_1(): void
    {
        $ef = EtatsFinanciers::calculer($this->client, 2024);
        $F = $ef['F'];
        $this->assertSame([2050.0, 600.0, -300.0, -2200.0, -1300.0, -1150.0], [$F['rn'], $F['amort'], $F['stocks'], $F['creances'], $F['dettes'], $F['exp']]);
        $this->assertSame([1000.0, 1000.0], [$F['emprunts'], $F['fin']]);
        $this->assertSame([5000.0, 4850.0, -150.0, 0.0], [$F['tresorerie_debut'], $F['tresorerie_fin'], $F['variation'], $F['autres_postes']]);   // flux expliqués sans écart

        $F1 = $ef['F1'];                                                                                       // 2023 depuis 2022 (vide)
        $this->assertSame([3000.0, -3000.0, 5000.0, 5000.0, 0.0], [$F1['exp'], $F1['acq'], $F1['emission'], $F1['variation'], $F1['autres_postes']]);
    }

    public function test_notes_de_detail(): void
    {
        $notes = EtatsFinanciers::calculer($this->client, 2024)['notes'];

        $this->assertSame(['A2', 'A5', 'A6', 'A9', 'CP1', 'CP2', 'CP4', 'CP5', 'R1', 'R5', 'R6', 'R7'], array_keys($notes));
        $this->assertSame([['Autres immobilisations corporelles', 3000.0, 3000.0]], array_map(fn ($l) => [$l['libelle'], $l['n'], $l['n1']], $notes['A2']['lignes']));
        $this->assertSame(['n' => 1200.0, 'n1' => 600.0], $notes['A2']['amort']);
        $this->assertSame(['601' => 1000.0, '603' => -300.0], array_column($notes['R5']['lignes'], 'n', 'compte'));      // achats et variation de stocks
        $this->assertSame(['42' => 500.0, '43' => 200.0], array_column($notes['CP5']['lignes'], 'n', 'compte'));
        $this->assertSame(['53' => 3850.0, '54' => 1000.0], array_column($notes['A9']['lignes'], 'n', 'compte'));      // banques et caisse, comme le modèle
        $this->assertSame(['(6 717,202)', '1 234,500', '0,000'], [EtatsFinanciers::montant(-6717.202), EtatsFinanciers::montant(1234.5), EtatsFinanciers::montant(0)]);
    }

    public function test_apercu_pdf_et_informations_de_presentation(): void
    {
        $this->post(route('liasse.etats-financiers.infos'), ['activite' => 'Restaurant', 'creation' => '2024-06-07', 'effectif' => 3])->assertSessionHas('ok');

        $this->get(route('liasse.index', ['tab' => 'ef', 'annee' => 2024]))->assertOk()->assertSee('Bilan équilibré')->assertSee('11 150,000');
        $this->get(route('liasse.etats-financiers', ['annee' => 2024]))->assertOk()
            ->assertSee('ÉTATS FINANCIERS ARRÊTÉS AU 31/12/2024')->assertSee('ÉTAT DES FLUX DE TRÉSORERIE')->assertSee('Restaurant')->assertSee('07/06/2024')
            ->assertSee('Autres immobilisations corporelles')->assertSee('(1 200,000)')->assertSee('2 050,000');

        $pdf = $this->get(route('liasse.etats-financiers', ['annee' => 2024, 'format' => 'pdf']))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $texte = (new \Smalot\PdfParser\Parser)->parseContent($pdf->getContent())->getText();
        $this->assertStringContainsString('Page 1 of', $texte);
        $this->assertStringContainsString('BILAN', $texte);
    }
}
