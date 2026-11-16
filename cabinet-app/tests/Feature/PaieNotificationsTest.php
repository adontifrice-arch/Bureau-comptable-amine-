<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Salarie;
use App\Models\User;
use App\Services\PaieAlertes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Paie, Fichier → Notifications : contrats, profils, anniversaires, échelons et catégories ; fiche employé (contrat et carrière). */
class PaieNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha Conseil']);
        session(['dossier' => $this->client->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function salarie(array $d): Salarie
    {
        return Salarie::create($d + ['client_id' => $this->client->id, 'salaire_base' => 1000]);
    }

    public function test_les_six_tableaux(): void
    {
        $this->salarie(['nom' => 'Jelassi Ridha', 'contrat_debut' => '2017-03-01', 'contrat_fin' => '2023-02-28']);
        $this->salarie(['nom' => 'Ben Ali Sami', 'contrat_debut' => '2026-06-01', 'contrat_fin' => '2026-12-10', 'date_naissance' => '1990-11-20']);
        $this->salarie(['nom' => 'Haddad Amel', 'contrat_fin' => '2027-06-30', 'date_naissance' => '1985-11-15',
            'profil' => 'Cadre', 'profil_debut' => '2025-01-01', 'profil_fin' => '2026-10-31',
            'echelon' => '4', 'echelon_debut' => '2024-01-01', 'echelon_fin' => '2026-11-14',
            'categorie' => 'B', 'categorie_debut' => '2024-01-01', 'categorie_fin' => '2026-11-15']);
        $this->salarie(['nom' => 'Trabelsi Mehdi', 'date_naissance' => '1992-03-01']);

        $t = PaieAlertes::tableaux($this->client);
        $this->assertSame([['Ben Ali Sami', '01/06/2026', '10/12/2026']], $t['contrats_bientot']['lignes']);
        $this->assertSame([['Jelassi Ridha', '01/03/2017', '28/02/2023']], $t['contrats_expires']['lignes']);
        $this->assertSame([['Haddad Amel', 'Cadre', '01/01/2025', '31/10/2026']], $t['profils_expires']['lignes']);
        $this->assertSame(['Haddad Amel', 'Ben Ali Sami'], array_column($t['anniversaires']['lignes'], 0));
        $this->assertStringContainsString('15/11/2026 (41 ans) — aujourd’hui', $t['anniversaires']['lignes'][0][1]);
        $this->assertCount(1, $t['echelons_expires']['lignes']);
        $this->assertSame([], $t['categories_expirees']['lignes']);   // se termine aujourd'hui : pas encore expirée

        $this->get(route('paie.index'))->assertOk()
            ->assertSee('Contrats expirent dans moins de 30 jours')->assertSee('Contrats expirés')->assertSee('Profils expirés')
            ->assertSee('Anniversaires')->assertSee('Échelons expirés')->assertSee('Catégories expirées')->assertSee('Jelassi Ridha');
    }

    public function test_import_avec_contrat_sans_effacer_les_colonnes_absentes(): void
    {
        $s = $this->salarie(['nom' => 'Karim', 'cin' => '01234567', 'rib' => '08000000000000000011', 'enfants' => 2]);
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        foreach ([['Nom et prénom', 'CIN', 'Salaire de base', 'Date de naissance', 'Type de contrat', 'Début du contrat', 'Fin du contrat', 'Échelon'],
            ['Karim', '01234567', 1500, '15/06/1990', 'contrat CDD', '01/01/2026', '31/12/2026', '5']] as $i => $ligne) {
            foreach ($ligne as $c => $v) is_string($v) ? $ws->setCellValueExplicit([$c + 1, $i + 1], $v, DataType::TYPE_STRING) : $ws->setCellValue([$c + 1, $i + 1], $v);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($ss))->save($tmp);
        $this->post(route('paie.import.employes'), ['fichier' => new UploadedFile($tmp, 'e.xlsx', null, null, true)])->assertSessionHas('ok');

        $s->refresh();
        $this->assertSame('CDD', $s->type_contrat);
        $this->assertSame('2026-12-31', $s->contrat_fin->format('Y-m-d'));
        $this->assertSame('1990-06-15', $s->date_naissance->format('Y-m-d'));
        $this->assertSame('5', $s->echelon);
        $this->assertEquals(1500, $s->salaire_base);
        // Colonnes absentes du fichier : valeurs conservées.
        $this->assertSame('08000000000000000011', $s->rib);
        $this->assertSame(2, $s->enfants);
    }
}
