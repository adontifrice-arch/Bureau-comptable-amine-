<?php

namespace Tests\Concerns;

use ZipArchive;

/** Fabrique de petits documents Word (.docx) pour les tests des modèles d'actes. */
trait CreeDocx
{
    /**
     * @param  string  $body    contenu XML de <w:body>
     * @param  ?string $header  contenu XML de <w:hdr>, optionnel
     */
    protected function creerDocx(string $body, ?string $header = null): string
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $chemin = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        $zip->open($chemin, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document '.$w.'><w:body>'.$body.'</w:body></w:document>');
        if ($header !== null) $zip->addFromString('word/header1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr '.$w.'>'.$header.'</w:hdr>');
        $zip->close();
        return $chemin;
    }

    /** Texte brut d'une partie du document (balises XML retirées, sauts de ligne Word → "\n"). */
    protected function texteDocx(string $chemin, string $partie = 'word/document.xml'): string
    {
        $zip = new ZipArchive();
        $zip->open($chemin);
        $xml = $zip->getFromName($partie);
        $zip->close();
        return html_entity_decode(strip_tags(str_replace(['<w:br/>', '</w:p>'], ["\n", "\n"], $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function xmlDocx(string $chemin, string $partie = 'word/document.xml'): string
    {
        $zip = new ZipArchive();
        $zip->open($chemin);
        $xml = $zip->getFromName($partie);
        $zip->close();
        return $xml;
    }
}
