<?php

namespace Tests\Feature;

use App\Models\Acte;
use App\Models\Client;
use App\Models\User;
use App\Services\Actes\Statuts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Statuts générés : plan et contenu des articles imposés par la spécification §3.2. */
class StatutsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::factory()->create([
            'nom' => 'Alpha Conseil', 'forme' => 'SARL', 'denomination_commerciale' => 'Alpha Formation', 'residence' => 'non_residente', 'regime_export' => 'totale',
            'capital' => 1000, 'nb_parts' => 100, 'valeur_nominale' => 10, 'adresse' => '12 rue de Marseille, Tunis',
            'gerant_nom' => 'John Smith', 'gerant_nationalite' => 'britannique', 'gerant_carte_sejour' => 'CS-123', 'gerant_passeport' => 'P998877',
            'gerant_passeport_date' => '2022-01-10', 'gerant_passeport_validite' => '2032-01-09', 'gerant_nomination_date' => '2026-03-15', 'gerant_nomination_duree' => '3 ans',
            'gerant_associe' => true, 'gerant_parts' => 60, 'gerant_parts_de' => 1, 'gerant_parts_a' => 60, 'gerant_apport' => 'numeraire',
        ]);
        $this->client->associes()->create(['nom' => 'Leila Trabelsi', 'cin' => '07654321', 'parts' => 40, 'parts_de' => 61, 'parts_a' => 100, 'apport' => 'nature']);
    }

    private function article(string $html, int $n): string
    {
        preg_match('#<h3>Article '.$n.' – [^<]+</h3>(.*?)(?=<h3>Article |<p>Fait à )#s', $html, $m);
        return $m[0] ?? '';
    }

    public function test_plan_des_articles(): void
    {
        $titres = array_column(Statuts::articles($this->client, ['type' => 'sarl', 'date' => '2026-03-15']), 0);

        $this->assertSame(['Forme', 'Objet', 'Dénomination', 'Siège social', 'Durée', 'Capital social', 'Répartition des parts', 'Gérance'], array_slice($titres, 0, 8));
        $this->assertSame(range(1, count($titres)), array_map(fn ($i) => $i + 1, array_keys($titres)));
    }

    public function test_contenu_des_articles_imposes(): void
    {
        $this->post(route('actes.store', $this->client), ['type' => 'sarl', 'date' => '2026-03-15', 'objet' => 'Le conseil en gestion'])->assertSessionHasNoErrors();
        $h = Acte::sole()->contenu;

        $a1 = $this->article($h, 1);
        $this->assertStringContainsString('Article 1 – Forme', $a1);
        $this->assertStringContainsString('société à responsabilité limitée (SARL)', $a1);
        $this->assertStringContainsString('La société est une société non résidente, totalement exportatrice.', $a1);

        $this->assertStringContainsString('La société a pour objet : Le conseil en gestion.', $this->article($h, 2));

        $a3 = $this->article($h, 3);
        $this->assertStringContainsString('Article 3 – Dénomination', $a3);
        $this->assertStringContainsString('Sa dénomination commerciale est : « Alpha Formation ».', $a3);

        $a6 = $this->article($h, 6);
        $this->assertStringContainsString('Article 6 – Capital social', $a6);
        $this->assertStringContainsString('fixé à 1 000,000 TND, divisé en 100 parts sociales d’une valeur nominale de 10,000 TND chacune', $a6);
        $this->assertStringContainsString('John Smith : 60 parts de 10,000 TND chacune, soit 600,000 TND, apport en numéraire.', $a6);
        $this->assertStringContainsString('Leila Trabelsi : 40 parts de 10,000 TND chacune, soit 400,000 TND, apport en nature.', $a6);

        $a7 = $this->article($h, 7);
        $this->assertStringContainsString('John Smith : 60 parts, numérotées de 1 à 60 inclus', $a7);
        $this->assertStringContainsString('Leila Trabelsi : 40 parts, numérotées de 61 à 100 inclus', $a7);

        $a8 = $this->article($h, 8);
        $this->assertStringContainsString('Article 8 – Gérance', $a8);
        $this->assertStringContainsString('John Smith, de nationalité britannique, titulaire de la carte de séjour n° CS-123, titulaire du passeport n° P998877 délivré le 10/01/2022 et valable jusqu’au 09/01/2032', $a8);
        $this->assertStringContainsString('nommé(e) gérant(e) le 15 mars 2026 pour une durée de 3 ans', $a8);

        $this->assertStringContainsString('Article 14 – Publicité et pouvoirs', $h);
        $this->assertStringContainsString('<p>Fait à Tunis, le 15 mars 2026', $h);
    }

    public function test_suarl_et_informations_manquantes(): void
    {
        $c = Client::factory()->create(['nom' => 'Solo', 'forme' => 'SUARL', 'residence' => null, 'regime_export' => null, 'gerant_nom' => 'Ali Ben Salah',
            'gerant_nomination_date' => null, 'gerant_nomination_duree' => null]);
        $this->post(route('actes.store', $c), ['type' => 'suarl', 'date' => '2026-03-15'])->assertSessionHasNoErrors();
        $h = Acte::sole()->contenu;

        $this->assertStringContainsString('Il est formé par l’associé unique une société unipersonnelle à responsabilité limitée (SUARL)', $this->article($h, 1));
        $this->assertStringNotContainsString('La société est une société', $this->article($h, 1));      // résidence non renseignée
        $this->assertStringNotContainsString('dénomination commerciale', $this->article($h, 3));
        $this->assertStringContainsString('[objet social à préciser]', $this->article($h, 2));
        $this->assertStringContainsString('nommé(e) gérant(e) pour une durée illimitée', $this->article($h, 8));
    }
}
