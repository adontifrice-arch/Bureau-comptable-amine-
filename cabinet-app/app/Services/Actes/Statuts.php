<?php

namespace App\Services\Actes;

use App\Models\Client;

/**
 * Statuts SARL / SUARL générés depuis la fiche client (spécification §3.2).
 *
 * Plan imposé par la spécification et le prototype : 1 Forme (résidence, régime d'exportation), 2 Objet,
 * 3 Dénomination (dénomination commerciale), 4 Siège social, 5 Durée, 6 Capital social et apports,
 * 7 Répartition des parts (numéros inclus), 8 Gérance (date et durée de nomination) ; puis les clauses usuelles.
 * Les articles sont numérotés dans l'ordre de la liste : un article ajouté ou retiré renumérote les suivants.
 */
class Statuts
{
    /** @return list<array{0: string, 1: string}> [titre, HTML du corps] */
    public static function articles(Client $c, array $d): array
    {
        $e = fn ($s) => e((string) $s);
        $P = fn ($s) => '<p>'.$s.'</p>';
        $uni = ($d['type'] ?? 'sarl') === 'suarl';
        $as = Balises::detenteurs($c);
        $v = Balises::valeurs($c, $d);
        $vn = $c->valeur_nominale ? tnd($c->valeur_nominale) : Balises::VIDE;
        $np = $c->nb_parts ?: $as->sum('p');
        $liste = fn ($items) => '<ul>'.$items->map(fn ($x) => '<li>'.$x.'</li>')->implode('').'</ul>';
        $forme = $uni ? 'société unipersonnelle à responsabilité limitée (SUARL)' : 'société à responsabilité limitée (SARL)';

        // Article 1 : résidence et régime d'exportation, s'ils sont renseignés.
        $regime = array_filter([
            ['residente' => 'résidente', 'non_residente' => 'non résidente'][$c->residence] ?? null,
            ['non' => 'non exportatrice', 'totale' => 'totalement exportatrice', 'partielle' => 'partiellement exportatrice'][$c->regime_export] ?? null,
        ]);

        $duree = trim((string) $c->gerant_nomination_duree);
        $gerance = 'La société est gérée par '.$e(Balises::identite(Balises::gerant($c))).', nommé(e) gérant(e)'
            .($c->gerant_nomination_date ? ' le '.dlong($c->gerant_nomination_date) : '')
            .($duree !== '' ? ' pour une durée '.(preg_match('/^\d/', $duree) ? 'de ' : '').$e($duree) : ' pour une durée illimitée')
            .', avec les pouvoirs les plus étendus pour agir au nom de la société, dans la limite de l’objet social et des pouvoirs que la loi attribue aux associés.';

        return [
            ['Forme', $P(($uni ? 'Il est formé par l’associé unique une ' : 'Il est formé entre les soussignés une ').$forme.', régie par le Code des sociétés commerciales, la législation en vigueur et les présents statuts.'
                .($regime ? ' La société est une société '.implode(', ', $regime).'.' : ''))],
            ['Objet', $P('La société a pour objet : '.$e($v['OBJET'] === Balises::VIDE ? '[objet social à préciser]' : rtrim($v['OBJET'], '. ')).'.')
                .$P('Et plus généralement, toutes opérations commerciales, financières, mobilières ou immobilières se rattachant directement ou indirectement à l’objet social.')],
            ['Dénomination', $P('La société est dénommée : <b>'.$e($c->nom).'</b>.'.($c->denomination_commerciale ? ' Sa dénomination commerciale est : « '.$e($c->denomination_commerciale).' ».' : ''))],
            ['Siège social', $P('Le siège social est fixé à : '.$e($v['SIEGE']).'. Il pourra être transféré en tout autre lieu par décision '.($uni ? 'de l’associé unique' : 'de l’assemblée générale extraordinaire des associés').'.')],
            ['Durée', $P('La durée de la société est fixée à '.$e($v['DUREE']).' ans à compter de son immatriculation au registre national des entreprises, sauf dissolution anticipée ou prorogation.')],
            ['Capital social', $P('Le capital social est fixé à '.$e($v['CAPITAL']).', divisé en '.$e($np ?: Balises::VIDE).' parts sociales d’une valeur nominale de '.$e($vn).' chacune, entièrement souscrites et libérées.')
                .($as->isEmpty() ? '' : $P('Les parts sont souscrites et libérées comme suit :').$liste($as->map(fn ($a) => $e($a['nom']).' : '.($a['p'] ?: Balises::VIDE).' parts'
                    .($c->valeur_nominale && $a['p'] ? ' de '.$e($vn).' chacune, soit '.tnd($a['p'] * $c->valeur_nominale) : '')
                    .', apport '.(($a['ap'] ?? null) === 'nature' ? 'en nature' : (($a['ap'] ?? null) === 'numeraire' ? 'en numéraire' : 'en numéraire ou en nature (à préciser)')).'.')))],
            ['Répartition des parts', $P('Les '.$e($np ?: Balises::VIDE).' parts sociales sont attribuées comme suit :')
                .$liste($as->map(fn ($a) => $e($a['nom']).' : '.($a['p'] ?: Balises::VIDE).' parts'.($a['pd'] ? ', numérotées de '.$a['pd'].' à '.$a['pf'].' inclus' : '')))],
            ['Gérance', $P($gerance)],
            ['Cession et transmission des parts', $P($uni
                ? 'Les parts sont librement cessibles par l’associé unique. Toute cession est constatée par écrit et rendue opposable à la société dans les formes légales.'
                : 'Les parts sociales ne peuvent être cédées à des tiers étrangers à la société qu’avec le consentement de la majorité des associés représentant au moins les trois quarts du capital social, conformément au Code des sociétés commerciales.')],
            ['Décisions collectives', $P($uni
                ? 'L’associé unique exerce les pouvoirs dévolus à l’assemblée générale. Il statue sur les comptes dans les six mois de la clôture de l’exercice et ses décisions sont consignées dans un registre.'
                : 'Les associés sont réunis en assemblée générale au moins une fois par an, dans les six mois de la clôture de l’exercice, pour statuer sur les comptes. Les décisions ordinaires sont adoptées par un ou plusieurs associés représentant plus de la moitié du capital ; les décisions modifiant les statuts, par les associés représentant au moins les trois quarts du capital.')],
            ['Exercice social', $P('L’exercice social commence le 1er janvier et se termine le 31 décembre. Par exception, le premier exercice commence à l’immatriculation de la société et se termine le 31 décembre de la même année.')],
            ['Affectation des résultats', $P('Sur le bénéfice net, il est prélevé 5 % pour constituer la réserve légale jusqu’à ce que celle-ci atteigne le dixième du capital. Le solde est affecté sur décision '.($uni ? 'de l’associé unique' : 'des associés').'.')],
            ['Dissolution et liquidation', $P('La société est dissoute dans les cas prévus par la loi. À l’expiration de la société ou en cas de dissolution anticipée, la liquidation est effectuée conformément aux dispositions légales.')],
            ['Publicité et pouvoirs', $P('Tous pouvoirs sont donnés au porteur d’une copie des présentes pour accomplir les formalités de dépôt et de publicité prévues par la loi.')],
        ];
    }

    public static function html(Client $c, array $d, string $entete): string
    {
        $e = fn ($s) => e((string) $s);
        $uni = ($d['type'] ?? 'sarl') === 'suarl';
        $as = Balises::detenteurs($c);

        $h = '<h3 style="text-align:center">STATUTS<br>'.($uni ? 'SOCIÉTÉ UNIPERSONNELLE À RESPONSABILITÉ LIMITÉE (SUARL)' : 'SOCIÉTÉ À RESPONSABILITÉ LIMITÉE (SARL)').'</h3>'.$entete;
        $h .= '<p>'.($uni ? 'Le soussigné (associé unique) :' : 'Les soussignés :').'</p><ul>'.$as->map(fn ($a) => '<li>'.$e(Balises::identite($a)).'</li>')->implode('').'</ul>';
        $h .= '<p>'.($uni ? 'a établi' : 'ont établi').' ainsi qu’il suit les statuts de la société.</p>';
        foreach (self::articles($c, $d) as $i => [$titre, $corps]) {
            $h .= '<h3>Article '.($i + 1).' – '.$titre.'</h3>'.$corps;
        }
        $h .= '<p>Fait à '.$e(Balises::lieu($c)).', le '.dlong($d['date'] ?? null).', en quatre exemplaires originaux.</p>';
        return $h.'<p style="margin-top:30px">'.$as->map(fn ($a) => $e($a['nom']).' : ……………………')->implode('<br>').'</p>';
    }
}
