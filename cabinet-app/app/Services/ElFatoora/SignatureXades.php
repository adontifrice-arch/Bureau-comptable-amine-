<?php

namespace App\Services\ElFatoora;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Signature XAdES-B enveloppée d'une facture TEIF, conforme aux « Spécifications techniques de la signature
 * électronique pour la plateforme El Fatoora » v3.0 (29/05/2026) : Id « SigFrs », RSA-SHA256, C14N exclusive,
 * transformations XPath excluant ds:Signature et RefTtnVal, politique urn:2.16.788.1.2.1.3.
 */
class SignatureXades
{
    public const DS = 'http://www.w3.org/2000/09/xmldsig#';
    public const XADES = 'http://uri.etsi.org/01903/v1.3.2#';
    public const C14N_EXCL = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    public const XPATH = 'http://www.w3.org/TR/1999/REC-xpath-19991116';
    public const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    public const POLITIQUE_OID = 'urn:2.16.788.1.2.1.3';
    public const POLITIQUE_DESCRIPTION = 'Politique de Signature Electronique de Tunisie TradeNet';
    public const POLITIQUE_URI = 'https://www.tradenet.com.tn/Politique_Signature_Electronique_Tunisie_TradeNet.pdf';
    /** SHA-256 (base 64) du PDF de la politique de signature de TTN (identique à la valeur des spécifications v3.0). */
    public const POLITIQUE_HASH = 'ZKLu5TojntPu+bUfZyjaEDvkYsAh7eyyV+Hf8nUSQEE=';

    /** Lit un certificat PKCS#12 (.p12 / .pfx) : ['cert' => PEM, 'cle' => PEM, 'chaine' => [PEM…], 'infos' => […]]. */
    public static function lireP12(string $contenu, string $motDePasse): array
    {
        $c = [];
        if (! openssl_pkcs12_read($contenu, $c, $motDePasse)) {
            throw new RuntimeException('Certificat illisible : mot de passe incorrect ou fichier .p12/.pfx invalide.');
        }
        $x = openssl_x509_parse($c['cert']);
        return ['cert' => $c['cert'], 'cle' => $c['pkey'], 'chaine' => array_values($c['extracerts'] ?? []), 'infos' => [
            'titulaire' => $x['subject']['CN'] ?? '', 'organisation' => $x['subject']['O'] ?? '', 'emetteur' => $x['issuer']['CN'] ?? '',
            'debut' => date('Y-m-d', $x['validFrom_time_t']), 'fin' => date('Y-m-d', $x['validTo_time_t']), 'serie' => $x['serialNumberHex'] ?? '',
        ]];
    }

    private static function der(string $pem): string
    {
        return base64_decode(preg_replace('#-----[^-]+-----|\s#', '', $pem));
    }

    /** Lit un élément ASN.1 DER à la position $p : [tag, début du contenu, longueur du contenu, longueur totale]. */
    private static function tlv(string $der, int $p): array
    {
        $tag = ord($der[$p]); $l = ord($der[$p + 1]); $h = 2;
        if ($l & 0x80) {
            $n = $l & 0x7F; $l = 0;
            for ($i = 0; $i < $n; $i++) $l = ($l << 8) | ord($der[$p + 2 + $i]);
            $h += $n;
        }
        return [$tag, $p + $h, $l, $h + $l];
    }

    private static function longueur(int $n): string
    {
        if ($n < 0x80) return chr($n);
        $s = ''; while ($n > 0) { $s = chr($n & 0xFF).$s; $n >>= 8; }
        return chr(0x80 | strlen($s)).$s;
    }

    /** IssuerSerialV2 (RFC 5035) : SEQUENCE { GeneralNames { [4] issuer }, serialNumber }, encodé DER puis base 64. */
    public static function issuerSerialV2(string $certPem): string
    {
        $der = self::der($certPem);
        [, $c] = self::tlv($der, 0);              // Certificate
        [, $p] = self::tlv($der, $c);             // tbsCertificate
        [$tag, , , $n] = self::tlv($der, $p);
        if ($tag === 0xA0) $p += $n;             // version [0]
        [, , , $n] = self::tlv($der, $p);
        $serie = substr($der, $p, $n); $p += $n; // serialNumber
        [, , , $n] = self::tlv($der, $p); $p += $n; // signature AlgorithmIdentifier
        [, , , $n] = self::tlv($der, $p);
        $emetteur = substr($der, $p, $n);        // issuer Name
        $dn = "\xA4".self::longueur(strlen($emetteur)).$emetteur;
        $gn = "\x30".self::longueur(strlen($dn)).$dn;
        $corps = $gn.$serie;
        return base64_encode("\x30".self::longueur(strlen($corps)).$corps);
    }

    /** Ensemble de nœuds signé par la référence « r-id-frs » : tout le document sauf ds:Signature et RefTtnVal, C14N exclusive. */
    public static function canoniserDocument(DOMDocument $d, bool $sansRefTtn = true): string
    {
        return $d->C14N(true, false, ['query' => '(//. | //@* | //namespace::*)[not(ancestor-or-self::ds:Signature)'.($sansRefTtn ? ' and not(ancestor-or-self::RefTtnVal)' : '').']',
            'namespaces' => ['ds' => self::DS]]);
    }

    private static function b64sha256(string $s): string
    {
        return base64_encode(hash('sha256', $s, true));
    }

    /**
     * Signe le XML TEIF et renvoie le document signé (à transmettre tel quel, sans reformatage).
     * $role : rôle déclaré du signataire (ClaimedRole), « Fournisseur » par défaut.
     */
    public static function signer(string $xml, string $certPem, string $clePem, array $chaine = [], string $role = 'Fournisseur', ?int $instant = null): string
    {
        $x = openssl_x509_parse($certPem);
        $instant ??= time();
        if (! $x) throw new RuntimeException('Certificat de signature invalide.');
        if ($instant < $x['validFrom_time_t'] || $instant > $x['validTo_time_t']) {
            throw new RuntimeException('Le certificat de signature n’est pas valide à cette date (du '.date('d/m/Y', $x['validFrom_time_t']).' au '.date('d/m/Y', $x['validTo_time_t']).').');
        }
        $cle = openssl_pkey_get_private($clePem);
        if (! $cle || ! openssl_x509_check_private_key($certPem, $cle)) throw new RuntimeException('La clé privée ne correspond pas au certificat.');

        $d = new DOMDocument();
        $d->preserveWhiteSpace = true;
        if (! $d->loadXML($xml)) throw new RuntimeException('XML TEIF invalide.');
        $racine = $d->documentElement;
        $xp = new DOMXPath($d); $xp->registerNamespace('ds', self::DS);
        if ($xp->query('//ds:Signature')->length) throw new RuntimeException('La facture est déjà signée : une seule signature est admise.');

        $empreinteDoc = self::b64sha256(self::canoniserDocument($d));

        $ds = fn (DOMElement $p, string $nom, ?string $t = null, array $a = []) => self::ajouter($d, $p, self::DS, 'ds:'.$nom, $t, $a);
        $xa = fn (DOMElement $p, string $nom, ?string $t = null, array $a = []) => self::ajouter($d, $p, self::XADES, 'xades:'.$nom, $t, $a);

        $sig = $d->createElementNS(self::DS, 'ds:Signature');
        $sig->setAttribute('Id', 'SigFrs');
        $racine->appendChild($sig);
        $si = $ds($sig, 'SignedInfo');
        $ds($si, 'CanonicalizationMethod', null, ['Algorithm' => self::C14N_EXCL]);
        $ds($si, 'SignatureMethod', null, ['Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256']);
        $r1 = $ds($si, 'Reference', null, ['Id' => 'r-id-frs', 'Type' => '', 'URI' => '']);
        $tr = $ds($r1, 'Transforms');
        $ds($ds($tr, 'Transform', null, ['Algorithm' => self::XPATH]), 'XPath', 'not(ancestor-or-self::ds:Signature)');
        $ds($ds($tr, 'Transform', null, ['Algorithm' => self::XPATH]), 'XPath', 'not(ancestor-or-self::RefTtnVal)');
        $ds($tr, 'Transform', null, ['Algorithm' => self::C14N_EXCL]);
        $ds($r1, 'DigestMethod', null, ['Algorithm' => self::SHA256]);
        $ds($r1, 'DigestValue', $empreinteDoc);
        $r2 = $ds($si, 'Reference', null, ['Type' => 'http://uri.etsi.org/01903#SignedProperties', 'URI' => '#xades-SigFrs']);
        $ds($ds($r2, 'Transforms'), 'Transform', null, ['Algorithm' => self::C14N_EXCL]);
        $ds($r2, 'DigestMethod', null, ['Algorithm' => self::SHA256]);
        $dvProps = $ds($r2, 'DigestValue', '');
        $sv = $ds($sig, 'SignatureValue', '', ['Id' => 'value-SigFrs']);

        $xd = $ds($ds($sig, 'KeyInfo'), 'X509Data');
        foreach (array_merge([$certPem], $chaine) as $pem) $ds($xd, 'X509Certificate', base64_encode(self::der($pem)));

        $qp = $d->createElementNS(self::XADES, 'xades:QualifyingProperties');
        $qp->setAttribute('Target', '#SigFrs');
        $ds($sig, 'Object')->appendChild($qp);
        $sp = $xa($qp, 'SignedProperties', null, ['Id' => 'xades-SigFrs']);
        $ssp = $xa($sp, 'SignedSignatureProperties');
        $xa($ssp, 'SigningTime', gmdate('Y-m-d\TH:i:s\Z', $instant));
        $cert = $xa($xa($ssp, 'SigningCertificateV2'), 'Cert');
        $cd = $xa($cert, 'CertDigest');
        $ds($cd, 'DigestMethod', null, ['Algorithm' => 'http://www.w3.org/2000/09/xmldsig#sha1']);
        $ds($cd, 'DigestValue', base64_encode(sha1(self::der($certPem), true)));
        $xa($cert, 'IssuerSerialV2', self::issuerSerialV2($certPem));
        $spi = $xa($xa($ssp, 'SignaturePolicyIdentifier'), 'SignaturePolicyId');
        $pid = $xa($spi, 'SigPolicyId');
        $xa($pid, 'Identifier', self::POLITIQUE_OID, ['Qualifier' => 'OIDAsURN']);
        $xa($pid, 'Description', self::POLITIQUE_DESCRIPTION);
        $ph = $xa($spi, 'SigPolicyHash');
        $ds($ph, 'DigestMethod', null, ['Algorithm' => self::SHA256]);
        $ds($ph, 'DigestValue', self::POLITIQUE_HASH);
        $xa($xa($xa($spi, 'SigPolicyQualifiers'), 'SigPolicyQualifier'), 'SPURI', self::POLITIQUE_URI);
        $xa($xa($xa($ssp, 'SignerRoleV2'), 'ClaimedRoles'), 'ClaimedRole', $role ?: 'Fournisseur');
        $dof = $xa($xa($sp, 'SignedDataObjectProperties'), 'DataObjectFormat', null, ['ObjectReference' => '#r-id-frs']);
        $xa($dof, 'MimeType', 'application/octet-stream');

        $dvProps->appendChild($d->createTextNode(self::b64sha256($sp->C14N(true, false))));
        $valeur = '';
        if (! openssl_sign($si->C14N(true, false), $valeur, $cle, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Échec de la signature RSA-SHA256.');
        $sv->appendChild($d->createTextNode(base64_encode($valeur)));

        return $d->saveXML();
    }

    private static function ajouter(DOMDocument $d, DOMElement $parent, string $ns, string $nom, ?string $texte, array $attr): DOMElement
    {
        $e = $d->createElementNS($ns, $nom);
        foreach ($attr as $k => $v) $e->setAttribute($k, $v);
        if ($texte !== null && $texte !== '') $e->appendChild($d->createTextNode($texte));
        $parent->appendChild($e);
        return $e;
    }

    /**
     * Vérifie la signature fournisseur (SigFrs) d'un TEIF signé : empreintes du document et des propriétés signées,
     * valeur RSA-SHA256 et empreinte du certificat. Renvoie la liste des erreurs (vide = signature valide).
     */
    public static function verifier(string $xml, string $id = 'SigFrs'): array
    {
        $d = new DOMDocument(); $d->preserveWhiteSpace = true;
        if (! @$d->loadXML($xml)) return ['XML illisible.'];
        $x = new DOMXPath($d); $x->registerNamespace('ds', self::DS); $x->registerNamespace('xades', self::XADES);
        $sig = $x->query('//ds:Signature[@Id="'.$id.'"]')->item(0);
        if (! $sig) return ['Signature « '.$id.' » absente.'];
        $e = [];
        $v = fn (string $q) => trim((string) $x->query($q, $sig)->item(0)?->textContent);
        $ref = 'ds:SignedInfo/ds:Reference[@URI=""]';
        $sansRefTtn = str_contains($v($ref.'/ds:Transforms'), 'RefTtnVal');   // la signature de TTN couvre aussi RefTtnVal
        if ($v($ref.'/ds:DigestValue') !== self::b64sha256(self::canoniserDocument($d, $sansRefTtn))) $e[] = 'Le contenu de la facture a été modifié après signature.';
        $sp = $x->query('ds:Object/xades:QualifyingProperties/xades:SignedProperties', $sig)->item(0);
        if (! $sp || $v('ds:SignedInfo/ds:Reference[@URI="#xades-'.$id.'"]/ds:DigestValue') !== self::b64sha256($sp->C14N(true, false))) $e[] = 'Propriétés signées (XAdES) altérées.';
        $certB64 = $v('ds:KeyInfo/ds:X509Data/ds:X509Certificate');
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($certB64, 64, "\n")."-----END CERTIFICATE-----\n";
        if ($v('.//xades:SigningCertificateV2/xades:Cert/xades:CertDigest/ds:DigestValue') !== base64_encode(sha1(base64_decode($certB64), true))) $e[] = 'Empreinte du certificat signataire incorrecte.';
        $si = $x->query('ds:SignedInfo', $sig)->item(0);
        if (openssl_verify($si->C14N(true, false), base64_decode($v('ds:SignatureValue')), $pem, OPENSSL_ALGO_SHA256) !== 1) $e[] = 'Valeur de signature RSA-SHA256 invalide.';
        return $e;
    }
}
