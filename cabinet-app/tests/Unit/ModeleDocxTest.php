<?php

namespace Tests\Unit;

use App\Services\Actes\Balises;
use App\Services\Actes\ModeleDocx;
use App\Services\Actes\ModeleTexte;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Concerns\CreeDocx;

class ModeleDocxTest extends TestCase
{
    use CreeDocx;

    /** Paragraphe dont la balise {{DENOMINATION}} est découpée en trois runs, comme le fait souvent Word. */
    private function modele(): string
    {
        return $this->creerDocx(
            '<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">Société {{DENOMI</w:t></w:r>'
            .'<w:r><w:rPr><w:b/></w:rPr><w:t>NAT</w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>ION}} au capital de {{CAPITAL}}</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Associés :</w:t></w:r></w:p><w:p><w:r><w:t>{{ASSOCIES}}</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Balise inconnue : {{FOO}}</w:t></w:r></w:p>',
            '<w:p><w:r><w:t>MF {{MATRICULE_</w:t></w:r><w:r><w:t>FISCAL}}</w:t></w:r></w:p>'
        );
    }

    public function test_les_balises_decoupees_en_plusieurs_runs_sont_detectees(): void
    {
        $this->assertSame(['DENOMINATION', 'CAPITAL', 'ASSOCIES', 'FOO', 'MATRICULE_FISCAL'], Balises::trouver(ModeleDocx::texte($this->modele())));
    }

    public function test_remplissage_conserve_la_mise_en_forme_et_gere_les_caracteres_speciaux(): void
    {
        $dest = tempnam(sys_get_temp_dir(), 'out');
        ModeleDocx::remplir($this->modele(), ['DENOMINATION' => 'A & B <Conseil>', 'CAPITAL' => '1 000,000 TND',
            'ASSOCIES' => "- Sami Ben Ali\n- Leila Trabelsi", 'MATRICULE_FISCAL' => '1234567/A/M/000'], $dest);

        $xml = $this->xmlDocx($dest);
        $this->assertTrue((new DOMDocument())->loadXML($xml), 'XML Word valide');
        $texte = $this->texteDocx($dest);
        $this->assertStringContainsString('Société A & B <Conseil> au capital de 1 000,000 TND', $texte);
        $this->assertStringContainsString("- Sami Ben Ali\n- Leila Trabelsi", $texte);   // saut de ligne Word
        $this->assertStringContainsString('{{FOO}}', $texte);                              // inconnue : laissée telle quelle
        $this->assertStringNotContainsString('{{DENOMI', $texte);
        $this->assertSame(3, substr_count($xml, '<w:b/>'));                                 // runs en gras conservés
        $this->assertStringContainsString('MF 1234567/A/M/000', $this->texteDocx($dest, 'word/header1.xml'));
    }

    public function test_conversion_html_pour_l_editeur(): void
    {
        $dest = tempnam(sys_get_temp_dir(), 'out');
        ModeleDocx::remplir($this->modele(), ['DENOMINATION' => 'Alpha', 'CAPITAL' => '1 000,000 TND', 'ASSOCIES' => "Un\nDeux"], $dest);
        $html = ModeleDocx::html($dest);

        $this->assertStringContainsString('<p style="text-align:center"><b>Société Alpha</b><b> au capital de 1 000,000 TND</b></p>', $html);
        $this->assertStringContainsString('Un<br>Deux', $html);
    }

    public function test_un_fichier_qui_n_est_pas_un_docx_est_refuse(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($f, 'pas un document Word');
        $this->expectException(RuntimeException::class);
        ModeleDocx::texte($f);
    }

    public function test_modele_texte_en_windows_1252_et_mise_en_paragraphes(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($f, mb_convert_encoding("Société {{DENOMINATION}}\r\nsiège : {{SIEGE}}\r\n\r\nArticle 2 – <objet>", 'Windows-1252', 'UTF-8'));
        $texte = Balises::remplacer(ModeleTexte::lire($f), ['DENOMINATION' => 'Alpha', 'SIEGE' => 'Tunis']);

        $this->assertSame("<p>Société Alpha<br>\nsiège : Tunis</p><p>Article 2 – &lt;objet&gt;</p>", ModeleTexte::html($texte));
    }
}
