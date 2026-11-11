<?php

namespace Tests\Feature;

use App\Models\Acte;
use App\Models\Client;
use App\Models\ModeleActe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreeDocx;
use Tests\TestCase;

/** Modèles d'actes importables (.docx / .txt) avec balises — spécification §3.2. */
class ModeleActeTest extends TestCase
{
    use CreeDocx, RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::factory()->create(['nom' => 'Alpha Conseil', 'forme' => 'SARL', 'capital' => 1000, 'nb_parts' => 100, 'valeur_nominale' => 10,
            'adresse' => '12 rue de Marseille, Tunis', 'matricule_fiscal' => '1234567/A/M/000', 'residence' => 'residente', 'regime_export' => 'non',
            'gerant_nom' => 'Sami Ben Ali', 'gerant_cin' => '01234567', 'gerant_associe' => true, 'gerant_parts' => 60, 'gerant_parts_de' => 1, 'gerant_parts_a' => 60]);
        $this->client->associes()->create(['nom' => 'Leila Trabelsi', 'nationalite' => 'tunisienne', 'cin' => '07654321', 'parts' => 40, 'parts_de' => 61, 'parts_a' => 100, 'apport' => 'nature']);
    }

    private function importer(string $type, string $nom, UploadedFile $f)
    {
        return $this->post(route('modeles-actes.store'), ['type' => $type, 'nom' => $nom, 'fichier' => $f]);
    }

    private function txt(string $contenu, string $nom = 'modele.txt'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, $contenu);
    }

    public function test_import_d_un_modele_texte_avec_detection_des_balises(): void
    {
        $this->importer('sarl', 'Statuts maison', $this->txt("Statuts de {{DENOMINATION}}\n{{ INCONNUE }}"))
            ->assertSessionHas('ok')->assertSessionHas('err', fn ($m) => str_contains($m, '{{INCONNUE}}'));

        $m = ModeleActe::sole();
        $this->assertSame(['txt', ['DENOMINATION', 'INCONNUE']], [$m->format, $m->balises]);
        Storage::disk('local')->assertExists($m->fichier);
        $this->get(route('modeles-actes.index'))->assertOk()->assertSee('Statuts maison')->assertSee('{{REPARTITION_PARTS}}', false);
    }

    public function test_formats_refuses(): void
    {
        $this->importer('sarl', 'X', UploadedFile::fake()->create('statuts.pdf', 10, 'application/pdf'))->assertSessionHasErrors('fichier');
        $this->importer('sarl', 'X', $this->txt('pas un document Word', 'faux.docx'))->assertSessionHasErrors('fichier');
        $this->assertSame(0, ModeleActe::count());
    }

    public function test_redaction_depuis_un_modele_texte(): void
    {
        $this->importer('sarl', 'Statuts maison', $this->txt(
            "STATUTS DE {{DENOMINATION}} ({{FORME}}), société {{RESIDENCE}} et {{REGIME_EXPORT}}\n\nCapital : {{CAPITAL}}, {{NB_PARTS}} parts de {{VALEUR_NOMINALE}}\n\n{{REPARTITION_PARTS}}\n\nFait à {{LIEU}}, le {{DATE}}. Objet : {{OBJET}}"));

        $this->post(route('actes.store', $this->client), ['type' => 'sarl', 'date' => '2026-03-15', 'modele_id' => ModeleActe::sole()->id, 'objet' => 'Conseil & formation'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $acte = Acte::sole();
        $this->assertSame(ModeleActe::sole()->id, $acte->modele_id);
        $this->assertStringContainsString('<p>STATUTS DE Alpha Conseil (SARL), société résidente et non exportatrice</p>', $acte->contenu);
        $this->assertStringContainsString('Capital : 1 000,000 TND, 100 parts de 10,000 TND', $acte->contenu);
        $this->assertStringContainsString('- Sami Ben Ali : 60 parts, numérotées de 1 à 60 inclus<br>', $acte->contenu);   // gérant associé inclus
        $this->assertStringContainsString('- Leila Trabelsi : 40 parts, numérotées de 61 à 100 inclus<br>', $acte->contenu);
        $this->assertStringContainsString('Total : 100 parts', $acte->contenu);
        $this->assertStringContainsString('Fait à Tunis, le 15 mars 2026. Objet : Conseil &amp; formation', $acte->contenu);
        $this->get(route('actes.edit', $acte))->assertOk()->assertDontSee('Word (modèle)');
    }

    public function test_redaction_et_telechargement_word_depuis_un_modele_docx(): void
    {
        $docx = $this->creerDocx('<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>CONTRAT DE CESSION – {{DENOMI</w:t></w:r><w:r><w:t>NATION}}</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Entre {{CEDANT}} et {{CESSIONNAIRE}} : {{NB_PARTS_CEDEES}} parts ({{NUMEROS_PARTS}}) pour {{PRIX}}.</w:t></w:r></w:p>');
        $this->importer('cession', 'Cession Word', new UploadedFile($docx, 'cession.docx', null, null, true))->assertSessionHasNoErrors();
        $m = ModeleActe::sole();
        $this->assertSame('docx', $m->format);

        $this->post(route('actes.store', $this->client), ['type' => 'cession', 'date' => '2026-03-15', 'modele_id' => $m->id, 'cedant' => 'Leila Trabelsi',
            'cessionnaire' => 'Nadia Gharbi', 'cessionnaire_cin' => '09999999', 'parts_de' => 61, 'parts_a' => 80, 'prix' => 2500])->assertSessionHasNoErrors();

        $acte = Acte::sole();
        $this->assertStringContainsString('<b>CONTRAT DE CESSION – Alpha Conseil</b>', $acte->contenu);
        $this->assertStringContainsString('Entre Leila Trabelsi, de nationalité tunisienne, titulaire de la CIN n° 07654321 et Nadia Gharbi, titulaire de la CIN n° 09999999 : 20 parts (de 61 à 80 inclus) pour 2 500,000 TND.', $acte->contenu);

        $this->get(route('actes.edit', $acte))->assertOk()->assertSee('Word (modèle)');
        $r = $this->get(route('actes.docx', $acte))->assertOk()->assertDownload();
        $fichier = $r->baseResponse->getFile()->getPathname();
        $this->assertStringContainsString('CONTRAT DE CESSION – Alpha Conseil', $this->texteDocx($fichier));
        $this->assertStringContainsString('20 parts (de 61 à 80 inclus)', $this->texteDocx($fichier));
    }

    public function test_redaction_d_un_pv_avec_ordre_du_jour_et_resolutions(): void
    {
        $this->importer('ago', 'PV AGO', $this->txt("PV de l’AG {{TYPE_AG}}\n\n{{PRESENTS}}\n\n{{ORDRE_DU_JOUR}}\n\n{{RESOLUTIONS}}"));
        $this->post(route('actes.store', $this->client), ['type' => 'ago', 'date' => '2026-06-30', 'modele_id' => ModeleActe::sole()->id,
            'ordre' => ['Quitus au gérant'], 'ordre_libre' => "Point libre"])->assertSessionHasNoErrors();

        $c = Acte::sole()->contenu;
        $this->assertStringContainsString('PV de l’AG ordinaire', $c);
        $this->assertStringContainsString('1. Quitus au gérant<br>', $c);
        $this->assertStringContainsString('2. Point libre', $c);
        $this->assertStringContainsString('Première résolution<br>', $c);
        $this->assertStringContainsString('donne quitus entier et sans réserve au gérant', $c);
        $this->assertStringContainsString('Deuxième résolution', $c);
    }

    public function test_modele_d_un_autre_type_refuse(): void
    {
        $this->importer('ago', 'PV AGO', $this->txt('{{DENOMINATION}}'));
        $this->post(route('actes.store', $this->client), ['type' => 'sarl', 'date' => '2026-03-15', 'modele_id' => ModeleActe::sole()->id])
            ->assertSessionHasErrors('modele_id');
        $this->assertSame(0, Acte::count());
    }

    public function test_texte_integre_inclut_le_gerant_associe_et_les_numeros_de_parts_cedees(): void
    {
        $this->post(route('actes.store', $this->client), ['type' => 'sarl', 'date' => '2026-03-15'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('Sami Ben Ali : 60 parts, numérotées de 1 à 60', Acte::sole()->contenu);

        $this->post(route('actes.store', $this->client), ['type' => 'cession', 'date' => '2026-03-15', 'cedant' => 'Leila Trabelsi', 'parts_de' => 61, 'parts_a' => 70]);
        $this->assertStringContainsString('10 parts sociales de 10,000 TND chacune, numérotées de 61 à 70', Acte::latest('id')->first()->contenu);
    }

    public function test_suppression_du_modele_conserve_les_documents(): void
    {
        $this->importer('sarl', 'Statuts maison', $this->txt('{{DENOMINATION}}'));
        $m = ModeleActe::sole();
        $this->post(route('actes.store', $this->client), ['type' => 'sarl', 'date' => '2026-03-15', 'modele_id' => $m->id]);

        $this->delete(route('modeles-actes.destroy', $m))->assertSessionHas('ok');

        Storage::disk('local')->assertMissing($m->fichier);
        $this->assertNull(Acte::sole()->modele_id);
        $this->assertStringContainsString('Alpha Conseil', Acte::sole()->contenu);
    }
}
