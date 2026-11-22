<?php

namespace App\Services\ElFatoora;

use App\Models\Facture;
use App\Models\Setting;
use App\Support\CabinetCourant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Facturation électronique du cabinet : réglages (certificat de signature, accès au web service),
 * signature, dépôt et suivi des factures sur El Fatoora. Les secrets sont chiffrés avec la clé de l'application.
 */
class ElFatoora
{
    public static function reglages(): array
    {
        return array_merge(['certificat' => null, 'certificat_mdp' => null, 'certificat_infos' => null, 'role' => 'Fournisseur',
            'url' => WebService::URL_PRODUCTION, 'login' => '', 'mdp' => null], Setting::get('elfatoora', []));
    }

    private static function dossier(): string
    {
        return 'elfatoora/'.(CabinetCourant::id() ?? 'aucun');
    }

    /** Enregistre le certificat PKCS#12 (vérifié : lisible avec ce mot de passe, en cours de validité). */
    public static function enregistrerCertificat(string $contenu, string $motDePasse): array
    {
        $p = SignatureXades::lireP12($contenu, $motDePasse);
        if ($p['infos']['fin'] < now()->toDateString()) throw new RuntimeException('Ce certificat a expiré le '.dfr($p['infos']['fin']).'.');
        $r = self::reglages();
        if ($r['certificat']) Storage::disk('local')->delete($r['certificat']);
        $chemin = self::dossier().'/certificat-'.now()->format('YmdHis').'.p12.enc';
        Storage::disk('local')->put($chemin, Crypt::encryptString(base64_encode($contenu)));
        Setting::put('elfatoora', ['certificat' => $chemin, 'certificat_mdp' => Crypt::encryptString($motDePasse), 'certificat_infos' => $p['infos']] + $r);
        return $p['infos'];
    }

    public static function retirerCertificat(): void
    {
        $r = self::reglages();
        if ($r['certificat']) Storage::disk('local')->delete($r['certificat']);
        Setting::put('elfatoora', ['certificat' => null, 'certificat_mdp' => null, 'certificat_infos' => null] + $r);
    }

    public static function enregistrerAcces(array $d): void
    {
        $r = self::reglages();
        $r['url'] = ($d['url'] ?? null) ?: WebService::URL_PRODUCTION;
        $r['login'] = (string) ($d['login'] ?? '');
        $r['role'] = ($d['role'] ?? null) ?: 'Fournisseur';
        if (filled($d['mdp'] ?? null)) $r['mdp'] = Crypt::encryptString($d['mdp']);
        Setting::put('elfatoora', $r);
    }

    private static function certificat(): array
    {
        $r = self::reglages();
        if (! $r['certificat'] || ! Storage::disk('local')->exists($r['certificat'])) {
            throw new RuntimeException('Aucun certificat de signature : importez le certificat .p12 du cabinet dans Paramètres › Facture électronique.');
        }
        return SignatureXades::lireP12(base64_decode(Crypt::decryptString(Storage::disk('local')->get($r['certificat']))), Crypt::decryptString($r['certificat_mdp']));
    }

    public static function service(): WebService
    {
        $r = self::reglages();
        if (! $r['login'] || ! $r['mdp']) throw new RuntimeException('Identifiants El Fatoora manquants : renseignez-les dans Paramètres › Facture électronique.');
        return new WebService($r['url'], $r['login'], Crypt::decryptString($r['mdp']), Teif::normaliserMf(Setting::cabinet()['mf']));
    }

    /** XML TEIF contrôlé (anomalies métier + XSD officiel) ; exception si la facture n'est pas conforme. */
    public static function teifControle(Facture $f): string
    {
        $f->loadMissing(['client', 'lignes']);
        if ($a = Teif::anomalies($f)) throw new RuntimeException(implode(' ', $a));
        $xml = Teif::generer($f);
        if ($e = Teif::erreursXsd($xml)) throw new RuntimeException('XML non conforme au schéma TEIF : '.implode(' ; ', array_slice($e, 0, 3)));
        return $xml;
    }

    public static function signer(Facture $f): void
    {
        if (in_array($f->teif_statut, ['deposee', 'validee'], true)) throw new RuntimeException('Cette facture a déjà été déposée sur El Fatoora.');
        $c = self::certificat();
        $xml = SignatureXades::signer(self::teifControle($f), $c['cert'], $c['cle'], $c['chaine'], self::reglages()['role']);
        if ($e = SignatureXades::verifier($xml)) throw new RuntimeException('Contrôle de la signature : '.implode(' ', $e));
        $chemin = self::dossier().'/'.$f->id.'-'.preg_replace('/[^A-Za-z0-9_\-]/', '_', $f->numero).'.xml';
        Storage::disk('local')->put($chemin, $xml);
        $f->update(['teif_statut' => 'signee', 'teif_fichier' => $chemin, 'teif_signe_le' => now(), 'teif_message' => null,
            'teif_reference' => null, 'teif_qr' => null]);
    }

    /** Annule la signature (facture modifiée avant dépôt) : le XML signé ne correspond plus. */
    public static function invalider(Facture $f): void
    {
        if ($f->teif_statut !== 'signee' && $f->teif_statut !== 'rejetee') return;
        if ($f->teif_fichier) Storage::disk('local')->delete($f->teif_fichier);
        $f->update(['teif_statut' => null, 'teif_fichier' => null, 'teif_signe_le' => null]);
    }

    public static function deposer(Facture $f): string
    {
        if ($f->teif_statut === 'rejetee') self::signer($f);   // nouvelle signature après correction
        if ($f->teif_statut !== 'signee') throw new RuntimeException('Signez d’abord la facture.');
        $message = self::service()->deposer(Storage::disk('local')->get($f->teif_fichier));
        $f->update(['teif_statut' => 'deposee', 'teif_depose_le' => now(), 'teif_message' => mb_substr($message, 0, 2000)]);
        return $message;
    }

    /** Interroge El Fatoora : facture validée (référence TTN + QR code) ou rejetée (acquittements négatifs). */
    public static function consulter(Facture $f): string
    {
        $res = collect(self::service()->consulter($f->numero))->first(fn ($r) => $r['numero'] === '' || $r['numero'] === $f->numero);
        if (! $res) return 'Facture pas encore traitée par El Fatoora.';
        if ($res['acquittements']) {
            $f->update(['teif_statut' => 'rejetee', 'teif_message' => mb_substr(implode("\n", $res['acquittements']), 0, 2000)]);
            return 'Facture rejetée par El Fatoora : '.implode(' ; ', $res['acquittements']);
        }
        [$ref, $qr] = $res['xml'] ? WebService::referenceTtn($res['xml']) : [null, null];
        $ref = $ref ?: ($res['reference_ttn'] ?: null);
        if (! $ref) return 'Facture en cours de traitement par El Fatoora.';
        if ($res['xml']) Storage::disk('local')->put($f->teif_fichier, $res['xml']);   // XML validé et signé par TTN
        $f->update(['teif_statut' => 'validee', 'teif_reference' => $ref, 'teif_qr' => $qr, 'teif_message' => null]);
        return 'Facture validée par El Fatoora sous la référence '.$ref.'.';
    }
}
