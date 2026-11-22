<?php

namespace App\Services\ElFatoora;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client SOAP du web service El Fatoora (« Spécifications techniques Web Services El Fatoora » v4/v5) :
 * saveEfact (dépôt d'une facture TEIF signée) et consultEfact (recherche, référence TTN, acquittements, XML validé).
 */
class WebService
{
    public const URL_PRODUCTION = 'https://elfatoora.tn/ElfatouraServices/EfactService';
    public const NS = 'http://services.elfatoura.tradenet.com.tn/';

    public function __construct(private string $url, private string $login, private string $motDePasse, private string $matricule) {}

    /** Noms des paramètres d'une opération, lus dans le schéma publié par le service (repli sur ceux de la spécification). */
    private function parametres(string $operation, array $defaut): array
    {
        return Cache::remember('elfatoora.xsd.'.md5($this->url).'.'.$operation, now()->addDay(), function () use ($operation, $defaut) {
            try {
                $r = Http::timeout(15)->get($this->url, ['xsd' => 1]);
                if (! $r->ok()) return $defaut;
                $d = new DOMDocument();
                if (! @$d->loadXML($r->body())) return $defaut;
                $x = new DOMXPath($d); $x->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
                $noms = [];
                foreach ($x->query('//xs:complexType[@name="'.$operation.'"]//xs:element/@name') as $a) $noms[] = $a->value;
                return count($noms) === count($defaut) ? $noms : $defaut;
            } catch (\Throwable) {
                return $defaut;
            }
        });
    }

    private function appeler(string $operation, array $valeurs): DOMXPath
    {
        $noms = $this->parametres($operation, array_keys($valeurs));
        $corps = '';
        foreach (array_values($valeurs) as $i => $v) $corps .= '<'.$noms[$i].'>'.$v.'</'.$noms[$i].'>';
        $env = '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="'.self::NS.'">'
            .'<soapenv:Header/><soapenv:Body><ser:'.$operation.'>'.$corps.'</ser:'.$operation.'></soapenv:Body></soapenv:Envelope>';
        try {
            $r = Http::timeout(60)->withHeaders(['Content-Type' => 'text/xml; charset=utf-8', 'SOAPAction' => '""'])->withBody($env, 'text/xml; charset=utf-8')->post($this->url);
        } catch (\Throwable $e) {
            throw new RuntimeException('El Fatoora injoignable : '.$e->getMessage());
        }
        $d = new DOMDocument();
        if (! @$d->loadXML($r->body())) throw new RuntimeException('Réponse illisible d’El Fatoora (HTTP '.$r->status().').');
        $x = new DOMXPath($d);
        $x->registerNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
        if ($f = $x->query('//s:Fault/faultstring')->item(0)) throw new RuntimeException('El Fatoora : '.trim($f->textContent));
        if (! $r->ok()) throw new RuntimeException('El Fatoora a répondu HTTP '.$r->status().'.');
        return $x;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function identification(): array
    {
        return ['login' => self::e($this->login), 'password' => self::e($this->motDePasse), 'matricule' => self::e($this->matricule)];
    }

    /** Dépose une facture TEIF signée ; renvoie le message de retour de la plateforme. */
    public function deposer(string $xmlSigne): string
    {
        $x = $this->appeler('saveEfact', $this->identification() + ['documentEfact' => base64_encode($xmlSigne)]);
        return trim((string) $x->query('//*[local-name()="saveEfactResponse"]/*')->item(0)?->textContent);
    }

    /**
     * Recherche par numéro de facture. Chaque résultat : numero, id_depot, reference_ttn, date_traitement,
     * acquittements (messages d'erreur), xml (facture validée et signée par TTN, si disponible).
     */
    public function consulter(string $numero): array
    {
        $x = $this->appeler('consultEfact', $this->identification() + ['efactCriteria' => '<documentNumber>'.self::e($numero).'</documentNumber>']);
        $res = [];
        foreach ($x->query('//*[local-name()="consultEfactResponse"]/*') as $n) {
            $v = fn (string $nom) => trim((string) $x->query('./*[local-name()="'.$nom.'"]', $n)->item(0)?->textContent);
            $acq = [];
            foreach ($x->query('./*[local-name()="listAcknowlegments"]', $n) as $a) $acq[] = trim(preg_replace('/\s+/', ' ', $a->textContent));
            $xml = $v('xmlContent');
            $res[] = ['numero' => $v('documentNumber'), 'id_depot' => $v('idSaveEfact'), 'reference_ttn' => $v('generatedRef'),
                'date_traitement' => $v('dateProcess'), 'acquittements' => array_values(array_filter($acq)), 'xml' => $xml !== '' ? (base64_decode($xml, true) ?: null) : null];
        }
        return $res;
    }

    /** Référence TTN (I-88) et cachet électronique (QR code PNG en base 64) d'une facture validée par TTN. */
    public static function referenceTtn(string $xmlValide): array
    {
        $d = new DOMDocument();
        if (! @$d->loadXML($xmlValide)) return [null, null];
        $x = new DOMXPath($d);
        return [trim((string) $x->query('//RefTtnVal/ReferenceTTN')->item(0)?->textContent) ?: null,
            trim((string) $x->query('//RefTtnVal/ReferenceCEV')->item(0)?->textContent) ?: null];
    }
}
