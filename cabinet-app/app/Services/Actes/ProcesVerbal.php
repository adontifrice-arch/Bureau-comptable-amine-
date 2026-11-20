<?php

namespace App\Services\Actes;

use App\Models\Client;
use App\Services\Compta;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Procès-verbaux d'assemblée générale ordinaire, extraordinaire ou mixte, selon les modèles du cabinet
 * (« PV AGO 2025 » et « PV AGE ») : en-tête de la société, ouverture (« L’an deux mille vingt-six, le … à … heures »),
 * ordre du jour, présents avec leur identité complète, résolutions (« Article premier » en AGO, « Première résolution » en AGE),
 * clôture et signatures. Les cessions de parts et l'augmentation de capital mettent à jour la répartition du capital
 * (tableaux de souscription et de répartition avant et après augmentation).
 */
class ProcesVerbal
{
    private static function e($s): string { return e((string) $s); }

    private static function P(string $s): string { return '<p style="text-align:justify">'.$s.'</p>'; }

    /** Montant : « 205 092,063 » (3 décimales) ou « 1 000 » (entier). */
    private static function dt(float $x): string { return Statuts::chiffres($x); }

    private static function memeNom($a, $b): bool { return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b)); }

    /** Points retenus de l'ordre du jour, en précisant l'exercice pour les points annuels. */
    private static function points(array $d, int $n): Collection
    {
        return Balises::ordreDuJour($d)->map(fn ($p) => match (true) {
            str_contains($p, 'états financiers de l’exercice clos le 31 décembre') && ! preg_match('/\d{4}$/', $p) => $p.' '.$n,
            $p === 'Quitus au gérant' => 'Quitus au gérant pour sa gestion de l’exercice '.$n,
            default => $p,
        });
    }

    /** Situation du capital : associés et parts avant, après cession et après augmentation. */
    private static function capital(Client $c, array $d, Collection $points): array
    {
        $avant = Balises::detenteurs($c)->map(fn ($a) => $a + ['nouveau' => false])->values();
        $apres = $avant->map(fn ($a) => $a);
        $cession = null;
        if ($points->contains(fn ($p) => str_contains(mb_strtolower($p), 'cession')) && ! empty($d['cedant'])) {
            $n = (int) ($d['parts'] ?? 0);
            $cession = ['cedant' => $d['cedant'], 'cessionnaire' => ($d['cessionnaire'] ?? null) ?: Balises::VIDE, 'parts' => $n, 'prix' => $d['prix'] ?? null,
                'de' => $d['parts_de'] ?? null, 'a' => $d['parts_a'] ?? null];
            $apres = $apres->map(fn ($a) => self::memeNom($a['nom'], $d['cedant']) && $n ? ['p' => max(0, (int) $a['p'] - $n)] + $a : $a);
            $i = $apres->search(fn ($a) => self::memeNom($a['nom'], $cession['cessionnaire']));
            if ($i !== false) {
                $apres[$i] = ['p' => (int) $apres[$i]['p'] + $n] + $apres[$i];
            } else {
                $apres->push(['nom' => $cession['cessionnaire'], 'cin' => $d['cessionnaire_cin'] ?? null, 'adr' => $d['cessionnaire_adresse'] ?? null, 'p' => $n, 'nouveau' => true]);
            }
            $apres = $apres->filter(fn ($a) => (int) $a['p'] > 0 || $a['nouveau'])->values();
        }

        // Augmentation en numéraire : parts nouvelles réparties au prorata des parts détenues (plus forts restes)
        $vn = (float) $c->valeur_nominale;
        $aug = (float) ($d['augmentation'] ?? 0);
        $souscrit = [];
        if ($aug > 0 && $vn > 0 && $points->contains(fn ($p) => str_contains(mb_strtolower($p), 'augmentation du capital'))) {
            $nouv = (int) round($aug / $vn);
            $tot = max(1, $apres->sum(fn ($a) => (int) $a['p']));
            $quotes = $apres->map(fn ($a) => $nouv * (int) $a['p'] / $tot);
            $souscrit = $quotes->map(fn ($q) => (int) floor($q))->all();
            $reste = $nouv - array_sum($souscrit);
            foreach ($quotes->map(fn ($q, $i) => $q - floor($q))->sortDesc()->keys()->take($reste) as $i) $souscrit[$i]++;
        }
        return ['avant' => $avant, 'apres' => $apres, 'cession' => $cession, 'souscrit' => $souscrit, 'aug' => $aug, 'vn' => $vn];
    }

    public static function html(Client $c, array $d): string
    {
        $e = [self::class, 'e'];
        $t = $d['type'];
        $date = Carbon::parse($d['date']);
        $n = (int) ($d['exercice'] ?? 0) ?: $date->year - 1;
        $points = self::points($d, $n);
        $K = self::capital($c, $d, $points);
        $uni = $K['avant']->count() === 1;
        $forme = trim((string) $c->forme) ?: ($uni ? 'SUARL' : 'SARL');
        $cap = (float) $c->capital;

        // En-tête de la société
        $h = '<p><b>SOCIÉTÉ '.$e(mb_strtoupper($c->nom)).'</b><br>'.$e($forme).' au capital de '.($cap ? self::dt($cap).' DT' : Balises::VIDE)
            .'<br>'.$e($c->adresse ?: Balises::VIDE).($c->matricule_fiscal ? '<br>Matricule fiscal : '.$e($c->matricule_fiscal) : '').'</p>';
        $titre = ['ago' => 'ORDINAIRE', 'age' => 'EXTRAORDINAIRE', 'mixte' => 'ORDINAIRE ET EXTRAORDINAIRE'][$t];
        $h .= '<h2 style="text-align:center">PV DE L’ASSEMBLÉE GÉNÉRALE '.$titre.'<br>DU '.$e(mb_strtoupper($date->locale('fr')->translatedFormat('j F Y'))).'</h2>';

        // Ouverture et ordre du jour
        $heure = trim((string) ($d['heure'] ?? ''));
        $heure = $heure === '' ? Balises::VIDE.' heures' : (preg_match('/^\d{1,2}$/', $heure) ? $heure.' heures' : preg_replace('/^(\d{1,2})\s*[hH:]\s*(\d{2})$/', '$1 heures $2', $heure));
        $quand = 'L’an '.Statuts::lettres($date->year).', le '.$date->day.($date->day === 1 ? 'er' : '').' '.$date->locale('fr')->translatedFormat('F').' à '.$e($heure).', ';
        $qui = $t === 'ago'
            ? ($uni ? 'l’associé(e) unique de la société '.$e($c->nom).' '.$e($forme).' s’est réuni(e)' : 'les associés de la société '.$e($c->nom).' '.$e($forme).' se sont réunis')
            : 'l’assemblée générale de la société '.$e($c->nom).' s’est réunie';
        $h .= self::P($quand.$qui.' au siège de la société à l’effet de délibérer sur l’ordre du jour suivant :');
        $h .= '<ul>'.$points->values()->map(fn ($p, $i) => '<li>'.$e($p).($i === $points->count() - 1 ? '.' : ' ;').'</li>')->implode('').'</ul>';

        // Présents
        if ($uni) {
            $h .= self::P('Était présent(e) : '.$e(Balises::identite($K['avant'][0])).', l’associé(e) unique de la société.');
        } else {
            $h .= self::P('Étaient présents :').'<ul>'.$K['avant']->map(fn ($a) => '<li>'.$e(Balises::identite($a)).', associé(e) détenant '.($a['p'] ?: Balises::VIDE).' parts sociales ;</li>')->implode('').'</ul>';
        }
        foreach ($K['apres']->where('nouveau', true) as $x) {
            $h .= self::P('Était présent(e) aussi : '.$e(Balises::identite($x)).'.');
        }

        // Résolutions
        $resolutions = $points->reject(fn ($p) => str_starts_with($p, 'Questions diverses'))->values();
        if ($t !== 'ago') $h .= self::P('Après discussions et échanges de vues, les résolutions suivantes ont été adoptées et prises à l’unanimité.');
        foreach ($resolutions as $i => $p) {
            $h .= $t === 'ago'
                ? '<h3>Article '.($i === 0 ? 'premier' : Statuts::lettres($i + 1)).' :</h3>'
                : '<h3>'.Balises::rang($i).' résolution :</h3>';
            $h .= self::resolution($p, $c, $d, $K, $n, $t, $uni);
            if ($t === 'ago') $h .= self::P('Cette résolution a été adoptée à l’unanimité.');
        }
        $h .= self::P('Plus rien n’étant à l’ordre du jour, la séance est levée.');

        // Signatures : associé unique, ou associés après les opérations de l'assemblée
        $sign = $K['apres']->isEmpty() ? $K['avant'] : $K['apres'];
        if ($sign->count() === 1) return $h.'<p style="margin-top:30px; text-align:right"><b>L’associé(e) unique</b><br><br>'.$e($sign[0]['nom']).'</p>';
        return $h.'<p style="margin-top:30px; text-align:center"><b>LES ASSOCIÉS</b></p><table style="width:100%"><tr>'
            .$sign->map(fn ($a) => '<td style="text-align:center; vertical-align:top; padding:4px">'.$e($a['nom']).'</td>')->implode('').'</tr></table>';
    }

    /** Texte d'une résolution, selon le point de l'ordre du jour. */
    private static function resolution(string $point, Client $c, array $d, array $K, int $n, string $t, bool $uni): string
    {
        $e = [self::class, 'e'];
        $V = Balises::VIDE;
        $p = mb_strtolower($point);
        $ag = $t === 'ago' ? 'l’Assemblée Générale Ordinaire' : 'l’assemblée générale';
        $AG = $t === 'ago' ? 'L’Assemblée Générale Ordinaire' : 'L’assemblée générale';

        // Total du bilan et résultat net : saisis, sinon repris de la comptabilité du dossier
        $bilan = $d['total_bilan'] ?? null;
        $res = $d['resultat'] ?? null;
        if (($bilan === null || $res === null) && (str_contains($p, 'états financiers') || str_contains($p, 'affectation'))) {
            $F = Compta::etats($c->id, $n);
            if ($F['n']) { $bilan ??= $F['tA']; $res ??= $F['P']['res']; }
        }
        $resTxt = $res === null ? 'un résultat net de '.$V.' dinars' : 'un résultat '.($res >= 0 ? 'bénéficiaire' : 'déficitaire').' net de '.number_format(abs((float) $res), 3, ',', ' ').' dinars';

        return match (true) {
            str_contains($p, 'rapport de gestion') => self::P('Après lecture du rapport de gestion de la gérance sur l’exercice '.$n.', '.$ag.' approuve ledit rapport dans toutes ses parties.'),
            str_contains($p, 'états financiers') => self::P('Après examen des états financiers arrêtés au 31 décembre '.$n.', '.$ag.' décide de les approuver dans leur intégralité.')
                .self::P('Ces états font ressortir un total bilan de '.($bilan === null ? $V : number_format((float) $bilan, 3, ',', ' ')).' dinars et '.$resTxt.'.'),
            str_contains($p, 'affectation') => self::P('Le résultat de l’exercice '.$n.' présente '.($res === null ? 'un résultat net de '.$V.' DT' : (($res >= 0 ? 'un bénéfice net de ' : 'une perte nette de ').number_format(abs((float) $res), 3, ',', ' ').' DT'))
                .', '.$ag.' décide de l’affecter '.$e(trim((string) ($d['affectation'] ?? '')) ?: 'en report à nouveau').'.'),
            str_contains($p, 'quitus') => self::P('Un quitus entier et définitif est donné au gérant pour sa gestion de l’exercice '.$n.'.'),
            str_contains($p, 'renouvellement') => self::P($AG.' décide de renouveler le mandat de gérant de '.$e($c->gerant_nom ?: $V).' pour une durée de '.$V.'.'),
            str_contains($p, 'rémunération') => self::P($AG.' fixe la rémunération du gérant à '.$V.' dinars par mois.'),
            str_contains($p, 'conventions') => self::P($AG.', après avoir entendu le rapport sur les conventions visées par le code des sociétés commerciales, approuve lesdites conventions.'),
            str_contains($p, 'dividendes') => self::P($AG.' décide de distribuer des dividendes d’un montant de '.$V.' dinars, répartis entre les associés proportionnellement au nombre de parts détenues par chacun.'),
            str_contains($p, 'pouvoirs') => self::P('Tous pouvoirs sont donnés au porteur d’une copie ou d’un extrait du présent procès-verbal pour accomplir les formalités légales de dépôt et de publicité.'),
            str_contains($p, 'cession') => self::cession($K, $uni),
            str_contains($p, 'augmentation du capital') => self::augmentation($c, $K),
            str_contains($p, 'réduction du capital') => self::P('Le capital social est réduit de '.$V.' DT et passe ainsi de '.((float) $c->capital ? self::dt((float) $c->capital) : $V).' DT à '.$V.' DT.'),
            str_contains($p, 'objet social') => self::P($AG.' décide de modifier l’objet social de la société, qui devient : '.$V.'.'),
            str_contains($p, 'dénomination') => self::P($AG.' décide de modifier la dénomination sociale de la société, qui devient : '.$V.'.'),
            str_contains($p, 'siège') => self::P($AG.' décide de transférer le siège social de la société du '.$e($c->adresse ?: $V).' au '.$V.'.'),
            str_contains($p, 'gérant') => self::P($AG.' décide de nommer '.$V.' en qualité de gérant de la société pour une durée de '.$V.', en remplacement de '.$e($c->gerant_nom ?: $V).'.'),
            str_contains($p, 'forme juridique') => self::P($AG.' décide la modification de la forme juridique de la société de '.$e(trim((string) $c->forme) ?: $V).' en '
                .$e(trim((string) ($d['nouvelle_forme'] ?? '')) ?: (str_contains(mb_strtoupper((string) $c->forme), 'SUARL') && $K['apres']->count() > 1 ? 'SARL' : $V)).'.')
                .self::P($AG.' décide par conséquent la mise à jour des statuts.'),
            str_contains($p, 'modification des statuts') => self::P($AG.' décide, en conséquence des résolutions qui précèdent, la mise à jour des statuts de la société.'),
            str_contains($p, 'dissolution') => self::P($AG.' décide la dissolution anticipée de la société à compter du '.$V.' et sa mise en liquidation amiable. Elle nomme '.$V.' en qualité de liquidateur.'),
            default => self::P('Après délibération, '.$ag.' décide : '.$e($point).' '.$V),
        };
    }

    private static function cession(array $K, bool $uni): string
    {
        $C = $K['cession'];
        $V = Balises::VIDE;
        if (! $C) return self::P('L’assemblée générale autorise et approuve la cession de '.$V.' parts sociales appartenant à '.$V.' au profit de '.$V.'.');
        $parts = ($C['parts'] ?: $V).' parts sociales'.($C['de'] && $C['a'] ? ', numérotées de '.$C['de'].' à '.$C['a'].' inclus,' : '');
        $prix = ! empty($C['prix']) ? ' moyennant le prix de '.number_format((float) $C['prix'], 3, ',', ' ').' DT' : '';
        return self::P(($uni ? 'L’associé(e) unique a décidé d’autoriser et d’approuver la cession de '.$parts.' lui appartenant à '
                : 'L’assemblée générale autorise et approuve la cession de '.$parts.' appartenant à '.self::e($C['cedant']).' au profit de ')
            .self::e($C['cessionnaire']).$prix.', et l’agrée en qualité d’associé(e).');
    }

    /** Augmentation du capital en numéraire, avec tableaux de souscription et de répartition avant et après. */
    private static function augmentation(Client $c, array $K): string
    {
        $V = Balises::VIDE;
        $cap = (float) $c->capital;
        $aug = $K['aug'];
        $vn = $K['vn'];
        if ($aug <= 0 || $vn <= 0) {
            return self::P('Le capital social est augmenté de '.$V.' DT en numéraire et passe ainsi de '.($cap ? self::dt($cap) : $V).' DT à '.$V.' DT.');
        }
        $nouv = (int) round($aug / $vn);
        $td = fn ($x, $al = 'right') => '<td style="border:1px solid #000; padding:4px; text-align:'.$al.'">'.$x.'</td>';
        $th = fn ($x, $span = 1) => '<th style="border:1px solid #000; padding:4px; text-align:center"'.($span > 1 ? ' colspan="'.$span.'"' : '').'>'.$x.'</th>';
        $pct = fn ($p, $tot) => $tot ? rtrim(rtrim(number_format($p / $tot * 100, 2, ',', ''), '0'), ',').' %' : '';

        $h = self::P('Le capital social est augmenté de '.self::dt($aug).' DT en numéraire et passe ainsi de '.self::dt($cap).' DT à '.self::dt($cap + $aug).' DT.')
            .self::P('Cette augmentation est réalisée par l’émission de '.$nouv.' parts sociales nouvelles de '.self::dt($vn).' DT chacune.')
            .self::P('Ces parts ont été souscrites par les associés de la manière suivante :');
        $h .= '<table style="border-collapse:collapse; width:100%"><thead><tr>'.$th('Associé').$th('Nombre de parts souscrites').$th('Souscription en valeur (DT)').'</tr></thead><tbody>';
        foreach ($K['apres'] as $i => $a) $h .= '<tr>'.$td(self::e($a['nom']), 'left').$td($K['souscrit'][$i] ?? 0).$td(self::dt(($K['souscrit'][$i] ?? 0) * $vn)).'</tr>';
        $h .= '<tr>'.$td('<b>Total</b>', 'left').$td($nouv).$td(self::dt($nouv * $vn)).'</tr></tbody></table>';

        // Répartition du capital avant et après augmentation (après les cessions de la même assemblée)
        $totAv = $K['apres']->sum(fn ($a) => (int) $a['p']);
        $totAp = $totAv + $nouv;
        $h .= self::P('Le tableau qui suit récapitule la répartition du capital social avant et après augmentation du capital :');
        $h .= '<table style="border-collapse:collapse; width:100%"><thead><tr>'.$th('Associé').$th('Avant augmentation', 4).$th('Après augmentation', 4).$th('Participation à l’augmentation (DT)').'</tr><tr>'
            .$th('').$th('Nombre de parts').$th('Valeur part (DT)').$th('Valeur totale (DT)').$th('%').$th('Nombre de parts').$th('Valeur part (DT)').$th('Valeur totale (DT)').$th('%').$th('').'</tr></thead><tbody>';
        foreach ($K['apres'] as $i => $a) {
            $av = (int) $a['p'];
            $ap = $av + ($K['souscrit'][$i] ?? 0);
            $h .= '<tr>'.$td(self::e($a['nom']), 'left').$td($av).$td(self::dt($vn)).$td(self::dt($av * $vn)).$td($pct($av, $totAv))
                .$td($ap).$td(self::dt($vn)).$td(self::dt($ap * $vn)).$td($pct($ap, $totAp)).$td(self::dt(($K['souscrit'][$i] ?? 0) * $vn)).'</tr>';
        }
        return $h.'<tr>'.$td('<b>Total</b>', 'left').$td($totAv).$td('').$td(self::dt($totAv * $vn)).$td('100 %').$td($totAp).$td('').$td(self::dt($totAp * $vn)).$td('100 %').$td(self::dt($nouv * $vn)).'</tr></tbody></table>';
    }
}
