<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Liasse;
use App\Models\User;
use App\Services\Compta;
use App\Services\LiasseFiscale;
use App\Services\Xsd11;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Liasse fiscale XML (cahier des charges CIMF, cas général) : F6001 à F6005 depuis la balance, saisies, contrôles, fichiers. */
class LiasseFiscaleTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->actingAs(User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']));
        $this->client = Client::factory()->create(['nom' => 'ASJB TENNIS CLUB', 'forme' => 'SARL', 'adresse' => 'Rue de Tunis', 'matricule_fiscal' => '1874290/R/A/M/000']);
        session(['dossier' => $this->client->id]);

        $e = fn ($date, $lignes) => Compta::passer($this->client->id, $date, 'OD', 'Test', $lignes);
        // 2023 : résultat 1 400
        $e('2023-01-02', [['compte' => '532', 'debit' => 5000], ['compte' => '101', 'credit' => 5000]]);
        $e('2023-02-01', [['compte' => '2282', 'debit' => 3000], ['compte' => '404', 'credit' => 3000]]);
        $e('2023-12-31', [['compte' => '6811', 'debit' => 600], ['compte' => '28282', 'credit' => 600]]);
        $e('2023-06-30', [['compte' => '411', 'debit' => 2000], ['compte' => '705', 'credit' => 2000]]);
        // 2024 : résultat 2 050
        $e('2024-01-10', [['compte' => '532', 'debit' => 2000], ['compte' => '411', 'credit' => 2000]]);
        $e('2024-01-15', [['compte' => '404', 'debit' => 3000], ['compte' => '532', 'credit' => 3000]]);
        $e('2024-03-01', [['compte' => '601', 'debit' => 1000], ['compte' => '401', 'credit' => 1000]]);
        $e('2024-12-31', [['compte' => '31', 'debit' => 300], ['compte' => '6031', 'credit' => 300]]);
        $e('2024-06-30', [['compte' => '411', 'debit' => 4200], ['compte' => '705', 'credit' => 4200]]);
        $e('2024-12-31', [['compte' => '6811', 'debit' => 600], ['compte' => '28282', 'credit' => 600]]);
        $e('2024-11-30', [['compte' => '640', 'debit' => 500], ['compte' => '425', 'credit' => 500]]);
        $e('2024-12-31', [['compte' => '6511', 'debit' => 50], ['compte' => '532', 'credit' => 50]]);
        $e('2024-12-31', [['compte' => '691', 'debit' => 200], ['compte' => '4343', 'credit' => 200]]);
        $e('2024-09-01', [['compte' => '67', 'debit' => 100], ['compte' => '532', 'credit' => 100]]);
        $e('2024-04-01', [['compte' => '54', 'debit' => 1000], ['compte' => '1621', 'credit' => 1000]]);
    }

    public function test_etat_de_resultat_f6003(): void
    {
        $R = LiasseFiscale::calculer($this->client, 2024)['docs']['F6003'];
        $n = $R['n'];
        $this->assertSame([4200.0, 4200.0, 1000.0, -300.0, 700.0, 500.0, 600.0], [$n[11], $n[1], $n[30], $n[34], $n[29], $n[36], $n[47]]);
        $this->assertSame([1800.0, 2400.0, 50.0, 2350.0, 200.0, 2150.0, -100.0, 2050.0, 2050.0], [$n[20], $n[61], $n[62], $n[76], $n[77], $n[80], $n[81], $n[84], $n[89]]);
        $this->assertSame(1400.0, $R['n1'][89]);
    }

    public function test_bilan_f6001_f6002_et_saisie_qui_remplace_la_balance(): void
    {
        $L = LiasseFiscale::calculer($this->client, 2024);
        [$A, $P] = [$L['docs']['F6001'], $L['docs']['F6002']];
        $this->assertSame([3000.0, 1200.0, 1800.0], [$A['brut'][17], $A['amort'][17], $A['net'][17]]);
        $this->assertSame([300.0, 4200.0, 3850.0, 1000.0, 11150.0], [$A['net'][38], $A['net'][45], $A['net'][65], $A['net'][66], $A['net'][68]]);
        $this->assertSame([5000.0, 1000.0, 1000.0, 500.0, 200.0, 2050.0], [$P['n'][2], $P['n'][12], $P['n'][33], $P['n'][40], $P['n'][41], $P['n'][7]]);
        // Résultat 2023 repris en résultats reportés : N et N-1 équilibrés
        $this->assertSame([9400.0, 9400.0], [$A['net1'][68], $P['n1'][53]]);
        $this->assertSame([1400.0, 11150.0], [$P['n'][5], $P['n'][53]]);
        $this->assertTrue($L['controles'][1][1]);

        // Saisie : remplace la balance (lignes de total ignorées), totaux recalculés, contrôle en défaut
        $this->post(route('liasse.lf.saisie'), ['annee' => 2024, 'doc' => 'F6002', 'v' => ['n' => [5 => '1000', 53 => '1', 2 => '']]])->assertRedirect();
        $this->assertEquals([5 => 1000], Liasse::where('client_id', $this->client->id)->first()->saisies['F6002']['n']);
        $L = LiasseFiscale::calculer($this->client, 2024);
        $this->assertSame([5000.0, 10750.0], [$L['docs']['F6002']['n'][2], $L['docs']['F6002']['n'][53]]);
        $this->assertFalse($L['controles'][1][1]);

        $this->post(route('liasse.lf.saisie'), ['annee' => 2024, 'doc' => 'F6002', 'reinit' => 1]);
        $this->assertSame(11150.0, LiasseFiscale::calculer($this->client, 2024)['docs']['F6002']['n'][53]);
    }

    public function test_resultat_fiscal_f6005(): void
    {
        // Bénéfice : réintégrations, provisions déductibles plafonnées à 50 %, déficits reportés
        $v = LiasseFiscale::resultatFiscal(10000, [9 => 500, 23 => 300, 35 => 2000, 53 => 800, 57 => 6000, 65 => 1000]);
        $this->assertSame([800.0, 2000.0, 2800.0, 800.0, 12000.0, 12000.0], [$v[3], $v[33], $v[45], $v[54], $v[55], $v[56]]);
        $this->assertSame([6000.0, 6000.0, 5000.0, 5000.0, 5000.0, 0.0], [$v[61], $v[63], $v[68], $v[80], $v[101], $v[107]]);

        // Déficit (cas 1) : lignes 56 à 101 à zéro, déficit reportable
        $v = LiasseFiscale::resultatFiscal(-3000, [44 => 500, 66 => 999, 104 => 200]);
        $this->assertSame([-2500.0, 0.0, 0.0, -2500.0, -2700.0], [$v[55], $v[56], $v[66], $v[102], $v[107]]);

        // Réintégrations par défaut : tableau de passage simplifié ; résultat avant impôt (2 050 + IS 200)
        $this->post(route('liasse.passage'), ['annee' => 2024, 'rei_l' => ['r1_17' => 100], 'rei_autres' => [['lib' => 'Autre', 'm' => 50]], 'ded_l' => ['d2' => 50]])->assertSessionHasNoErrors();
        $F = LiasseFiscale::calculer($this->client, 2024)['docs']['F6005']['n'];
        // Chaque ligne du passage à sa ligne F6005 : amendes et pénalités (23), autres réintégrations (44), reprise sur provisions (47)
        $this->assertSame([2250.0, 100.0, 50.0, 150.0, 50.0, 50.0, 2350.0, 2350.0], [$F[2], $F[23], $F[44], $F[45], $F[47], $F[54], $F[55], $F[101]]);
    }

    public function test_fichiers_xml_et_archive(): void
    {
        $this->assertSame('1874290RAM000', LiasseFiscale::matricule('1874290/R/A/M/000'));
        $this->assertNull(LiasseFiscale::matricule('1874290/R/X/M/000'));

        $this->post(route('liasse.lf.entete'), ['annee' => 2024, 'activite' => 'Club de tennis', 'acte' => '0', 'nature' => 'P', 'unite' => 'millimes', 'categorie' => 'M', 'forme' => 'SC'])->assertRedirect();
        $r = $this->get(route('liasse.lf.xml', ['doc' => 'F6001', 'annee' => 2024]))->assertOk();
        $this->assertStringContainsString('F6001-1874290R-2024.xml', $r->headers->get('Content-Disposition'));
        $x = $r->getContent();
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $x);
        foreach (['<lf:F6001 xmlns:lf="http://www.impots.finances.gov.tn/liasse"', 'xsi:schemaLocation="http://www.impots.finances.gov.tn/liasse F6001.xsd"',
            '<lf:MatriculeFiscalDeclarant>1874290RAM000</lf:MatriculeFiscalDeclarant>', '<lf:Activite>Club de tennis</lf:Activite>', '<lf:Adresse>Rue de Tunis</lf:Adresse>',
            '<lf:DateClotureExercice>31/12/2024</lf:DateClotureExercice>', '<lf:NatureDepot>P</lf:NatureDepot>',
            '<lf:F60010017>3000000</lf:F60010017>', '<lf:F60011017>1200000</lf:F60011017>', '<lf:F60012068>11150000</lf:F60012068>', '<lf:F60013068>9400000</lf:F60013068>'] as $attendu) {
            $this->assertStringContainsString($attendu, $x);
        }
        $this->assertNotFalse(simplexml_load_string($x));
        // Même structure que les fichiers produits par l'outil de la DGI : feuille de style, VersionDocument, Entete, Details
        $this->assertMatchesRegularExpression('#^<\?xml[^>]*\?>\s*<\?xml-stylesheet type="text/xsl" href="F6001.xsl"\?>\s*<lf:F6001 [^>]*>\s*<lf:VersionDocument>1</lf:VersionDocument>\s*<lf:Entete>#', $x);
        $this->assertMatchesRegularExpression('#</lf:Entete>\s*<lf:Details>\s*<lf:F60010001>.*<lf:F60013068>9400000</lf:F60013068>\s*</lf:Details>\s*</lf:F6001>\s*$#s', $x);

        // F6002 : colonne N-1 codée comme F6003 (même chiffre, lignes décalées de 53)
        $x = $this->get(route('liasse.lf.xml', ['doc' => 'F6002', 'annee' => 2024]))->getContent();
        $this->assertStringContainsString('<lf:F60020053>11150000</lf:F60020053>', $x);
        $this->assertStringContainsString('<lf:F60020106>9400000</lf:F60020106>', $x);
        $this->assertStringNotContainsString('<lf:F60021', $x);

        // F6004 : modèle autorisé
        $x = $this->get(route('liasse.lf.xml', ['doc' => 'F6004', 'annee' => 2024]))->getContent();
        $this->assertStringContainsString('xsi:schemaLocation="http://www.impots.finances.gov.tn/liasse F6004-MODELE-AUT.xsd"', $x);

        $x = $this->get(route('liasse.lf.xml', ['doc' => 'F6003', 'annee' => 2024]))->getContent();
        $this->assertStringContainsString('<lf:F60030089>2050000</lf:F60030089>', $x);
        $this->assertStringContainsString('<lf:F60030178>1400000</lf:F60030178>', $x);

        $x = $this->get(route('liasse.lf.xml', ['doc' => 'F6005', 'annee' => 2024]))->getContent();
        $this->assertStringContainsString('<lf:F60050000 categorie="M" codeformejuridique="SC"/>', $x);
        $this->assertStringContainsString('<lf:F60050001 resultat="B" F60050002="2250000"/>', $x);
        $this->assertStringContainsString('<lf:F60050101>2250000</lf:F60050101>', $x);
        $this->assertStringContainsString('<lf:F60050055 resultat="B" F60050955="2250000"/>', $x);
        $this->assertMatchesRegularExpression('#<lf:F60051055 resultat="[BP]" F60051955="\d+"/>#', $x);

        $r = $this->get(route('liasse.lf.zip', ['annee' => 2024]))->assertOk();
        $zip = new \ZipArchive();
        $zip->open($r->baseResponse->getFile()->getPathname());
        $noms = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $this->assertSame(['F6001-1874290R-2024.xml', 'F6002-1874290R-2024.xml', 'F6003-1874290R-2024.xml', 'F6004-1874290R-2024.xml', 'F6005-1874290R-2024.xml'], $noms);
        $zip->close();
    }

    public function test_bouton_valider_controle_selon_le_cahier_des_charges(): void
    {
        // Schémas officiels livrés avec l'application (XSD 1.1)
        $this->get(route('liasse.index', ['tab' => 'lf', 'annee' => 2024]))->assertSee('Valider')->assertSee('F6001, F6002, F6003, F6004-MODELE-AUT, F6005');

        // Activité non renseignée : anomalie d'entête sur chaque fichier
        $this->post(route('liasse.lf.valider'), ['annee' => 2024])->assertRedirect()->assertSessionHas('validation_ko');
        $v = session('validation');
        $this->assertSame(LiasseFiscale::DOCUMENTS, array_keys($v));
        $this->assertStringContainsString('Activité', implode(' ', $v['F6001']['erreurs']));
        $this->assertTrue($v['F6001']['xsd']);

        // Entête complète : liasse conforme
        $this->post(route('liasse.lf.entete'), ['annee' => 2024, 'activite' => 'Club de tennis', 'acte' => '0', 'nature' => 'D', 'unite' => 'millimes', 'categorie' => 'M', 'forme' => 'SC']);
        $this->post(route('liasse.lf.valider'), ['annee' => 2024])->assertSessionHas('ok')->assertSessionMissing('validation_ko');
        $this->assertSame([[], [], [], [], []], array_column(session('validation'), 'erreurs'));
        $this->followRedirects($this->post(route('liasse.lf.valider'), ['annee' => 2024]))->assertSee('Fichiers conformes')->assertSee('F6003-1874290R-2024.xml');

        // Fichier altéré : total faux, montant négatif en zone positive, décimale, élément absent, mauvais nom
        $x = LiasseFiscale::xml('F6001', $this->client, 2024, LiasseFiscale::calculer($this->client, 2024));
        $x = str_replace(['<lf:F60012068>11150000<', '<lf:F60010013>0<', '<lf:F60010014>0<', "<lf:F60010015>0</lf:F60010015>"], ['<lf:F60012068>11150001<', '<lf:F60010013>-5<', '<lf:F60010014>1.5<', ''], $x);
        $e = implode("\n", LiasseFiscale::verifier('F6001', 'F6001-2024.xml', $x)['liste']);
        foreach (['Nom de fichier non conforme', 'F6001 ligne 68', 'F60010013 (Terrains) : montant négatif', 'F60010014 : « 1.5 »', 'Élément F60010015 absent'] as $attendu) {
            $this->assertStringContainsString($attendu, $e);
        }
        $this->assertSame(['Fichier XML mal formé.'], LiasseFiscale::verifier('F6001', 'F6001-1874290R-2024.xml', '<lf:F6001>')['liste']);

        // Structure : montants hors de Details, VersionDocument absent ; F6005 ligne 55 sans attribut de signe
        $x = LiasseFiscale::xml('F6001', $this->client, 2024, LiasseFiscale::calculer($this->client, 2024));
        $x = preg_replace('#\s*<lf:VersionDocument>1</lf:VersionDocument>#', '', str_replace(['<lf:Details>', '</lf:Details>'], '', $x));
        $e = implode("\n", LiasseFiscale::verifier('F6001', 'F6001-1874290R-2024.xml', $x)['liste']);
        $this->assertStringContainsString('Structure attendue : VersionDocument, Entete puis Details', $e);
        $x = str_replace('<lf:F60050055 resultat="B" F60050955="2250000"/>', '<lf:F60050055>2250000</lf:F60050055>',
            LiasseFiscale::xml('F6005', $this->client, 2024, LiasseFiscale::calculer($this->client, 2024)));
        $this->assertStringContainsString('F60050055 : attribut resultat (B/P) invalide', implode("\n", LiasseFiscale::verifier('F6005', 'F6005-1874290R-2024.xml', $x)['liste']));
    }

    public function test_validation_par_le_schema_xsd_depose(): void
    {
        $dir = sys_get_temp_dir().'/lf-xsd-'.uniqid();
        config(['liasse.xsd_dir' => $dir, 'liasse.xsd_integres' => null]);     // schémas déposés seuls
        $this->post(route('liasse.lf.entete'), ['annee' => 2024, 'activite' => 'Club de tennis', 'acte' => '0', 'nature' => 'D', 'unite' => 'millimes', 'categorie' => 'M', 'forme' => 'SC']);
        $ns = 'http://www.impots.finances.gov.tn/liasse';
        $schema = fn (string $racine, string $contenu) => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="'.$ns.'" elementFormDefault="qualified">'
            .'<xs:element name="'.$racine.'"><xs:complexType><xs:sequence>'.$contenu.'</xs:sequence></xs:complexType></xs:element></xs:schema>';

        // Dépôt par archive ZIP : seuls les .xsd sont gardés
        $zip = tempnam(sys_get_temp_dir(), 'z');
        $z = new \ZipArchive(); $z->open($zip, \ZipArchive::OVERWRITE);
        $z->addFromString('schemas/F6001_2015.xsd', $schema('F6001', '<xs:any processContents="skip" maxOccurs="unbounded"/>'));
        $z->addFromString('lisez-moi.txt', 'texte');
        $z->close();
        $this->post(route('liasse.lf.xsd'), ['fichiers' => [new \Illuminate\Http\UploadedFile($zip, 'xsd.zip', 'application/zip', null, true)]])->assertSessionHas('ok');
        $this->assertFileExists($dir.'/F6001_2015.xsd');
        $this->assertFileDoesNotExist($dir.'/lisez-moi.txt');

        // Schéma F6002 strict (n'admet que l'entête) : le fichier généré est rejeté par le XSD
        file_put_contents($dir.'/F6002.xsd', $schema('F6002', '<xs:any processContents="skip" maxOccurs="1"/>'));
        $this->post(route('liasse.lf.valider'), ['annee' => 2024]);
        $v = session('validation');
        $this->assertTrue($v['F6001']['xsd']);
        $this->assertSame([], $v['F6001']['erreurs']);
        $this->assertTrue($v['F6002']['xsd']);
        $this->assertStringStartsWith('XSD ligne', $v['F6002']['erreurs'][0]);
        $this->assertFalse($v['F6003']['xsd']);

        // F6004 : seul le schéma du modèle autorisé s'applique (le modèle de référence F6004.xsd est ignoré)
        file_put_contents($dir.'/F6004.xsd', $schema('F6004', '<xs:any processContents="skip" maxOccurs="1"/>'));
        $this->assertNull(LiasseFiscale::xsd('F6004'));
        file_put_contents($dir.'/F6004-MODELE-AUT.xsd', $schema('F6004', '<xs:any processContents="skip" maxOccurs="unbounded"/>'));
        $this->assertSame($dir.'/F6004-MODELE-AUT.xsd', LiasseFiscale::xsd('F6004'));
        $this->post(route('liasse.lf.valider'), ['annee' => 2024]);
        $this->assertSame([], session('validation')['F6004']['erreurs']);

        $this->post(route('liasse.lf.xsd'), ['fichiers' => [\Illuminate\Http\UploadedFile::fake()->createWithContent('notes.txt', 'x')]])->assertSessionHasErrors('fichiers');
        array_map('unlink', glob($dir.'/*')); rmdir($dir);
    }

    public function test_schemas_officiels_xsd_11(): void
    {
        $this->post(route('liasse.lf.entete'), ['annee' => 2024, 'activite' => 'Club de tennis', 'acte' => '0', 'nature' => 'D', 'unite' => 'millimes', 'categorie' => 'M', 'forme' => 'SC']);
        $L = LiasseFiscale::calculer($this->client, 2024);
        $xsd = fn ($c) => LiasseFiscale::xsd($c);
        $this->assertStringEndsWith('F6004-MODELE-AUT.xsd', $xsd('F6004'));

        // Les cinq fichiers générés respectent les schémas officiels : types, formules de totalisation, règles du F6005
        foreach (LiasseFiscale::DOCUMENTS as $c) $this->assertSame([], Xsd11::valider(LiasseFiscale::xml($c, $this->client, 2024, $L), $xsd($c)), $c);

        // Total faux : la formule du schéma le signale avec les montants lus et attendus
        $x = str_replace('<lf:F60012068>11150000<', '<lf:F60012068>11150001<', LiasseFiscale::xml('F6001', $this->client, 2024, $L));
        $e = implode("\n", Xsd11::valider($x, $xsd('F6001')));
        $this->assertStringContainsString('Règle XSD non respectée : F60012068 = sum(', $e);
        $this->assertStringContainsString('(lu 11150001, attendu 11150000)', $e);

        // Déficit : montant du résultat en négatif (type conditionnel PerteComptable / PerteFiscal), lignes 56 à 101 à zéro
        $L['docs']['F6005']['n'] = LiasseFiscale::resultatFiscal(-3000, [44 => 500, 104 => 200]);
        $x = LiasseFiscale::xml('F6005', $this->client, 2024, $L);
        $this->assertStringContainsString('<lf:F60050001 resultat="P" F60050002="-3000000"/>', $x);
        $this->assertStringContainsString('<lf:F60050055 resultat="P" F60050955="-2500000"/>', $x);
        $this->assertSame([], Xsd11::valider($x, $xsd('F6005')));
        $this->assertSame([], LiasseFiscale::verifier('F6005', 'F6005-1874290R-2024.xml', $x)['liste']);

        // Cas 2 à 4 : bénéfice absorbé par les déficits reportés
        $L2 = $L;
        $L2['docs']['F6005']['n'] = LiasseFiscale::resultatFiscal(1000, [65 => 5000]);
        $this->assertSame([], Xsd11::valider(LiasseFiscale::xml('F6005', $this->client, 2024, $L2), $xsd('F6005')));

        // Perte déclarée avec un montant positif : refusée par le type conditionnel et par le contrôle
        $x = str_replace('resultat="P" F60050002="-3000000"', 'resultat="P" F60050002="3000000"', $x);
        $this->assertStringContainsString('F60050002', implode("\n", Xsd11::valider($x, $xsd('F6005'))));
        $this->assertStringContainsString('F60050001 : montant F60050002 invalide', implode("\n", LiasseFiscale::verifier('F6005', 'F6005-1874290R-2024.xml', $x)['liste']));

        // Règle conditionnelle : forme juridique incompatible avec la catégorie
        $x = str_replace('categorie="M" codeformejuridique="SC"', 'categorie="C" codeformejuridique="SC"', LiasseFiscale::xml('F6005', $this->client, 2024, LiasseFiscale::calculer($this->client, 2024)));
        $this->assertStringContainsString('F60050000/@codeformejuridique', implode("\n", Xsd11::valider($x, $xsd('F6005'))));
    }

    public function test_evaluateur_xpath_des_assertions(): void
    {
        $d = new \DOMDocument();
        $d->loadXML('<r xmlns:lf="urn:x"><lf:a>5</lf:a><lf:b>-2</lf:b><lf:c t="P" m="-7">3</lf:c></r>');
        $v = fn ($e) => Xsd11::evaluer($e, $d->documentElement);
        $this->assertSame([true], $v('lf:a = sum( ((lf:b) , (-1)*(lf:b) , (lf:a)))'));
        $this->assertSame([10], $v('lf:a - (if (lf:b >= 0) then 0 else lf:b) + 3'));
        $this->assertSame([true], $v("lf:c/@t eq 'P' and lf:c/@m < 0 or 1 = 2"));
        $this->assertSame(['PP'], $v("if (lf:c/@t = 'B') then ('SC') else (if (lf:c/@t = 'P') then ( 'PP' ) else ())"));
        $this->assertSame([], $v('lf:absent'));
        $this->assertSame([0], $v('sum(lf:absent)'));
        $this->assertSame([false], $v('lf:absent = 0'));
    }

    public function test_ecran_liasse_fiscale(): void
    {
        $this->get(route('liasse.index', ['tab' => 'lf', 'annee' => 2024]))->assertOk()
            ->assertSee('Entête du dépôt')->assertSee('F6001')->assertSee('Investissement recherche et développement')->assertSee('Contrôles');
        foreach (['F6002', 'F6003', 'F6004', 'F6005'] as $doc) {
            $this->get(route('liasse.index', ['tab' => 'lf', 'doc' => $doc, 'annee' => 2024]))->assertOk()->assertSee(LiasseFiscale::document($doc)['titre']);
        }
    }
}
