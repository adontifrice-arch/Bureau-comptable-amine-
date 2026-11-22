<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Facture;
use App\Models\Setting;
use App\Models\User;
use App\Services\ElFatoora\SignatureXades;
use App\Services\ElFatoora\Teif;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Facture électronique El Fatoora : TEIF 1.8.8 (XSD officiel), signature XAdES-B, dépôt et consultation par web service. */
class ElFatooraTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->chef = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        $this->actingAs($this->chef);
        Setting::put('cabinet', ['nom' => 'Cabinet Test & Associés', 'adresse' => 'Rue du Lac, Tunis', 'mf' => '1234567/A/P/M/000', 'rib' => '08 000 0000000000000 00', 'banque' => 'BIAT']);
    }

    private function facture(array $client = []): Facture
    {
        $c = Client::create(array_merge(['nom' => 'Société Alpha', 'type_personne' => 'morale', 'matricule_fiscal' => '7654321B/A/M/000', 'adresse' => 'Sfax'], $client));
        $f = Facture::create(['type' => 'facture', 'numero' => 'FAC-2026-001', 'client_id' => $c->id, 'date' => '2026-11-20', 'echeance' => '2026-12-20',
            'tva_taux' => 19, 'timbre' => 1, 'statut' => 0]);
        $f->lignes()->create(['designation' => 'Honoraires de tenue comptable', 'quantite' => 1, 'prix_unitaire' => 500, 'ordre' => 0]);
        $f->lignes()->create(['designation' => 'Déclarations fiscales <mensuelles>', 'quantite' => 2, 'prix_unitaire' => 75.5, 'ordre' => 1]);
        return $f->fresh(['client', 'lignes']);
    }

    /** Certificat auto-signé de test au format PKCS#12. */
    private function p12(string $mdp = 'secret', int $jours = 365): string
    {
        // Configuration OpenSSL minimale (absente par défaut sous Windows)
        $cnf = sys_get_temp_dir().DIRECTORY_SEPARATOR.'elfatoora-test-openssl.cnf';
        file_put_contents($cnf, "[req]\ndistinguished_name = dn\n[dn]\n[v3]\nbasicConstraints = CA:FALSE\nkeyUsage = digitalSignature, nonRepudiation\n");
        $o = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'x509_extensions' => 'v3'];
        $cle = openssl_pkey_new($o);
        $csr = openssl_csr_new(['countryName' => 'TN', 'organizationName' => 'Cabinet Test', 'commonName' => 'Signataire Test'], $cle, $o);
        $cert = openssl_csr_sign($csr, null, $cle, $jours, $o, 4242);
        openssl_pkcs12_export($cert, $p12, $cle, $mdp);
        return $p12;
    }

    /** Web service simulé : schéma indisponible (noms de paramètres de la spécification), réponses SOAP par opération. */
    private function simuler(string $depot, string $consultation): void
    {
        $env = fn ($b) => Http::response('<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body>'.$b.'</S:Body></S:Envelope>');
        Http::fake(fn ($r) => $r->method() === 'GET' ? Http::response('', 404) : (str_contains($r->body(), 'saveEfact') ? $env($depot) : $env($consultation)));
    }

    public function test_matricule_fiscal_controle_comme_le_xsd_1_8_9(): void
    {
        $this->assertTrue(Teif::mfValide('1234567/A/P/M/000'));
        $this->assertTrue(Teif::mfValide('1234567a'));
        $this->assertFalse(Teif::mfValide('1234567/A/M/000'));   // code TVA manquant
        $this->assertFalse(Teif::mfValide('1234567I'));          // clé I exclue
        $this->assertFalse(Teif::mfValide(''));
    }

    public function test_teif_conforme_au_xsd_officiel(): void
    {
        $xml = Teif::generer($this->facture());
        $this->assertSame([], Teif::erreursXsd($xml));
        $d = new \DOMDocument(); $d->loadXML($xml); $x = new \DOMXPath($d);
        $v = fn ($q) => $x->query($q)->item(0)?->textContent;
        $this->assertSame('1234567APM000', $v('//MessageSenderIdentifier'));
        $this->assertSame('7654321BAM000', $v('//MessageRecieverIdentifier'));
        $this->assertSame('201126', $v('//Dtm/DateText[@functionCode="I-31"]'));
        $this->assertSame('19', $v('//LinTax/TaxDetails/TaxRate'));                   // 5 caractères au plus
        $this->assertSame('151.000', $v('//Lin[2]//Moa[@amountTypeCode="I-171"]/Amount'));
        $this->assertSame('651.000', $v('//InvoiceMoa//Moa[@amountTypeCode="I-176"]/Amount'));
        $this->assertSame('123.690', $v('//InvoiceMoa//Moa[@amountTypeCode="I-181"]/Amount'));
        $this->assertSame('775.690', $v('//InvoiceMoa//Moa[@amountTypeCode="I-180"]/Amount'));
        $this->assertSame('1.000', $v('//InvoiceTaxDetails[Tax/TaxTypeName/@code="I-1601"]//Amount'));
        $this->assertSame('Déclarations fiscales <mensuelles>', $v('//Lin[2]//ItemDescription'));
        $this->assertSame([], Teif::anomalies($this->facture(['matricule_fiscal' => '1111111A'])));
    }

    public function test_anomalies_bloquantes(): void
    {
        $f = $this->facture(['matricule_fiscal' => null]);
        $this->assertNotEmpty(Teif::anomalies($f));
        $this->get(route('factures.teif', $f))->assertRedirect()->assertSessionHas('err');
        // Personne physique sans matricule : CIN (I-02)
        $f = $this->facture(['matricule_fiscal' => null, 'type_personne' => 'physique', 'gerant_cin' => '01234567', 'nom' => 'Mme X']);
        $this->assertSame([], Teif::anomalies($f));
        $this->assertStringContainsString('<MessageRecieverIdentifier type="I-02">01234567</MessageRecieverIdentifier>', Teif::generer($f));
    }

    public function test_signature_xades_verifiee_et_alteration_detectee(): void
    {
        $p = SignatureXades::lireP12($this->p12(), 'secret');
        $signe = SignatureXades::signer(Teif::generer($this->facture()), $p['cert'], $p['cle'], $p['chaine']);
        $this->assertSame([], SignatureXades::verifier($signe));
        foreach (['Id="SigFrs"', 'URI="#xades-SigFrs"', 'not(ancestor-or-self::RefTtnVal)', 'urn:2.16.788.1.2.1.3', SignatureXades::POLITIQUE_HASH, '<xades:ClaimedRole>Fournisseur</xades:ClaimedRole>'] as $s) {
            $this->assertStringContainsString($s, $signe);
        }
        $this->assertNotEmpty(SignatureXades::verifier(str_replace('500.000', '50.000', $signe)));
        // L'ajout ultérieur du bloc RefTtnVal par TTN ne casse pas la signature fournisseur
        $avecRef = str_replace('</TEIF>', '<RefTtnVal><ReferenceTTN refID="I-88">123</ReferenceTTN></RefTtnVal></TEIF>', $signe);
        $this->assertSame([], SignatureXades::verifier($avecRef));
        $this->expectException(\RuntimeException::class);
        SignatureXades::signer($signe, $p['cert'], $p['cle']);   // une seule signature admise
    }

    public function test_certificat_expire_refuse(): void
    {
        $p = SignatureXades::lireP12($this->p12(), 'secret');
        $this->expectExceptionMessage('n’est pas valide');
        SignatureXades::signer(Teif::generer($this->facture()), $p['cert'], $p['cle'], [], 'Fournisseur', strtotime('+2 years'));
    }

    public function test_parcours_complet_signature_depot_validation(): void
    {
        $this->post(route('parametres.elfatoora.certificat'), ['certificat' => UploadedFile::fake()->createWithContent('cabinet.p12', $this->p12()), 'certificat_mdp' => 'faux'])
            ->assertSessionHas('err');
        $this->post(route('parametres.elfatoora.certificat'), ['certificat' => UploadedFile::fake()->createWithContent('cabinet.p12', $this->p12()), 'certificat_mdp' => 'secret'])
            ->assertSessionHas('ok');
        $this->post(route('parametres.elfatoora.acces'), ['login' => 'cabinet', 'mdp' => 'mdp-ttn', 'url' => 'https://test.elfatoora.tn/ElfatouraServices/EfactService', 'role' => ''])
            ->assertSessionHas('ok');
        $this->get(route('parametres.index', ['tab' => 'elfatoora']))->assertOk()->assertSee('Signataire Test');

        $f = $this->facture();
        $this->post(route('factures.teif.signer', $f))->assertSessionHas('ok');
        $f->refresh();
        $this->assertSame('signee', $f->teif_statut);
        $signe = Storage::disk('local')->get($f->teif_fichier);
        $this->assertSame([], SignatureXades::verifier($signe));
        $this->get(route('factures.teif.signe', $f))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="FAC-2026-001.xml"');

        $valide = str_replace('</TEIF>', '<RefTtnVal><ReferenceTTN refID="I-88">0736202XAM000535629</ReferenceTTN><ReferenceCEV>iVBORw0KGgo=</ReferenceCEV></RefTtnVal></TEIF>', $signe);
        $this->simuler('<ns2:saveEfactResponse xmlns:ns2="http://services.elfatoura.tradenet.com.tn/"><return>Facture reçue : 98765</return></ns2:saveEfactResponse>',
            '<ns2:consultEfactResponse xmlns:ns2="http://services.elfatoura.tradenet.com.tn/"><return><documentNumber>FAC-2026-001</documentNumber><generatedRef>0736202XAM000535629</generatedRef><xmlContent>'.base64_encode($valide).'</xmlContent></return></ns2:consultEfactResponse>');
        $this->post(route('factures.teif.deposer', $f))->assertSessionHas('ok');
        $this->assertSame('deposee', $f->fresh()->teif_statut);
        Http::assertSent(fn ($r) => str_contains($r->body(), '<login>cabinet</login>') && str_contains($r->body(), '<matricule>1234567APM000</matricule>')
            && str_contains($r->body(), '<documentEfact>'.base64_encode($signe).'</documentEfact>'));

        $this->post(route('factures.teif.consulter', $f))->assertSessionHas('ok');
        $f->refresh();
        $this->assertSame(['validee', '0736202XAM000535629', 'iVBORw0KGgo='], [$f->teif_statut, $f->teif_reference, $f->teif_qr]);
        $this->get(route('factures.show', $f))->assertOk()->assertSee('0736202XAM000535629')->assertSee('data:image/png;base64,iVBORw0KGgo=', false);

        // Facture transmise : ni modification ni suppression
        $this->get(route('factures.edit', $f))->assertRedirect(route('factures.show', $f));
        $this->delete(route('factures.destroy', $f))->assertSessionHas('err');
        $this->assertNotNull($f->fresh());
    }

    public function test_rejet_puis_modification_annule_la_signature(): void
    {
        $this->post(route('parametres.elfatoora.certificat'), ['certificat' => UploadedFile::fake()->createWithContent('c.pfx', $this->p12()), 'certificat_mdp' => 'secret']);
        $this->post(route('parametres.elfatoora.acces'), ['login' => 'cabinet', 'mdp' => 'x']);
        $f = $this->facture();
        $this->post(route('factures.teif.signer', $f));
        $this->simuler('<saveEfactResponse><return>OK</return></saveEfactResponse>',
            '<consultEfactResponse><return><documentNumber>FAC-2026-001</documentNumber><listAcknowlegments><message>Matricule fiscal du client inconnu</message></listAcknowlegments></return></consultEfactResponse>');
        $this->post(route('factures.teif.deposer', $f));
        $this->post(route('factures.teif.consulter', $f))->assertSessionHas('ok');
        $this->assertSame('rejetee', $f->fresh()->teif_statut);
        $this->assertStringContainsString('Matricule fiscal du client inconnu', $f->fresh()->teif_message);

        $this->put(route('factures.update', $f), ['type' => 'facture', 'numero' => 'FAC-2026-001', 'client_id' => $f->client_id, 'date' => '2026-11-20', 'tva_taux' => 19, 'timbre' => 1,
            'lignes' => [['designation' => 'Honoraires', 'quantite' => 1, 'prix_unitaire' => 600]]])->assertSessionHas('ok');
        $f->refresh();
        $this->assertNull($f->teif_statut);
        $this->assertNull($f->teif_fichier);
    }

    public function test_sans_certificat_ni_acces(): void
    {
        $f = $this->facture();
        $this->post(route('factures.teif.signer', $f))->assertSessionHas('err', fn ($m) => str_contains($m, 'Aucun certificat'));
        $this->assertNull($f->fresh()->teif_statut);
    }
}
