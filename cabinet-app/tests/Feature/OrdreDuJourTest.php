<?php

namespace Tests\Feature;

use App\Models\Acte;
use App\Models\Client;
use App\Models\User;
use App\Services\Actes\OrdreDuJour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ordre du jour des PV : liste par type, points personnalisés conservés, ordre = ordre de la liste (§3.2). */
class OrdreDuJourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
    }

    private function textes(string $type): array
    {
        return array_column(OrdreDuJour::points($type), 'texte');
    }

    public function test_listes_usuelles_par_type(): void
    {
        $ago = $this->textes('ago');
        $this->assertSame('Lecture et approbation du rapport de gestion', $ago[0]);
        $this->assertSame('Questions diverses', end($ago));
        $this->assertContains('Augmentation du capital', $this->textes('mixte'));
        $this->assertContains('Quitus au gérant', $this->textes('mixte'));
        $this->assertSame(1, count(array_keys($this->textes('mixte'), 'Questions diverses')));   // pas de doublon
        $this->assertNotEmpty($this->textes('cess'));
    }

    public function test_ajout_d_un_point_conserve_et_place_avant_les_points_de_cloture(): void
    {
        $r = $this->postJson(route('actes.odd.store'), ['type' => 'ago', 'point' => '  Approbation   du budget 2027 '])->assertOk();

        $ago = array_column($r->json('ago'), 'texte');
        $i = array_search('Approbation du budget 2027', $ago, true);
        $this->assertNotFalse($i);
        $this->assertSame('Pouvoirs pour l’accomplissement des formalités', $ago[$i + 1]);
        $this->assertSame(['texte' => 'Approbation du budget 2027', 'type' => 'ago'], $r->json('ago')[$i]);
        $this->assertContains('Approbation du budget 2027', $this->textes('mixte'));      // repris en AG mixte
        $this->assertNotContains('Approbation du budget 2027', $this->textes('age'));

        $this->postJson(route('actes.odd.store'), ['type' => 'ago', 'point' => 'Approbation du budget 2027'])->assertOk();
        $this->postJson(route('actes.odd.store'), ['type' => 'ago', 'point' => 'Quitus au gérant'])->assertOk();   // déjà un point usuel
        $this->assertSame(['Approbation du budget 2027'], OrdreDuJour::perso()['ago']);

        $client = Client::factory()->create();
        $this->get(route('actes.create', $client))->assertOk()->assertSee('Approbation du budget 2027');
    }

    public function test_retrait_d_un_point_du_cabinet(): void
    {
        $this->postJson(route('actes.odd.store'), ['type' => 'age', 'point' => 'Ouverture d’une succursale']);
        $r = $this->deleteJson(route('actes.odd.destroy'), ['type' => 'age', 'point' => 'Ouverture d’une succursale'])->assertOk();

        $this->assertNotContains('Ouverture d’une succursale', array_column($r->json('age'), 'texte'));
        $this->assertSame([], OrdreDuJour::perso()['age']);
    }

    public function test_type_ou_point_invalide(): void
    {
        $this->postJson(route('actes.odd.store'), ['type' => 'sarl', 'point' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson(route('actes.odd.store'), ['type' => 'ago', 'point' => ''])->assertUnprocessable()->assertJsonValidationErrors('point');
    }

    public function test_les_resolutions_suivent_l_ordre_de_la_liste(): void
    {
        $this->postJson(route('actes.odd.store'), ['type' => 'ago', 'point' => 'Approbation du budget 2027']);
        $client = Client::factory()->create();

        // Cases envoyées dans le désordre : le PV reprend l'ordre de la liste.
        $this->post(route('actes.store', $client), ['type' => 'ago', 'date' => '2026-06-30',
            'ordre' => ['Pouvoirs pour l’accomplissement des formalités', 'Approbation du budget 2027', 'Quitus au gérant'], 'ordre_libre' => 'Point ponctuel'])
            ->assertSessionHasNoErrors();

        $c = Acte::sole()->contenu;
        $pos = fn ($s) => strpos($c, $s);
        $this->assertTrue($pos('<li>Quitus au gérant</li>') < $pos('<li>Approbation du budget 2027</li>'));
        $this->assertTrue($pos('<li>Approbation du budget 2027</li>') < $pos('<li>Pouvoirs pour l’accomplissement des formalités</li>'));
        $this->assertTrue($pos('<li>Pouvoirs pour l’accomplissement des formalités</li>') < $pos('<li>Point ponctuel</li>'));
        $this->assertStringContainsString('<h3>1re résolution</h3><p>L’assemblée générale donne quitus', $c);
    }
}
