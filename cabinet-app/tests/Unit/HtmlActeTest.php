<?php

namespace Tests\Unit;

use App\Services\Actes\HtmlActe;
use PHPUnit\Framework\TestCase;

class HtmlActeTest extends TestCase
{
    public function test_la_mise_en_forme_de_l_editeur_est_conservee(): void
    {
        $html = '<h3 style="text-align:center">STATUTS<br>SARL</h3><p style="text-align: justify;">Le capital est de <b>1 000,000 TND</b>, <i>soit</i> <u>100 parts</u> – « Société ».</p>'
            .'<ol><li>Un</li></ol><ul><li>Deux</li></ul><p style="margin-top:30px">Signatures</p>'
            .'<table style="width:100%;border-collapse:collapse"><tr><td style="border:1px solid #999;padding:4px;vertical-align:top">Case</td></tr></table>';

        $this->assertSame(str_replace('text-align: justify;', 'text-align:justify', $html), HtmlActe::nettoyer($html));
    }

    public function test_tailles_et_alignements_produits_par_le_navigateur(): void
    {
        $this->assertSame('<span style="font-size:18pt">Grand</span>', HtmlActe::nettoyer('<font size="5">Grand</font>'));
        $this->assertSame('<div style="text-align:right">Droite</div>', HtmlActe::nettoyer('<div align="right">Droite</div>'));
    }

    public function test_le_code_dangereux_est_retire(): void
    {
        $sale = '<p onclick="alert(1)" class="x" style="background:url(javascript:alert(1));text-align:center">Texte</p>'
            .'<script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">lien</a>'
            .'<iframe src="https://exemple.test"></iframe><style>p{}</style><!-- commentaire --><svg><script>alert(1)</script></svg>';

        $this->assertSame('<p style="text-align:center">Texte</p>lien', HtmlActe::nettoyer($sale));
    }

    public function test_texte_vide(): void
    {
        $this->assertSame('', HtmlActe::nettoyer('  '));
        $this->assertSame('Texte &amp; &lt;suite&gt;', HtmlActe::nettoyer('Texte &amp; &lt;suite&gt;'));
    }
}
