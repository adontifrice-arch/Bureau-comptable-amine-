<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Salarie;
use App\Models\User;
use App\Services\Exports\FichiersVirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Fichiers de virement aux modèles des banques (BT, STB, UIB, BIAT, ATB, UBCI, Zitouna, BH Bank). */
class PaieVirementBanqueTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 09:00:00');
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Société Étoile', 'matricule_fiscal' => '1234567/A/M/000', 'paie_rib' => '14 013 0131017009756 43']);
        session(['dossier' => $this->client->id]);
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Ben Ali', 'prenom' => 'Hédi', 'matricule' => '7', 'salaire_base' => 1000, 'mode_paiement' => 'virement', 'rib' => '10109062506997478833']);
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Trabelsi', 'prenom' => 'Sonia', 'matricule' => '12', 'salaire_base' => 800, 'mode_paiement' => 'virement', 'rib' => '08104000744006319029']);
        Salarie::create(['client_id' => $this->client->id, 'nom' => 'Espèces', 'salaire_base' => 700, 'mode_paiement' => 'especes']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fichier(string $modele): string
    {
        return $this->get(route('paie.virement.banque', [$modele, 'mois' => '2026-10']))->assertOk()->getContent();
    }

    public function test_formats_a_positions_fixes(): void
    {
        [$D] = FichiersVirement::donnees($this->client, '2026-10');
        $this->assertCount(2, $D['lignes']);   // le salarié payé en espèces est exclu
        $total = str_pad((string) array_sum(array_column($D['lignes'], 'montant')), 12, '0', STR_PAD_LEFT);
        $this->assertSame('1234567A', $D['mf']);
        $this->assertSame('HEDI', explode(' ', collect($D['lignes'])->firstWhere('rib', '10109062506997478833')['nom'])[2]);

        // Longueur de chaque enregistrement (hors en-tête et fin de fichier STB), CR LF en fin de ligne.
        foreach (['uib' => 160, 'uib-masse' => 160, 'biat' => 280, 'ubci' => 280, 'zitouna' => 240, 'bh' => 240] as $m => $n) {
            $c = ltrim($this->fichier($m), "\xEF\xBB\xBF");
            $this->assertStringEndsWith("\r\n", $c, $m);
            $L = explode("\r\n", rtrim($c, "\r\n"));
            $this->assertCount(3, $L, $m);
            foreach ($L as $l) $this->assertSame($n, strlen($l), $m);
        }
        $atb = explode("\r\n", rtrim($this->fichier('atb')));
        $this->assertSame([100, 76, 76], array_map('strlen', $atb));
        $this->assertSame('010131017009756Virement du mois de Octobre 2026', rtrim(substr($atb[0], 0, 85)));
        $this->assertSame($total.'002', substr($atb[0], 85));

        $stb = explode("\r\n", rtrim($this->fichier('stb')));
        $this->assertSame(['DEBUT FICHIER QUA 17/11/2026', 'FIN FICHIER QUA 17/11/2026'], [$stb[0], $stb[3 + 1]]);
        $this->assertSame('D14013013101700975643SOCIETE ETOILE           000'.$total.'VOSA', $stb[1]);

        $uib = explode("\r\n", $this->fichier('uib'));
        $this->assertSame('0914'.'14013013101700975643'.str_repeat('0', 35).'1234567A'.'0000000002'.$total, substr($uib[0], 0, 89));
        $this->assertStringContainsString('Paie Salaire 2026 BEN ALI HEDI', $uib[1]);

        $biat = explode("\r\n", $this->fichier('biat'));
        $this->assertSame('1101401301310170097564320261117000111788', substr($biat[0], 0, 40));
        $this->assertSame('0007', substr($biat[1], 114, 4));   // matricule de l'employé sur 4 chiffres
        $this->assertSame('Salaire Octobre 2026', substr($biat[1], 137, 20));

        $z = $this->fichier('zitouna');
        $this->assertStringStartsWith("\xEF\xBB\xBFV1  14013013101700975643".'20261117SalGlob Octobre_2026', $z);
        $this->assertStringContainsString('SalGlob Octobre 2026 BEN ALI HEDI', $z);
    }

    public function test_classeur_bt_et_nom_des_fichiers(): void
    {
        $r = $this->get(route('paie.virement.banque', ['bt', 'mois' => '2026-10']))->assertOk();
        $this->assertStringContainsString('VirBT.xlsx', $r->headers->get('Content-Disposition'));
        $tmp = tempnam(sys_get_temp_dir(), 'bt');
        file_put_contents($tmp, $r->getContent());
        $ws = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);
        $this->assertSame(['Nom', 'RIB', 'Montant', 'Opération', 'Référence'], $ws->rangeToArray('A1:E1')[0]);
        $this->assertSame("10109062506997478833'", $ws->getCell('B2')->getValue());
        $this->assertSame('Salaire Octobre 2026', $ws->getCell('D2')->getValue());
        $this->assertSame($ws->getCell('C2')->getValue() + $ws->getCell('C3')->getValue(), $ws->getCell('C4')->getValue());

        $this->assertStringContainsString('ZitounaMassTransfer_20261117.txt', $this->get(route('paie.virement.banque', ['zitouna', 'mois' => '2026-10']))->headers->get('Content-Disposition'));
        $this->get(route('paie.virement.banque', ['inconnue', 'mois' => '2026-10']))->assertNotFound();
        $this->get(route('paie.index', ['mois' => '2026-10']))->assertOk()->assertSee('Fichier pour la banque')->assertSee('UIB — virement de masse');
    }

    public function test_refus_si_un_rib_manque(): void
    {
        Salarie::where('nom', 'Trabelsi')->update(['rib' => '0810']);
        $this->from(route('paie.index'))->get(route('paie.virement.banque', ['stb', 'mois' => '2026-10']))
            ->assertRedirect(route('paie.index'))->assertSessionHas('err', fn ($m) => str_contains($m, 'RIB absent ou incomplet : Trabelsi Sonia'));

        $this->client->update(['paie_rib' => null]);
        Salarie::where('nom', 'Trabelsi')->update(['rib' => '08104000744006319029']);
        $this->from(route('paie.index'))->get(route('paie.virement.banque', ['bh', 'mois' => '2026-10']))
            ->assertSessionHas('err', fn ($m) => str_contains($m, 'RIB de l’employeur'));
    }
}
