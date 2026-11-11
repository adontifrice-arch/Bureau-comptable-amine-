<?php

namespace App\Services\Actes;

use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Balises {{…}} des modèles d'actes (spécification §3.2) : valeurs en texte brut calculées depuis la fiche
 * client et la saisie du formulaire de rédaction. Les valeurs sur plusieurs lignes sont séparées par "\n".
 */
class Balises
{
    /** Valeur affichée quand l'information manque dans la fiche : à compléter à la main. */
    public const VIDE = '………';

    /** Balises reconnues, avec leur description (affichée dans l'écran des modèles). */
    public const LISTE = [
        'Société' => [
            'DENOMINATION' => 'Raison sociale', 'DENOMINATION_COMMERCIALE' => 'Nom commercial', 'FORME' => 'Forme juridique',
            'CAPITAL' => 'Capital (ex. 10 000,000 TND)', 'NB_PARTS' => 'Nombre de parts', 'VALEUR_NOMINALE' => 'Valeur nominale d’une part',
            'SIEGE' => 'Adresse du siège', 'MATRICULE_FISCAL' => 'Matricule fiscal', 'RESIDENCE' => 'résidente / non résidente',
            'REGIME_EXPORT' => 'non / totalement / partiellement exportatrice', 'OBJET' => 'Objet social (saisi à la rédaction)',
            'DUREE' => 'Durée de la société en années', 'DATE' => 'Date de l’acte (en toutes lettres)', 'LIEU' => 'Ville (fin de l’adresse du siège)',
        ],
        'Gérant' => [
            'GERANT' => 'Nom du gérant', 'GERANT_CIN' => 'N° de CIN du gérant', 'GERANT_NATIONALITE' => 'Nationalité', 'GERANT_ADRESSE' => 'Adresse',
            'DATE_NOMINATION_GERANT' => 'Date de nomination', 'DUREE_GERANT' => 'Durée de nomination',
        ],
        'Associés' => [
            'ASSOCIES' => 'Identité complète de chaque associé (une ligne par associé)', 'REPARTITION_PARTS' => 'Parts et numéros de parts par associé, avec le total',
            'APPORTS' => 'Apport de chaque associé (numéraire / nature)', 'SIGNATURES' => 'Lignes de signature des associés',
        ],
        'Procès-verbaux' => [
            'TYPE_AG' => 'ordinaire / extraordinaire / mixte', 'PRESENTS' => 'Associés présents et leurs parts', 'ORDRE_DU_JOUR' => 'Points de l’ordre du jour numérotés',
            'RESOLUTIONS' => 'Texte des résolutions', 'DATE_CESSATION' => 'Date de cessation d’activité', 'MOTIF' => 'Motif de la cessation',
        ],
        'Cession de parts' => [
            'CEDANT' => 'Identité du cédant', 'CESSIONNAIRE' => 'Identité du cessionnaire', 'NB_PARTS_CEDEES' => 'Nombre de parts cédées',
            'NUMEROS_PARTS' => 'Numéros des parts cédées', 'PRIX' => 'Prix de cession',
        ],
    ];

    public static function noms(): array
    {
        return array_merge(...array_values(array_map('array_keys', self::LISTE)));
    }

    /** Balises présentes dans un texte (sans doublon, dans l'ordre d'apparition). */
    public static function trouver(string $texte): array
    {
        preg_match_all('/\{\{\s*([A-Z0-9_]+)\s*\}\}/', $texte, $m);
        return array_values(array_unique($m[1]));
    }

    /** Remplace les balises connues d'un texte brut ; les balises inconnues sont laissées telles quelles. */
    public static function remplacer(string $texte, array $valeurs): string
    {
        return preg_replace_callback('/\{\{\s*([A-Z0-9_]+)\s*\}\}/', fn ($m) => $valeurs[$m[1]] ?? $m[0], $texte);
    }

    /** Détenteurs de parts : les associés, plus le gérant s'il est associé et absent de la liste. */
    public static function detenteurs(Client $c): Collection
    {
        $c->loadMissing('associes');
        $as = $c->associes->map(fn ($a) => ['nom' => $a->nom, 'nat' => $a->nationalite, 'dn' => $a->naissance_date, 'ln' => $a->naissance_lieu, 'cin' => $a->cin,
            'cind' => $a->cin_date, 'cinl' => $a->cin_lieu, 'pas' => $a->passeport, 'cs' => $a->carte_sejour, 'adr' => $a->adresse,
            'p' => $a->parts, 'pd' => $a->parts_de, 'pf' => $a->parts_a, 'ap' => $a->apport])->values();
        $gerantListe = $c->gerant_nom && $as->contains(fn ($a) => mb_strtolower(trim($a['nom'])) === mb_strtolower(trim($c->gerant_nom)));
        if ($c->gerant_nom && ! $gerantListe && ($c->gerant_associe || $as->isEmpty())) {
            $as->prepend(self::gerant($c) + ['p' => $c->gerant_parts ?: ($as->isEmpty() ? $c->nb_parts : null), 'pd' => $c->gerant_parts_de, 'pf' => $c->gerant_parts_a, 'ap' => $c->gerant_apport]);
        }
        return $as;
    }

    public static function gerant(Client $c): array
    {
        return ['nom' => $c->gerant_nom ?: self::VIDE, 'nat' => $c->gerant_nationalite, 'dn' => $c->gerant_naissance_date, 'ln' => $c->gerant_naissance_lieu,
            'cin' => $c->gerant_cin, 'cind' => $c->gerant_cin_date, 'cinl' => $c->gerant_cin_lieu, 'pas' => $c->gerant_passeport, 'cs' => $c->gerant_carte_sejour,
            'adr' => $c->gerant_adresse];
    }

    /** « Nom, de nationalité …, né(e) le … à …, titulaire de la CIN n° … délivrée le … à …, demeurant à … » */
    public static function identite(array $x): string
    {
        $s = $x['nom'];
        if (! empty($x['nat'])) $s .= ', de nationalité '.$x['nat'];
        if (! empty($x['dn'])) $s .= ', né(e) le '.dlong($x['dn']).(! empty($x['ln']) ? ' à '.$x['ln'] : '');
        if (! empty($x['cin'])) $s .= ', titulaire de la CIN n° '.$x['cin'].(! empty($x['cind']) ? ' délivrée le '.dfr($x['cind']) : '').(! empty($x['cinl']) ? ' à '.$x['cinl'] : '');
        elseif (! empty($x['pas'])) $s .= ', titulaire du passeport n° '.$x['pas'].(! empty($x['cs']) ? ' et de la carte de séjour n° '.$x['cs'] : '');
        if (! empty($x['adr'])) $s .= ', demeurant à '.$x['adr'];
        return $s;
    }

    public static function lieu(Client $c): string
    {
        return trim(collect(explode(',', (string) $c->adresse))->last()) ?: self::VIDE;
    }

    /** Points de l'ordre du jour : cases cochées puis points libres (un par ligne). */
    public static function ordreDuJour(array $d): Collection
    {
        $items = collect($d['ordre'] ?? [])->merge(preg_split('/\r?\n/', (string) ($d['ordre_libre'] ?? '')))->map(fn ($x) => trim((string) $x))->filter()->values();
        if (($d['type'] ?? null) === 'cess' && $items->isEmpty()) {
            $items = collect(['Cessation définitive de l’activité de la société', 'Dissolution anticipée et mise en liquidation', 'Nomination du liquidateur', 'Pouvoirs pour formalités']);
        }
        return $items;
    }

    /** Texte d'une résolution, selon le point de l'ordre du jour. */
    public static function resolution(string $point, array $d): string
    {
        $annee = Carbon::parse($d['date'])->year;
        return match (true) {
            str_contains($point, 'Approbation des états financiers') => 'L’assemblée générale, après avoir entendu la lecture du rapport de gestion'.(($d['type'] ?? '') !== 'cess' ? ' et, le cas échéant, du rapport du commissaire aux comptes' : '').', approuve les états financiers de l’exercice clos le 31 décembre '.($annee - 1).' tels qu’ils lui ont été présentés.',
            str_contains($point, 'Affectation') => 'L’assemblée générale décide d’affecter le résultat de l’exercice comme suit : ……………………',
            str_contains($point, 'Quitus') => 'L’assemblée générale donne quitus entier et sans réserve au gérant pour sa gestion au titre de l’exercice écoulé.',
            str_contains($point, 'Cessation') => 'L’assemblée générale décide la cessation définitive de l’activité de la société à compter du '.dlong($d['date_cessation'] ?? $d['date']).(! empty($d['motif']) ? ', pour le motif suivant : '.$d['motif'] : '').'.',
            str_contains($point, 'Pouvoirs') => 'L’assemblée générale confère tous pouvoirs au porteur d’une copie ou d’un extrait du présent procès-verbal pour accomplir les formalités légales.',
            default => 'Après délibération, l’assemblée générale adopte la résolution suivante : '.$point.' ……………………',
        };
    }

    public static function rang(int $i): string
    {
        return ['Première', 'Deuxième', 'Troisième', 'Quatrième', 'Cinquième', 'Sixième', 'Septième', 'Huitième', 'Neuvième', 'Dixième'][$i] ?? ($i + 1).'e';
    }

    /** Valeur de chaque balise pour ce client et cette saisie. */
    public static function valeurs(Client $c, array $d): array
    {
        $v = fn ($x) => ($x === null || $x === '' || $x === 0) ? self::VIDE : (string) $x;
        $as = self::detenteurs($c);
        $np = $c->nb_parts ?: $as->sum('p');
        $type = $d['type'] ?? '';
        $items = self::ordreDuJour($d);
        $ced = $as->first(fn ($a) => mb_strtolower(trim($a['nom'])) === mb_strtolower(trim((string) ($d['cedant'] ?? ''))));
        $cessionnaire = ['nom' => ($d['cessionnaire'] ?? null) ?: self::VIDE, 'cin' => $d['cessionnaire_cin'] ?? null, 'adr' => $d['cessionnaire_adresse'] ?? null];

        return [
            'DENOMINATION' => $v($c->nom),
            'DENOMINATION_COMMERCIALE' => $v($c->denomination_commerciale),
            'FORME' => $v($c->forme),
            'CAPITAL' => $c->capital > 0 ? tnd($c->capital) : self::VIDE,
            'NB_PARTS' => $v($np),
            'VALEUR_NOMINALE' => $c->valeur_nominale ? tnd($c->valeur_nominale) : self::VIDE,
            'SIEGE' => $v($c->adresse),
            'MATRICULE_FISCAL' => $v($c->matricule_fiscal),
            'RESIDENCE' => ['residente' => 'résidente', 'non_residente' => 'non résidente'][$c->residence] ?? self::VIDE,
            'REGIME_EXPORT' => ['non' => 'non exportatrice', 'totale' => 'totalement exportatrice', 'partielle' => 'partiellement exportatrice'][$c->regime_export] ?? self::VIDE,
            'OBJET' => $v($d['objet'] ?? null),
            'DUREE' => $v($d['duree'] ?? 99),
            'DATE' => dlong($d['date'] ?? null),
            'LIEU' => self::lieu($c),

            'GERANT' => $v($c->gerant_nom),
            'GERANT_CIN' => $v($c->gerant_cin),
            'GERANT_NATIONALITE' => $v($c->gerant_nationalite),
            'GERANT_ADRESSE' => $v($c->gerant_adresse),
            'DATE_NOMINATION_GERANT' => dlong($c->gerant_nomination_date),
            'DUREE_GERANT' => $v($c->gerant_nomination_duree),

            'ASSOCIES' => $as->isEmpty() ? self::VIDE : $as->map(fn ($a) => '- '.self::identite($a))->implode("\n"),
            'REPARTITION_PARTS' => $as->isEmpty() ? self::VIDE : $as->map(fn ($a) => '- '.$a['nom'].' : '.($a['p'] ?: self::VIDE).' parts'.($a['pd'] ? ', numérotées de '.$a['pd'].' à '.$a['pf'] : ''))
                ->push('Total : '.($np ?: self::VIDE).' parts')->implode("\n"),
            'APPORTS' => $as->isEmpty() ? self::VIDE : $as->map(fn ($a) => '- '.$a['nom'].' : apport en '.(($a['ap'] ?? 'numeraire') === 'nature' ? 'nature' : 'numéraire')
                .' de '.($c->valeur_nominale && $a['p'] ? tnd($a['p'] * $c->valeur_nominale) : self::VIDE))->implode("\n"),
            'SIGNATURES' => $as->isEmpty() ? self::VIDE : $as->map(fn ($a) => $a['nom'].' : ……………………')->implode("\n"),

            'TYPE_AG' => ['ago' => 'ordinaire', 'age' => 'extraordinaire', 'mixte' => 'mixte', 'cess' => 'extraordinaire'][$type] ?? self::VIDE,
            'PRESENTS' => $as->isEmpty() ? self::VIDE : $as->map(fn ($a) => '- '.$a['nom'].' : '.($a['p'] ?: self::VIDE).' parts')->implode("\n"),
            'ORDRE_DU_JOUR' => $items->isEmpty() ? self::VIDE : $items->map(fn ($x, $i) => ($i + 1).'. '.$x)->implode("\n"),
            'RESOLUTIONS' => $items->isEmpty() ? self::VIDE : $items->map(fn ($x, $i) => self::rang($i).' résolution'."\n".self::resolution($x, $d)."\n".'Cette résolution, mise aux voix, est adoptée à l’unanimité.')->implode("\n\n"),
            'DATE_CESSATION' => dlong($d['date_cessation'] ?? null),
            'MOTIF' => $v($d['motif'] ?? null),

            'CEDANT' => $ced ? self::identite($ced) : $v($d['cedant'] ?? null),
            'CESSIONNAIRE' => self::identite($cessionnaire),
            'NB_PARTS_CEDEES' => $v($d['parts'] ?? null),
            'NUMEROS_PARTS' => ! empty($d['parts_de']) && ! empty($d['parts_a']) ? 'n° '.$d['parts_de'].' à '.$d['parts_a'] : self::VIDE,
            'PRIX' => ! empty($d['prix']) ? tnd($d['prix']) : self::VIDE,
        ];
    }
}
