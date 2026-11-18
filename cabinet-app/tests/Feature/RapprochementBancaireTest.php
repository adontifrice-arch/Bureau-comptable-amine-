<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Ecriture;
use App\Models\EcritureLigne;
use App\Models\Releve;
use App\Models\ReleveLigne;
use App\Models\User;
use App\Services\Compta;
use App\Services\Rapprochement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * État de rapprochement au 31/01/2026 du compte 532 :
 * - relevé importé et comptabilisé : virement reçu 1 000 (pointé d'office) ;
 * - chèque émis 300 (comptabilisé, pas encore débité par la banque) ;
 * - remise de chèque 200 (comptabilisée, créditée par la banque : à pointer) ;
 * - frais bancaires 15 sur le relevé, pas encore comptabilisés.
 * Relevé au 31/01 : 1 000 + 200 − 15 = 1 185. Comptabilité : 900 − 15 = 885 = 1 185 − 300.
 */
class RapprochementBancaireTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private User $chef;
    private Ecriture $remise;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->chef = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']);
        $this->actingAs($this->chef);
        $this->client = Client::factory()->create();
        session(['dossier' => $this->client->id]);
        $p = fn ($date, $jnl, $lib, $l, $src = null) => Compta::passer($this->client->id, $date, $jnl, $lib, $l, $src, $this->chef->id);

        $releve = Releve::create(['client_id' => $this->client->id, 'nom_fichier' => 'janvier.pdf', 'methode' => 'pdf', 'compte_banque' => '532']);
        $vir = ReleveLigne::create(['client_id' => $this->client->id, 'releve_id' => $releve->id, 'date' => '2026-01-10', 'libelle' => 'VIR RECU CLIENT', 'debit' => 0, 'credit' => 1000, 'statut' => 'cpt', 'compte' => '411']);
        $p('2026-01-10', 'BQ', 'VIR RECU CLIENT', [['compte' => '532', 'debit' => 1000], ['compte' => '411', 'credit' => 1000]], 'rb:'.$vir->id);
        ReleveLigne::create(['client_id' => $this->client->id, 'releve_id' => $releve->id, 'date' => '2026-01-28', 'libelle' => 'FRAIS TENUE', 'debit' => 15, 'credit' => 0, 'statut' => 'att']);
        ReleveLigne::create(['client_id' => $this->client->id, 'releve_id' => $releve->id, 'date' => '2026-01-29', 'libelle' => 'DOUBLON', 'debit' => 50, 'credit' => 0, 'statut' => 'ign']);
        ReleveLigne::create(['client_id' => $this->client->id, 'releve_id' => $releve->id, 'date' => '2026-02-03', 'libelle' => 'FRAIS FEVRIER', 'debit' => 12, 'credit' => 0, 'statut' => 'att']);

        $p('2026-01-20', 'BQ', 'Chèque n° 101 fournisseur', [['compte' => '401', 'debit' => 300], ['compte' => '532', 'credit' => 300]]);
        $this->remise = $p('2026-01-25', 'BQ', 'Remise de chèque client', [['compte' => '532', 'debit' => 200], ['compte' => '411', 'credit' => 200]]);
        $p('2026-02-05', 'BQ', 'Virement de février', [['compte' => '401', 'debit' => 80], ['compte' => '532', 'credit' => 80]]);
    }

    private function etat(?float $solde = 1185): array
    {
        return Rapprochement::etat($this->client->id, '532', '2026-01-31', $solde);
    }

    public function test_etat_et_pointage(): void
    {
        $R = $this->etat();
        $this->assertEquals(900, $R['soldeCompta']);                     // opérations de février exclues
        $this->assertEquals([15, 0], [$R['relDec'], $R['relEnc']]);       // ligne ignorée et frais de février exclus
        $this->assertSame(['Chèque n° 101 fournisseur', 'Remise de chèque client'], $R['nonPointees']->pluck('libelle')->all());   // le virement du relevé est pointé d'office
        $this->assertEquals(885, $R['comptaAjuste']);
        $this->assertEquals(1185 + 200 - 300, $R['banqueAjuste']);
        $this->assertEquals(-200, $R['ecart']);

        // La remise figure sur le relevé : on la pointe → équilibré
        $ligne = EcritureLigne::where('ecriture_id', $this->remise->id)->where('compte', '532')->first();
        $this->post(route('compta.rapprochement.pointer'), ['compte' => '532', 'date' => '2026-01-31', 'action' => 'pointer', 'lignes' => [$ligne->id]])
            ->assertRedirect(route('compta.index', ['tab' => 'bq', 'vue' => 'rapprochement', 'compte' => '532', 'date' => '2026-01-31', 'annee' => '2026']))->assertSessionHas('ok');
        $R = $this->etat();
        $this->assertEquals([0, 300, 885, 0], [$R['nonPD'], $R['nonPC'], $R['banqueAjuste'], $R['ecart']]);
        $this->assertSame(['Remise de chèque client'], $R['pointees']->pluck('libelle')->all());
        // Pointée au 31/01 : elle n'est pas pointée dans un état arrêté plus tôt
        $this->assertContains('Remise de chèque client', Rapprochement::etat($this->client->id, '532', '2026-01-30', null)['nonPointees']->pluck('libelle')->all());

        // Une modification sans toucher la ligne de banque garde le pointage
        $this->put(route('compta.ecritures.update', $this->remise), ['date' => '2026-01-25', 'journal' => 'BQ', 'libelle' => 'Remise chèque n° 55',
            'lignes' => [['compte' => '532', 'debit' => 200, 'credit' => 0], ['compte' => '411', 'debit' => 0, 'credit' => 200]]])->assertSessionHas('ok');
        $this->assertEquals(0, $this->etat()['ecart']);

        $ligne = EcritureLigne::where('ecriture_id', $this->remise->id)->where('compte', '532')->first();
        $this->post(route('compta.rapprochement.pointer'), ['compte' => '532', 'date' => '2026-01-31', 'action' => 'depointer', 'lignes' => [$ligne->id]])->assertSessionHas('ok');
        $this->assertEquals(-200, $this->etat()['ecart']);
    }

    public function test_ecran_solde_du_releve_et_impression(): void
    {
        $this->assertNull($this->etat(null)['ecart']);
        $this->get(route('compta.index', ['tab' => 'bq', 'vue' => 'rapprochement', 'compte' => '532', 'date' => '2026-01-31']))->assertOk()
            ->assertSee('Saisissez le solde du relevé')->assertSee('Écritures non pointées')->assertSee('FRAIS TENUE')->assertDontSee('DOUBLON');

        $this->post(route('compta.rapprochement.solde'), ['compte' => '532', 'date' => '2026-01-31', 'solde' => 1185])->assertSessionHas('ok');
        $this->assertSame(1185.0, Rapprochement::soldeReleve($this->client->id, '532', '2026-01-31'));
        $this->get(route('compta.index', ['tab' => 'bq', 'vue' => 'rapprochement', 'compte' => '532', 'date' => '2026-01-31']))->assertOk()
            ->assertSee('Écart de rapprochement : 200,000');
        $this->get(route('compta.rapprochement.print', ['compte' => '532', 'date' => '2026-01-31']))->assertOk()
            ->assertSee('ÉTAT DE RAPPROCHEMENT BANCAIRE')->assertSee('Solde comptable rapproché')->assertSee('Chèque n° 101 fournisseur');
        $this->get(route('compta.index', ['tab' => 'bq']))->assertOk()->assertSee('État de rapprochement');

        $this->assertSame(['532'], Rapprochement::comptes($this->client->id));
        $collab = User::factory()->create(['cabinet_id' => $this->chef->cabinet_id, 'role' => 'collaborateur', 'status' => 'active']);
        $ligne = EcritureLigne::where('ecriture_id', $this->remise->id)->where('compte', '532')->first();
        $this->actingAs($collab)->post(route('compta.rapprochement.pointer'), ['compte' => '532', 'date' => '2026-01-31', 'action' => 'pointer', 'lignes' => [$ligne->id]])
            ->assertSessionHas('err');
        $this->assertNull($ligne->fresh()->pointe_le);
    }
}
