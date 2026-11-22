<?php

namespace App\Services\ElFatoora;

use App\Models\Client;
use App\Models\Facture;
use App\Models\Setting;
use DOMDocument;

/**
 * Facture électronique au format TEIF (Tunisie TradeNet, plateforme El Fatoora), version 1.8.8.
 * Codes : Guide d'implémentation TEIF v2.0, annexe A (référentiels I-0 à I-17).
 */
class Teif
{
    public const VERSION = '1.8.8';

    /** Référentiel I-0 : type d'identifiant du partenaire. */
    public const IDENTIFIANTS = ['I-01' => 'Matricule fiscal tunisien', 'I-02' => 'Carte d’identité nationale', 'I-03' => 'Carte de séjour', 'I-04' => 'Matricule fiscal non tunisien'];

    /** Matricule fiscal sans séparateurs ni espaces, en majuscules (1234567/A/M/000 → 1234567AM000…). */
    public static function normaliserMf(?string $mf): string
    {
        return strtoupper(preg_replace('#[\s/.\-]#', '', (string) $mf));
    }

    /** Contrôle du matricule fiscal tunisien (assertion du XSD 1.8.9) : 7 chiffres + clé, éventuellement + code TVA + catégorie + n° d'établissement. */
    public static function mfValide(?string $mf): bool
    {
        $m = self::normaliserMf($mf);
        return (bool) preg_match('/^(\d{7}[ABCDEFGHJKLMNPQRSTVWXYZ][ABDNP][CMNPE]\d{3}|\d{7}[ABCDEFGHJKLMNPQRSTVWXYZ])$/', $m);
    }

    /** Identifiant TEIF du client : [type I-0x, valeur] ou null s'il n'en a pas de valable. */
    public static function identifiantClient(Client $c): ?array
    {
        if (self::mfValide($c->matricule_fiscal)) return ['I-01', self::normaliserMf($c->matricule_fiscal)];
        if ($c->residence === 'non_residente' && preg_match('/^[A-Za-z0-9]{1,30}$/', $m = self::normaliserMf($c->matricule_fiscal))) return ['I-04', $m];
        $cin = preg_replace('/\D/', '', (string) $c->gerant_cin);
        if ($c->type_personne === 'physique' && strlen($cin) === 8) return ['I-02', $cin];
        if ($c->type_personne === 'physique' && preg_match('/^\d{9}$/', $s = preg_replace('/\D/', '', (string) $c->gerant_carte_sejour))) return ['I-03', $s];
        return null;
    }

    /** Anomalies bloquantes avant génération (la facture ne serait pas acceptée par El Fatoora). */
    public static function anomalies(Facture $f): array
    {
        $cab = Setting::cabinet(); $a = [];
        if ($f->estDevis()) $a[] = 'Un devis ne peut pas être transmis à El Fatoora.';
        if (! self::mfValide($cab['mf'])) $a[] = 'Matricule fiscal du cabinet absent ou invalide (Paramètres › Cabinet ; format 1234567A ou 1234567AAM000).';
        if (! self::identifiantClient($f->client)) $a[] = 'Client « '.$f->client->nom.' » : matricule fiscal absent ou invalide (ou CIN à 8 chiffres pour une personne physique).';
        if ($f->lignes->isEmpty()) $a[] = 'La facture n’a aucune ligne.';
        if (mb_strlen($f->numero) > 70 || str_contains($f->numero, '/')) $a[] = 'Le numéro de facture ne doit pas dépasser 70 caractères ni contenir « / ».';
        return $a;
    }

    /** Taux sur 5 caractères au plus (TaxRate) : 19, 7, 13.5… */
    private static function taux($t): string
    {
        return rtrim(rtrim(number_format((float) $t, 2, '.', ''), '0'), '.') ?: '0';
    }

    private static function montant($n): string
    {
        return number_format((float) $n, 3, '.', '');
    }

    /** XML TEIF non signé (compact, sans indentation : il sera signé tel quel). */
    public static function generer(Facture $f): string
    {
        $f->loadMissing(['client', 'lignes']);
        $cab = Setting::cabinet(); $c = $f->client; $t = $f->totaux();
        $mfCab = self::normaliserMf($cab['mf']);
        [$typeCli, $idCli] = self::identifiantClient($c) ?? ['I-01', self::normaliserMf($c->matricule_fiscal)];

        $d = new DOMDocument('1.0', 'UTF-8');
        $el = function ($parent, string $nom, ?string $texte = null, array $attr = []) use ($d) {
            $n = $d->createElement($nom);
            foreach ($attr as $k => $v) $n->setAttribute($k, $v);
            if ($texte !== null) $n->appendChild($d->createTextNode($texte));
            return $parent->appendChild($n);
        };
        $moa = function ($parent, string $code, $n, ?string $libelle = null) use ($el) {
            $m = $el($parent, 'Moa', null, ['amountTypeCode' => $code, 'currencyCodeList' => 'ISO_4217']);
            $el($m, 'Amount', self::montant($n), ['currencyIdentifier' => 'TND']);
            if ($libelle) $el($m, 'AmountDescription', $libelle, ['lang' => 'fr']);
        };
        $partenaire = function ($parent, string $fonction, string $type, string $id, string $nom, ?string $adresse) use ($el) {
            $p = $el($parent, 'PartnerDetails', null, ['functionCode' => $fonction]);
            $nad = $el($p, 'Nad');
            $el($nad, 'PartnerIdentifier', $id, ['type' => $type]);
            $el($nad, 'PartnerName', mb_substr($nom, 0, 200), ['nameType' => 'Qualification']);
            $a = $el($nad, 'PartnerAdresses', null, ['lang' => 'fr']);
            $el($a, 'AdressDescription', mb_substr((string) $adresse, 0, 500));
            $el($a, 'Country', 'TN', ['codeList' => 'ISO_3166-1']);
            return $p;
        };

        $root = $el($d, 'TEIF', null, ['controlingAgency' => 'TTN', 'version' => self::VERSION]);
        $h = $el($root, 'InvoiceHeader');
        $el($h, 'MessageSenderIdentifier', $mfCab, ['type' => 'I-01']);
        $el($h, 'MessageRecieverIdentifier', $idCli, ['type' => $typeCli]);

        $b = $el($root, 'InvoiceBody');
        $bgm = $el($b, 'Bgm');
        $el($bgm, 'DocumentIdentifier', $f->numero);
        $el($bgm, 'DocumentType', 'Facture', ['code' => 'I-11']);
        $dtm = $el($b, 'Dtm');
        $el($dtm, 'DateText', $f->date->format('dmy'), ['format' => 'ddMMyy', 'functionCode' => 'I-31']);           // date d'émission
        if ($f->echeance) $el($dtm, 'DateText', $f->echeance->format('dmy'), ['format' => 'ddMMyy', 'functionCode' => 'I-32']); // date limite de paiement

        $ps = $el($b, 'PartnerSection');
        $partenaire($ps, 'I-62', 'I-01', $mfCab, $cab['nom'], $cab['adresse']);   // fournisseur
        $partenaire($ps, 'I-64', $typeCli, $idCli, $c->nom, $c->adresse);          // client

        if ($cab['rib'] || $cab['iban']) {
            $det = $el($el($b, 'PytSection'), 'PytSectionDetails');
            $pyt = $el($det, 'Pyt');
            $el($pyt, 'PaymentTearmsTypeCode', 'I-114');                                // par virement bancaire
            $el($pyt, 'PaymentTearmsDescription', mb_substr('Paiement par virement'.($cab['banque'] ? ' – '.$cab['banque'] : '').' – RIB '.($cab['rib'] ?: $cab['iban']), 0, 500));
        }

        $ls = $el($b, 'LinSection');
        foreach ($f->lignes->values() as $i => $l) {
            $lin = $el($ls, 'Lin');
            $el($lin, 'ItemIdentifier', (string) ($i + 1));
            $imd = $el($lin, 'LinImd', null, ['lang' => 'fr']);
            $el($imd, 'ItemCode', (string) ($i + 1));
            $el($imd, 'ItemDescription', mb_substr($l->designation, 0, 500));
            $el($el($lin, 'LinQty'), 'Quantity', self::montant($l->quantite), ['measurementUnit' => 'UNIT']);
            $tax = $el($lin, 'LinTax');
            $el($tax, 'TaxTypeName', 'TVA', ['code' => 'I-1602']);
            $el($el($tax, 'TaxDetails'), 'TaxRate', self::taux($f->tva_taux));
            $lm = $el($lin, 'LinMoa');
            $moa($el($lm, 'MoaDetails'), 'I-183', $l->prix_unitaire);                       // montant HT unitaire
            $moa($el($lm, 'MoaDetails'), 'I-171', $l->total);                               // montant total HT de l'article
        }

        $im = $el($b, 'InvoiceMoa');
        $moa($el($im, 'AmountDetails'), 'I-180', $t['ttc'], montant_lettres($t['ttc']));  // total TTC
        $moa($el($im, 'AmountDetails'), 'I-176', $t['ht']);                                  // total HT facture
        $moa($el($im, 'AmountDetails'), 'I-182', $t['ht']);                                  // total base taxe
        $moa($el($im, 'AmountDetails'), 'I-181', $t['tva']);                                 // total taxe (TVA)

        $it = $el($b, 'InvoiceTax');
        if ($t['timbre'] > 0) {
            $x = $el($it, 'InvoiceTaxDetails');
            $tx = $el($x, 'Tax');
            $el($tx, 'TaxTypeName', 'droit de timbre', ['code' => 'I-1601']);
            $el($el($tx, 'TaxDetails'), 'TaxRate', '0');
            $moa($el($x, 'AmountDetails'), 'I-178', $t['timbre']);
        }
        $x = $el($it, 'InvoiceTaxDetails');
        $tx = $el($x, 'Tax');
        $el($tx, 'TaxTypeName', 'TVA', ['code' => 'I-1602']);
        $el($el($tx, 'TaxDetails'), 'TaxRate', self::taux($f->tva_taux));
        $moa($el($x, 'AmountDetails'), 'I-177', $t['ht']);                                   // base
        $moa($el($x, 'AmountDetails'), 'I-178', $t['tva']);                                  // montant

        return $d->saveXML();
    }

    /** Erreurs de validation contre le XSD officiel (les assertions XSD 1.1 sont contrôlées à part, voir mfValide). */
    public static function erreursXsd(string $xml): array
    {
        // libxml ne connaît que XSD 1.0 : on retire les assertions et alternatives XSD 1.1 (contrôle du matricule fiscal, refait par mfValide)
        $xsd = file_get_contents(resource_path('elfatoora/facture_INVOIC_V1.8.8_withoutSig.xsd'));
        $xsd = preg_replace('#<xs:assert\b[^>]*?/>#s', '', $xsd);
        $xsd = preg_replace('#<xs:element name="PartnerDetails" maxOccurs="unbounded">\s*(<xs:alternative\b[^>]*/>\s*)+</xs:element>#',
            '<xs:element name="PartnerDetails" type="PartDetailType" maxOccurs="unbounded"/>', $xsd);
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $d->loadXML($xml);
        $ok = $d->schemaValidateSource($xsd);
        $err = $ok ? [] : array_map(fn ($e) => trim($e->message).' (ligne '.$e->line.')', libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $err;
    }
}
