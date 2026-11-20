<?php

namespace App\Services\Actes;

use App\Models\Client;

/**
 * Contrat de cession de parts sociales selon le modèle du cabinet (« CESSION PARTS ») : parties (vendeur, acquéreur),
 * exposé de la constitution de la société, cinq articles (cession, prix, connaissance des documents, jouissance, frais
 * et élection de domicile), « Fait en autant d’exemplaires… » et signatures de l'acheteur et du vendeur.
 */
class Cession
{
    private static function e($s): string { return e((string) $s); }

    private static function P(string $s): string { return '<p style="text-align:justify">'.$s.'</p>'; }

    private static function memeNom($a, $b): bool { return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b)); }

    /** « Mme Amira Joudi, née à Mannouba le 20/07/1987, titulaire de la CIN n°07409114 du 27/08/2018 » */
    public static function identite(array $x): string
    {
        $s = Balises::nomCivil($x);
        if (! empty($x['dn']) || ! empty($x['ln'])) {
            $s .= ', '.Balises::accord($x, 'né', 'née', 'né(e)').(! empty($x['ln']) ? ' à '.$x['ln'] : '').(! empty($x['dn']) ? ' le '.dfr($x['dn']) : '');
        }
        if (! empty($x['cin'])) $s .= ', titulaire de la CIN n°'.$x['cin'].(! empty($x['cind']) ? ' du '.dfr($x['cind']) : '');
        elseif (! empty($x['pas'])) $s .= ', titulaire du passeport n°'.$x['pas'].(! empty($x['pasd']) ? ' du '.dfr($x['pasd']) : '');
        else $s .= ', titulaire de la CIN n°'.Balises::VIDE;
        return $s;
    }

    /** Ville du siège : la partie de l'adresse qui suit le code postal (« 2036 Soukra » → Soukra). */
    public static function ville(Client $c): string
    {
        $lieu = rtrim(Balises::lieu($c), '. ');
        return preg_match('/\b\d{4}\s+([^\d,]+)$/u', $lieu, $m) ? trim($m[1]) : $lieu;
    }

    public static function html(Client $c, array $d): string
    {
        $e = fn ($s) => self::e($s);
        $V = Balises::VIDE;
        $as = Balises::detenteurs($c);
        $ced = $as->first(fn ($a) => self::memeNom($a['nom'], $d['cedant'] ?? '')) ?? ['nom' => ($d['cedant'] ?? null) ?: $V];
        $cess = ['civ' => $d['cessionnaire_civilite'] ?? null, 'nom' => ($d['cessionnaire'] ?? null) ?: $V, 'cin' => $d['cessionnaire_cin'] ?? null,
            'cind' => $d['cessionnaire_cin_date'] ?? null, 'dn' => $d['cessionnaire_naissance_date'] ?? null, 'ln' => $d['cessionnaire_naissance_lieu'] ?? null];
        $nomCed = $e(Balises::nomCivil($ced));
        $nomCess = $e(Balises::nomCivil($cess));
        $societe = $e(mb_strtoupper($c->nom));
        $forme = $e($c->forme ?: 'SARL');
        $ville = self::ville($c);
        $date = fn ($k) => ! empty($d[$k]) ? dfr($d[$k]) : $V;

        $h = '<h3 style="text-align:center"><u>CONTRAT DE CESSION DE PARTS SOCIALES</u></h3>';
        $h .= self::P('<b>ENTRE LES SOUSSIGNES</b>');
        $h .= '<ul><li>'.$e(self::identite($ced)).'.</li></ul>';
        $h .= self::P('En qualité de '.Balises::accord($ced, 'vendeur', 'vendeuse', 'vendeur').' d’une part');
        $h .= '<ul><li>'.$e(self::identite($cess)).'.</li></ul>';
        $h .= self::P('En qualité d’Acquéreur d’autre part');

        // Exposé : constitution de la société
        $associes = $as->map(fn ($a) => Balises::nomCivil($a))->filter()->values();
        $entre = $associes->count() > 1
            ? 'entre les associés : '.$e($associes->slice(0, -1)->implode(', ').' et '.$associes->last())
            : 'par l’associé unique '.$e($associes->first() ?? $V);
        $capital = $c->capital > 0 ? Statuts::chiffres((float) $c->capital).' DT' : $V.' DT';
        $h .= self::P('<b>IL EST PREALABLEMENT EXPOSE QUE :</b>');
        $h .= self::P('Par acte sous seing privé daté du '.$date('constitution_date').', enregistré au bureau des enregistrements des actes du '
            .$e(($d['enregistrement_bureau'] ?? null) ?: 'guichet unique de l’API Tunis').' le '.$date('enregistrement_date').', sous le n°'.$e(($d['enregistrement_numero'] ?? null) ?: $V)
            .', la société '.$societe.' a été constituée '.$entre.' sous la forme juridique d’une '.$forme.' au capital de '.$capital.' entièrement libéré.');

        $h .= self::P('<b>CECI ETANT DIT, LES PARTIES ONT ARRETE ET CONVENU CE QUI SUIT :</b>');
        $parts = ($d['parts'] ?? null) ?: $V;
        $numeros = ! empty($d['parts_de']) && ! empty($d['parts_a']) ? ', numérotées de '.$d['parts_de'].' à '.$d['parts_a'].' inclus,' : '';
        $h .= '<h3>Article 1 :</h3>'.self::P($nomCed.', '.Balises::accord($ced, 'cité', 'citée', 'cité(e)').' en premier part cède et transporte par les présents '.$parts.' parts sociales'.$numeros
            .' lui appartenant dans la société '.$societe.' '.$forme.' sous toutes les garanties de fait et de droit à '.$nomCess
            .' qui les accepte telles que décrites et énumérées dans les statuts.');
        $prix = isset($d['prix']) && $d['prix'] !== null && $d['prix'] !== '' ? Statuts::chiffres((float) $d['prix']).' dinars' : $V.' dinars';
        $h .= '<h3>Article 2 :</h3>'.self::P('La présente cession est consentie et acceptée moyennant le prix forfaitaire global de '.$prix.', payé par '.$nomCess.' à '.$nomCed
            .', qui les reconnaît et en donne par sa signature au bas des présents bonne et valable quittance entière et définitive.');
        $h .= '<h3>Article 3 :</h3>'.self::P('L’acheteur des parts sociales déclare avoir pris connaissance de tous documents concernant la société et accepté les parts sociales ainsi vendues dans l’état actuel sans réserves ni restrictions.');
        $h .= '<h3>Article 4 :</h3>'.self::P('L’entrée en jouissance aura lieu à partir du jour de la signature du dit contrat. Du fait de la présente cession, le cédant met l’acquéreur dans tous ses droits et actions résultant tant de la loi que des statuts dérivant de sa qualité de porteur de parts dans la '
            .$forme.' '.$societe.' à charge des obligations résultant de la propriété des sus dits parts sociales.');
        $h .= '<h3>Article 5 :</h3>'.self::P('Les frais du présent contrat sont à la charge de l’acquéreur qui s’y oblige et déclare en outre faire élection de domicile à '.$e($ville).'.');

        $h .= self::P('Fait en autant d’exemplaires que de droit et de bonne foi à '.$e($ville).' le '.(! empty($d['date']) ? dlong($d['date']) : $V));
        $col = fn ($titre, $nom) => '<td style="width:50%; text-align:center; vertical-align:top; padding:4px"><b>'.$titre.'</b><br><br><br>'.$nom.'</td>';
        return $h.'<table style="width:100%; margin-top:30px"><tr>'
            .$col(Balises::accord($cess, 'L’ACHETEUR', 'L’ACHETEUSE', 'L’ACHETEUR'), $nomCess)
            .$col(Balises::accord($ced, 'LE VENDEUR', 'LA VENDEUSE', 'LE VENDEUR'), $nomCed).'</tr></table>';
    }
}
