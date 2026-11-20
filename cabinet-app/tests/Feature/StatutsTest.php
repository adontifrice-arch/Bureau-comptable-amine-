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

    /** Article n des statuts SARL (modèle du cabinet : « ARTICLE PREMIER : FORME », « ARTICLE 2 : OBJET »…). */
    private function articleSarl(string $html, int $n): string
    {
        preg_match('#<h3>ARTICLE '.($n === 1 ? 'PREMIER' : $n).' : [^<]+</h3>(.*?)(?=<h2|<h3>ARTICLE |<p style="margin-top:20px">Fait à )#s', $html, $m);
        return $m[0] ?? '';
    }

    public function test_plan_des_statuts_sarl_selon_le_modele_du_cabinet(): void
    {
        $articles = Statuts::articles($this->client, ['type' => 'sarl', 'date' => '2026-03-15']);

        $this->assertCount(33, $articles);
        $this->assertSame(['Forme', 'Objet', 'Dénomination', 'Siège social', 'Durée', 'Capital social / Apports', 'Comptes courants'], array_slice(array_column($articles, 0), 0, 7));
        $this->assertSame('Tribunaux compétents', $articles[32][0]);
        // Huit titres : I formation, II capital, III gérance, IV décisions, V exercice, VI surveillance, VII dissolution, VIII contestations
        $titres = array_values(array_filter(array_map(fn ($a) => $a[2] ?? null, $articles)));
        $this->assertCount(8, $titres);
        $this->assertSame(['TITRE I – FORMATION', 'TITRE III – GÉRANCE', 'TITRE VIII – CONTESTATIONS'], [$titres[0], $titres[2], $titres[7]]);
        $this->assertSame('Nomination du gérant', $articles[11][0]);
        $this->assertSame('Assemblées générales', $articles[19][0]);   // l'article 19 renvoie à l'article 20
    }

    public function test_contenu_des_statuts_sarl(): void
    {
        $this->post(route('actes.store', $this->client), ['type' => 'sarl', 'date' => '2026-03-15', 'objet' => "la programmation informatique\nLe conseil en gestion."])->assertSessionHasNoErrors();
        $h = Acte::sole()->contenu;

        // En-tête et comparution
        $this->assertStringContainsString('SARL AU CAPITAL DE 1.000 DINARS', $h);
        $this->assertStringContainsString('NON RÉSIDENTE', $h);
        $this->assertStringContainsString('TOTALEMENT EXPORTATRICE', $h);
        $this->assertStringContainsString('Entre les soussignés :', $h);
        $this->assertStringContainsString('1- John Smith, de nationalité britannique', $h);
        $this->assertStringContainsString('2- Leila Trabelsi, titulaire de la CIN n° 07654321', $h);

        $a1 = $this->articleSarl($h, 1);
        $this->assertStringContainsString('ARTICLE PREMIER : FORME', $a1);
        $this->assertStringContainsString('une société non résidente, totalement exportatrice, ayant la forme de société à responsabilité limitée SARL, qui sera régie par la loi n° 2000-93', $a1);

        $a2 = $this->articleSarl($h, 2);
        $this->assertStringContainsString('<li>La programmation informatique.</li><li>Le conseil en gestion.</li><li>La participation directe ou indirecte', $a2);

        $a3 = $this->articleSarl($h, 3);
        $this->assertStringContainsString('La société prend la dénomination de : <b>Alpha Conseil</b>. Sa dénomination commerciale est : « Alpha Formation ».', $a3);
        $this->assertStringContainsString('article 91 du code des sociétés commerciales', $a3);

        $this->assertStringContainsString('fixé au : 12 rue de Marseille, Tunis.', $this->articleSarl($h, 4));
        $this->assertStringContainsString('fixée à 99 ans', $this->articleSarl($h, 5));

        $a6 = $this->articleSarl($h, 6);
        $this->assertStringContainsString('ARTICLE 6 : CAPITAL SOCIAL / APPORTS', $a6);
        $this->assertStringContainsString('fixé à la somme de mille dinars (1 000 DT), soit l’équivalent de sa valeur en devises, divisé en cent (100) parts sociales de dix dinars (10 DT) chacune', $a6);
        $this->assertStringContainsString('1- John Smith apporte à la présente société la somme de SIX CENTS (600) dinars et reçoit en rémunération de son apport soixante (60) parts de dix dinars (10 DT) chacune', $a6);
        $this->assertStringContainsString('2- Leila Trabelsi apporte à la présente société des biens en nature évalués à la somme de QUATRE CENTS (400) dinars', $a6);
        $this->assertMatchesRegularExpression('#Leila Trabelsi</td>\s*<td[^>]*>400</td>\s*<td[^>]*>0</td>\s*<td[^>]*>400</td>\s*<td[^>]*>40 %</td>#', $a6);
        $this->assertMatchesRegularExpression('#<b>Total</b></td>\s*<td[^>]*>400</td>\s*<td[^>]*>600</td>\s*<td[^>]*>1 000</td>\s*<td[^>]*>100 %</td>#', $a6);
        $this->assertStringContainsString('<li>60 parts numérotées de 1 à 60 inclus à John Smith.</li><li>40 parts numérotées de 61 à 100 inclus à Leila Trabelsi.</li>', $a6);

        $a12 = $this->articleSarl($h, 12);
        $this->assertStringContainsString('ARTICLE 12 : NOMINATION DU GÉRANT', $a12);
        $this->assertStringContainsString('John Smith a été désigné(e) gérant(e) statutaire de la société pour une durée de 3 ans à compter du 15 mars 2026', $a12);
        $this->assertStringContainsString('pour une durée de 3 ans à partir de la date de la constitution définitive', $this->articleSarl($h, 13));
        $this->assertStringContainsString('ainsi qu’il est dit à l’article 20 des présents statuts', $this->articleSarl($h, 19));
        $this->assertStringContainsString('ARTICLE 20 : ASSEMBLÉES GÉNÉRALES', $h);
        $this->assertStringContainsString('ARTICLE 33 : TRIBUNAUX COMPÉTENTS', $h);

        // Signatures
        $this->assertStringContainsString('Fait à TUNIS en autant d’exemplaires que de droit.<br>Le 15-03-2026', $h);
        $this->assertStringContainsString('<b>LES ASSOCIÉS</b>', $h);
        $this->assertStringContainsString('<b>LE GÉRANT</b><br>John Smith', $h);
    }

    public function test_statuts_sarl_avec_civilites(): void
    {
        $this->client->update(['gerant_civilite' => 'M', 'gerant_naissance_date' => '1988-07-10', 'gerant_naissance_lieu' => 'Tunis']);
        $this->client->associes()->update(['civilite' => 'Mme', 'naissance_date' => '1994-06-20', 'naissance_lieu' => 'Sfax']);
        $h = Statuts::html($this->client->fresh(), ['type' => 'sarl', 'date' => '2026-10-01'], '');

        $this->assertStringContainsString('1- Monsieur John Smith, de nationalité britannique, né le 10 juillet 1988 à Tunis', $h);
        $this->assertStringContainsString('2- Madame Leila Trabelsi, née le 20 juin 1994 à Sfax', $h);
        $this->assertStringContainsString('1- Mr John Smith apporte à la présente société', $h);
        $this->assertStringContainsString('<li>40 parts numérotées de 61 à 100 inclus à Mme Leila Trabelsi.</li>', $h);
        $this->assertStringContainsString('Mr John Smith a été désigné gérant statutaire', $h);
        $this->assertStringContainsString('<b>LE GÉRANT</b><br>Mr John Smith', $h);
        $this->assertStringNotContainsString('(e)', $h);
    }

    public function test_sarl_gerant_non_associe_et_numerotation_des_parts(): void
    {
        $c = Client::factory()->create(['nom' => 'Beta', 'forme' => 'SARL', 'capital' => 5000, 'valeur_nominale' => 10, 'nb_parts' => 500, 'adresse' => 'Rue Chatt Mariem bureau B1.2 Montplaisir 1073 Tunis.',
            'gerant_nom' => 'Sami Gharbi', 'gerant_cin' => '01234567', 'gerant_associe' => false, 'gerant_nomination_duree' => null]);
        $c->associes()->create(['nom' => 'A', 'parts' => 300]);
        $c->associes()->create(['nom' => 'B', 'parts' => 200]);
        $h = Statuts::html($c, ['type' => 'sarl', 'date' => '2026-10-01', 'duree' => 50], '');

        $this->assertStringContainsString('300 parts numérotées de 1 à 300 inclus à A.', $h);      // numéros déduits de l'ordre des associés
        $this->assertStringContainsString('200 parts numérotées de 301 à 500 inclus à B.', $h);
        $this->assertStringContainsString('Sami Gharbi, titulaire de la CIN n° 01234567 a été désigné(e) gérant(e) statutaire de la société pour toute la durée de la société', $h);
        $this->assertStringContainsString('La nomination du gérant est faite pour une durée de 50 ans', $h);
        $this->assertStringContainsString('Fait à TUNIS en autant', $h);
        $this->assertStringContainsString('<li>[objet social à préciser].</li>', $h);
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
