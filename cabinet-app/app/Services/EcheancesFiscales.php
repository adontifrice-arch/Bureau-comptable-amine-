<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Declaration;
use App\Models\Echeance;
use App\Models\Setting;
use App\Support\CabinetCourant;
use Carbon\Carbon;

/**
 * Échéances fiscales et sociales générées automatiquement à l'entrée de chaque mois, pour chaque client :
 * déclaration mensuelle du mois précédent (le 15 pour une personne physique, le 20 pour une personne morale en télé-déclaration,
 * le 28 à la recette des finances, reportés au jour ouvrable suivant), déclarations annuelles, déclaration d'employeur,
 * acompte provisionnel exigible dans le mois, déclarations CNSS trimestrielles et dépôt des états financiers au RNE.
 * Toutes les dates sont reportées au jour ouvrable suivant. Une échéance supprimée n'est jamais recréée ; une échéance cochée n'est pas modifiée.
 */
class EcheancesFiscales
{
    public const CATEGORIE_FISCALE = 'Échéance fiscale';
    public const CATEGORIE_SOCIALE = 'Échéance sociale';
    public const CATEGORIE_JURIDIQUE = 'Échéance juridique';

    /** Jours fériés fixes (mois-jour). */
    public const FERIES_FIXES = ['01-01', '03-20', '04-09', '05-01', '07-25', '08-13', '10-15', '12-17'];

    /**
     * Jours fériés religieux (calendrier lunaire) : dates prévisionnelles, à confirmer chaque année
     * (Aïd el-Fitr 2 jours, Aïd el-Idha 2 jours, Ras El Am El Hijri, Mouled). Les dates annoncées se saisissent au paramétrage.
     */
    public const FERIES_RELIGIEUX = [
        '2025-03-30', '2025-03-31', '2025-06-06', '2025-06-07', '2025-06-26', '2025-09-04',
        '2026-03-20', '2026-03-21', '2026-05-27', '2026-05-28', '2026-06-16', '2026-08-25',
        '2027-03-09', '2027-03-10', '2027-05-16', '2027-05-17', '2027-06-06', '2027-08-14',
        '2028-02-26', '2028-02-27', '2028-05-05', '2028-05-06', '2028-05-25', '2028-08-03',
    ];

    private const MOIS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /** Jours fériés retenus : fixes, religieux prévisionnels et dates saisies au paramétrage (Paramètres › Fiscalité). */
    public static function joursFeries(): array
    {
        $saisis = preg_split('/[\s,;]+/', (string) (Setting::fiscal()['feries'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $dates = [];
        foreach ($saisis as $s) {
            try { $dates[] = (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $s) ? Carbon::createFromFormat('d/m/Y', $s) : Carbon::parse($s))->format('Y-m-d'); } catch (\Throwable) {}
        }
        return array_values(array_unique([...self::FERIES_RELIGIEUX, ...$dates]));
    }

    public static function estOuvrable(Carbon $d, ?array $feries = null): bool
    {
        $feries ??= self::joursFeries();
        return ! $d->isWeekend() && ! in_array($d->format('m-d'), self::FERIES_FIXES, true) && ! in_array($d->format('Y-m-d'), $feries, true);
    }

    /** Date reportée au premier jour ouvrable (samedi, dimanche et jours fériés exclus). */
    public static function jourOuvrable(Carbon $d, ?array $feries = null): Carbon
    {
        $feries ??= self::joursFeries();
        $d = $d->copy()->startOfDay();
        while (! self::estOuvrable($d, $feries)) $d->addDay();
        return $d;
    }

    /** Le client déclare-t-il des salaires (salariés en paie ou matricule CNSS employeur) ? */
    private static function employeur(Client $c): bool
    {
        return $c->salaries()->exists() || trim((string) $c->matricule_cnss) !== '';
    }

    /** La déclaration annuelle d'une personne physique relève-t-elle des BIC (échéance le 25 avril) ? */
    private static function bic(Client $c): bool
    {
        $cats = (array) ($c->categories_revenus ?? []);
        return $cats ? in_array('bic', $cats, true) : DeclarationAnnuelle::prefixeIr($c) === 'bic_';
    }

    /**
     * Échéances d'un client dont la date de base tombe dans le mois donné : [code, date, intitulé, catégorie].
     * Le code (colonne « auto ») et l'intitulé identifient l'échéance.
     */
    public static function duMois(Client $c, int $annee, int $mois, ?array $feries = null): array
    {
        $feries ??= self::joursFeries();
        $pp = $c->estPhysique();
        $e = [];
        // Toute échéance tombant un samedi, un dimanche ou un jour férié est reportée au premier jour ouvrable suivant
        $date = fn (int $j) => self::jourOuvrable(Carbon::create($annee, $mois, $j), $feries);

        // 1 à 3 : déclaration mensuelle du mois précédent
        $prec = Carbon::create($annee, $mois, 1)->subMonth();
        $jour = $pp ? 15 : ($c->mode_depot === 'teledeclaration' ? 20 : 28);
        $e[] = ['dm', $date($jour),'Déclaration mensuelle '.(in_array(mb_substr(self::MOIS[$prec->month], 0, 1), ['a', 'o'], true) ? 'd’' : 'de ').self::MOIS[$prec->month].' '.$prec->year, self::CATEGORIE_FISCALE];

        // 4 et 5 : acompte provisionnel exigible ce mois-ci (juin, septembre, décembre), le 25 (PP) ou le 28 (PM)
        if ($rang = [6 => '1er', 9 => '2e', 12 => '3e'][$mois] ?? null) {
            $e[] = ['ap', $date($pp ? 25 : 28), "$rang acompte provisionnel $annee", self::CATEGORIE_FISCALE];
        }

        $n = $annee - 1;
        // 6 à 8 : déclarations annuelles de l'année précédente
        if (! $pp && $mois === 3) $e[] = ['da', $date(25), "Déclaration annuelle de l’impôt sur les sociétés $n (initiale ou définitive)", self::CATEGORIE_FISCALE];
        if (! $pp && $mois === 6 && Declaration::where(['client_id' => $c->id, 'type' => 'ann', 'periode' => (string) $n])->get()->contains(fn ($d) => ($d->champs['nature'] ?? 'd') === 'p')) {
            $e[] = ['dad', $date(25), "Déclaration annuelle définitive de l’impôt sur les sociétés $n", self::CATEGORIE_FISCALE];
        }
        if ($pp && $mois === 4 && self::bic($c)) $e[] = ['da', $date(25), "Déclaration annuelle de l’impôt sur le revenu $n (BIC)", self::CATEGORIE_FISCALE];
        if ($pp && $mois === 5 && ! self::bic($c)) $e[] = ['da', $date(25), "Déclaration annuelle de l’impôt sur le revenu $n", self::CATEGORIE_FISCALE];
        // Déclaration d'employeur : tous les clients, personnes physiques ou morales, avec ou sans salariés
        if ($mois === 4) $e[] = ['de', $date(30), "Déclaration d’employeur $n", self::CATEGORIE_FISCALE];
        // 9 : CNSS, dans les 15 jours qui suivent le trimestre
        if (in_array($mois, [1, 4, 7, 10], true)) {
            $t = intdiv($prec->month - 1, 3) + 1;
            $trim = ($t === 1 ? '1er' : $t.'e').' trimestre '.$prec->year;
            if (self::employeur($c)) $e[] = ['cn', $date(15), "Déclaration CNSS des salaires – $trim", self::CATEGORIE_SOCIALE];
            if ($pp && trim((string) $c->matricule_independant) !== '') $e[] = ['ci', $date(15), "Cotisations CNSS du travailleur indépendant – $trim", self::CATEGORIE_SOCIALE];
        }
        // 10 : états financiers au registre national des entreprises
        if (! $pp && $mois === 7) $e[] = ['rne', $date(31), "Dépôt des états financiers $n au RNE", self::CATEGORIE_JURIDIQUE];
        return $e;
    }

    /**
     * Crée les échéances du mois pour un client. Une échéance existante (même code et même intitulé, supprimée comprise) n'est pas
     * recréée ; non cochée, elle suit les règles (date, report au jour ouvrable). Pour un client créé en cours de mois,
     * les échéances antérieures à sa création ne sont pas ajoutées.
     */
    public static function genererMois(Client $c, int $annee, int $mois, ?array $feries = null): int
    {
        $feries ??= self::joursFeries();
        // Acomptes provisionnels : seul l'acompte exigible dans le mois est affiché ; les acomptes futurs non cochés créés
        // à l'avance sont retirés (ils reviendront à l'entrée de leur mois de paiement)
        Echeance::where('client_id', $c->id)->where('auto', 'ap')->whereNull('accomplie_le')
            ->where('date_echeance', '>', Carbon::create($annee, $mois, 1)->endOfMonth())->forceDelete();
        $existants = Echeance::withTrashed()->where('client_id', $c->id)->whereIn('auto', ['dm', 'ap', 'da', 'dad', 'de', 'cn', 'ci', 'rne'])->get()
            ->keyBy(fn ($x) => $x->auto.'|'.$x->intitule);
        $debut = $c->created_at?->copy()->startOfDay();
        $n = 0;
        foreach (self::duMois($c, $annee, $mois, $feries) as [$code, $date, $intitule, $categorie]) {
            if ($x = $existants->get("$code|$intitule")) {
                if (! $x->trashed() && ! $x->accomplie_le && ! $x->date_echeance->isSameDay($date)) $x->update(['date_echeance' => $date]);
                continue;
            }
            if ($debut && $date->lt($debut)) continue;
            Echeance::create(['client_id' => $c->id, 'auto' => $code, 'date_echeance' => $date, 'categorie' => $categorie, 'intitule' => $intitule]);
            $n++;
        }
        return $n;
    }

    /** Génère les échéances du mois pour tous les clients du cabinet courant. */
    public static function genererCabinet(int $annee, int $mois): int
    {
        $feries = self::joursFeries();
        return Client::all()->sum(fn ($c) => self::genererMois($c, $annee, $mois, $feries));
    }

    /**
     * À l'entrée de chaque mois : génère une seule fois par mois et par cabinet les échéances du mois en cours
     * (appelé à chaque requête d'un utilisateur connecté, et par la commande planifiée « echeances:generer »).
     */
    public static function entreeDuMois(): void
    {
        $cab = CabinetCourant::id();
        if ($cab === null) return;
        $mois = now()->format('Y-m');
        if ((Setting::get('echeances_auto') ?? null) === $mois) return;
        self::genererCabinet(now()->year, now()->month);
        Setting::put('echeances_auto', $mois);
    }
}
