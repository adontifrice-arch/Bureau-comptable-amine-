<?php

namespace App\Services\Actes;

use App\Models\Client;
use Illuminate\Support\Collection;

/**
 * Statuts SARL / SUARL générés depuis la fiche client.
 *
 * SARL : modèle du cabinet (statuts « OFF THE GRID ») : 33 articles répartis en huit titres (formation, capital et parts,
 * gérance, décisions collectives, exercice et résultats, surveillance, dissolution, contestations), les données de la fiche
 * (dénomination, siège, capital, apports, répartition des parts, gérant…) étant reprises dans les articles concernés.
 * SUARL : plan court (forme, objet, dénomination, siège, durée, capital, parts, gérance, clauses usuelles).
 */
class Statuts
{
    /** @return list<array{0: string, 1: string, 2?: string}> [titre de l'article, HTML du corps, titre (TITRE …) ouvert par l'article] */
    public static function articles(Client $c, array $d): array
    {
        return ($d['type'] ?? 'sarl') === 'suarl' ? self::articlesSuarl($c, $d) : self::articlesSarl($c, $d);
    }

    public static function html(Client $c, array $d, string $entete): string
    {
        return ($d['type'] ?? 'sarl') === 'suarl' ? self::htmlSuarl($c, $d, $entete) : self::htmlSarl($c, $d);
    }

    // ---------------------------------------------------------------- SARL : modèle du cabinet

    /** Nombre en toutes lettres : « six mille huit cents ». */
    public static function lettres(float $n): string
    {
        if (abs($n - round($n)) > 0.0005) return mb_strtolower(montant_lettres($n));
        if (! class_exists(\NumberFormatter::class)) return number_format($n, 0, ',', ' ');
        return (new \NumberFormatter('fr', \NumberFormatter::SPELLOUT))->format((int) round($n));
    }

    /** Montant en chiffres : « 6 800 » (millimes affichés s'il y en a). */
    public static function chiffres(float $n, string $mille = ' '): string
    {
        return number_format($n, abs($n - round($n)) > 0.0005 ? 3 : 0, ',', $mille);
    }

    /** Détenteurs avec leurs parts numérotées : numéros de la fiche, sinon numérotation à la suite dans l'ordre des associés. */
    private static function numerotes(Client $c): Collection
    {
        $suivant = 1;
        return Balises::detenteurs($c)->map(function ($a) use (&$suivant) {
            if (! $a['pd'] && $a['p']) { $a['pd'] = $suivant; $a['pf'] = $suivant + $a['p'] - 1; }
            if ($a['pf']) $suivant = $a['pf'] + 1;
            return $a;
        });
    }

    private static function articlesSarl(Client $c, array $d): array
    {
        $e = fn ($s) => e((string) $s);
        $P = fn (string $s) => '<p style="text-align:justify">'.$s.'</p>';
        $S = fn (string $s) => '<p><b>'.$s.'</b></p>';
        $liste = fn (array $items) => '<ul>'.implode('', array_map(fn ($x) => '<li>'.$x.'</li>', $items)).'</ul>';
        $V = Balises::VIDE;
        $as = self::numerotes($c);
        $np = (int) ($c->nb_parts ?: $as->sum('p'));
        $vn = (float) $c->valeur_nominale;
        $cap = (float) ($c->capital ?: ($vn && $np ? $vn * $np : 0));
        $D = (int) ($d['duree'] ?? 0) ?: 99;
        $nom = $e($c->nom);

        // Article 1 : résidence et régime d'exportation, s'ils sont renseignés
        $regime = implode(', ', array_filter([
            ['residente' => 'résidente', 'non_residente' => 'non résidente'][$c->residence] ?? null,
            ['non' => 'non exportatrice', 'totale' => 'totalement exportatrice', 'partielle' => 'partiellement exportatrice'][$c->regime_export] ?? null,
        ]));

        // Article 2 : une activité par ligne saisie
        $objet = array_values(array_filter(array_map(fn ($x) => trim($x, " \t-*•;."), preg_split('/\r?\n/', (string) ($d['objet'] ?? '')))));
        $objet = $objet ? array_map(fn ($x) => $e(mb_strtoupper(mb_substr($x, 0, 1)).mb_substr($x, 1)).'.', $objet) : ['[objet social à préciser].'];

        // Article 6 : capital, apports et répartition des parts
        $dinars = fn (float $x) => $x ? $e(self::lettres($x)).' dinars ('.self::chiffres($x).' DT)' : $V.' dinars';
        $apports = $as->values()->map(function ($a, $i) use ($e, $vn, $dinars, $V) {
            $montant = $vn && $a['p'] ? $a['p'] * $vn : 0;
            $nature = ($a['ap'] ?? null) === 'nature';
            return ($i + 1).'- '.$e(Balises::nomCivil($a)).' apporte à la présente société '.($nature ? 'des biens en nature évalués à la somme de ' : 'la somme de ')
                .($montant ? $e(mb_strtoupper(self::lettres($montant))).' ('.self::chiffres($montant).') dinars' : $V.' dinars')
                .' et reçoit en rémunération de son apport '.($a['p'] ? $e(self::lettres($a['p'])).' ('.$a['p'].')' : $V).' parts de '.$dinars($vn).' chacune à créer à cet effet.';
        })->all();
        $td = fn ($x, $al = 'right') => '<td style="border:1px solid #000; padding:4px; text-align:'.$al.'">'.$x.'</td>';
        $th = fn ($x) => '<th style="border:1px solid #000; padding:4px; text-align:center">'.$x.'</th>';
        $lignes = $as->map(function ($a) use ($e, $vn, $np, $td) {
            $m = $vn && $a['p'] ? $a['p'] * $vn : 0;
            $nature = ($a['ap'] ?? null) === 'nature';
            return '<tr>'.$td($e(Balises::nomCivil($a)), 'left').$td(self::chiffres($nature ? $m : 0)).$td(self::chiffres($nature ? 0 : $m)).$td(self::chiffres($m))
                .$td($np && $a['p'] ? rtrim(rtrim(number_format($a['p'] / $np * 100, 2, ',', ''), '0'), ',').' %' : '').'</tr>';
        })->implode('');
        $totNat = $as->filter(fn ($a) => ($a['ap'] ?? null) === 'nature')->sum(fn ($a) => $vn * (int) $a['p']);
        $totApp = $as->sum(fn ($a) => $vn * (int) $a['p']);
        $tableau = '<table style="border-collapse:collapse; width:100%"><thead><tr>'.$th('Associé').$th('Apport en nature').$th('Apport en numéraire').$th('Total en DT').$th('%').'</tr></thead><tbody>'
            .$lignes.'<tr>'.$td('<b>Total</b>', 'left').$td(self::chiffres($totNat)).$td(self::chiffres($totApp - $totNat)).$td(self::chiffres($totApp)).$td($totApp ? '100 %' : '').'</tr></tbody></table>';
        $repartition = $as->map(fn ($a) => ($a['p'] ?: $V).' parts'.($a['pd'] ? ' numérotées de '.$a['pd'].' à '.$a['pf'].' inclus' : '').' à '.$e(Balises::nomCivil($a)).'.')->all();

        // Articles 12 et 13 : gérant statutaire
        $gerant = Balises::gerant($c);
        $associe = $as->contains(fn ($a) => mb_strtolower(trim($a['nom'])) === mb_strtolower(trim((string) $c->gerant_nom)));
        $duree = trim((string) $c->gerant_nomination_duree);
        $dureeG = $duree !== '' ? (preg_match('/^\d/', $duree) ? 'une durée de ' : '').$e($duree) : 'toute la durée de la société';
        $designation = ($associe || ! $c->gerant_nom ? $e(Balises::nomCivil($gerant)) : $e(Balises::identite($gerant))).' a été '.Balises::accord($gerant, 'désigné gérant', 'désignée gérante', 'désigné(e) gérant(e)').' statutaire de la société pour '.$dureeG
            .($c->gerant_nomination_date ? ' à compter du '.dlong($c->gerant_nomination_date) : '').' et dispose des pouvoirs les plus étendus sans limitation aucune.';

        return [
            // TITRE I – FORMATION
            ['Forme', $P('Il est formé par les présents, entre les propriétaires des parts ci-après, une société '.($regime ? $regime.', ' : '')
                .'ayant la forme de société à responsabilité limitée SARL, qui sera régie par la loi n° 2000-93 du 3 novembre 2000 portant promulgation du code des sociétés commerciales, par toutes autres dispositions légales et réglementaires en vigueur et par les présents statuts.'), 'TITRE I – FORMATION'],
            ['Objet', $P('La société a pour objet :').$liste(array_merge($objet, [
                'La participation directe ou indirecte, sous quelque forme que ce soit, dans toutes entreprises ou opérations quelconques relatives et pouvant se rattacher principalement ou accessoirement à l’un des objets précités, soit par voie de création de sociétés nouvelles, d’apports, de fusion, d’achats de titres ou droits sociaux, soit de toute autre manière ;',
                'Et généralement toutes opérations financières, commerciales, industrielles, mobilières ou immobilières, pouvant se rattacher directement ou indirectement à l’objet ci-dessus ou à tous objets similaires ou connexes de nature à favoriser son développement ou son extension.']))],
            ['Dénomination', $P('La société prend la dénomination de : <b>'.$nom.'</b>.'.($c->denomination_commerciale ? ' Sa dénomination commerciale est : « '.$e($c->denomination_commerciale).' ».' : ''))
                .$P('Conformément à l’article 91 du code des sociétés commerciales, cette dénomination devra être toujours précédée ou suivie de la mention SARL et de l’énonciation du montant du capital social, et ce, dans tous actes, factures, annonces publiques ou autres émanant de la société.')],
            ['Siège social', $P('Le siège social de la société est fixé au : '.$e($c->adresse ?: $V).'.').$P('Il pourra être transféré dans tout autre endroit ou lieu en vertu d’une simple décision de la gérance.')],
            ['Durée', $P('La durée de la société est fixée à '.$D.' ans qui commence à courir à compter de son immatriculation au registre national des entreprises, sauf le cas de dissolution anticipée ou de prorogation de durée par décision extraordinaire des associés.')
                .$P('L’exercice social commence le 1<sup>er</sup> janvier et finit le 31 décembre de chaque année.')],

            // TITRE II – CAPITAL – PARTS SOCIALES
            ['Capital social / Apports', $P('Le capital social est fixé à la somme de '.$dinars($cap).($c->residence === 'non_residente' ? ', soit l’équivalent de sa valeur en devises,' : '')
                .' divisé en '.($np ? $e(self::lettres($np)).' ('.$np.')' : $V).' parts sociales de '.$dinars($vn).' chacune, attribuées à chacun des associés en proportion de son apport respectif de la manière suivante :')
                .implode('', array_map($P, $apports))
                .$P('Les apports ont été réalisés comme suit :').$tableau
                .$P('Ces parts sont réparties comme suit :').$liste($repartition), 'TITRE II – CAPITAL – PARTS SOCIALES'],
            ['Comptes courants', $P('Outre leurs apports, les associés pourront verser ou laisser à la disposition de la société toutes sommes dont elle pourrait avoir besoin. Ces sommes seront inscrites au crédit d’un compte ouvert au nom de l’associé. La société a la faculté d’en rembourser tout ou partie, après avis donné par écrit un mois à l’avance, sauf stipulation contraire.')
                .$P('Ces sommes seront génératrices d’intérêts selon un taux fixé ultérieurement par l’assemblée des associés.')],
            ['Augmentation et réduction du capital', $S('Augmentation du capital').$S('1 – Modalités de l’augmentation du capital')
                .$P('Le capital social peut, en vertu d’une décision extraordinaire des associés, être augmenté, en une ou plusieurs fois, en représentation d’apports en nature ou en numéraire, ou par incorporation de tout ou partie des bénéfices ou réserves disponibles, au moyen de la création de parts sociales nouvelles ou de l’élévation de la valeur nominale des parts existantes. Les parts nouvelles peuvent être créées au pair ou avec prime ; dans ce cas, la collectivité des associés, par la décision extraordinaire portant augmentation du capital, fixe le montant de la prime et détermine son affectation.')
                .$S('2 – Souscription en numéraire et apport en nature')
                .$P('En cas d’augmentation de capital par souscription de parts sociales en numéraire, les fonds provenant de la libération des parts sociales doivent faire l’objet d’un dépôt dans un établissement financier, conformément aux dispositions de l’article 98 du code des sociétés commerciales.')
                .$P('Au cas où l’augmentation du capital a été réalisée, en tout ou partie, par des apports en nature, l’évaluation de ces apports doit être faite par un commissaire aux apports qui doit être désigné à l’unanimité des associés ou, à défaut, par ordonnance sur requête rendue par le président du tribunal de première instance dans le ressort duquel est situé le siège de la société. Cette ordonnance est rendue à la demande du futur associé le plus diligent. Toutefois, les associés peuvent décider à la majorité des voix de ne pas recourir à des commissaires aux apports si la valeur de chaque apport en nature ne dépasse pas la somme de trois mille dinars. Lorsque la valeur retenue est différente de celle proposée par le commissaire aux apports, les associés au jour de l’augmentation et les personnes ayant souscrit à l’augmentation du capital sont solidairement responsables à l’égard des tiers de l’évaluation de l’apport en nature pour une période de trois ans. Les parts représentatives de toute augmentation de capital doivent être entièrement libérées et réparties lors de leur création.')
                .$S('3 – Droit préférentiel de souscription')
                .$P('En cas d’augmentation du capital par voie d’apports en numéraire, chaque associé aura le droit de participer à l’augmentation du capital social proportionnellement à sa part. Le droit de souscription des associés peut être exercé dans le délai fixé par la résolution décidant l’augmentation du capital. Le délai sus-indiqué ne peut être inférieur à vingt et un jours à compter de la date de l’ouverture du droit de souscription. Les associés seront avisés de l’ouverture de la souscription ainsi que du délai pour souscrire par lettre recommandée avec accusé de réception. Passé ce délai, l’associé est considéré comme ayant renoncé à son droit de participer à l’augmentation. Dans ce cas, les parts sociales nouvelles non souscrites seront réparties entre les autres associés dans un délai de vingt et un jours et proportionnellement à leurs parts sociales dans la société. Passé ce délai, la souscription sera ouverte aux tiers en vertu d’une décision de l’assemblée générale extraordinaire. Par ailleurs, aucune attribution à des tiers ne pourra être faite sans l’accord du gérant. Toutefois, aucune décision ne peut obliger un associé à augmenter son engagement social.')
                .$S('Réduction du capital social')
                .$P('Le capital social peut être réduit, pour quelque cause et de quelque manière que ce soit, par décision extraordinaire de l’assemblée générale des associés. En aucun cas cette réduction ne peut porter atteinte à l’égalité entre les associés.')
                .$P('La réduction du capital à un montant inférieur au minimum légal ne peut être décidée, à moins que la société ne soit transformée en société d’une autre forme. À défaut, tout intéressé peut demander au tribunal compétent la dissolution de la société. Cette dissolution ne peut être prononcée si, au jour où le tribunal statue sur le fond en premier ressort, la régularisation a eu lieu. Nonobstant la régularisation, les frais de poursuite restent à la charge des défendeurs. Si les documents comptables font apparaître que les fonds propres de la société sont inférieurs de moitié au capital social suite aux pertes qu’elle a subies, une assemblée générale extraordinaire sera convoquée dans les deux mois de la constatation des pertes pour se prononcer, s’il y a lieu, sur la dissolution anticipée de la société, et ce selon les conditions de quorum et de majorité prévues pour les assemblées générales extraordinaires. Si la dissolution n’est pas décidée, la société est tenue, au plus tard à la clôture de l’exercice suivant, de réduire ou d’augmenter son capital d’un montant au moins égal à celui des pertes.')],
            ['Indivisibilité des parts', $P('Les parts sociales sont indivisibles à l’égard de la société, qui ne reconnaît qu’un seul propriétaire pour chaque part. Les copropriétaires d’une part indivise, héritiers ou ayants droit d’un associé décédé, sont tenus de se faire représenter auprès de la société par l’un d’entre eux, considéré par elle comme seul propriétaire. À défaut d’entente, le président du tribunal de première instance du siège social de la société fera désigner un mandataire chargé de représenter tous les copropriétaires.')],
            ['Cession des parts', $P('Les parts sociales sont librement cessibles entre associés. Elles sont également librement transmissibles par voie de succession. Mais elles ne peuvent être cédées à une personne physique ou morale préalablement non associée qu’avec l’agrément exprès des associés représentant au moins les 3/4 du capital social.')
                .$P('En cas de cession projetée à une personne étrangère à la société, le cédant doit en faire la déclaration à la société, par lettre recommandée avec accusé de réception, en lui indiquant le nom, prénom, profession et domicile du cessionnaire, le nombre de parts à céder ainsi que le prix de la cession. Dans les dix jours qui suivent cette déclaration, le gérant doit convoquer l’assemblée générale pour statuer sur ce projet de cession ou, à défaut, doit notifier, par lettre recommandée avec accusé de réception, le projet de cession à tous les associés. Ces derniers expriment leur avis par écrit dans les quinze jours de la réception du projet de cession. Dans ce délai, tout associé a le droit de se rendre acquéreur de la totalité ou d’une partie des parts mises en vente à un prix égal à celui indiqué dans le projet de cession. Si un ou plusieurs associés, représentant le quart au moins du capital, veulent user du droit de préemption, la vente est consentie entre les associés acheteurs dans la proportion du nombre de parts déjà détenues par eux. Si aucun associé n’a usé du droit de préemption ou s’il n’a été usé de ce droit qu’en partie, la cession des parts sur lesquelles le droit de préemption n’a pas été exercé sera opérée au profit de la personne indiquée dans le projet de cession. Dans tous les cas de cession des parts, l’acte de cession doit être notifié au gérant de la société au plus tard quinze jours après sa conclusion.')],
            ['Nantissement', $P('Lorsque l’associé a l’intention de donner ses parts en nantissement, il devra aviser la société par lettre recommandée avec accusé de réception. Autrement, l’acte de nantissement et toutes les conséquences qui peuvent en découler ne seront pas opposables à la société et aux autres associés.')],

            // TITRE III – GÉRANCE
            ['Nomination du gérant', $P('La société est représentée, dirigée et administrée par un ou plusieurs gérants pris parmi les associés ou en dehors d’eux. La nomination du ou des gérants est faite par les associés représentant plus de la moitié du capital social. À défaut de la réunion de ce quorum après deux réunions consécutives, la nomination du gérant peut être faite par la majorité relative des votes émis à la troisième réunion des associés, quelle que soit la portion du capital qu’ils représentent.')
                .$P($designation), 'TITRE III – GÉRANCE'],
            ['Durée des fonctions du gérant', $P('La nomination du gérant est faite pour '.($duree !== '' ? $dureeG : 'une durée de '.$D.' ans').' à partir de la date de la constitution définitive de la société.')
                .$P('Le gérant pourra résilier ses fonctions, mais à charge de prévenir tous les associés un mois à l’avance par lettre recommandée.')
                .$P('Il ne pourra être révoqué que pour motifs légitimes, conformément aux dispositions de l’article 122 du code des sociétés commerciales.')],
            ['Pouvoirs du gérant', $P('Dans tous les rapports avec les tiers, le gérant est investi des pouvoirs les plus étendus pour agir en toutes circonstances au nom de la société, sauf les pouvoirs que la loi attribue expressément aux associés ou que les associés eux-mêmes se sont réservés expressément.')
                .$P('Il a notamment le pouvoir de la signature sociale ; il est seul habilité à représenter la société en justice. Il a le pouvoir de vendre, nantir ou hypothéquer tout bien, meuble ou immeuble, au nom et pour le compte de la société.')
                .$P('Il est en droit de déléguer certains de ses pouvoirs à un ou plusieurs agents de la société pour des objets bien déterminés. Toute délégation générale est interdite. Le ou les gérants pourront aussi, sous leur responsabilité, désigner des mandataires pour un ou plusieurs objets spéciaux et limités.')],
            ['Obligations du gérant', $P('Le ou les gérants sont tenus de consacrer à la société tout le temps et tous les soins nécessaires à sa bonne marche. En dehors des fonctions qu’ils assument actuellement, ils ne pourront être associés ou accepter de nouveaux postes de gérant, de président, d’administrateur ou de directeur d’une entreprise dont l’objet social serait analogue à celui de la société présentement gérée, à moins d’y être préalablement autorisés par la majorité des associés représentant les 3/4 du capital social.')],
            ['Responsabilités du gérant', $P('Le ou les gérants ne contractent, à raison de leur gestion, aucune obligation personnelle ou solidaire relativement aux engagements de la société. Toutefois, ils sont responsables, soit envers la société, soit envers les tiers, des infractions aux dispositions des présents statuts, ainsi que des fautes commises par eux dans leur gestion et des infractions aux dispositions édictées en la matière par la loi tunisienne.')],
            ['Rémunération des gérants', $P('Le gérant aura droit, en rémunération de son travail, à un traitement de directeur, dont le montant sera fixé par décision collective des associés. Il aura droit, en outre, au remboursement de ses frais de déplacement, à passer en frais généraux, dont les modalités sont précisées dans l’acte fixant sa rémunération. Il pourra avoir droit, s’il en est ainsi décidé par une décision collective ordinaire des associés, à une participation sur le chiffre d’affaires ou sur les bénéfices, à passer en frais généraux de l’exercice. Cette rémunération ou participation pourra être augmentée, diminuée ou supprimée par une décision collective ordinaire des porteurs de parts.')],
            ['Conventions entre la société et la gérance ou un associé', $P('Toute convention intervenue directement ou par personne interposée entre la société et son gérant, associé ou non, ainsi qu’entre la société et l’un des autres associés, devra faire l’objet d’un rapport présenté à l’assemblée générale soit par le gérant, soit par le commissaire aux comptes s’il en existe un. L’assemblée générale statue sur ce rapport sans que le gérant ou l’associé intéressé puisse prendre part au vote ou que ses parts soient prises en compte pour le calcul du quorum ou de la majorité. Les conventions non approuvées produisent leurs effets, mais le gérant ou l’associé contractant seront tenus pour responsables, individuellement et solidairement s’il y a lieu, des dommages subis par la société de ce fait.')
                .$P('Les dispositions du présent article s’appliquent aux conventions passées avec une société dont un associé solidairement responsable, gérant, administrateur, directeur général ou membre du directoire ou du conseil de surveillance est simultanément gérant ou associé de la société. Il est interdit à la société d’accorder des emprunts à un gérant sous quelque forme que ce soit, ainsi que de cautionner ou d’avaliser ses engagements envers les tiers. L’interdiction s’étend aux représentants légaux des personnes morales associées ainsi qu’aux conjoints et descendants des personnes visées ci-dessus.')],

            // TITRE IV – DÉCISIONS COLLECTIVES
            ['Modalités diverses des décisions collectives', $P('1 – Les décisions collectives statuant sur les comptes sociaux sont prises en assemblées générales convoquées à l’initiative soit de la gérance, soit du commissaire aux comptes s’il en existe un, soit d’associés, soit enfin d’un mandataire désigné par justice, ainsi qu’il est dit à l’article 20 des présents statuts.')
                .$P('2 – Les décisions collectives peuvent être prises par consultation écrite des associés ou peuvent résulter du consentement de tous les associés exprimé dans un acte (si le nombre des associés est inférieur à six).')
                .$P('3 – Les décisions collectives sont qualifiées d’ordinaires ou d’extraordinaires.')
                .$P('Elles sont qualifiées d’extraordinaires lorsqu’elles ont pour objet la modification des statuts. Elles sont qualifiées d’ordinaires dans tous les autres cas. Une délibération n’est adoptée en assemblée générale ordinaire que si elle a été votée par un ou plusieurs associés représentant plus de la moitié du capital social.')
                .$P('Si la majorité ci-dessus n’est pas atteinte lors de la première assemblée, les associés sont convoqués de nouveau sans que le délai entre la première et la seconde assemblée générale soit inférieur à quinze jours, et cette convocation se fera par lettre recommandée avec accusé de réception huit jours au moins avant la tenue de la deuxième assemblée. Lors de la seconde assemblée générale, les décisions sont prises à la majorité des voix des associés présents ou représentés, quel que soit le nombre des votants. Les décisions extraordinaires doivent être adoptées par les associés représentant au moins les 3/4 du capital social.'), 'TITRE IV – DÉCISIONS COLLECTIVES'],
            ['Assemblées générales', $S('1 – Convocation')
                .$P('Les décisions sociales sont prises par les associés réunis en assemblée générale ordinaire ou extraordinaire. Toutefois, si le nombre des associés est inférieur à six, les décisions peuvent être prises par consultation écrite des associés, sauf pour les délibérations portant sur l’approbation des comptes annuels. Les associés sont convoqués aux assemblées générales par le gérant et, à défaut, par le commissaire aux comptes s’il en existe un. La convocation sera adressée soit par lettre recommandée avec accusé de réception, soit par remise directe contre décharge, et ce vingt jours au moins avant la date de la tenue de l’assemblée générale. Elle mentionne clairement l’ordre du jour de l’assemblée générale ainsi que le texte des résolutions proposées.')
                .$P('Un ou plusieurs associés détenant au moins le quart du capital social peuvent, une fois par an, demander au gérant de convoquer l’assemblée générale suivant les formes ci-avant indiquées. Tout associé peut, pour juste motif, demander au juge des référés d’ordonner au gérant de convoquer l’assemblée et de fixer l’ordre du jour. Dans tous les cas, la société sera tenue de supporter les dépenses occasionnées par la réunion de l’assemblée générale. Tout associé peut recourir au juge des référés pour faire constater la nullité d’une assemblée irrégulièrement convoquée, sauf si tous les associés y étaient présents ou représentés. L’assemblée générale ordinaire annuelle doit être tenue dans le délai de trois mois à compter de la clôture de l’exercice.')
                .$S('2 – Ordre du jour')
                .$P('L’ordre du jour de l’assemblée, qui doit être indiqué dans la lettre de convocation, est arrêté par l’auteur de la convocation. Sous réserve des questions diverses, qui ne doivent présenter qu’une minime importance, les questions inscrites à l’ordre du jour sont libellées de telle sorte que leur contenu et leur portée apparaissent clairement sans qu’il y ait lieu de se reporter à d’autres documents.')
                .$S('3 – Participation aux décisions et nombre de voix')
                .$P('Tout associé a le droit de participer aux décisions et dispose d’un nombre de voix égal à celui des parts qu’il possède.')
                .$S('4 – Représentation')
                .$P('Chaque associé peut être représenté par une autre personne munie d’une procuration spéciale.')
                .$S('5 – Réunion – Président de l’assemblée')
                .$P('L’assemblée est réunie au lieu indiqué dans la convocation. Elle est présidée par le gérant ou l’un des gérants s’ils sont associés. Si aucun des gérants n’est associé, elle est présidée par l’associé présent et acceptant qui possède ou représente le plus grand nombre de parts sociales. Si plusieurs associés qui possèdent ou représentent le même nombre de parts sont acceptants, la présidence de l’assemblée est assurée par le plus âgé d’entre eux.')],
            ['Consultation écrite', $P('À l’appui de la demande de consultation écrite, le texte des résolutions proposées ainsi que les documents nécessaires à l’information des associés sont adressés à ceux-ci par lettre recommandée avec accusé de réception. Les associés doivent, dans un délai maximal de quinze jours à compter de la date de réception des projets de résolutions, émettre leur vote par écrit ; pendant ledit délai, les associés peuvent demander à la gérance les explications complémentaires qu’ils jugent utiles. Chaque associé dispose d’un nombre de voix égal à celui des parts sociales qu’il possède. Pour chaque résolution, le vote est exprimé par « oui » ou par « non ». Tout associé qui n’aura pas adressé sa réponse dans le délai maximal fixé ci-dessus sera considéré comme s’étant abstenu.')],
            ['Procès-verbaux', $S('1 – Procès-verbal d’assemblée générale')
                .$P('Toute délibération de l’assemblée générale des associés est constatée par un procès-verbal établi et signé par l’ensemble des associés présents ou représentés.')
                .$P('Le procès-verbal indique la date et le lieu de la réunion, les nom, prénom et qualité des associés présents et représentés avec l’indication du nombre de parts détenues par chacun d’eux, les documents et rapports soumis à l’assemblée, un résumé des débats, le texte des résolutions mises aux voix et le résultat des votes.')
                .$S('2 – Consultation écrite')
                .$P('En cas de consultation écrite, il en est fait mention dans le procès-verbal, auquel sera annexée la réponse de chaque associé.')
                .$S('3 – Registre des procès-verbaux')
                .$P('Les procès-verbaux établis seront portés sur des registres spéciaux cotés et paraphés, tenus au siège social de la société.')],
            ['Information des associés', $P('Vingt et un jours au moins avant la tenue de l’assemblée générale ayant pour objet l’approbation des comptes de gestion, les documents suivants sont communiqués aux associés par lettre recommandée avec accusé de réception :')
                .$liste(['le rapport de gestion ;', 'les états financiers établis selon les dispositions du système comptable des entreprises ;', 'le texte des résolutions proposées ;', 'le rapport du commissaire aux comptes.'])
                .$P('À compter de la communication prévue ci-dessus, tout associé peut poser par écrit des questions au gérant, et ce huit jours au moins avant la date prévue pour la tenue de l’assemblée. Le gérant sera tenu de répondre aux questions écrites au cours de l’assemblée générale. En outre, et à tout moment, tout associé peut prendre connaissance des documents visés ci-dessus concernant les trois derniers exercices et se faire aider par un expert-comptable.')],

            // TITRE V – EXERCICE SOCIAL, RÉPARTITION DES BÉNÉFICES ET DES PERTES
            ['Exercice social', $P('L’exercice social commence le premier janvier de l’année et expire le trente et un décembre.'), 'TITRE V – EXERCICE SOCIAL – RÉPARTITION DES BÉNÉFICES ET DES PERTES'],
            ['États financiers et rapport de la gérance', $P('À la clôture de chaque exercice, le gérant établit les états financiers et prépare un rapport aux associés sur la marche de la société pendant l’exercice écoulé et ses perspectives d’avenir.')
                .$P('Les états financiers et généralement tous les documents qui, d’après la loi, doivent être communiqués à l’assemblée, doivent être tenus à la disposition des associés au siège social vingt jours avant la date de l’assemblée, le tout sans préjudice de tous autres droits de communication susceptibles d’être conférés aux associés ou à tous tiers par la législation en vigueur.')],
            ['Bénéfices nets', $P('Les bénéfices nets sont composés des produits nets de l’exercice, après déduction des frais généraux et autres charges sociales, ainsi que de tous les amortissements de l’actif social et de toutes les provisions pour risques commerciaux et industriels.')],
            ['Emploi des bénéfices', $S('1 – Réserve légale')
                .$P('Il est fait sur les bénéfices nets de l’exercice, diminués le cas échéant des pertes antérieures, un prélèvement d’un vingtième affecté à la formation d’un fonds de réserve dit « réserve légale ». Ce prélèvement cesse d’être obligatoire lorsque la réserve atteint le dixième du capital social.')
                .$S('2 – Bénéfice distribuable')
                .$P('Le bénéfice distribuable est constitué par le bénéfice net de l’exercice, diminué des pertes antérieures et du prélèvement de la réserve légale, et augmenté des reports bénéficiaires. En outre, l’assemblée générale peut décider la mise en distribution de sommes prélevées sur les réserves dont elle a la disposition. Dans ce cas, la décision indique expressément les postes de réserves sur lesquels les prélèvements sont effectués.')
                .$S('3 – Réserve statutaire – Report à nouveau')
                .$P('L’assemblée peut décider l’inscription au compte report à nouveau, ou à tous comptes de réserves, de tout ou partie des bénéfices distribuables. Elle peut fixer l’affectation ou l’emploi des bénéfices ainsi inscrits à ces comptes. Ils peuvent être affectés notamment au financement des investissements de la société.')
                .$S('4 – Sommes distribuables')
                .$P('Le total du bénéfice distribuable et des réserves, diminué le cas échéant des montants portés au compte report à nouveau ou aux comptes de réserves dont l’assemblée a la disposition, constitue les sommes distribuables. Après approbation des comptes et constatation de l’existence de sommes distribuables, l’assemblée décide la répartition de ces sommes proportionnellement aux parts sociales détenues par chaque associé.')],
            ['Pertes', $P('Les pertes, s’il en existe, sont, après approbation des comptes par l’assemblée générale, inscrites en report à nouveau débiteur.')
                .$P('Le compte report à nouveau débiteur sera imputé par priorité sur les bénéfices ultérieurs jusqu’à extinction.')],

            // TITRE VI – ORGANES DE SURVEILLANCE
            ['Commissaires aux comptes', $P('Un ou plusieurs commissaires aux comptes doivent être désignés dans les conditions prévues par l’article 123 du code des sociétés commerciales promulgué par la loi n° 2000-93 du 3 novembre 2000.'), 'TITRE VI – ORGANES DE SURVEILLANCE'],

            // TITRE VII – DISSOLUTION ET LIQUIDATION
            ['Causes de dissolution', $P('La société n’est pas dissoute par la mort, l’interdiction, la faillite ou l’incapacité frappant l’un des associés. Elle ne peut être dissoute qu’à l’arrivée du terme fixé par les statuts ou par décision des associés représentant les trois quarts au moins du capital social.'), 'TITRE VII – DISSOLUTION DE LA SOCIÉTÉ ET LIQUIDATION'],
            ['Cas de perte', $P('En cas de perte de la moitié du capital, le gérant doit, dans les quatre mois qui suivent l’approbation des comptes ayant fait apparaître cette perte, provoquer la réunion de l’assemblée générale extraordinaire des associés à l’effet de statuer sur la question de savoir s’il y a lieu de prononcer la dissolution de la société. La résolution de l’assemblée sera, dans tous les cas, rendue publique.')],
            ['Liquidation', $P('En cas de dissolution de la société, sa liquidation peut être confiée soit au gérant en exercice, soit à un liquidateur nommé par une décision collective des associés prise à la majorité des 3/4 du capital social. La procédure de liquidation suivra les règles édictées par le code des sociétés commerciales.')],

            // TITRE VIII – CONTESTATIONS
            ['Tribunaux compétents', $P('Toutes contestations qui peuvent s’élever au cours de la vie de la société ou de sa liquidation, soit entre les associés et la société, soit entre les associés eux-mêmes, au sujet des affaires sociales, sont jugées conformément à la loi et soumises à la juridiction des tribunaux compétents du lieu du siège social.'), 'TITRE VIII – CONTESTATIONS'],
        ];
    }

    private static function htmlSarl(Client $c, array $d): string
    {
        $e = fn ($s) => e((string) $s);
        $C = fn (string $s) => '<p style="text-align:center"><b>'.$s.'</b></p>';
        $as = self::numerotes($c);
        $np = (int) ($c->nb_parts ?: $as->sum('p'));
        $cap = (float) ($c->capital ?: ((float) $c->valeur_nominale * $np));

        // En-tête : dénomination, capital, régime, siège
        $h = '<h2 style="text-align:center">STATUTS</h2>'.$C($e($c->nom)).$C('SARL AU CAPITAL DE '.($cap ? self::chiffres($cap, '.') : Balises::VIDE).' DINARS');
        if ($r = ['residente' => 'RÉSIDENTE', 'non_residente' => 'NON RÉSIDENTE'][$c->residence] ?? null) $h .= $C($r);
        if ($r = ['non' => 'NON EXPORTATRICE', 'totale' => 'TOTALEMENT EXPORTATRICE', 'partielle' => 'PARTIELLEMENT EXPORTATRICE'][$c->regime_export] ?? null) $h .= $C($r);
        $h .= '<p style="text-align:center">Siège social : '.$e($c->adresse ?: Balises::VIDE).'</p>';

        foreach (self::articles($c, $d) as $i => $a) {
            if (! empty($a[2])) {
                $h .= '<h2 style="text-align:center">'.$a[2].'</h2>';
                if ($i === 0) $h .= '<p>Entre les soussignés :</p>'.($as->isEmpty() ? '<p>'.Balises::VIDE.'</p>' : $as->values()->map(fn ($x, $k) => '<p style="text-align:justify">'.($k + 1).'- '.$e(Balises::identite($x)).'.</p>')->implode(''));
            }
            $h .= '<h3>ARTICLE '.($i === 0 ? 'PREMIER' : $i + 1).' : '.mb_strtoupper($a[0]).'</h3>'.$a[1];
        }

        // Signatures des associés et du gérant
        // Ville : après le code postal (« … Montplaisir 1073 Tunis »), sinon fin de l'adresse
        $lieu = rtrim(Balises::lieu($c), '. ');
        if (preg_match('/\b\d{4}\s+([^\d,]+)$/u', $lieu, $m)) $lieu = trim($m[1]);
        $h .= '<p style="margin-top:20px">Fait à '.$e(mb_strtoupper($lieu)).' en autant d’exemplaires que de droit.<br>Le '.(! empty($d['date']) ? dfr($d['date'], 'd-m-Y') : Balises::VIDE).'</p>';
        $h .= '<p>Lu et approuvé :</p><p style="text-align:center"><b>LES ASSOCIÉS</b></p>';
        $h .= '<table style="width:100%"><tr>'.$as->map(fn ($x) => '<td style="text-align:center; vertical-align:top; padding:4px">'.$e(Balises::nomCivil($x)).'</td>')->implode('').'</tr></table>';
        return $h.'<p style="margin-top:60px; text-align:center"><b>LE GÉRANT</b><br>'.$e($c->gerant_nom ? Balises::nomCivil(Balises::gerant($c)) : Balises::VIDE).'</p>';
    }

    // ---------------------------------------------------------------- SUARL : plan court

    private static function articlesSuarl(Client $c, array $d): array
    {
        $e = fn ($s) => e((string) $s);
        $P = fn ($s) => '<p>'.$s.'</p>';
        $as = Balises::detenteurs($c);
        $v = Balises::valeurs($c, $d);
        $vn = $c->valeur_nominale ? tnd($c->valeur_nominale) : Balises::VIDE;
        $np = $c->nb_parts ?: $as->sum('p');
        $liste = fn ($items) => '<ul>'.$items->map(fn ($x) => '<li>'.$x.'</li>')->implode('').'</ul>';

        $regime = array_filter([
            ['residente' => 'résidente', 'non_residente' => 'non résidente'][$c->residence] ?? null,
            ['non' => 'non exportatrice', 'totale' => 'totalement exportatrice', 'partielle' => 'partiellement exportatrice'][$c->regime_export] ?? null,
        ]);

        $duree = trim((string) $c->gerant_nomination_duree);
        $gerance = 'La société est gérée par '.$e(Balises::identite(Balises::gerant($c))).', '.Balises::accord(Balises::gerant($c), 'nommé gérant', 'nommée gérante', 'nommé(e) gérant(e)')
            .($c->gerant_nomination_date ? ' le '.dlong($c->gerant_nomination_date) : '')
            .($duree !== '' ? ' pour une durée '.(preg_match('/^\d/', $duree) ? 'de ' : '').$e($duree) : ' pour une durée illimitée')
            .', avec les pouvoirs les plus étendus pour agir au nom de la société, dans la limite de l’objet social et des pouvoirs que la loi attribue aux associés.';

        return [
            ['Forme', $P('Il est formé par l’associé unique une société unipersonnelle à responsabilité limitée (SUARL), régie par le Code des sociétés commerciales, la législation en vigueur et les présents statuts.'
                .($regime ? ' La société est une société '.implode(', ', $regime).'.' : ''))],
            ['Objet', $P('La société a pour objet : '.$e($v['OBJET'] === Balises::VIDE ? '[objet social à préciser]' : rtrim($v['OBJET'], '. ')).'.')
                .$P('Et plus généralement, toutes opérations commerciales, financières, mobilières ou immobilières se rattachant directement ou indirectement à l’objet social.')],
            ['Dénomination', $P('La société est dénommée : <b>'.$e($c->nom).'</b>.'.($c->denomination_commerciale ? ' Sa dénomination commerciale est : « '.$e($c->denomination_commerciale).' ».' : ''))],
            ['Siège social', $P('Le siège social est fixé à : '.$e($v['SIEGE']).'. Il pourra être transféré en tout autre lieu par décision de l’associé unique.')],
            ['Durée', $P('La durée de la société est fixée à '.$e($v['DUREE']).' ans à compter de son immatriculation au registre national des entreprises, sauf dissolution anticipée ou prorogation.')],
            ['Capital social', $P('Le capital social est fixé à '.$e($v['CAPITAL']).', divisé en '.$e($np ?: Balises::VIDE).' parts sociales d’une valeur nominale de '.$e($vn).' chacune, entièrement souscrites et libérées.')
                .($as->isEmpty() ? '' : $P('Les parts sont souscrites et libérées comme suit :').$liste($as->map(fn ($a) => $e(Balises::nomCivil($a)).' : '.($a['p'] ?: Balises::VIDE).' parts'
                    .($c->valeur_nominale && $a['p'] ? ' de '.$e($vn).' chacune, soit '.tnd($a['p'] * $c->valeur_nominale) : '')
                    .', apport '.(($a['ap'] ?? null) === 'nature' ? 'en nature' : (($a['ap'] ?? null) === 'numeraire' ? 'en numéraire' : 'en numéraire ou en nature (à préciser)')).'.')))],
            ['Répartition des parts', $P('Les '.$e($np ?: Balises::VIDE).' parts sociales sont attribuées comme suit :')
                .$liste($as->map(fn ($a) => $e(Balises::nomCivil($a)).' : '.($a['p'] ?: Balises::VIDE).' parts'.($a['pd'] ? ', numérotées de '.$a['pd'].' à '.$a['pf'].' inclus' : '')))],
            ['Gérance', $P($gerance)],
            ['Cession et transmission des parts', $P('Les parts sont librement cessibles par l’associé unique. Toute cession est constatée par écrit et rendue opposable à la société dans les formes légales.')],
            ['Décisions collectives', $P('L’associé unique exerce les pouvoirs dévolus à l’assemblée générale. Il statue sur les comptes dans les six mois de la clôture de l’exercice et ses décisions sont consignées dans un registre.')],
            ['Exercice social', $P('L’exercice social commence le 1er janvier et se termine le 31 décembre. Par exception, le premier exercice commence à l’immatriculation de la société et se termine le 31 décembre de la même année.')],
            ['Affectation des résultats', $P('Sur le bénéfice net, il est prélevé 5 % pour constituer la réserve légale jusqu’à ce que celle-ci atteigne le dixième du capital. Le solde est affecté sur décision de l’associé unique.')],
            ['Dissolution et liquidation', $P('La société est dissoute dans les cas prévus par la loi. À l’expiration de la société ou en cas de dissolution anticipée, la liquidation est effectuée conformément aux dispositions légales.')],
            ['Publicité et pouvoirs', $P('Tous pouvoirs sont donnés au porteur d’une copie des présentes pour accomplir les formalités de dépôt et de publicité prévues par la loi.')],
        ];
    }

    private static function htmlSuarl(Client $c, array $d, string $entete): string
    {
        $e = fn ($s) => e((string) $s);
        $as = Balises::detenteurs($c);
        $h = '<h3 style="text-align:center">STATUTS<br>SOCIÉTÉ UNIPERSONNELLE À RESPONSABILITÉ LIMITÉE (SUARL)</h3>'.$entete;
        $h .= '<p>Le soussigné (associé unique) :</p><ul>'.$as->map(fn ($a) => '<li>'.$e(Balises::identite($a)).'</li>')->implode('').'</ul>';
        $h .= '<p>a établi ainsi qu’il suit les statuts de la société.</p>';
        foreach (self::articles($c, $d) as $i => [$titre, $corps]) {
            $h .= '<h3>Article '.($i + 1).' – '.$titre.'</h3>'.$corps;
        }
        $h .= '<p>Fait à '.$e(Balises::lieu($c)).', le '.dlong($d['date'] ?? null).', en quatre exemplaires originaux.</p>';
        return $h.'<p style="margin-top:30px">'.$as->map(fn ($a) => $e(Balises::nomCivil($a)).' : ……………………')->implode('<br>').'</p>';
    }
}
