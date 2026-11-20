<?php

namespace Tests\Feature;

use App\Models\Acte;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Contrat de cession de parts sociales selon le modèle du cabinet (« CESSION PARTS »). */
class CessionPartsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::factory()->create(['nom' => 'All by Bim', 'forme' => 'SARL', 'capital' => 10000, 'nb_parts' => 1000, 'valeur_nominale' => 10,
            'adresse' => '5 rue du Lac, 1053 Tunis']);
        $this->client->associes()->create(['civilite' => 'M', 'nom' => 'Bilel Ajej', 'cin' => '07103326', 'cin_date' => '2011-11-08', 'naissance_date' => '1984-05-13', 'naissance_lieu' => 'Tunis', 'parts' => 800]);
        $this->client->associes()->create(['civilite' => 'Mme', 'nom' => 'Amira Joudi', 'cin' => '07409114', 'cin_date' => '2018-08-27', 'naissance_date' => '1987-07-20', 'naissance_lieu' => 'Mannouba', 'parts' => 200]);
    }

    public function test_contrat_selon_le_modele_du_cabinet(): void
    {
        $this->post(route('actes.store', $this->client), ['type' => 'cession', 'date' => '2022-05-30', 'cedant' => 'Amira Joudi', 'parts' => 200, 'prix' => 2000,
            'cessionnaire' => 'Bilel Ajej', 'cessionnaire_civilite' => 'M', 'cessionnaire_cin' => '07103326', 'cessionnaire_cin_date' => '2011-11-08',
            'cessionnaire_naissance_date' => '1984-05-13', 'cessionnaire_naissance_lieu' => 'Tunis',
            'constitution_date' => '2022-03-07', 'enregistrement_date' => '2022-03-09', 'enregistrement_numero' => '22501074', 'enregistrement_bureau' => 'guichet unique de l’API Tunis',
        ])->assertSessionHasNoErrors();
        $h = Acte::sole()->contenu;

        $this->assertStringContainsString('CONTRAT DE CESSION DE PARTS SOCIALES', $h);
        $this->assertStringContainsString('<li>Mme Amira Joudi, née à Mannouba le 20/07/1987, titulaire de la CIN n°07409114 du 27/08/2018.</li>', $h);
        $this->assertStringContainsString('En qualité de vendeuse d’une part', $h);
        $this->assertStringContainsString('<li>Mr Bilel Ajej, né à Tunis le 13/05/1984, titulaire de la CIN n°07103326 du 08/11/2011.</li>', $h);
        $this->assertStringContainsString('En qualité d’Acquéreur d’autre part', $h);
        $this->assertStringContainsString('Par acte sous seing privé daté du 07/03/2022, enregistré au bureau des enregistrements des actes du guichet unique de l’API Tunis le 09/03/2022, sous le n°22501074, la société ALL BY BIM a été constituée entre les associés : Mr Bilel Ajej et Mme Amira Joudi sous la forme juridique d’une SARL au capital de 10 000 DT entièrement libéré.', $h);
        $this->assertStringContainsString('Mme Amira Joudi, citée en premier part cède et transporte par les présents 200 parts sociales lui appartenant dans la société ALL BY BIM SARL sous toutes les garanties de fait et de droit à Mr Bilel Ajej qui les accepte', $h);
        $this->assertStringContainsString('moyennant le prix forfaitaire global de 2 000 dinars, payé par Mr Bilel Ajej à Mme Amira Joudi, qui les reconnaît', $h);
        $this->assertStringContainsString('<h3>Article 5 :</h3>', $h);
        $this->assertStringContainsString('faire élection de domicile à Tunis.', $h);
        $this->assertStringContainsString('Fait en autant d’exemplaires que de droit et de bonne foi à Tunis le 30 mai 2022', $h);
        $this->assertStringContainsString('<b>L’ACHETEUR</b><br><br><br>Mr Bilel Ajej', $h);
        $this->assertStringContainsString('<b>LA VENDEUSE</b><br><br><br>Mme Amira Joudi', $h);
    }

    public function test_informations_manquantes(): void
    {
        $this->post(route('actes.store', $this->client), ['type' => 'cession', 'date' => '2026-03-15', 'cedant' => 'Bilel Ajej', 'cessionnaire' => 'Nadia Gharbi'])->assertSessionHasNoErrors();
        $h = Acte::sole()->contenu;
        $this->assertStringContainsString('<li>Nadia Gharbi, titulaire de la CIN n°………</li>', str_replace('.</li>', '</li>', $h));
        $this->assertStringContainsString('Par acte sous seing privé daté du ………', $h);
        $this->assertStringContainsString('<b>LE VENDEUR</b>', $h);
    }
}
