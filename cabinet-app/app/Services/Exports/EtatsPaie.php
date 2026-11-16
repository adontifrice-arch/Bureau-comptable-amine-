<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Services\PaieCalc;
use Carbon\Carbon;

/** Éditions du module Paie : journal global, déclarations CNSS, déclaration employeur, ordre de virement, bordereau. */
class EtatsPaie
{
    public const EDITIONS = [
        'journal' => 'Journal global',
        'dcnss' => 'D. CNSS',
        'recap-cnss' => 'Récap CNSS',
        'dcnss-patronal' => 'D. CNSS patronal',
        'demp' => 'D. Emp',
        'virement' => 'Ordre de virement',
        'bordereau' => 'Bordereau',
    ];

    /** États proposés par Fichier → Exportation (hors barre d'outils). */
    public const EXPORTS = [
        'employes' => 'Liste du personnel',
        'annuel' => 'Récapitulatif annuel des paies',
    ];

    public static function etat(string $etat, Client $client, string $mois): Etat
    {
        $e = self::construire($etat, $client, $mois);
        $k = $client->paie_config ?? [];
        if (! empty($k['afficher_logo']) && ! empty($k['logo'])) $e->logo = $k['logo'];
        return $e;
    }

    private static function construire(string $etat, Client $client, string $mois): Etat
    {
        return match ($etat) {
            'journal' => self::journal($client, $mois),
            'dcnss' => self::dcnss($client, $mois),
            'recap-cnss' => self::recapCnss($client, $mois),
            'dcnss-patronal' => self::dcnssPatronal($client, $mois),
            'demp' => self::demp($client, $mois),
            'virement' => self::virement($client, $mois),
            'bordereau' => self::bordereau($client, $mois),
            'employes' => self::employes($client),
            'annuel' => self::annuel($client, $mois),
        };
    }

    /** Liste du personnel avec les informations de la fiche employé. */
    public static function employes(Client $client): Etat
    {
        $modes = ['virement' => 'Virement', 'cheque' => 'Chèque', 'especes' => 'Espèces'];
        $e = new Etat('Liste du personnel', $client->nom, 'Au '.now()->format('d/m/Y'), [
            ['Matricule', 'text'], ['Nom et prénom', 'text'], ['CIN', 'text'], ['N° CNSS', 'text'], ['Poste', 'text'], ['Embauche', 'date'],
            ['Chef de famille', 'text'], ['Enfants', 'text'], ['Paiement', 'text'], ['Banque', 'text'], ['RIB', 'text'], ['Salaire de base', 'num'],
        ]);
        $tot = 0.0;
        foreach (self::salaries($client) as $s) {
            $e->ligne([$s->matricule, $s->nomComplet(), $s->cin, $s->cnss, $s->poste, $s->date_embauche, $s->chef_famille ? 'Oui' : 'Non', (string) $s->enfants,
                $modes[$s->mode_paiement] ?? '', $s->banque, $s->rib, (float) $s->salaire_base]);
            $tot += $s->salaire_base;
        }
        $e->ligne(['', 'TOTAL', '', '', '', null, '', '', '', '', '', r3($tot)], 'total');
        return $e;
    }

    /** Récapitulatif annuel : totaux de paie mois par mois pour l'année de $mois. */
    public static function annuel(Client $client, string $mois): Etat
    {
        $annee = substr($mois, 0, 4);
        $cles = ['brut', 'cnssS', 'ir', 'css', 'net', 'cnssP', 'tfp', 'fop', 'cout'];
        $e = new Etat('Récapitulatif annuel des paies', $client->nom, 'Année '.$annee, [
            ['Mois', 'text'], ['Effectif', 'text'], ['Brut', 'num'], ['CNSS salariale', 'num'], ['IRPP', 'num'], ['CSS', 'num'], ['Net', 'num'],
            ['CNSS patronale', 'num'], ['TFP', 'num'], ['FOPROLOS', 'num'], ['Coût employeur', 'num'],
        ]);
        $tot = array_fill_keys($cles, 0.0);
        foreach (range(1, 12) as $i) {
            $m = sprintf('%s-%02d', $annee, $i);
            $T = PaieCalc::totaux($R = PaieCalc::mois($client, $m));
            $e->ligne([ucfirst(mois_fr($m)), (string) count($R), ...array_map(fn ($k) => $T[$k], $cles)]);
            foreach ($cles as $k) $tot[$k] += $T[$k];
        }
        $e->ligne(['TOTAL '.$annee, '', ...array_map('r3', array_values($tot))], 'total');
        return $e;
    }

    /** Les trois mois du trimestre contenant $mois (AAAA-MM). */
    public static function trimestre(string $mois): array
    {
        $d = Carbon::parse($mois.'-01');
        $debut = $d->copy()->firstOfQuarter();
        return array_map(fn ($i) => $debut->copy()->addMonths($i)->format('Y-m'), [0, 1, 2]);
    }

    public static function libelleTrimestre(string $mois): string
    {
        $d = Carbon::parse($mois.'-01');
        return $d->quarter.($d->quarter === 1 ? 'er' : 'e').' trimestre '.$d->year;
    }

    private static function salaries(Client $client)
    {
        return PaieCalc::trier($client->salaries()->get(), PaieCalc::parametres($client)['tri'])->keyBy('id');
    }

    /** Journal de paie du mois : une ligne par salarié, toutes les rubriques. */
    public static function journal(Client $client, string $mois): Etat
    {
        $R = PaieCalc::mois($client, $mois);
        $e = new Etat('Journal de paie', $client->nom, mois_fr($mois), [
            ['Salarié', 'text'], ['Jours', 'text'], ['Salaire de base', 'num'], ['H. sup.', 'text'], ['Montant H. sup.', 'num'], ['Primes', 'num'], ['Brut', 'num'], ['CNSS salariale', 'num'], ['Imposable annuel', 'num'],
            ['IRPP', 'num'], ['CSS', 'num'], ['Net à payer', 'num'], ['CNSS patronale', 'num'], ['TFP', 'num'], ['FOPROLOS', 'num'], ['Coût employeur', 'num'],
        ]);
        foreach ($R as $x) {
            $e->ligne([$x['nom'], (string) ($x['jours'] ?? ''), $x['salaire'] ?? $x['base'], ($x['hs'] ?? 0) ? (string) $x['hs'] : '', $x['hs_montant'] ?? 0, $x['prime'], $x['brut'], $x['cnssS'], $x['bi'], $x['ir'], $x['css'], $x['net'], $x['cnssP'], $x['tfp'], $x['fop'], $x['cout']]);
        }
        $T = PaieCalc::totaux($R);
        $e->ligne(['TOTAL (' . count($R) . ' salarié(s))', '', r3(array_sum(array_map(fn ($x) => $x['salaire'] ?? $x['base'], $R))), '', r3(array_sum(array_column($R, 'hs_montant'))), r3(array_sum(array_column($R, 'prime'))), $T['brut'], $T['cnssS'], null,
            $T['ir'], $T['css'], $T['net'], $T['cnssP'], $T['tfp'], $T['fop'], $T['cout']], 'total');
        return $e;
    }

    /** Déclaration trimestrielle des salaires à la CNSS : salaire brut de chaque mois du trimestre par assuré. */
    public static function dcnss(Client $client, string $mois): Etat
    {
        $mm = self::trimestre($mois);
        $e = new Etat('Déclaration trimestrielle des salaires (CNSS)', $client->nom,
            self::libelleTrimestre($mois).($client->matricule_cnss ? ' · employeur n° '.$client->matricule_cnss : ''), [
            ['N°', 'text'], ['Matricule CNSS', 'text'], ['Nom et prénom', 'text'], ['CIN', 'text'],
            ...array_map(fn ($m) => ['Salaire '.Carbon::parse($m.'-01')->locale('fr')->translatedFormat('F'), 'num'], $mm), ['Total trimestre', 'num'],
        ], false);
        $par = self::parSalarie($client, $mm, 'brut');
        $tot = [0, 0, 0, 0]; $n = 0;
        foreach ($par as $p) {
            $v = [...array_map(fn ($m) => $p['mois'][$m] ?? 0.0, $mm)];
            $v[] = r3(array_sum($v));
            $e->ligne([++$n, $p['cnss'], $p['nom'], $p['cin'], ...$v]);
            foreach ($v as $k => $x) $tot[$k] += $x;
        }
        $e->ligne(['', '', 'TOTAL', '', ...array_map('r3', $tot)], 'total');
        return $e;
    }

    /** Récapitulatif des cotisations CNSS du trimestre (mois par mois). */
    public static function recapCnss(Client $client, string $mois): Etat
    {
        $P = PaieCalc::parametres($client);
        $e = new Etat('Récapitulatif CNSS', $client->nom, self::libelleTrimestre($mois), [
            ['Mois', 'text'], ['Effectif', 'text'], ['Masse salariale', 'num'], ['Part salariale ('.$P['cnssS'].' %)', 'num'], ['Part patronale ('.$P['cnssP'].' %)', 'num'], ['Total à payer', 'num'],
        ], false);
        $tot = [0, 0, 0, 0];
        foreach (self::trimestre($mois) as $m) {
            $T = PaieCalc::totaux($R = PaieCalc::mois($client, $m));
            $v = [$T['brut'], $T['cnssS'], $T['cnssP'], r3($T['cnssS'] + $T['cnssP'])];
            $e->ligne([ucfirst(mois_fr($m)), (string) count($R), ...$v]);
            foreach ($v as $k => $x) $tot[$k] += $x;
        }
        $e->ligne(['TOTAL DU TRIMESTRE', '', ...array_map('r3', $tot)], 'total');
        return $e;
    }

    /** Cotisations patronales du trimestre par salarié (CNSS patronale, TFP, FOPROLOS). */
    public static function dcnssPatronal(Client $client, string $mois): Etat
    {
        $mm = self::trimestre($mois);
        $e = new Etat('Déclaration des charges patronales', $client->nom, self::libelleTrimestre($mois), [
            ['Matricule CNSS', 'text'], ['Nom et prénom', 'text'], ['Brut du trimestre', 'num'], ['CNSS salariale', 'num'], ['CNSS patronale', 'num'],
            ['Total CNSS', 'num'], ['TFP', 'num'], ['FOPROLOS', 'num'],
        ]);
        $cles = ['brut', 'cnssS', 'cnssP', 'tfp', 'fop'];
        $tot = array_fill(0, 6, 0.0);
        foreach (self::cumul($client, $mm, $cles) as $p) {
            $c = $p['cumul'];
            $v = [$c['brut'], $c['cnssS'], $c['cnssP'], r3($c['cnssS'] + $c['cnssP']), $c['tfp'], $c['fop']];
            $e->ligne([$p['cnss'], $p['nom'], ...$v]);
            foreach ($v as $k => $x) $tot[$k] += $x;
        }
        $e->ligne(['', 'TOTAL', ...array_map('r3', $tot)], 'total');
        return $e;
    }

    /** Déclaration employeur annuelle (annexe 1 : traitements et salaires) pour l'année de $mois. */
    public static function demp(Client $client, string $mois): Etat
    {
        $annee = substr($mois, 0, 4);
        $mm = array_map(fn ($i) => sprintf('%s-%02d', $annee, $i), range(1, 12));
        $e = new Etat('Déclaration employeur — Annexe 1 (traitements et salaires)', $client->nom,
            'Année '.$annee.($client->matricule_fiscal ? ' · MF '.$client->matricule_fiscal : ''), [
            ['N°', 'text'], ['Nom et prénom', 'text'], ['CIN', 'text'], ['Situation', 'text'], ['Brut annuel', 'num'], ['CNSS salariale', 'num'],
            ['Net imposable', 'num'], ['Retenue IRPP', 'num'], ['CSS', 'num'], ['Net servi', 'num'],
        ]);
        $cles = ['brut', 'cnssS', 'ir', 'css', 'net'];
        $tot = array_fill(0, 6, 0.0); $n = 0;
        $sal = self::salaries($client);
        foreach (self::cumul($client, $mm, $cles) as $id => $p) {
            $c = $p['cumul'];
            $s = $sal[$id] ?? null;
            $v = [$c['brut'], $c['cnssS'], r3($c['brut'] - $c['cnssS']), $c['ir'], $c['css'], $c['net']];
            $e->ligne([++$n, $p['nom'], $p['cin'], $s ? ($s->chef_famille ? 'C.F.' : 'N.C.F.').' · '.$s->enfants.' enf.' : '', ...$v]);
            foreach ($v as $k => $x) $tot[$k] += $x;
        }
        $e->ligne(['', 'TOTAL', '', '', ...array_map('r3', $tot)], 'total');
        return $e;
    }

    /** Ordre de virement des salaires nets du mois (salariés payés par virement). */
    public static function virement(Client $client, string $mois): Etat
    {
        $sal = self::salaries($client);
        $e = new Etat('Ordre de virement des salaires', $client->nom, mois_fr($mois)
            .($client->paie_rib ? ' · compte à débiter : '.trim($client->paie_banque.' '.$client->paie_rib) : ''), [
            ['N°', 'text'], ['Bénéficiaire', 'text'], ['Banque', 'text'], ['RIB', 'text'], ['Montant net', 'num'],
        ], false);
        $tot = 0.0; $n = 0;
        foreach (PaieCalc::mois($client, $mois) as $x) {
            $s = $sal[$x['salarie_id']] ?? null;
            if ($s && $s->mode_paiement !== 'virement') continue;
            $e->ligne([++$n, $x['nom'], $s?->banque, $s?->rib ?: 'RIB manquant', $x['net']]);
            $tot += $x['net'];
        }
        $e->ligne(['', 'TOTAL À VIRER', '', '', r3($tot)], 'total');
        return $e;
    }

    /** Bordereau de paie : liste d'émargement des nets payés du mois. */
    public static function bordereau(Client $client, string $mois): Etat
    {
        $sal = self::salaries($client);
        $modes = ['virement' => 'Virement', 'cheque' => 'Chèque', 'especes' => 'Espèces'];
        $e = new Etat('Bordereau de paie (émargement)', $client->nom, mois_fr($mois), [
            ['N°', 'text'], ['Matricule', 'text'], ['Nom et prénom', 'text'], ['Mode de paiement', 'text'], ['Net à payer', 'num'], ['Signature', 'text'],
        ], false);
        $tot = 0.0; $n = 0;
        foreach (PaieCalc::mois($client, $mois) as $x) {
            $s = $sal[$x['salarie_id']] ?? null;
            $e->ligne([++$n, $s?->matricule, $x['nom'], $modes[$s?->mode_paiement ?? 'virement'] ?? '', $x['net'], '']);
            $tot += $x['net'];
        }
        $e->ligne(['', '', 'TOTAL', '', r3($tot), ''], 'total');
        return $e;
    }

    /** Valeur d'une rubrique par salarié et par mois. */
    private static function parSalarie(Client $client, array $mois, string $cle): array
    {
        $par = [];
        foreach ($mois as $m) {
            foreach (PaieCalc::mois($client, $m) as $x) {
                $par[$x['salarie_id']] ??= ['nom' => $x['nom'], 'cin' => $x['cin'] ?? null, 'cnss' => $x['cnss'] ?? null, 'mois' => []];
                $par[$x['salarie_id']]['mois'][$m] = $x[$cle];
            }
        }
        uasort($par, fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));
        return $par;
    }

    /** Cumul de plusieurs rubriques par salarié sur une liste de mois. */
    private static function cumul(Client $client, array $mois, array $cles): array
    {
        $par = [];
        foreach ($mois as $m) {
            foreach (PaieCalc::mois($client, $m) as $x) {
                $par[$x['salarie_id']] ??= ['nom' => $x['nom'], 'cin' => $x['cin'] ?? null, 'cnss' => $x['cnss'] ?? null, 'cumul' => array_fill_keys($cles, 0.0)];
                foreach ($cles as $k) $par[$x['salarie_id']]['cumul'][$k] = r3($par[$x['salarie_id']]['cumul'][$k] + $x[$k]);
            }
        }
        uasort($par, fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));
        return $par;
    }
}
