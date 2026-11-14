<?php

namespace App\Services;

use App\Models\Immobilisation;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Calcul des amortissements (spécification §3.8 et §5.5).
 *
 * - Linéaire : base amortissable × taux × fraction d'année.
 * - Dégressif : VNC × taux × coefficient (1,5 jusqu'à 4 ans, 2 pour 5–6 ans, 2,5 au-delà), avec passage au
 *   linéaire sur la durée restante dès que celui-ci devient plus favorable.
 * - Fraction d'année (prorata temporis) : au jour (défaut, jours réels / jours de l'année, jours de mise en service et de
 *   cession comptés), au mois (mois de mise en service et mois de cession comptés)
 *   ou sans prorata (année entière).
 * - Base amortissable = valeur d'origine − valeur résiduelle ; le cumul ne la dépasse jamais.
 * - Plus de dotation après l'année de cession.
 */
class Amortissement
{
    /** nature => [libellé, compte d'immobilisation, compte d'amortissement (null : non amortissable), taux par défaut] */
    public const NATURES = [
        'incorporelle' => ['Immobilisations incorporelles', '211', '2281', 33.33],
        'terrain' => ['Terrains', '220', null, 0],
        'construction' => ['Constructions', '221', '2282', 5],
        'materiel' => ['Matériel et outillage', '223', '2283', 10],
        'transport' => ['Matériel de transport', '224', '2284', 20],
        'mobilier' => ['Mobilier et matériel de bureau', '225', '2285', 10],
        'informatique' => ['Matériel informatique', '226', '2286', 33.33],
        'agencement' => ['Agencements et installations', '227', '2287', 10],
    ];

    public const PRORATAS = ['jour' => 'Au jour', 'mois' => 'Au mois', 'aucun' => 'Sans prorata'];
    public const METHODES = ['lineaire' => 'Linéaire', 'degressif' => 'Dégressif'];

    /** Nature déduite d'un compte de classe 2 (import des factures reçues). */
    public static function natureDuCompte(string $compte): string
    {
        foreach (self::NATURES as $n => [, $cpt]) if (str_starts_with($compte, $cpt)) return $n;
        return str_starts_with($compte, '21') ? 'incorporelle' : 'materiel';
    }

    /**
     * Durée d'amortissement en années, déduite du taux linéaire. Arrondie au centième : un taux arrondi
     * comme 33,33 % (100 / 33,33 = 3,0003) correspond à 3 ans, pas à 3 ans et une fraction.
     */
    public static function duree(float $taux): float
    {
        return $taux > 0 ? round(100 / $taux, 2) : 0;
    }

    /** Coefficient du dégressif selon la durée : 1,5 jusqu'à 4 ans, 2 pour 5–6 ans, 2,5 au-delà. */
    public static function coefficient(float $taux): float
    {
        $n = round(self::duree($taux), 2);
        return $n <= 4 ? 1.5 : ($n <= 6 ? 2.0 : 2.5);
    }

    /** Part de l'année $annee pendant laquelle le bien est en service (0 à 1). */
    public static function fraction(int $annee, CarbonInterface $debut, ?CarbonInterface $cession, string $prorata): float
    {
        if ($annee < $debut->year || ($cession && $annee > $cession->year)) return 0.0;
        if ($prorata === 'aucun') return 1.0;

        $de = $annee === $debut->year ? $debut : Carbon::create($annee, 1, 1);
        $a = $cession && $annee === $cession->year ? $cession : Carbon::create($annee, 12, 31);
        if ($a->lt($de)) return 0.0;
        if ($prorata === 'jour') {
            $jours = $de->copy()->startOfDay()->diffInDays($a->copy()->startOfDay()) + 1;
            return min(1.0, $jours / (Carbon::create($annee)->isLeapYear() ? 366 : 365));
        }
        return ($a->month - $de->month + 1) / 12;
    }

    /**
     * Plan d'amortissement année par année, de l'année de mise en service jusqu'à $jusqua inclus.
     *
     * @param  array{valeur_origine: float, valeur_residuelle?: float, methode: string, taux: float, prorata?: string,
     *               date_mise_service: CarbonInterface|string, date_cession?: CarbonInterface|string|null, amortissable?: bool} $b
     * @return array<int, array{debut: float, dotation: float, cumul: float, vnc: float, fraction: float}>
     */
    public static function plan(array $b, int $jusqua): array
    {
        $debut = Carbon::parse($b['date_mise_service']);
        $cession = ! empty($b['date_cession']) ? Carbon::parse($b['date_cession']) : null;
        $vo = (float) $b['valeur_origine'];
        $base = max(0.0, $vo - (float) ($b['valeur_residuelle'] ?? 0));
        $taux = (float) $b['taux'];
        $prorata = $b['prorata'] ?? 'jour';
        $degressif = ($b['methode'] ?? 'lineaire') === 'degressif';
        $amortissable = ($b['amortissable'] ?? true) && $taux > 0 && $base > 0;
        $duree = self::duree($taux);
        $tauxDegressif = $taux * self::coefficient($taux) / 100;

        $plan = [];
        $cumul = 0.0;
        $ecoule = 0.0;     // durée déjà amortie, en années (fractions comprises)
        for ($y = $debut->year; $y <= $jusqua; $y++) {
            $f = self::fraction($y, $debut, $cession, $prorata);
            $reste = r3($base - $cumul);
            $dot = 0.0;
            if ($amortissable && $f > 0 && $reste > 0) {
                if ($degressif) {
                    $restant = $duree - $ecoule;
                    $lineaireRestant = $restant > 0 ? $reste * min(1, $f / $restant) : $reste;
                    $dot = max(($vo - $cumul) * $tauxDegressif * $f, $lineaireRestant);
                } elseif ($ecoule + $f >= $duree - 1e-9) {
                    $dot = $reste;      // dernière annuité : solde le reliquat dû à l'arrondi du taux (ex. 33,33 %)
                } else {
                    $dot = $base * $taux / 100 * $f;
                }
                $dot = min(r3($dot), $reste);
            }
            $ecoule += $f;
            $plan[$y] = ['debut' => r3($vo - $cumul), 'dotation' => $dot, 'cumul' => r3($cumul + $dot), 'vnc' => r3($vo - $cumul - $dot), 'fraction' => $f];
            $cumul = r3($cumul + $dot);
        }
        return $plan;
    }

    /** Plan d'une immobilisation enregistrée. */
    public static function planDe(Immobilisation $i, int $jusqua): array
    {
        return self::plan([
            'valeur_origine' => $i->valeur_origine, 'valeur_residuelle' => $i->valeur_residuelle, 'methode' => $i->methode, 'taux' => $i->taux,
            'prorata' => $i->prorata, 'date_mise_service' => $i->date_mise_service, 'date_cession' => $i->date_cession, 'amortissable' => $i->amortissable(),
        ], $jusqua);
    }

    /** Situation d'une immobilisation pour un exercice : cumul antérieur, dotation, cumul et VNC de fin d'exercice. */
    public static function situation(Immobilisation $i, int $exercice): array
    {
        $plan = self::planDe($i, $exercice);
        $ligne = $plan[$exercice] ?? null;
        $vo = (float) $i->valeur_origine;
        if (! $ligne) return ['anterieur' => 0.0, 'dotation' => 0.0, 'cumul' => 0.0, 'vnc' => $vo, 'en_service' => false];
        return ['anterieur' => r3($ligne['cumul'] - $ligne['dotation']), 'dotation' => $ligne['dotation'], 'cumul' => $ligne['cumul'],
            'vnc' => $ligne['vnc'], 'en_service' => ! $i->date_cession || $i->date_cession->year >= $exercice];
    }
}
