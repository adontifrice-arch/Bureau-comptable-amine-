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
        'drc' => 'Déclaration du régime complémentaire',
        'recap' => 'Récapitulation de paie',
        'vierge' => 'État vierge de saisie',
    ];

    /**
     * Déclaration trimestrielle du régime complémentaire CNSS : salariés affiliés (case « CNSS régime complémentaire » de la fiche),
     * assiette = part du salaire cotisable de chaque mois au-delà du plafond (6 × SMIG, Renseignements employeur), et cotisations
     * aux taux salarié / employeur du dossier quand ils sont renseignés.
     */
    public static function drc(Client $client, string $mois): Etat
    {
        $k = $client->paie_config ?? [];
        $plafond = (float) ($k['plafond_rc'] ?? 0);
        $ts = (float) ($k['rc_taux_s'] ?? 0);
        $tp = (float) ($k['rc_taux_p'] ?? 0);
        $mm = self::trimestre($mois);
        $e = new Etat('Déclaration du régime complémentaire (CNSS)', $client->nom,
            self::libelleTrimestre($mois).' · plafond mensuel '.($plafond ? n3($plafond) : 'non renseigné').($client->matricule_cnss ? ' · employeur n° '.$client->matricule_cnss : ''), [
            ['N°', 'text'], ['Matricule CNSS', 'text'], ['Nom et prénom', 'text'], ['CIN', 'text'],
            ...array_map(fn ($m) => ['Excédent '.Carbon::parse($m.'-01')->locale('fr')->translatedFormat('F'), 'num'], $mm),
            ['Assiette du trimestre', 'num'], ['Cotisation salariale'.($ts ? ' ('.$ts.' %)' : ''), 'num'], ['Cotisation patronale'.($tp ? ' ('.$tp.' %)' : ''), 'num'],
        ]);
        $affilies = self::salaries($client)->filter(fn ($s) => (bool) $s->cnss_rc)->keys()->all();
        $par = array_filter(self::parSalarie($client, $mm, 'base_cnss', 'decl_cnss'), fn ($id) => in_array($id, $affilies), ARRAY_FILTER_USE_KEY);
        $tot = array_fill(0, 6, 0.0); $n = 0;
        foreach ($par as $p) {
            $v = array_map(fn ($m) => r3(max(0, ($p['mois'][$m] ?? 0) - $plafond)), $mm);
            $assiette = r3(array_sum($v));
            $v = [...$v, $assiette, r3($assiette * $ts / 100), r3($assiette * $tp / 100)];
            $e->ligne([++$n, $p['cnss'], $p['nom'], $p['cin'], ...$v]);
            foreach ($v as $i => $x) $tot[$i] += $x;
        }
        if (! $n) $e->ligne(['', '', 'NÉANT', '', ...array_fill(0, 6, null)]);   // déclaration néant : aucun salaire d'affilié ce trimestre
        $e->ligne(['', '', 'TOTAL ('.$n.' affilié(s))', '', ...array_map('r3', $tot)], 'total');
        return $e;
    }

    /** Congé → Etat des Congés. */
    public const CONGES = ['conges-solde' => 'Solde des congés', 'conges-registre' => 'Registre des congés de l’année', 'conges-salarie' => 'Congés par salarié'];

    /** Solde des congés de chaque salarié à la fin du mois $mois : report des années précédentes, acquis et pris de l'année, reste. */
    public static function congesSolde(Client $client, string $mois): Etat
    {
        $e = new Etat('Solde des congés', $client->nom, 'À fin '.mois_fr($mois), [
            ['Matricule', 'text'], ['Nom et prénom', 'text'], ['Embauche', 'date'], ['Acquis / mois', 'text'], ['Report années préc.', 'num'], ['Acquis '.substr($mois, 0, 4), 'num'],
            ['Pris '.substr($mois, 0, 4), 'num'], ['Reste (jours)', 'num'], ['Valeur (TND)', 'num'],
        ]);
        $P = PaieCalc::parametres($client);
        $tot = array_fill(0, 5, 0.0);
        foreach (self::salaries($client) as $s) {
            if ($s->date_sortie && $s->date_sortie->format('Y-m') < substr($mois, 0, 4).'-01') continue;
            $c = PaieCalc::soldeConges($s, $mois);
            $base = $s->regime === 'horaire' ? (float) $s->salaire_base * (float) $P['heures_mois'] : (float) $s->salaire_base;
            $valeur = r3(max(0, $c['reste']) * ($P['jours_mois'] > 0 ? $base / $P['jours_mois'] : 0));
            $v = [$c['report'], $c['acquis'], $c['pris'], $c['reste'], $valeur];
            $e->ligne([$s->matricule, $s->nomComplet(), $s->date_embauche, rtrim(rtrim(number_format((float) $s->conges_acquis, 2, ',', ''), '0'), ','), ...$v]);
            foreach ($v as $k => $x) $tot[$k] += $x;
        }
        $e->ligne(['', 'TOTAL', null, '', ...array_map('r3', $tot)], 'total');
        return $e;
    }

    /** Registre des congés de l'année de $mois (Congé → Saisie des congés), dans l'ordre des dates. */
    public static function congesRegistre(Client $client, string $mois, bool $parSalarie = false): Etat
    {
        $annee = substr($mois, 0, 4);
        $e = new Etat($parSalarie ? 'Congés par salarié' : 'Registre des congés', $client->nom, 'Année '.$annee, [
            ['Matricule', 'text'], ['Nom et prénom', 'text'], ['Type', 'text'], ['Du', 'date'], ['Au', 'date'], ['Jours', 'num'], ['Observation', 'text'],
        ], false);
        $conges = \App\Models\Conge::with('salarie')->where('client_id', $client->id)->where('au', '>=', $annee.'-01-01')->where('du', '<=', $annee.'-12-31 23:59:59')->orderBy('du')->get();
        $groupes = $parSalarie ? $conges->groupBy(fn ($c) => $c->salarie?->nomComplet() ?? '—')->sortKeys() : collect(['' => $conges]);
        $tot = 0.0;
        foreach ($groupes as $nom => $grp) {
            if ($parSalarie) $e->ligne(['', mb_strtoupper($nom), '', null, null, null, ''], 'groupe');
            foreach ($grp as $c) $e->ligne([$c->salarie?->matricule, $c->salarie?->nomComplet(), \App\Models\Conge::TYPES[$c->type] ?? $c->type, $c->du, $c->au, $c->jours, $c->observation]);
            if ($parSalarie) {
                $paye = $grp->where('type', 'paye')->sum('jours');
                $e->ligne(['', 'Sous-total '.$nom.' (congés payés : '.rtrim(rtrim(number_format($paye, 2, ',', ''), '0'), ',').' j)', '', null, null, r3($grp->sum('jours')), ''], 'soustotal');
            }
            $tot += $grp->sum('jours');
        }
        $e->ligne(['', 'TOTAL ('.$conges->count().' congé(s))', '', null, null, r3($tot), ''], 'total');
        return $e;
    }

    /** Acompte → Etat des acomptes. */
    public const ACOMPTES = ['acomptes-mois' => 'Acomptes du mois', 'acomptes-annuel' => 'Récapitulatif annuel des acomptes'];

    /** Acomptes retenus sur la paie du mois $mois, avec la signature du salarié. */
    public static function acomptesMois(Client $client, string $mois): Etat
    {
        $e = new Etat('État des acomptes', $client->nom, 'Retenus sur la paie de '.mois_fr($mois), [
            ['N°', 'text'], ['Matricule', 'text'], ['Nom et prénom', 'text'], ['Date', 'date'], ['Mode', 'text'], ['Montant', 'num'], ['Observation', 'text'], ['Signature', 'text'],
        ]);
        $L = \App\Models\Acompte::with('salarie')->where('client_id', $client->id)->where('mois', $mois)->orderBy('date')->get();
        $n = 0;
        foreach ($L as $a) $e->ligne([++$n, $a->salarie?->matricule, $a->salarie?->nomComplet(), $a->date, self::MODES[$a->mode] ?? $a->mode, $a->montant, $a->observation, '']);
        $e->ligne(['', '', 'TOTAL ('.$L->count().')', null, '', r3($L->sum('montant')), '', ''], 'total');
        return $e;
    }

    /** Acomptes de l'année de $mois par salarié et par mois de retenue. */
    public static function acomptesAnnuel(Client $client, string $mois): Etat
    {
        $annee = substr($mois, 0, 4);
        $mm = array_map(fn ($i) => sprintf('%s-%02d', $annee, $i), range(1, 12));
        $e = new Etat('Récapitulatif annuel des acomptes', $client->nom, 'Année '.$annee, [
            ['Nom et prénom', 'text'], ...array_map(fn ($m) => [ucfirst(Carbon::parse($m.'-01')->locale('fr')->translatedFormat('M')), 'num'], $mm), ['Total', 'num'],
        ]);
        $L = \App\Models\Acompte::with('salarie')->where('client_id', $client->id)->where('mois', 'like', $annee.'-%')->get();
        $tot = array_fill(0, 13, 0.0);
        foreach ($L->groupBy('salarie_id')->sortBy(fn ($g) => mb_strtolower($g->first()->salarie?->nom ?? '')) as $g) {
            $v = array_map(fn ($m) => r3($g->where('mois', $m)->sum('montant')), $mm);
            $v[] = r3(array_sum($v));
            $e->ligne([$g->first()->salarie?->nomComplet(), ...$v]);
            foreach ($v as $k => $x) $tot[$k] += $x;
        }
        $e->ligne(['TOTAL', ...array_map('r3', $tot)], 'total');
        return $e;
    }

    /** Prêt → Prêt Société / Prêt CNSS → états (option « type » : societe ou cnss). */
    public const PRETS = ['prets-etat' => 'Etat des prêts', 'prets-retenues' => 'Retenues du mois'];

    /** Prêts en cours ou accordés dans l'année : montant, échéance, remboursé et reste dû à la fin du mois $mois. */
    public static function pretsEtat(Client $client, string $mois, string $type): Etat
    {
        $type = isset(\App\Models\Pret::TYPES[$type]) ? $type : 'societe';
        $e = new Etat('Etat des prêts — '.\App\Models\Pret::TYPES[$type], $client->nom, 'Situation à fin '.mois_fr($mois), [
            ['Matricule', 'text'], ['Nom et prénom', 'text'], ['Référence', 'text'], ['Date', 'date'], ['Montant', 'num'], ['Échéance', 'num'], ['Nb éch.', 'text'],
            ['Du', 'text'], ['Au', 'text'], ['Remboursé', 'num'], ['Reste dû', 'num'],
        ]);
        $tot = [0.0, 0.0, 0.0];
        $prets = \App\Models\Pret::with('salarie')->where('client_id', $client->id)->where('type', $type)->where('premier_mois', '<=', $mois)->orderBy('date')->get()
            ->filter(fn ($p) => $p->situation($mois)['reste'] > 0.0005 || substr((string) $p->dernierMois(), 0, 4) === substr($mois, 0, 4));
        foreach ($prets as $p) {
            $s = $p->situation($mois);
            $e->ligne([$p->salarie?->matricule, $p->salarie?->nomComplet(), $p->reference, $p->date, $p->montant, $p->echeance, (string) $p->nb_echeances,
                ucfirst(mois_fr($p->premier_mois)), $p->dernierMois() ? ucfirst(mois_fr($p->dernierMois())) : '', $s['rembourse'], $s['reste']]);
            $tot[0] += $p->montant; $tot[1] += $s['rembourse']; $tot[2] += $s['reste'];
        }
        $e->ligne(['', 'TOTAL ('.$prets->count().')', '', null, r3($tot[0]), null, '', '', '', r3($tot[1]), r3($tot[2])], 'total');
        return $e;
    }

    /** Échéances retenues sur la paie du mois, à reverser (prêt CNSS) ou à imputer (prêt société). */
    public static function pretsRetenues(Client $client, string $mois, string $type): Etat
    {
        $type = isset(\App\Models\Pret::TYPES[$type]) ? $type : 'societe';
        $e = new Etat('Retenues '.mb_strtolower(\App\Models\Pret::TYPES[$type]), $client->nom, mois_fr($mois), [
            ['N°', 'text'], ['Matricule', 'text'], ['Nom et prénom', 'text'], ['Référence', 'text'], ['Échéance', 'num'], ['Reste dû après', 'num'],
        ], false);
        $n = 0; $tot = 0.0;
        foreach (\App\Models\Pret::with('salarie')->where('client_id', $client->id)->where('type', $type)->where('premier_mois', '<=', $mois)->orderBy('salarie_id')->get() as $p) {
            if (! ($m = $p->echeanceDuMois($mois))) continue;
            $e->ligne([++$n, $p->salarie?->matricule, $p->salarie?->nomComplet(), $p->reference, $m, $p->situation($mois)['reste']]);
            $tot += $m;
        }
        $e->ligne(['', '', 'TOTAL', '', r3($tot), null], 'total');
        return $e;
    }

    /** Modes de paiement (bordereau payeur, enveloppes). */
    public const MODES = ['virement' => 'Virement', 'cheque' => 'Chèque', 'especes' => 'Espèces'];

    /**
     * Banques du récapitulatif de virement : code banque (2 premiers chiffres du RIB tunisien) et mots reconnus dans le nom de la banque
     * de la fiche employé quand le RIB manque. formats : fichiers proposés en plus de l'état imprimé.
     */
    public const BANQUES = [
        'uib' => ['nom' => 'UIB', 'code' => '12', 'mots' => ['uib', 'union internationale'], 'formats' => []],
        'biat' => ['nom' => 'BIAT', 'code' => '08', 'mots' => ['biat'], 'formats' => ['txt', 'xls']],
        'stb' => ['nom' => 'STB', 'code' => '10', 'mots' => ['stb', 'societe tunisienne de banque', 'société tunisienne de banque'], 'formats' => []],
        'bt' => ['nom' => 'BT', 'code' => '05', 'mots' => ['banque de tunisie'], 'formats' => []],
        'attijari' => ['nom' => 'Attijari', 'code' => '04', 'mots' => ['attijari'], 'formats' => ['txt', 'xls']],
        'ubci' => ['nom' => 'UBCI', 'code' => '11', 'mots' => ['ubci'], 'formats' => []],
    ];

    /** La banque du salarié (RIB, sinon nom de la banque) est $banque. */
    public static function estDeLaBanque(?\App\Models\Salarie $s, string $banque): bool
    {
        $b = self::BANQUES[$banque] ?? null;
        if (! $s || ! $b) return false;
        $rib = preg_replace('/\D/', '', (string) $s->rib);
        if (strlen($rib) >= 2) return substr($rib, 0, 2) === $b['code'];
        $nom = mb_strtolower(trim((string) $s->banque));
        if ($nom === mb_strtolower($b['nom'])) return true;
        foreach ($b['mots'] as $m) if (str_contains($nom, $m)) return true;
        return false;
    }

    /**
     * Paie éditée (option « paie » des éditions) : normale (paie du mois, par défaut), une paie spéciale (conge, m13… m18) déclarée avec
     * le mois, ou « tous » (paie du mois et paies spéciales déclarées avec le mois, brouillons compris).
     */
    public static function paieChoisie(?string $paie): string
    {
        return $paie === 'tous' || ctype_digit((string) $paie) || isset(\App\Models\Paie::TYPES[$paie ?? '']) ? $paie : 'normale';
    }

    /** Paie désignée par son numéro (menu Prime : la prime ouverte), dans le dossier. */
    public static function paieParNumero(Client $client, ?string $paie): ?\App\Models\Paie
    {
        return ctype_digit((string) $paie) ? \App\Models\Paie::where('client_id', $client->id)->find((int) $paie) : null;
    }

    /** Résultats de la paie éditée ; avec « tous », le type de paie spéciale est ajouté au nom du salarié. */
    public static function resultats(Client $client, string $mois, ?string $paie = null): array
    {
        $paie = self::paieChoisie($paie);
        if (ctype_digit($paie)) return ($p = self::paieParNumero($client, $paie)) ? PaieCalc::moisSpecial($client, $p) : [];
        if ($paie === 'normale') return PaieCalc::mois($client, $mois);
        $speciale = fn ($t) => \App\Models\Paie::where('client_id', $client->id)->where('mois', $mois)->where('type', $t)->first()
            ?? new \App\Models\Paie(['client_id' => $client->id, 'mois' => $mois, 'type' => $t, 'statut' => 'brouillon']);
        if ($paie !== 'tous') return PaieCalc::moisSpecial($client, $speciale($paie));
        $R = PaieCalc::mois($client, $mois);
        foreach (PaieCalc::speciales($client, $mois, true) as $p) {
            foreach (PaieCalc::moisSpecial($client, $p) as $x) $R[] = ['nom' => $x['nom'].' — '.$p->nomType()] + $x;
        }
        return $R;
    }

    /** Période affichée en tête de l'état : « octobre 2026 », « 13ème mois — déclaré avec octobre 2026 », « Prime de bilan — déclarée avec … ». */
    public static function periode(string $mois, ?string $paie = null, ?Client $client = null): string
    {
        $paie = self::paieChoisie($paie);
        if (ctype_digit($paie)) return ($client && ($p = self::paieParNumero($client, $paie))) ? $p->libelle() : 'Paie n° '.$paie;
        return match ($paie) {
            'normale' => mois_fr($mois),
            'tous' => mois_fr($mois).' · paie du mois et paies spéciales',
            default => \App\Models\Paie::TYPES[$paie].' — déclaré avec '.mois_fr($mois),
        };
    }

    /** Salariés payés par virement (tous, ou ceux d'une banque) : [[résultat de paie, fiche employé], …]. */
    public static function aVirer(Client $client, string $mois, ?string $banque = null, ?string $paie = null): array
    {
        $sal = self::salaries($client);
        $out = [];
        foreach (self::resultats($client, $mois, $paie) as $x) {
            $s = $sal[$x['salarie_id']] ?? null;
            if ($s && $s->mode_paiement !== 'virement') continue;
            if ($banque && ! self::estDeLaBanque($s, $banque)) continue;
            $out[] = [$x, $s];
        }
        return $out;
    }

    /**
     * Fichier texte de virement (une ligne par bénéficiaire, champs séparés par « ; ») : RIB du donneur d'ordre, RIB du bénéficiaire,
     * montant en millimes, nom du bénéficiaire, motif. Format générique, à aligner sur la spécification remise par la banque.
     */
    public static function fichierVirement(Client $client, string $mois, ?string $banque = null, ?string $paie = null): string
    {
        $donneur = preg_replace('/\D/', '', (string) $client->paie_rib);
        $p = self::paieChoisie($paie);
        $nom = match (true) {
            in_array($p, ['normale', 'tous'], true) => 'SALAIRE '.mois_fr($mois),
            ctype_digit($p) => (self::paieParNumero($client, $p)?->nomType() ?? 'PRIME').' '.mois_fr($mois),
            default => \App\Models\Paie::TYPES[$p].' '.substr($mois, 0, 4),
        };
        $motif = mb_strtoupper(\Illuminate\Support\Str::ascii($nom));
        $lignes = [];
        foreach (self::aVirer($client, $mois, $banque, $paie) as [$x, $s]) {
            $nom = mb_strtoupper(\Illuminate\Support\Str::limit(\Illuminate\Support\Str::ascii($x['nom']), 35, ''));
            $lignes[] = implode(';', [$donneur, preg_replace('/\D/', '', (string) $s?->rib), (string) (int) round($x['net'] * 1000), $nom, $motif]);
        }
        return implode("\r\n", $lignes).($lignes ? "\r\n" : '');
    }

    public static function etat(string $etat, Client $client, string $mois, array $opt = []): Etat
    {
        $e = self::construire($etat, $client, $mois, $opt);
        $k = $client->paie_config ?? [];
        if (! empty($k['afficher_logo']) && ! empty($k['logo'])) $e->logo = $k['logo'];
        return $e;
    }

    private static function construire(string $etat, Client $client, string $mois, array $opt = []): Etat
    {
        return match ($etat) {
            'journal' => self::journal($client, $mois, (string) ($opt['modele'] ?? 'modele1'), (string) ($opt['groupe'] ?? 'integral'), $opt['paie'] ?? null),
            'dcnss' => self::dcnss($client, $mois),
            'recap-cnss' => self::recapCnss($client, $mois),
            'dcnss-patronal' => self::dcnssPatronal($client, $mois),
            'drc' => self::drc($client, $mois),
            'demp' => self::demp($client, $mois),
            'virement' => self::virement($client, $mois, isset(self::BANQUES[$opt['banque'] ?? '']) ? $opt['banque'] : null, $opt['paie'] ?? null),
            'bordereau' => self::bordereau($client, $mois, isset(self::MODES[$opt['mode'] ?? '']) ? $opt['mode'] : null, ($opt['groupe'] ?? null) === 'departement', $opt['paie'] ?? null),
            'recap' => self::recap($client, $mois, $opt['paie'] ?? null),
            'vierge' => self::vierge($client, $mois),
            'employes' => self::employes($client),
            'annuel' => self::annuel($client, $mois),
            'conges-solde' => self::congesSolde($client, $mois),
            'conges-registre' => self::congesRegistre($client, $mois),
            'conges-salarie' => self::congesRegistre($client, $mois, true),
            'acomptes-mois' => self::acomptesMois($client, $mois),
            'acomptes-annuel' => self::acomptesAnnuel($client, $mois),
            'prets-etat' => self::pretsEtat($client, $mois, (string) ($opt['type'] ?? 'societe')),
            'prets-retenues' => self::pretsRetenues($client, $mois, (string) ($opt['type'] ?? 'societe')),
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
            $T = PaieCalc::totaux($R = PaieCalc::moisDeclares($client, $m));
            $e->ligne([ucfirst(mois_fr($m)), (string) count(array_unique(array_column($R, 'salarie_id'))), ...array_map(fn ($k) => $T[$k], $cles)]);
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

    /** Paie → Journal : modèles et regroupements. */
    public const JOURNAUX = ['modele1' => 'Modèle 1', 'modele2' => 'Modèle 2', 'detaille' => 'Détaillé', 'variable' => 'Variable'];
    public const GROUPES = ['integral' => 'Intégral', 'profil' => 'Par profil', 'regime' => 'Par régime salarial', 'departement' => 'Par département'];

    /**
     * Journal de paie du mois, une ligne par salarié :
     *  - modèle 1 : pointage, brut, cotisations, impôt, net et charges patronales ;
     *  - modèle 2 : vue condensée du brut au coût employeur (retenues regroupées) ;
     *  - détaillé : une colonne par élément du bulletin, rubriques du dossier comprises ;
     *  - variable : les seuls éléments variables du mois (pointage, congés, fériés, heures sup., primes, acompte, rubriques saisies).
     * Regroupement intégral, par profil, par régime salarial ou par département, avec un sous-total par groupe.
     */
    public static function journal(Client $client, string $mois, string $modele = 'modele1', string $groupe = 'integral', ?string $paie = null): Etat
    {
        $modele = isset(self::JOURNAUX[$modele]) ? $modele : 'modele1';
        $groupe = isset(self::GROUPES[$groupe]) ? $groupe : 'integral';
        $R = self::resultats($client, $mois, $paie);
        $sal = self::salaries($client);
        $aSaisir = $modele === 'variable' ? \App\Models\PaieRubrique::duDossier($client)->filter->aSaisir()->pluck('id')->all() : [];
        $rubriques = collect($R)->flatMap(fn ($x) => $x['rubriques'] ?? [])->unique('code')->sortBy('code')
            ->when($modele === 'variable', fn ($c) => $c->filter(fn ($l) => in_array($l['id'] ?? null, $aSaisir, true)))
            ->when($modele === 'detaille', fn ($c) => $c->where('sens', '!=', 'note'))
            ->values();
        $rub = fn ($x, $l) => collect($x['rubriques'] ?? [])->where('code', $l['code'])->sum(fn ($y) => $l['sens'] === 'note' || $modele === 'variable' && $y['quantite'] !== null ? (float) $y['quantite'] : (float) $y['montant']);
        $retenuesTot = fn ($x) => r3($x['cnssS'] + $x['ir'] + $x['css'] + ($x['retenues'] ?? 0) + ($x['nature'] ?? 0) + ($x['acompte'] ?? 0));

        // Colonnes : [libellé, type, valeur(x), totalisable]
        $c = fn ($l, $t, $f, $tot = true) => [$l, $t, $f, $tot && $t === 'num'];
        $nom = [$c('Matricule', 'text', fn ($x) => $x['matricule'] ?? ($sal[$x['salarie_id']]->matricule ?? '')), $c('Nom et prénom', 'text', fn ($x) => $x['nom'])];
        $nb = fn ($v) => $v === null || $v === '' || (float) $v == 0 ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
        $cols = match ($modele) {
            'modele2' => [...$nom,
                $c('Brut', 'num', fn ($x) => $x['brut']), $c('CNSS', 'num', fn ($x) => $x['cnssS']), $c('IRPP + CSS', 'num', fn ($x) => $x['ras']),
                $c('Autres retenues', 'num', fn ($x) => r3(($x['retenues'] ?? 0) + ($x['nature'] ?? 0) + ($x['acompte'] ?? 0))), $c('Total retenues', 'num', $retenuesTot),
                $c('Net à payer', 'num', fn ($x) => $x['net']), $c('Charges patronales', 'num', fn ($x) => r3($x['cnssP'] + $x['tfp'] + $x['fop'])), $c('Coût employeur', 'num', fn ($x) => $x['cout'])],
            'detaille' => [...$nom,
                $c('Jours', 'text', fn ($x) => $nb($x['jours'] ?? null)), $c('Salaire', 'num', fn ($x) => $x['salaire'] ?? $x['base']), $c('H. sup.', 'num', fn ($x) => $x['hs_montant'] ?? 0),
                $c('Primes', 'num', fn ($x) => $x['prime']),
                ...$rubriques->map(fn ($l) => $c(trim($l['code'].' '.$l['nom']).($l['sens'] === 'retenue' ? ' (−)' : ''), 'num', fn ($x) => $rub($x, $l)))->all(),
                $c('Brut', 'num', fn ($x) => $x['brut']), $c('CNSS', 'num', fn ($x) => $x['cnssS']), $c('Imposable annuel', 'num', fn ($x) => $x['bi'], false),
                $c('IRPP', 'num', fn ($x) => $x['ir']), $c('CSS', 'num', fn ($x) => $x['css']), $c('Acompte', 'num', fn ($x) => $x['acompte'] ?? 0),
                $c('Net à payer', 'num', fn ($x) => $x['net']), $c('CNSS patronale', 'num', fn ($x) => $x['cnssP']), $c('TFP', 'num', fn ($x) => $x['tfp']),
                $c('FOPROLOS', 'num', fn ($x) => $x['fop']), $c('Coût employeur', 'num', fn ($x) => $x['cout'])],
            'variable' => [...$nom,
                $c('J/H ouv.', 'text', fn ($x) => $nb($x['jours_mois'] ?? null)), $c('J/H trav.', 'text', fn ($x) => $nb($x['pt_trav'] ?? null)),
                $c('Congé', 'text', fn ($x) => $nb($x['pt_conge'] ?? null)), $c('Férié', 'text', fn ($x) => $nb($x['pt_ferie'] ?? null)),
                $c('H. sup.', 'text', fn ($x) => $nb($x['hs'] ?? null)), $c('Primes', 'num', fn ($x) => $x['prime']), $c('Acompte', 'num', fn ($x) => $x['acompte'] ?? 0),
                ...$rubriques->map(fn ($l) => $c(trim($l['code'].' '.$l['nom']), $l['quantite'] !== null || $l['sens'] === 'note' ? 'text' : 'num',
                    fn ($x) => ($l['quantite'] !== null || $l['sens'] === 'note') ? $nb($rub($x, $l)) : $rub($x, $l)))->all()],
            default => [...$nom,
                $c('Jours', 'text', fn ($x) => $nb($x['jours'] ?? null)), $c('Salaire de base', 'num', fn ($x) => $x['salaire'] ?? $x['base']), $c('H. sup.', 'text', fn ($x) => $nb($x['hs'] ?? null)),
                $c('Montant H. sup.', 'num', fn ($x) => $x['hs_montant'] ?? 0), $c('Primes', 'num', fn ($x) => $x['prime']), $c('Brut', 'num', fn ($x) => $x['brut']),
                $c('CNSS salariale', 'num', fn ($x) => $x['cnssS']), $c('Imposable annuel', 'num', fn ($x) => $x['bi'], false), $c('IRPP', 'num', fn ($x) => $x['ir']),
                $c('CSS', 'num', fn ($x) => $x['css']), $c('Net à payer', 'num', fn ($x) => $x['net']), $c('CNSS patronale', 'num', fn ($x) => $x['cnssP']),
                $c('TFP', 'num', fn ($x) => $x['tfp']), $c('FOPROLOS', 'num', fn ($x) => $x['fop']), $c('Coût employeur', 'num', fn ($x) => $x['cout'])],
        };

        $titre = 'Journal de paie'.($modele !== 'modele1' ? ' — '.mb_strtolower(self::JOURNAUX[$modele]) : '');
        $e = new Etat($titre, $client->nom, self::periode($mois, $paie, $client).($groupe !== 'integral' ? ' · '.mb_strtolower(self::GROUPES[$groupe]) : ''),
            array_map(fn ($col) => [$col[0], $col[1]], $cols), count($cols) > 6);
        $cle = fn ($x) => match ($groupe) {
            'profil' => trim((string) ($x['profil'] ?? $sal[$x['salarie_id']]->profil ?? '')) ?: 'Sans profil',
            'regime' => ($x['regime'] ?? 'mensuel') === 'horaire' ? 'Régime horaire' : 'Régime mensuel',
            'departement' => trim((string) ($sal[$x['salarie_id']]->departement ?? '')) ?: 'Sans département',
            default => '',
        };
        $somme = function (array $lignes, string $libelle, string $style) use ($cols, $e) {
            $v = array_map(fn ($col) => $col[3] ? r3(array_sum(array_map(fn ($x) => (float) $col[2]($x), $lignes))) : null, $cols);
            $v[0] = ''; $v[1] = $libelle.' ('.count($lignes).' salarié(s))';
            $e->ligne($v, $style);
        };
        $groupes = collect($R)->groupBy($cle)->sortKeys();
        foreach ($groupes as $g => $lignes) {
            if ($groupe !== 'integral') $e->ligne(['', mb_strtoupper($g), ...array_fill(0, count($cols) - 2, null)], 'groupe');
            foreach ($lignes as $x) $e->ligne(array_map(fn ($col) => $col[2]($x), $cols));
            if ($groupe !== 'integral') $somme($lignes->all(), 'Sous-total '.$g, 'soustotal');
        }
        $somme($R, 'TOTAL', 'total');
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
        $par = self::parSalarie($client, $mm, 'base_cnss', 'decl_cnss');
        $tot = [0, 0, 0, 0]; $n = 0;
        foreach ($par as $p) {
            $v = [...array_map(fn ($m) => $p['mois'][$m] ?? 0.0, $mm)];
            $v[] = r3(array_sum($v));
            $e->ligne([++$n, $p['cnss'], $p['nom'], $p['cin'], ...$v]);
            foreach ($v as $k => $x) $tot[$k] += $x;
        }
        if (! $n) $e->ligne(['', '', 'NÉANT', '', ...array_fill(0, count($mm) + 1, null)]);   // déclaration néant : pas de paie ce trimestre
        $e->ligne(['', '', 'TOTAL', '', ...array_map('r3', $tot)], 'total');
        return $e;
    }

    /** Lignes par page de la déclaration trimestrielle au modèle CNSS. */
    public const CNSS_LIGNES_PAGE = 10;

    /**
     * Données des modèles CNSS du trimestre de $mois : déclaration trimestrielle des salariés et des salaires
     * (brut 1, 2, 3 et total par assuré, paginée) et état récapitulatif des salaires et appointements
     * (sécurité sociale, accident du travail, total à payer, montants en lettres).
     */
    /** Code d'exploitation CNSS d'une ligne de paie (profil de l'employé ; régime général 0000 par défaut). */
    private static function codeCnss(array $x): string
    {
        return trim((string) ($x['code_exploitant'] ?? '')) ?: PaieCalc::CODE_REGIME_GENERAL;
    }

    /**
     * Ventilation CNSS d'une ligne de paie par code d'exploitation : [{code, salaire, cnssS, cnssP}]. Contrats aidés (Dignité 0334,
     * Exo 2 ans 0337) : 600 DT par mois sous le code du contrat, l'excédent sous le régime général (0000). Paies figées avant la
     * ventilation : tout le salaire sous le code du profil, sauf un contrat Dignité, scindé selon son assiette cotisée.
     */
    private static function tranchesCnss(array $x, array $P): array
    {
        if (isset($x['cnss_tranches'])) return $x['cnss_tranches'];
        $code = self::codeCnss($x);
        $base = (float) ($x['base_cnss'] ?? $x['brut']);
        $tS = (float) ($x['taux_cnssS'] ?? $P['cnssS']);
        $tP = (float) ($x['taux_cnssP'] ?? $P['cnssP']);
        $T = [['code' => $code, 'salaire' => $base, 'cnssS' => $tS, 'cnssP' => $tP]];
        if ($code === '0334' && isset($x['base_cotisee'])) {
            $exc = (float) $x['base_cotisee'];
            $T = [['code' => $code, 'salaire' => r3($base - $exc), 'cnssS' => 0.0, 'cnssP' => 0.0],
                ['code' => PaieCalc::CODE_REGIME_GENERAL, 'salaire' => $exc, 'cnssS' => $tS, 'cnssP' => $tP]];
        }
        return array_values(array_filter($T, fn ($t) => abs($t['salaire']) > 0.0005));
    }

    /**
     * Codes d'exploitation CNSS du trimestre de $mois (régime général 0000, Dignité 0334, Exo 2 ans 0337…), chacun avec ses taux
     * salarial et patronal : chaque code fait l'objet de sa propre déclaration trimestrielle et de son propre état récapitulatif.
     * Un salarié sous contrat aidé figure sur la déclaration de son contrat pour ses 600 premiers dinars du mois et, au-delà,
     * sur celle du régime général.
     * @return array<string, array{cnssS: float, cnssP: float, profil: ?string}>
     */
    public static function codesCnss(Client $client, string $mois): array
    {
        $P = PaieCalc::parametres($client);
        $codes = [];
        foreach (self::trimestre($mois) as $m) {
            foreach (self::declares(PaieCalc::moisDeclares($client, $m), 'decl_cnss') as $x) {
                foreach (self::tranchesCnss($x, $P) as $t) {
                    $c = $codes[$t['code']] ?? null;
                    // Taux du code : ceux de la première tranche cotisée (un salarié non assujetti n'en donne pas).
                    if (! $c || ($c['cnssS'] + $c['cnssP'] == 0 && $t['cnssS'] + $t['cnssP'] > 0)) $c = ['cnssS' => (float) $t['cnssS'], 'cnssP' => (float) $t['cnssP'], 'profil' => $c['profil'] ?? null];
                    if ($t['code'] === self::codeCnss($x)) $c['profil'] ??= $x['profil'] ?? null;
                    $codes[$t['code']] = $c;
                }
            }
        }
        ksort($codes);
        return $codes ?: ['0000' => ['cnssS' => (float) $P['cnssS'], 'cnssP' => (float) $P['cnssP'], 'profil' => null]];
    }

    /**
     * $code : code d'exploitation déclaré (par défaut le premier du trimestre). Les assurés des autres codes (contrats aidés : Dignité,
     * Exo 2 ans…) figurent sur leur propre déclaration, aux taux de leur régime.
     */
    public static function modeleCnss(Client $client, string $mois, ?string $code = null): array
    {
        $mm = self::trimestre($mois);
        $d = Carbon::parse($mois.'-01');
        $P = PaieCalc::parametres($client);
        $codes = self::codesCnss($client, $mois);
        $code = isset($codes[$code]) ? $code : array_key_first($codes);
        $k = $client->paie_config ?? [];
        $lignes = [];
        $tot = [0.0, 0.0, 0.0, 0.0];
        // Permanents : CDI ou contrat non renseigné ; occasionnels : tout autre contrat (CDD, SIVP, Karama, stage…).
        $contrats = \App\Models\Salarie::where('client_id', $client->id)->pluck('type_contrat', 'id');
        $permanents = 0;
        foreach (self::parSalarie($client, $mm, 'base_cnss', 'decl_cnss', $code) as $id => $p) {
            $v = array_map(fn ($m) => (float) ($p['mois'][$m] ?? 0.0), $mm);
            $v[] = r3(array_sum($v));
            if ($v[3] == 0.0) continue;
            if (in_array($contrats[$id] ?? null, [null, '', 'CDI'], true)) $permanents++;
            $lignes[] = ['ordre' => count($lignes) + 1, 'assure' => self::numeroCnss($p['cnss']), 'nom' => mb_strtoupper($p['nom']), 'matricule' => $p['matricule'] ?? null, 'brut' => $v];
            foreach ($v as $i => $x) $tot[$i] = r3($tot[$i] + $x);
        }
        $pages = array_map(function ($bloc) {
            $t = [0.0, 0.0, 0.0, 0.0];
            foreach ($bloc as $l) foreach ($l['brut'] as $i => $x) $t[$i] = r3($t[$i] + $x);
            return ['lignes' => $bloc, 'total' => $t];
        }, array_chunk($lignes, self::CNSS_LIGNES_PAGE) ?: [[]]);

        // Sécurité sociale : parts salariale et patronale du régime de ce code, hors accident du travail, déclaré sur sa propre ligne
        // (le taux patronal d'un contrat aidé réduit au taux de l'accident du travail ou en dessous ne couvre que celui-ci).
        $taux = $codes[$code];
        $tauxAt = min((float) ($P['at'] ?? 0), $taux['cnssP']);
        $tauxSs = r3($taux['cnssS'] + $taux['cnssP'] - $tauxAt);
        // Assiette des cotisations : salaires déclarés sous ce code, hors ceux d'un contrat Dignité (cotisations prises en charge par l'État).
        $base = r3(array_sum(array_map(fn ($p) => array_sum($p['mois']), self::parSalarie($client, $mm, 'base_cotisee', 'decl_cnss', $code))));
        $ss = r3($base * $tauxSs / 100);
        $at = r3($base * $tauxAt / 100);
        $total = r3($ss + $at);
        return [
            'client' => $client, 'trimestre' => $d->quarter, 'libelle_trimestre' => $d->quarter.($d->quarter === 1 ? 'er' : 'ème').' Trimestre', 'annee' => $d->year,
            'employeur' => self::numeroCnss($client->matricule_cnss), 'bureau' => $client->cnss_bureau, 'matricule_fiscal' => $client->matricule_fiscal,
            'code' => $code, 'codes' => array_keys($codes), 'profil' => $taux['profil'],
            'activite' => $client->paie_activite, 'adresse' => $client->adresse, 'code_postal' => $k['code_postal'] ?? null, 'ville' => $k['ville'] ?? null,
            'pages' => $pages, 'totaux' => $tot, 'effectif' => count($lignes),
            'permanents' => $permanents, 'occasionnels' => count($lignes) - $permanents,
            'base' => $base, 'taux_ss' => $tauxSs, 'ss' => $ss, 'taux_at' => $tauxAt, 'at' => $at, 'total' => $total,
            // Déclaration néant : aucun salaire déclaré ce trimestre (pas de paie), la déclaration reste due.
            'neant' => ! $lignes,
            'lettres_salaires' => $lignes ? montant_lettres($tot[3]) : 'NÉANT', 'lettres_total' => $lignes ? montant_lettres($total) : 'NÉANT', 'date' => now()->format('d/m/Y'),
        ];
    }

    /** N° CNSS au format des modèles : « 17059880 - 02 ». */
    private static function numeroCnss(?string $n): string
    {
        $n = trim((string) $n);
        return preg_match('/^(\d+)\s*[-\/ ]\s*(\d+)$/', $n, $m) ? $m[1].' - '.$m[2] : $n;
    }

    /** Lignes par page du fichier de télédéclaration CNSS (nomenclature du document papier). */
    public const CNSS_FICHIER_LIGNES_PAGE = 12;

    /** N° CNSS (employeur ou assuré) en matricule de 8 chiffres et clé de 2 : « 123456-72 » → « 0012345672 ». */
    public static function matriculeCnss10(?string $n): string
    {
        $n = trim((string) $n);
        if (preg_match('/^(\d{1,8})\D+(\d{1,2})$/', $n, $m)) return str_pad($m[1], 8, '0', STR_PAD_LEFT).str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $d = preg_replace('/\D/', '', $n);
        return $d === '' ? str_repeat('0', 10) : str_pad(substr($d, -10), 10, '0', STR_PAD_LEFT);
    }

    /** Champ alphanumérique du fichier CNSS : majuscules sans accents, cadré à gauche et complété par des espaces. */
    private static function champCnss(?string $v, int $n): string
    {
        $v = mb_strtoupper(\Illuminate\Support\Str::ascii(preg_replace('/\s+/', ' ', trim((string) $v))));
        return str_pad(substr($v, 0, $n), $n);
    }

    /**
     * Fichier de télédéclaration des salaires à la CNSS (tracé « nouvelle version 2012 ») pour un code d'exploitation du trimestre
     * de $mois : un enregistrement de 122 caractères par assuré — n° employeur (matricule 8 + clé 2), code d'exploitation (4),
     * trimestre (1) et année (4), n° de page (3) et de ligne (2) à raison de 12 lignes par page, n° d'assuré (8 + 2),
     * identité (60 : prénom, prénom du père, nom, en majuscules), n° CIN (8), salaire du trimestre en millimes (10), zone vierge (10).
     * Nom du fichier : DS + n° employeur (10) + code (4) + « . » + trimestre + année, ex. DS00123456720006.12010.
     * @return array{nom: string, contenu: string, assures: int, total: float, anomalies: list<string>}
     */
    public static function fichierCnss(Client $client, string $mois, ?string $code = null): array
    {
        $mm = self::trimestre($mois);
        $d = Carbon::parse($mm[0].'-01');
        $codes = self::codesCnss($client, $mois);
        $code = isset($codes[$code]) ? $code : array_key_first($codes);
        $code4 = str_pad(substr(preg_replace('/\D/', '', $code), -4), 4, '0', STR_PAD_LEFT);
        $employeur = self::matriculeCnss10($client->matricule_cnss);
        $periode = $d->quarter.$d->year;
        $sal = \App\Models\Salarie::where('client_id', $client->id)->get()->keyBy('id');
        $lignes = []; $anomalies = []; $total = 0.0; $i = 0;
        if (! trim((string) $client->matricule_cnss)) $anomalies[] = 'N° d’affiliation CNSS de l’employeur non renseigné.';
        foreach (self::parSalarie($client, $mm, 'base_cnss', 'decl_cnss', $code) as $id => $p) {
            $montant = r3(array_sum($p['mois']));
            if ($montant == 0.0) continue;
            $s = $sal[$id] ?? null;
            $page = intdiv($i, self::CNSS_FICHIER_LIGNES_PAGE) + 1;
            $ligne = $i % self::CNSS_FICHIER_LIGNES_PAGE + 1;
            $i++;
            $identite = $s ? implode(' ', array_filter([trim((string) $s->prenom), trim((string) $s->prenom_pere), trim((string) $s->nom)])) : $p['nom'];
            $cin = preg_replace('/\s/', '', (string) ($s?->cin ?? $p['cin']));
            $nom = $s?->nomComplet() ?? $p['nom'];
            if (! trim((string) $p['cnss'])) $anomalies[] = $nom.' : n° d’assuré social CNSS non renseigné.';
            if ($s && ! trim((string) $s->prenom_pere)) $anomalies[] = $nom.' : prénom du père non renseigné (identité de l’assuré).';
            if ($cin === '') $anomalies[] = $nom.' : n° CIN non renseigné.';
            $lignes[] = $employeur.$code4.$periode.sprintf('%03d%02d', $page, $ligne).self::matriculeCnss10($p['cnss'])
                .self::champCnss($identite, 60)
                .(ctype_digit($cin) ? str_pad(substr($cin, -8), 8, '0', STR_PAD_LEFT) : self::champCnss($cin, 8))
                .str_pad((string) (int) round($montant * 1000), 10, '0', STR_PAD_LEFT)
                .str_repeat(' ', 10);
            $total = r3($total + $montant);
        }
        return ['nom' => 'DS'.$employeur.$code4.'.'.$periode, 'contenu' => $lignes ? implode("\r\n", $lignes)."\r\n" : '',
            'code' => $code, 'codes' => array_keys($codes), 'assures' => count($lignes), 'total' => $total, 'anomalies' => $anomalies];
    }

    /**
     * Fichier des salaires du régime complémentaire de retraite (tracé CNSS) pour le trimestre de $mois : une ligne de 59 caractères
     * par salarié affilié (case « CNSS régime complémentaire » de la fiche) — n° employeur (10), n° d'assuré (10), salaire déclaré,
     * salaire plafonné et salaire différentiel du trimestre (13 chacun), en millimes complétés de zéros à gauche. Plafond et
     * différentiel sont établis mois par mois : part du salaire jusqu'au plafond (6 × SMIG, Renseignements employeur) et au-delà.
     * Nom du fichier : RC + n° employeur (10) + « . » + trimestre + année (même forme que le fichier DS).
     * @return array{nom: string, contenu: string, assures: int, totaux: array{declare: float, plafonne: float, differentiel: float}, anomalies: list<string>}
     */
    public static function fichierRc(Client $client, string $mois): array
    {
        $mm = self::trimestre($mois);
        $d = Carbon::parse($mm[0].'-01');
        $plafond = (float) (($client->paie_config ?? [])['plafond_rc'] ?? 0);
        $employeur = self::matriculeCnss10($client->matricule_cnss);
        $affilies = self::salaries($client)->filter(fn ($s) => (bool) $s->cnss_rc);
        $mil = fn (float $v) => str_pad((string) (int) round($v * 1000), 13, '0', STR_PAD_LEFT);
        $anomalies = [];
        if (! trim((string) $client->matricule_cnss)) $anomalies[] = 'N° d’affiliation CNSS de l’employeur non renseigné.';
        if ($plafond <= 0) $anomalies[] = 'Plafond du régime complémentaire (6 × SMIG) non renseigné dans les renseignements employeur.';
        $lignes = []; $tot = ['declare' => 0.0, 'plafonne' => 0.0, 'differentiel' => 0.0];
        foreach (self::parSalarie($client, $mm, 'base_cnss', 'decl_cnss') as $id => $p) {
            if (! $affilies->has($id)) continue;
            $declare = $plafonne = 0.0;
            foreach ($mm as $m) {
                $s = (float) ($p['mois'][$m] ?? 0);
                $declare += $s;
                $plafonne += $plafond > 0 ? min($s, $plafond) : $s;
            }
            [$declare, $plafonne] = [r3($declare), r3($plafonne)];
            if ($declare == 0.0) continue;
            $diff = r3($declare - $plafonne);
            if (! trim((string) $p['cnss'])) $anomalies[] = $affilies[$id]->nomComplet().' : n° d’assuré social CNSS non renseigné.';
            $lignes[] = $employeur.self::matriculeCnss10($p['cnss']).$mil($declare).$mil($plafonne).$mil($diff);
            $tot = ['declare' => r3($tot['declare'] + $declare), 'plafonne' => r3($tot['plafonne'] + $plafonne), 'differentiel' => r3($tot['differentiel'] + $diff)];
        }
        return ['nom' => 'RC'.$employeur.'.'.$d->quarter.$d->year, 'contenu' => $lignes ? implode("\r\n", $lignes)."\r\n" : '',
            'assures' => count($lignes), 'totaux' => $tot, 'anomalies' => $anomalies];
    }

    /** Récapitulatif des cotisations CNSS du trimestre (mois par mois). */
    public static function recapCnss(Client $client, string $mois): Etat
    {
        // Une ligne par mois et par code d'exploitation, chacun à ses taux (régime général, contrats aidés)
        $codes = self::codesCnss($client, $mois);
        $P = PaieCalc::parametres($client);
        $taux = fn ($c) => $c['cnssS'].' % + '.$c['cnssP'].' %';
        $e = new Etat('Récapitulatif CNSS', $client->nom, self::libelleTrimestre($mois), [
            ['Mois', 'text'], ['Code d’exploitation', 'text'], ['Taux (salarial + patronal)', 'text'], ['Effectif', 'text'], ['Masse salariale', 'num'], ['Part salariale', 'num'], ['Part patronale', 'num'], ['Total à payer', 'num'],
        ], false);
        $tot = [0, 0, 0, 0];
        foreach (self::trimestre($mois) as $m) {
            // Masse salariale soumise à cotisation (hors gains non cotisables) et cotisations, ventilées par code (contrats aidés :
            // 600 DT sous le code du contrat, l'excédent au régime général).
            $parCode = [];
            foreach (self::declares(PaieCalc::moisDeclares($client, $m), 'decl_cnss') as $x) {
                foreach (self::tranchesCnss($x, $P) as $t) {
                    $g = $parCode[$t['code']] ?? ['ids' => [], 'masse' => 0.0, 's' => 0.0, 'p' => 0.0];
                    $g['ids'][$x['salarie_id']] = true;
                    $g['masse'] += $t['salaire'];
                    $g['s'] += $t['salaire'] * $t['cnssS'] / 100;
                    $g['p'] += $t['salaire'] * $t['cnssP'] / 100;
                    $parCode[$t['code']] = $g;
                }
            }
            foreach ($codes as $code => $c) {
                $g = $parCode[$code] ?? null;
                if (! $g && count($codes) > 1) continue;
                $g ??= ['ids' => [], 'masse' => 0.0, 's' => 0.0, 'p' => 0.0];
                $v = [r3($g['masse']), r3($g['s']), r3($g['p']), r3($g['s'] + $g['p'])];
                $e->ligne([ucfirst(mois_fr($m)), $code, $taux($c), (string) count($g['ids']), ...$v]);
                foreach ($v as $k => $x) $tot[$k] += $x;
            }
        }
        if (! array_filter($tot)) $e->ligne(['NÉANT', '', '', '0', null, null, null, null]);   // déclaration néant : pas de paie ce trimestre
        $e->ligne(['TOTAL DU TRIMESTRE', '', '', '', ...array_map('r3', $tot)], 'total');
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
        foreach (self::cumul($client, $mm, $cles, 'decl_cnss') as $p) {
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
        foreach (self::cumul($client, $mm, $cles, 'decl_employeur') as $id => $p) {
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
    public static function virement(Client $client, string $mois, ?string $banque = null, ?string $paie = null): Etat
    {
        $e = new Etat($banque ? 'Récapitulatif de virement — '.self::BANQUES[$banque]['nom'] : 'Ordre de virement des salaires', $client->nom, self::periode($mois, $paie, $client)
            .($client->paie_rib ? ' · compte à débiter : '.trim($client->paie_banque.' '.$client->paie_rib) : ''), [
            ['N°', 'text'], ['Matricule', 'text'], ['Bénéficiaire', 'text'], ['Banque', 'text'], ['RIB', 'text'], ['Montant net', 'num'],
        ], false);
        $tot = 0.0; $n = 0;
        foreach (self::aVirer($client, $mois, $banque, $paie) as [$x, $s]) {
            $e->ligne([++$n, $s?->matricule, $x['nom'], $s?->banque, $s?->rib ?: 'RIB manquant', $x['net']]);
            $tot += $x['net'];
        }
        $e->ligne(['', '', 'TOTAL À VIRER ('.$n.')', '', '', r3($tot)], 'total');
        return $e;
    }

    /**
     * Bordereau payeur : liste d'émargement des nets payés du mois, tous modes de paiement ou un seul ($mode),
     * éventuellement regroupée par département avec un sous-total par département.
     */
    public static function bordereau(Client $client, string $mois, ?string $mode = null, bool $parDepartement = false, ?string $paie = null): Etat
    {
        $sal = self::salaries($client);
        $e = new Etat('Bordereau payeur'.($mode ? ' — '.mb_strtolower(self::MODES[$mode]) : '').($parDepartement ? ' par département' : ''), $client->nom, self::periode($mois, $paie, $client), [
            ['N°', 'text'], ['Matricule', 'text'], ['Nom et prénom', 'text'], ['Mode de paiement', 'text'], ['Net à payer', 'num'], ['Signature', 'text'],
        ], false);
        $lignes = [];
        foreach (self::resultats($client, $mois, $paie) as $x) {
            $s = $sal[$x['salarie_id']] ?? null;
            $m = $s?->mode_paiement ?: 'virement';
            if ($mode && $m !== $mode) continue;
            $lignes[] = [$x, $s, $m];
        }
        $groupes = $parDepartement ? collect($lignes)->groupBy(fn ($l) => trim((string) $l[1]?->departement) ?: 'Sans département')->sortKeys() : collect(['' => collect($lignes)]);
        $tot = 0.0; $n = 0;
        foreach ($groupes as $g => $grp) {
            if ($parDepartement) $e->ligne(['', '', mb_strtoupper($g), '', null, ''], 'groupe');
            $st = 0.0;
            foreach ($grp as [$x, $s, $m]) {
                $e->ligne([++$n, $s?->matricule, $x['nom'], self::MODES[$m] ?? '', $x['net'], '']);
                $st += $x['net'];
            }
            if ($parDepartement) $e->ligne(['', '', 'Sous-total '.$g.' ('.count($grp).')', '', r3($st), ''], 'soustotal');
            $tot += $st;
        }
        $e->ligne(['', '', 'TOTAL ('.$n.' salarié(s))', '', r3($tot), ''], 'total');
        return $e;
    }

    /**
     * Récapitulation de paie du mois : total de chaque élément du bulletin (salaire de base, heures supplémentaires, primes,
     * rubriques du dossier, cotisations, impôt, net) avec le nombre de salariés concernés, puis les charges patronales.
     */
    public static function recap(Client $client, string $mois, ?string $paie = null): Etat
    {
        $R = self::resultats($client, $mois, $paie);
        $P = PaieCalc::parametres($client);
        $e = new Etat('Récapitulation de paie', $client->nom, self::periode($mois, $paie, $client).' · '.count($R).' bulletin(s)', [
            ['Code', 'text'], ['Élément', 'text'], ['Salariés', 'text'], ['Gains', 'num'], ['Retenues', 'num'],
        ], false);
        $ligne = function (string $code, string $lib, callable $f, bool $retenue = false, string $style = 'normal') use ($e, $R) {
            $n = 0; $t = 0.0;
            foreach ($R as $x) { $v = (float) $f($x); if (abs($v) > 0.0005) { $n++; $t += $v; } }
            if ($style === 'normal' && ! $n) return;
            $e->ligne([$code, $lib, $n ? (string) $n : '', $retenue ? null : r3($t), $retenue ? r3($t) : null], $style);
        };
        // Rubriques du dossier payées ce mois-ci (hors notes), dans l'ordre des codes.
        $rub = collect($R)->flatMap(fn ($x) => $x['rubriques'] ?? [])->where('sens', '!=', 'note')
            ->unique(fn ($l) => $l['code'].'|'.$l['sens'].'|'.(int) $l['cotisable'])->sortBy('code');
        $mt = fn ($l) => fn ($x) => collect($x['rubriques'] ?? [])->where('code', $l['code'])->where('sens', $l['sens'])->where('cotisable', $l['cotisable'])->sum('montant');

        $e->ligne(['', 'GAINS', '', null, null], 'groupe');
        $ligne('', 'Salaire de base', fn ($x) => empty($x['special']) ? ($x['salaire'] ?? $x['base']) : 0);
        foreach (collect($R)->pluck('special')->filter()->map(fn ($l) => preg_replace('/ payé \(.*$/', '', $l))->unique() as $lib) $ligne('', $lib, fn ($x) => ! empty($x['special']) && str_starts_with($x['special'], $lib) ? $x['salaire'] : 0);
        $ligne('', 'Heures supplémentaires', fn ($x) => $x['hs_montant'] ?? 0);
        $ligne('', 'Primes et indemnités imposables', fn ($x) => $x['prime'] ?? 0);
        foreach ($rub->where('sens', 'gain') as $l) $ligne((string) $l['code'], $l['nom'].(! empty($l['nature']) ? ' (en nature)' : ''), $mt($l));
        foreach ($rub->where('sens', 'retenue')->where('cotisable', true) as $l) $ligne((string) $l['code'], $l['nom'], $mt($l), true);
        $ligne('', 'SALAIRE BRUT', fn ($x) => $x['brut'], false, 'soustotal');

        $e->ligne(['', 'RETENUES', '', null, null], 'groupe');
        $ligne('', 'CNSS salariale', fn ($x) => $x['cnssS'], true);
        $ligne('', 'Retenue à la source IRPP', fn ($x) => $x['ir'], true);
        $ligne('', 'Contribution sociale de solidarité', fn ($x) => $x['css'], true);
        foreach ($rub->where('sens', 'retenue')->where('cotisable', false) as $l) $ligne((string) $l['code'], $l['nom'], $mt($l), true);
        $ligne('', 'Avantages en nature (non versés)', fn ($x) => $x['nature'] ?? 0, true);
        $ligne('', 'NET À PAYER', fn ($x) => $x['net'], false, 'total');

        $e->ligne(['', 'CHARGES PATRONALES', '', null, null], 'groupe');
        $ligne('', 'CNSS patronale', fn ($x) => $x['cnssP']);
        $ligne('', 'TFP ('.$P['tfp'].' %)', fn ($x) => $x['tfp']);
        $ligne('', 'FOPROLOS ('.$P['fop'].' %)', fn ($x) => $x['fop']);
        $ligne('', 'COÛT TOTAL EMPLOYEUR', fn ($x) => $x['cout'], false, 'total');
        return $e;
    }

    /**
     * État vierge de saisie : les salariés payés le mois, avec des colonnes vides (jours, heures supplémentaires, primes
     * et rubriques à saisir du dossier) à remplir à la main avant la saisie de la paie.
     */
    public static function vierge(Client $client, string $mois): Etat
    {
        $P = PaieCalc::parametres($client);
        $aSaisir = \App\Models\PaieRubrique::duDossier($client)->filter->aSaisir()->values();
        $cols = [['Matricule', 'text'], ['Nom et prénom', 'text'], ['Jours (/'.$P['jours_mois'].')', 'text'], ['H. sup.', 'text'], ['Primes', 'text'],
            ...$aSaisir->map(fn ($rb) => [trim($rb->code.' '.$rb->nom), 'text'])->all(), ['Observations', 'text']];
        $e = new Etat('État de saisie de la paie', $client->nom, mois_fr($mois), $cols, count($cols) > 7);
        $e->grille = true;
        $vide = array_fill(0, count($cols) - 2, '');
        $fin = Carbon::parse($mois.'-01')->endOfMonth();
        foreach (self::salaries($client) as $s) {
            if (($s->date_embauche && $s->date_embauche->gt($fin)) || ! PaieCalc::payeLeMois($s, $mois)) continue;
            $e->ligne([$s->matricule, $s->nomComplet(), ...$vide]);
        }
        return $e;
    }

    /** Valeur d'une rubrique par salarié et par mois. */
    private static function parSalarie(Client $client, array $mois, string $cle, ?string $decl = null, ?string $code = null): array
    {
        $par = [];
        $P = $code !== null ? PaieCalc::parametres($client) : null;
        foreach ($mois as $m) {
            foreach (self::declares(PaieCalc::moisDeclares($client, $m), $decl) as $x) {
                if ($code !== null) {
                    // Part du salaire déclarée sous ce code d'exploitation (base_cotisee : la part soumise à cotisation)
                    $T = array_filter(self::tranchesCnss($x, $P), fn ($t) => $t['code'] === $code);
                    if (! $T) continue;   // autre code d'exploitation CNSS
                    if ($cle === 'base_cotisee') $T = array_filter($T, fn ($t) => $t['cnssS'] + $t['cnssP'] > 0);
                    $par[$x['salarie_id']] ??= ['nom' => $x['nom'], 'cin' => $x['cin'] ?? null, 'cnss' => $x['cnss'] ?? null, 'matricule' => $x['matricule'] ?? null, 'mois' => []];
                    $par[$x['salarie_id']]['mois'][$m] = r3(($par[$x['salarie_id']]['mois'][$m] ?? 0) + array_sum(array_column($T, 'salaire')));
                    continue;
                }
                $par[$x['salarie_id']] ??= ['nom' => $x['nom'], 'cin' => $x['cin'] ?? null, 'cnss' => $x['cnss'] ?? null, 'matricule' => $x['matricule'] ?? null, 'mois' => []];
                $v = $x[$cle] ?? ($cle === 'base_cotisee' ? ($x['base_cnss'] ?? $x['brut']) : $x['brut']);   // paies figées avant l'assiette cotisée
                $par[$x['salarie_id']]['mois'][$m] = r3(($par[$x['salarie_id']]['mois'][$m] ?? 0) + $v);
            }
        }
        uasort($par, fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));
        return $par;
    }

    /** Cumul de plusieurs rubriques par salarié sur une liste de mois. */
    /** Résultats limités aux employés dont le profil est inclus dans la déclaration ($decl : decl_cnss ou decl_employeur). */
    private static function declares(array $R, ?string $decl): array
    {
        return $decl ? array_values(array_filter($R, fn ($x) => $x[$decl] ?? true)) : $R;
    }

    private static function cumul(Client $client, array $mois, array $cles, ?string $decl = null): array
    {
        $par = [];
        foreach ($mois as $m) {
            foreach (self::declares(PaieCalc::moisDeclares($client, $m), $decl) as $x) {
                $par[$x['salarie_id']] ??= ['nom' => $x['nom'], 'cin' => $x['cin'] ?? null, 'cnss' => $x['cnss'] ?? null, 'cumul' => array_fill_keys($cles, 0.0)];
                foreach ($cles as $k) $par[$x['salarie_id']]['cumul'][$k] = r3($par[$x['salarie_id']]['cumul'][$k] + $x[$k]);
            }
        }
        uasort($par, fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));
        return $par;
    }
}
