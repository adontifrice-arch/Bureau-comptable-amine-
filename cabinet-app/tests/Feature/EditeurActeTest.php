<?php

namespace Tests\Feature;

use App\Models\Acte;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Éditeur des actes : enregistrement nettoyé, aperçu A4, export PDF paginé (§3.2). */
class EditeurActeTest extends TestCase
{
    use RefreshDatabase;

    private Acte $acte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $client = Client::factory()->create(['nom' => 'Alpha Conseil']);
        $this->post(route('actes.store', $client), ['type' => 'sarl', 'date' => '2026-03-15'])->assertSessionHasNoErrors();
        $this->acte = Acte::sole();
    }

    public function test_l_editeur_s_affiche_avec_sa_barre_d_outils(): void
    {
        $this->get(route('actes.edit', $this->acte))->assertOk()
            ->assertSee('Titre 1')->assertSee('Taille…')->assertSee('Rétablir (Ctrl+Y)')->assertSee('a4-page')
            ->assertSee(route('actes.pdf', $this->acte))->assertSee('STATUTS', false);
    }

    public function test_enregistrement_nettoie_le_html(): void
    {
        $this->put(route('actes.update', $this->acte), ['titre' => 'Statuts – version 2',
            'contenu' => '<h2>Article 1</h2><p onclick="alert(1)">Texte <font size="5">grand</font></p><script>alert(1)</script>'])
            ->assertSessionHasNoErrors()->assertSessionHas('ok');

        $this->assertSame('<h2>Article 1</h2><p>Texte <span style="font-size:18pt">grand</span></p>', $this->acte->fresh()->contenu);
        $this->assertSame('Statuts – version 2', $this->acte->fresh()->titre);
    }

    public function test_un_document_vide_est_refuse(): void
    {
        $this->put(route('actes.update', $this->acte), ['titre' => 'X', 'contenu' => '<p><br></p><script>x</script>'])->assertSessionHasErrors('contenu');
        $this->assertStringContainsString('STATUTS', $this->acte->fresh()->contenu);
    }

    public function test_export_pdf_a4_pagine(): void
    {
        $this->acte->update(['contenu' => str_repeat('<p>Article : texte suffisamment long pour remplir plusieurs pages du document au format A4 portrait.</p>', 160)]);

        $r = $this->get(route('actes.pdf', $this->acte))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $r->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('alpha-conseil-statuts-sarl-15-03-2026.pdf', $r->headers->get('Content-Disposition'));
        $this->assertSame([595.28, 841.89], $this->format($pdf));       // A4 portrait, en points

        // Pied de page « Page i / n » sur chaque page, n = nombre total de pages.
        preg_match_all('#\((Page \d+ / \d+)\)#', $this->flux($pdf), $m);
        $pages = count($m[1]);
        $this->assertGreaterThan(1, $pages);
        $this->assertSame(array_map(fn ($i) => "Page $i / $pages", range(1, $pages)), $m[1]);
    }

    /** Contenu décompressé des flux du PDF. */
    private function flux(string $pdf): string
    {
        // Chaque flux est lu d'après sa longueur déclarée (/Length), les données compressées pouvant contenir n'importe quel octet.
        preg_match_all('#/Length (\d+)[^>]*>>\s*stream\r?\n#', $pdf, $m, PREG_OFFSET_CAPTURE);
        $out = '';
        foreach ($m[0] as $k => [$entete, $pos]) {
            $out .= (string) @gzuncompress(substr($pdf, $pos + strlen($entete), (int) $m[1][$k][0]));
        }
        return $out;
    }

    /** Dimensions de la première page (MediaBox). */
    private function format(string $pdf): array
    {
        preg_match('#/MediaBox\s*\[\s*0\.?0*\s+0\.?0*\s+([\d.]+)\s+([\d.]+)#', $pdf, $m);
        return [round((float) $m[1], 2), round((float) $m[2], 2)];
    }
}
