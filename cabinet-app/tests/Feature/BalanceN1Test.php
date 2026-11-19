<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Compte;
use App\Models\Ecriture;
use App\Models\User;
use App\Services\Compta;
use App\Services\LecteurBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Reprise d'un nouveau dossier : balance N-1 (Excel, Word, PDF) contrôlée puis passée en à-nouveaux au 31/12/N-1. */
class BalanceN1Test extends TestCase
{
    use RefreshDatabase;

    private User $chef;

    /** Balance 2025 équilibrée : capital 10 000, fournisseur 750, banque 9 500, client 2 000, achats 1 250,500, ventes 2 000,500. */
    private const BALANCE = [
        ['101000', 'Capital social', 0, 10000, 0, 10000],
        ['401001', 'Fournisseur Alpha', 250, 1000, 0, 750],
        ['411001', 'Client Beta', 5000, 3000, 2000, 0],
        ['532000', 'Banque BIAT', 12500, 3000, 9500, 0],
        ['606100', 'Achats non stockés', 1250.5, 0, 1250.5, 0],
        ['706000', 'Prestations de services', 0, 2000.5, 0, 2000.5],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->chef = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']);
        $this->actingAs($this->chef);
    }

    private function excel(): UploadedFile
    {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->fromArray([['BALANCE GENERALE AU 31/12/2025'], [], ['Compte', 'Intitulé', 'Mouvements', '', 'Soldes', ''], ['', '', 'Débit', 'Crédit', 'Débit', 'Crédit'],
            ['10', 'CAPITAL', '', '', '', 10000]], null, 'A1');
        $ws->fromArray(self::BALANCE, null, 'A6');
        $ws->fromArray([['', 'TOTAL', 19000.5, 19000.5, 12750.5, 12750.5]], null, 'A12');
        $tmp = tempnam(sys_get_temp_dir(), 'bal').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($tmp);
        return new UploadedFile($tmp, 'Balance 2025.xlsx', null, null, true);
    }

    private function nombre(float $v): string
    {
        return $v ? number_format($v, 3, ',', ' ') : '';
    }

    public function test_creation_du_client_avec_la_balance_excel_puis_reprise(): void
    {
        $this->post(route('clients.store'), ['nom' => 'Nouvelle Société', 'type_personne' => 'morale', 'exercice' => 2026, 'balance_n1' => $this->excel()])
            ->assertRedirect(route('compta.balance-n1'));
        $client = Client::where('nom', 'Nouvelle Société')->firstOrFail();
        $import = session('balance_n1.'.$client->id);
        $this->assertSame(2026, $import['annee']);
        $this->assertSame(array_column(self::BALANCE, 0), array_column($import['lignes'], 'compte'));   // regroupement « 10 » et total écartés
        $this->assertEquals([0, 750], [$import['lignes'][1]['debit'], $import['lignes'][1]['credit']]);
        $this->get(route('compta.balance-n1'))->assertOk()->assertSee('Balance 2025.xlsx')->assertSee('Enregistrer la reprise au 31/12/2025');

        // Déséquilibrée après correction à l'écran : refusée, sauf écart passé sur le compte d'attente
        $lignes = $import['lignes'];
        $lignes[2]['debit'] = 2100;
        $this->post(route('compta.balance-n1.enregistrer'), ['annee' => 2026, 'lignes' => json_encode($lignes), 'ajouter_comptes' => 1])->assertSessionHas('err');
        $this->assertSame(0, Ecriture::count());

        $this->post(route('compta.balance-n1.enregistrer'), ['annee' => 2026, 'lignes' => json_encode($import['lignes']), 'ajouter_comptes' => 1])
            ->assertRedirect(route('compta.index', ['tab' => 'bal', 'annee' => 2026]))->assertSessionHas('ok');
        $e = Ecriture::where('client_id', $client->id)->sole();
        $this->assertSame(['AN', '2025-12-31', 'bal:2025'], [$e->journal, $e->date->format('Y-m-d'), $e->source]);
        $this->assertSame('Fournisseur Alpha', Compte::where('numero', '401001')->value('libelle'));
        $this->assertNull(session('balance_n1.'.$client->id));

        // Balance 2026 : comptes de bilan repris, résultat 2025 (750) au compte 121 ; balance 2025 complète (colonne N-1)
        $B = collect(Compta::balance($client->id, 2026))->keyBy('compte');
        $this->assertEquals([9500, 0], [$B['532000']['rd'], $B['532000']['rc']]);
        $this->assertEquals([0, 750], [$B['401001']['rd'], $B['401001']['rc']]);
        $this->assertEqualsWithDelta(750, $B['121']['rc'], 0.001);
        $this->assertArrayNotHasKey('606100', $B->all());
        $this->assertEqualsWithDelta(2000.5, collect(Compta::balance($client->id, 2025))->firstWhere('compte', '706000')['sc'], 0.001);

        // Un nouvel import remplace le précédent (conservé dans « Pièces supprimées »)
        $this->get(route('compta.balance-n1', ['annee' => 2026]))->assertSee('déjà été reprise');
        $lignes = $import['lignes'];
        $lignes[] = ['compte' => '461', 'libelle' => '', 'debit' => 0, 'credit' => 0.5];
        $this->post(route('compta.balance-n1.enregistrer'), ['annee' => 2026, 'lignes' => json_encode($lignes), 'ecart' => 1, 'compte_ecart' => '461'])->assertSessionHas('ok');
        $this->assertSame(1, Ecriture::where('source', 'bal:2025')->count());
        $this->assertSame(1, Ecriture::onlyTrashed()->where('source', 'bal:2025')->count());
        $this->assertEqualsWithDelta(0, Ecriture::where('source', 'bal:2025')->first()->lignes->where('compte', '461')->sum(fn ($l) => $l->credit - $l->debit), 0.001);
    }

    public function test_balance_word_et_pdf(): void
    {
        $client = Client::factory()->create();
        session(['dossier' => $client->id]);

        // Word : tableau avec en-tête « Solde débiteur | Solde créditeur »
        $w = new \PhpOffice\PhpWord\PhpWord();
        $t = $w->addSection()->addTable();
        $t->addRow(); foreach (['N° compte', 'Intitulé', 'Total débit', 'Total crédit', 'Solde débiteur', 'Solde créditeur'] as $h) $t->addCell(1500)->addText($h);
        foreach (self::BALANCE as $l) { $t->addRow(); foreach ([$l[0], $l[1], $this->nombre($l[2]), $this->nombre($l[3]), $this->nombre($l[4]), $this->nombre($l[5])] as $v) $t->addCell(1500)->addText($v); }
        $tmp = tempnam(sys_get_temp_dir(), 'bal').'.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save($tmp);
        $this->post(route('compta.balance-n1.lire'), ['annee' => 2026, 'fichier' => new UploadedFile($tmp, 'balance.docx', null, null, true)])->assertRedirect(route('compta.balance-n1'));
        $L = collect(session('balance_n1.'.$client->id)['lignes'])->keyBy('compte');
        $this->assertCount(6, $L);
        $this->assertEquals([1250.5, 0, false], [$L['606100']['debit'], $L['606100']['credit'], $L['606100']['doute']]);
        $this->post(route('compta.balance-n1.abandonner'))->assertSessionHas('ok');

        // PDF : les cellules vides disparaissent du texte ; le sens se déduit des mouvements
        $html = '<table>'.collect(self::BALANCE)->map(fn ($l) => '<tr><td>'.$l[0].'</td><td>'.$l[1].'</td><td>'.$this->nombre($l[2]).'</td><td>'.$this->nombre($l[3])
            .'</td><td>'.$this->nombre($l[4]).'</td><td>'.$this->nombre($l[5]).'</td></tr>')->implode('').'</table>';
        $tmp = tempnam(sys_get_temp_dir(), 'bal').'.pdf';
        file_put_contents($tmp, \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<p>Balance générale 2025</p>'.$html)->output());
        $this->post(route('compta.balance-n1.lire'), ['annee' => 2026, 'fichier' => new UploadedFile($tmp, 'balance.pdf', null, null, true)])->assertRedirect(route('compta.balance-n1'));
        $L = collect(session('balance_n1.'.$client->id)['lignes'])->keyBy('compte');
        $this->assertSame(array_column(self::BALANCE, 0), $L->keys()->map(fn ($k) => (string) $k)->all());
        foreach (self::BALANCE as $l) $this->assertEquals([$l[4], $l[5]], [$L[$l[0]]['debit'], $L[$l[0]]['credit']], $l[0]);
    }

    public function test_sens_du_solde_d_une_ligne_de_texte(): void
    {
        $this->assertSame([750.0, false], LecteurBalance::soldeDesMontants('401', [1000.0, 250.0, 750.0]));
        $this->assertSame([-750.0, false], LecteurBalance::soldeDesMontants('411', [250.0, 1000.0, 750.0]));
        $this->assertSame([-400.0, true], LecteurBalance::soldeDesMontants('2815', [400.0, 400.0]));     // un seul mouvement : nature du compte
        $this->assertSame([-35.5, false], LecteurBalance::soldeDesMontants('6061', [-35.5]));            // solde signé
        $lignes = LecteurBalance::texte("4366 TVA 120,000 0,000 120,000 0,000\n532 Banque 1 000,000 250,000 750,000\nTotal 1 000,000");
        $this->assertEquals([['4366', 120.0, 0.0], ['532', 750.0, 0.0]], array_map(fn ($l) => [$l['compte'], $l['debit'], $l['credit']], $lignes));
    }

    public function test_seul_le_chef_enregistre_et_fichier_illisible(): void
    {
        $client = Client::factory()->create();
        session(['dossier' => $client->id]);
        $tmp = tempnam(sys_get_temp_dir(), 'bal').'.csv';
        file_put_contents($tmp, "Rien à lire\nici");
        $this->post(route('compta.balance-n1.lire'), ['annee' => 2026, 'fichier' => new UploadedFile($tmp, 'vide.csv', null, null, true)])
            ->assertSessionHas('err', fn ($m) => str_contains($m, 'Aucun compte'));

        $collab = User::factory()->create(['cabinet_id' => $this->chef->cabinet_id, 'role' => 'collaborateur', 'status' => 'active']);
        $this->actingAs($collab)->post(route('compta.balance-n1.enregistrer'), ['annee' => 2026, 'lignes' => json_encode([['compte' => '532', 'debit' => 1], ['compte' => '101', 'credit' => 1]])]);
        $this->assertSame(0, Ecriture::count());
    }
}
