<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Ecriture;
use App\Models\FactureRecue;
use App\Models\User;
use App\Services\Compta;
use App\Services\Saisie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Longueur des numéros de compte des écritures d'un dossier (tels que saisis, 6, 7, 8 chiffres ou plus). */
class LongueurComptesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->actingAs(User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']));
        $this->client = Client::factory()->create();
        session(['dossier' => $this->client->id]);
    }

    private function parametrer(int $longueur, bool $convertir = false): void
    {
        $p = Saisie::parametres($this->client->id);
        $this->post(route('compta.parametres'), ['comptes' => ['longueur' => $longueur], 'convertir' => $convertir ? '1' : '0',
            'achat' => ['compte_tiers' => '401', 'tva_taux' => 19, 'timbre' => 1, 'tva_deductible' => '1'],
            'vente' => ['compte_tiers' => '411', 'tva_taux' => 19, 'timbre' => 1, 'compte_timbre' => '437'],
            'banque' => ['compte' => '532', 'attente' => '461', 'regles' => $p['banque']['regles']]])->assertSessionHasNoErrors()->assertSessionHas('ok');
    }

    private function comptes(Ecriture $e): array
    {
        return $e->fresh()->lignes->pluck('compte')->all();
    }

    public function test_numeros_completes_par_des_zeros(): void
    {
        $this->assertSame(['401000', '436600', '45311000', '2818', '6061', '6278000000', '4011234'],
            [Saisie::normaliser('401', 6), Saisie::normaliser('4366', 6), Saisie::normaliser('45311', 8), Saisie::normaliser('2818', 0),
                Saisie::normaliser('6061', 4), Saisie::normaliser('6278', 10), Saisie::normaliser('4011234', 6)]);    // plus long : gardé
        $this->assertSame("Fournisseurs d'exploitation", Compta::libelle('401000'));
        $this->assertSame("Taxes sur le chiffre d'affaires déductibles", Compta::libelle('436600'));
    }

    public function test_les_pieces_sont_comptabilisees_a_la_longueur_choisie(): void
    {
        $this->assertSame(0, Saisie::longueur($this->client->id));                     // par défaut : tels que saisis
        $this->parametrer(6);
        $this->assertSame(6, Saisie::longueur($this->client->id));

        $f = FactureRecue::create(['client_id' => $this->client->id, 'date' => '2026-03-10', 'fournisseur' => 'STEG', 'ht' => 100, 'tva' => 19, 'timbre' => 1, 'compte' => '6062', 'statut' => 'prop']);
        $this->post(route('compta.factures.valider', $f))->assertSessionHas('ok');
        $e = Ecriture::where('source', 'fr:'.$f->id)->sole();
        $this->assertSame(['606200', '436600', '665400', '401000'], $this->comptes($e));

        // Modification de l'écriture : comptes saisis courts, enregistrés à 6 chiffres
        $this->put(route('compta.ecritures.update', $e), ['date' => '2026-03-10', 'journal' => 'ACH', 'libelle' => 'STEG',
            'lignes' => [['compte' => '6062', 'debit' => 120], ['compte' => '401', 'credit' => 120]]])->assertSessionHas('ok');
        $this->assertSame(['606200', '401000'], $this->comptes($e));

        // Écriture manuelle à 8 chiffres
        $this->parametrer(8);
        $od = Compta::passer($this->client->id, '2026-03-11', 'OD', 'Test', [['compte' => '613', 'debit' => 10], ['compte' => '53210000', 'credit' => 10]]);
        $this->assertSame(['61300000', '53210000'], $this->comptes($od));
    }

    public function test_report_a_nouveau_et_conversion_des_ecritures_existantes(): void
    {
        $ancienne = Compta::passer($this->client->id, '2025-12-31', 'OD', 'Exercice précédent', [['compte' => '606', 'debit' => 500], ['compte' => '401', 'credit' => 500]]);
        $this->parametrer(6);
        $this->assertSame(['606', '401'], $this->comptes($ancienne));                   // sans conversion : inchangée

        // Report du résultat antérieur au compte 121 à la même longueur
        $this->assertContains('121000', array_column(Compta::balance($this->client->id, 2026), 'compte'));

        $this->parametrer(6, true);
        $this->assertSame(['606000', '401000'], $this->comptes($ancienne));
        $this->assertSame(['121000', '401000'], array_column(Compta::balance($this->client->id, 2026), 'compte'));   // balance homogène

        $this->get(route('compta.index', ['tab' => 'param']))->assertSee('Comptabiliser les pièces avec des comptes à')->assertSee('6 chiffres (401000)');
        $this->post(route('compta.parametres'), ['comptes' => ['longueur' => 5]])->assertSessionHasErrors('comptes.longueur');
    }
}
