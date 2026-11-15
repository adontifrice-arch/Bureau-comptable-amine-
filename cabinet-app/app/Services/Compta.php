<?php

namespace App\Services;

use App\Models\Compte;
use App\Models\Ecriture;
use App\Models\EcritureLigne;
use App\Models\FactureRecue;
use App\Support\CabinetCourant;
use Illuminate\Support\Facades\DB;

/** Calculs comptables : balance, états financiers (repris du prototype). */
class Compta
{
    public static function plan(): array
    {
        return Compte::orderBy('numero')->pluck('libelle', 'numero')->all();
    }

    /** Libellés des comptes mis en cache, par cabinet. */
    private static array $plans = [];

    /** À appeler quand le plan comptable change (installation, ajout ou modification d'un compte). */
    public static function oublierPlan(): void
    {
        self::$plans = [];
    }

    public static function libelle(string $cpt): string
    {
        $p = self::$plans[CabinetCourant::id() ?? 0] ??= static::plan();
        if (isset($p[$cpt])) return $p[$cpt];
        for ($i = strlen($cpt) - 1; $i >= 2; $i--) if (isset($p[substr($cpt, 0, $i)])) return $p[substr($cpt, 0, $i)];
        return '';
    }

    /** Somme d'un côté (debit|credit) des comptes commençant par $pre sur une période. */
    public static function somme(int $client, string $from, string $to, string $pre, string $side): float
    {
        return r3(EcritureLigne::query()->join('ecritures', 'ecritures.id', '=', 'ecriture_lignes.ecriture_id')
            ->whereNull('ecritures.deleted_at')->where('ecritures.client_id', $client)->whereBetween('ecritures.date', [$from, $to])
            ->where('ecriture_lignes.compte', 'like', $pre.'%')->sum('ecriture_lignes.'.$side));
    }

    /** Balance générale : report à nouveau, mouvements de l'exercice, soldes. */
    public static function balance(int $client, int $annee): array
    {
        $from = "$annee-01-01"; $to = "$annee-12-31";
        $rows = EcritureLigne::query()->join('ecritures', 'ecritures.id', '=', 'ecriture_lignes.ecriture_id')
            ->whereNull('ecritures.deleted_at')->where('ecritures.client_id', $client)->where('ecritures.date', '<=', $to)
            ->groupBy('ecriture_lignes.compte')
            ->select('ecriture_lignes.compte',
                DB::raw("SUM(CASE WHEN ecritures.date < '$from' THEN debit ELSE 0 END) as rd"),
                DB::raw("SUM(CASE WHEN ecritures.date < '$from' THEN credit ELSE 0 END) as rc"),
                DB::raw("SUM(CASE WHEN ecritures.date >= '$from' THEN debit ELSE 0 END) as md"),
                DB::raw("SUM(CASE WHEN ecritures.date >= '$from' THEN credit ELSE 0 END) as mc"))
            ->orderBy('ecriture_lignes.compte')->get();
        // Les comptes de gestion (6, 7) repartent à zéro : le résultat antérieur est reporté au compte 121
        $prior = 0;
        foreach ($rows as $r) if (preg_match('/^[67]/', $r->compte)) $prior += $r->rd - $r->rc;
        if (abs($prior) > 0.0005) {
            $c121 = Saisie::normaliser('121', Saisie::longueur($client));       // même longueur que les autres comptes du dossier
            $r121 = $rows->firstWhere('compte', $c121);
            if (! $r121) { $r121 = (object) ['compte' => $c121, 'rd' => 0, 'rc' => 0, 'md' => 0, 'mc' => 0]; $rows->push($r121); }
            $r121->rd += $prior;
            $rows = $rows->sortBy('compte')->values();
        }
        $B = [];
        foreach ($rows as $r) {
            $rn = preg_match('/^[67]/', $r->compte) ? 0 : r3($r->rd - $r->rc);
            $n = r3($rn + $r->md - $r->mc);
            if (! $rn && ! (float) $r->md && ! (float) $r->mc) continue;
            $B[] = ['compte' => $r->compte, 'libelle' => static::libelle($r->compte),
                'rd' => max($rn, 0), 'rc' => max(-$rn, 0), 'md' => r3($r->md), 'mc' => r3($r->mc),
                'sd' => max($n, 0), 'sc' => max(-$n, 0)];
        }
        return $B;
    }

    public static function totaux(array $B): array
    {
        $T = ['rd' => 0, 'rc' => 0, 'md' => 0, 'mc' => 0, 'sd' => 0, 'sc' => 0];
        foreach ($B as $b) foreach ($T as $k => $_) $T[$k] += $b[$k];
        return array_map('r3', $T);
    }

    /** Bilan et état de résultat simplifiés (finCalc du prototype). */
    public static function etats(int $client, int $annee): array
    {
        $g = array_fill_keys(['imm', 'stk', 'cli', 'aac', 'liq', 'cap', 'rep', 'emp', 'fou', 'apc', 'cob'], 0);
        $P = array_fill_keys(['ca', 'ap', 'ach', 'srv', 'per', 'imp', 'dot', 'fin', 'pfi', 'is', 'ext'], 0);
        $B = static::balance($client, $annee);
        foreach ($B as $b) {
            $s = r3($b['sd'] - $b['sc']); $k = $b['compte'];
            if ($k[0] === '1') { if (str_starts_with($k, '16')) $g['emp'] -= $s; elseif (str_starts_with($k, '10')) $g['cap'] -= $s; else $g['rep'] -= $s; }
            elseif ($k[0] === '2') $g['imm'] += $s;
            elseif ($k[0] === '3') $g['stk'] += $s;
            elseif ($k[0] === '4' || $k[0] === '5') {
                if ($s >= 0) { if (str_starts_with($k, '41')) $g['cli'] += $s; elseif ($k[0] === '5') $g['liq'] += $s; else $g['aac'] += $s; }
                else { if (str_starts_with($k, '40')) $g['fou'] -= $s; elseif ($k[0] === '5') $g['cob'] -= $s; else $g['apc'] -= $s; }
            } elseif ($k[0] === '6') {
                $p2 = substr($k, 0, 2);
                // Plan comptable tunisien : 64 personnel, 65 financières, 66 impôts et taxes, 67 pertes extraordinaires, 68 dotations, 69 IS
                $key = match (true) { $p2 === '60' => 'ach', $p2 === '64' => 'per', $p2 === '65' => 'fin', $p2 === '66' => 'imp', $p2 === '68' => 'dot', $p2 === '69' => 'is', default => 'srv' };
                if ($p2 === '67') { $P['ext'] -= $s; continue; }
                $P[$key] += $s;
            } elseif ($k[0] === '7') {
                $v = -$s;
                // 70 chiffre d'affaires, 75 produits financiers, 77 gains extraordinaires
                if (str_starts_with($k, '70')) $P['ca'] += $v; elseif (str_starts_with($k, '75')) $P['pfi'] += $v; elseif (str_starts_with($k, '77')) $P['ext'] += $v; else $P['ap'] += $v;
            }
        }
        $g = array_map('r3', $g); $P = array_map('r3', $P);
        $P['prod'] = r3($P['ca'] + $P['ap']);
        $P['chg'] = r3($P['ach'] + $P['srv'] + $P['per'] + $P['imp'] + $P['dot']);
        $P['rex'] = r3($P['prod'] - $P['chg']);
        $P['rfi'] = r3($P['pfi'] - $P['fin']);
        $P['rav'] = r3($P['rex'] + $P['rfi'] + $P['ext']);     // y compris les éléments extraordinaires (77 − 67), imposables
        $P['res'] = r3($P['rav'] - $P['is']);
        $g['res'] = $P['res'];
        $g['cp'] = r3($g['cap'] + $g['rep'] + $g['res']);
        $tA = r3($g['imm'] + $g['stk'] + $g['cli'] + $g['aac'] + $g['liq']);
        $tP = r3($g['cp'] + $g['emp'] + $g['fou'] + $g['apc'] + $g['cob']);
        return ['g' => $g, 'P' => $P, 'tA' => $tA, 'tP' => $tP, 'n' => count($B)];
    }

    /** Enregistre une écriture équilibrée. */
    public static function passer(int $client, string $date, string $journal, string $libelle, array $lignes, ?string $source = null, ?int $user = null): Ecriture
    {
        // Numéros de compte à la longueur choisie dans le paramétrage du dossier (401 → 401000…)
        $longueur = Saisie::longueur($client);
        return DB::transaction(function () use ($client, $date, $journal, $libelle, $lignes, $source, $user, $longueur) {
            $e = Ecriture::create(compact('date', 'journal', 'libelle', 'source') + ['client_id' => $client, 'valide_par' => $user]);
            foreach ($lignes as $l) {
                if ((float) ($l['debit'] ?? 0) == 0 && (float) ($l['credit'] ?? 0) == 0) continue;
                $e->lignes()->create(['compte' => Saisie::normaliser((string) $l['compte'], $longueur), 'debit' => r3($l['debit'] ?? 0), 'credit' => r3($l['credit'] ?? 0)]);
            }
            return $e;
        });
    }

    /** Catégorie de retenue d'une facture reçue (honoraires, loyers…). */
    public static function categorieRetenue(FactureRecue $f): string
    {
        $tx = mb_strtolower(($f->description ?? '').' '.$f->fournisseur); $c = (string) $f->compte;
        if (str_starts_with($c, '622') || str_contains($tx, 'honorair')) return 'hon';
        if (str_starts_with($c, '613') || str_contains($tx, 'loyer')) return 'loy';
        if (str_contains($tx, 'commission')) return 'com';
        if (str_contains($tx, 'courtage')) return 'cou';
        return 'mar';
    }
}
