<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Paie;
use App\Models\Salarie;
use App\Models\User;
use App\Services\PaieCalc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Paie : pointage (jours travaillés, heures supplémentaires, primes) et Fichier → Importation. */
class PaiePointageTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Salarie $karim;
    private Salarie $amel;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha Conseil']);
        session(['dossier' => $this->client->id]);
        $this->karim = Salarie::create(['client_id' => $this->client->id, 'nom' => 'Karim Haddad', 'matricule' => 'E01', 'cin' => '01234567', 'salaire_base' => 1300]);
        $this->amel = Salarie::create(['client_id' => $this->client->id, 'nom' => 'Amel Jlassi', 'matricule' => 'E02', 'salaire_base' => 1040]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fichier(array $lignes): UploadedFile
    {
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        foreach ($lignes as $i => $ligne) {
            foreach ($ligne as $c => $v) {
                if ($v === null) continue;
                is_string($v) ? $ws->setCellValueExplicit([$c + 1, $i + 1], $v, DataType::TYPE_STRING) : $ws->setCellValue([$c + 1, $i + 1], $v);
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pt').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        return new UploadedFile($tmp, 'pointage.xlsx', null, null, true);
    }

    public function test_jours_travailles_et_heures_supplementaires(): void
    {
        $P = PaieCalc::parametres($this->client);   // régime 48 h : 26 jours, 208 h par mois, majoration 25 %
        $this->assertSame(26, $P['jours_mois']);
        $x = PaieCalc::calcul($this->karim, 100, $P, null, 13, 8);
        $this->assertEqualsWithDelta(650, $x['salaire'], 0.001);
        $this->assertEqualsWithDelta(8 * 1300 / 208 * 1.25, $x['hs_montant'], 0.001);
        $this->assertEqualsWithDelta(650 + 62.5 + 100, $x['brut'], 0.001);
        // Sans pointage, le mois est payé en entier.
        $this->assertEqualsWithDelta(1300, PaieCalc::calcul($this->karim, 0, $P)['brut'], 0.001);
    }

    public function test_saisie_du_pointage_dans_le_calcul_du_mois(): void
    {
        $this->post(route('paie.calcul'), ['mois' => '2026-11', 'primes' => [$this->karim->id => 50],
            'pointage' => [$this->karim->id => ['jours' => 20, 'hs' => 4], $this->amel->id => ['jours' => '', 'hs' => '']]])->assertSessionHas('ok');
        $paie = Paie::sole();
        $this->assertEquals([$this->karim->id => ['jours' => 20.0, 'hs' => 4.0]], $paie->pointage);
        $R = collect(PaieCalc::mois($this->client, '2026-11'))->keyBy('salarie_id');
        $this->assertEqualsWithDelta(1000, $R[$this->karim->id]['salaire'], 0.001);
        $this->assertEqualsWithDelta(1040, $R[$this->amel->id]['brut'], 0.001);
        $this->get(route('paie.index', ['mois' => '2026-11']))->assertOk()->assertSee('H. sup.');
        $this->get(route('paie.bulletins', ['mois' => '2026-11']))->assertOk()->assertSee('Heures supplémentaires (4 h)');
    }

    public function test_import_du_pointage_mensuel(): void
    {
        $f = $this->fichier([
            ['Matricule', 'Nom et prénom', 'Jours travaillés', 'Heures supplémentaires', 'Primes'],
            ['E01', null, 22, 6, 120],
            [null, 'amel jlassi', null, null, 30],
            ['E99', 'Inconnu', 26, 0, 0],
        ]);
        $this->post(route('paie.import.pointage'), ['type' => 'mensuel', 'mois' => '2026-10', 'fichier' => $f])
            ->assertRedirect()->assertSessionHas('err', fn ($m) => str_contains($m, 'Pointage importé pour octobre 2026') && str_contains($m, 'Inconnu'));
        $paie = Paie::where('mois', '2026-10')->sole();
        $this->assertEquals(['jours' => 22.0, 'hs' => 6.0], $paie->pointage[$this->karim->id]);
        $this->assertArrayNotHasKey($this->amel->id, $paie->pointage);
        $this->assertEquals(30, $paie->primes[$this->amel->id]);
    }

    public function test_import_de_l_historique_avec_cloture(): void
    {
        Paie::create(['client_id' => $this->client->id, 'mois' => '2026-03', 'statut' => 'val', 'resultats' => [], 'totaux' => []]);
        $f = $this->fichier([
            ['Mois', 'CIN', 'Nom', 'Jours', 'Primes'],
            ['2026-01', '01234567', null, 26, 0],
            ['02/2026', '01234567', null, 13, 0],
            ['2026-01', null, 'Amel Jlassi', 26, 200],
            ['2026-03', '01234567', null, 26, 0],
        ]);
        $this->post(route('paie.import.pointage'), ['type' => 'historique', 'cloturer' => '1', 'fichier' => $f])
            ->assertSessionHas('err', fn ($m) => str_contains($m, 'Ignoré (paie déjà clôturée) : mars 2026'));
        $jan = Paie::where('mois', '2026-01')->sole();
        $this->assertSame('val', $jan->statut);
        $this->assertCount(2, $jan->resultats);
        $this->assertEqualsWithDelta(1300 + 1040 + 200, $jan->totaux['brut'], 0.001);
        $this->assertEqualsWithDelta(650, collect(Paie::where('mois', '2026-02')->sole()->resultats)->firstWhere('salarie_id', $this->karim->id)['brut'], 0.001);
    }

    public function test_modeles_de_paie_et_du_personnel(): void
    {
        Paie::create(['client_id' => $this->client->id, 'mois' => '2026-10', 'primes' => [$this->karim->id => 75], 'pointage' => [$this->karim->id => ['jours' => 20, 'hs' => 2]]]);
        $rep = $this->get(route('paie.import.modele-paie', ['mois' => '2026-10']))->assertOk();
        $ws = IOFactory::load($rep->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame(['Mois', 'Matricule', 'CIN', 'Nom et prénom', 'Jours travaillés', 'Heures supplémentaires', 'Primes'], $ws->rangeToArray('A1:G1')[0]);
        $lignes = collect($ws->rangeToArray('A2:G3'))->keyBy(3);
        $this->assertSame(['2026-10', 'E01', '01234567'], array_slice($lignes['Karim Haddad'], 0, 3));
        $this->assertEquals([20, 2, 75], array_slice($lignes['Karim Haddad'], 4));
        $this->assertEquals(26, $lignes['Amel Jlassi'][4]);

        $rep = $this->get(route('paie.import.modele'))->assertOk();
        $ws = IOFactory::load($rep->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('01234567', $ws->getCell('C3')->getValue() ?: $ws->getCell('C2')->getValue());
        $this->get(route('paie.index'))->assertSee('Historique du pointage de la paie')->assertSee('Modèle du personnel');
    }
}
