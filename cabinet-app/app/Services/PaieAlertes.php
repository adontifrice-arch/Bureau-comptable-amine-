<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Paie;
use App\Services\Exports\EtatsPaie;
use Carbon\Carbon;

/** Paie, menu Fichier → Notifications : informations manquantes et paies ou déclarations en attente. */
class PaieAlertes
{
    /**
     * Tableaux de la fenêtre Notifications : contrats qui expirent dans moins de 30 jours, contrats expirés, profils expirés,
     * anniversaires des 30 prochains jours, échelons expirés et catégories expirées.
     *
     * @return array<string, array{titre: string, colonnes: string[], lignes: array<int, array>}>
     */
    public static function tableaux(Client $client): array
    {
        $auj = Carbon::today();
        $sal = $client->salaries()->orderBy('nom')->get();
        $expires = fn (string $c) => $sal->filter(fn ($e) => $e->{$c.'_fin'} && $e->{$c.'_fin'}->lt($auj))->sortBy(fn ($e) => $e->{$c.'_fin'})->values();

        $bientot = $sal->filter(fn ($e) => $e->contrat_fin && $e->contrat_fin->betweenIncluded($auj, $auj->copy()->addDays(30)))->sortBy('contrat_fin')->values();
        $anniv = $sal->filter(fn ($e) => $e->date_naissance)->map(function ($e) use ($auj) {
            $p = $e->date_naissance->copy()->year($auj->year);
            if ($e->date_naissance->format('m-d') === '02-29' && ! $p->isLeapYear()) $p = $p->setDate($auj->year, 2, 28);
            if ($p->lt($auj)) $p = $e->date_naissance->copy()->year($auj->year + 1);
            return ['e' => $e, 'date' => $p];
        })->filter(fn ($a) => $a['date']->lte($auj->copy()->addDays(30)))->sortBy('date')->values();

        $periode = fn ($c) => fn ($e) => [$e->nomComplet(), $e->$c, dfr($e->{$c.'_debut'}), dfr($e->{$c.'_fin'})];
        return [
            'contrats_bientot' => ['titre' => 'Contrats expirent dans moins de 30 jours', 'colonnes' => ['Nom & prénom', 'Date début', 'Date fin'],
                'lignes' => $bientot->map(fn ($e) => [$e->nomComplet(), dfr($e->contrat_debut), dfr($e->contrat_fin)])->all()],
            'contrats_expires' => ['titre' => 'Contrats expirés', 'colonnes' => ['Nom & prénom', 'Date début', 'Date fin'],
                'lignes' => $expires('contrat')->map(fn ($e) => [$e->nomComplet(), dfr($e->contrat_debut), dfr($e->contrat_fin)])->all()],
            'profils_expires' => ['titre' => 'Profils expirés', 'colonnes' => ['Nom & prénom', 'Profil', 'Date début', 'Date fin'], 'lignes' => $expires('profil')->map($periode('profil'))->all()],
            'anniversaires' => ['titre' => 'Anniversaires', 'colonnes' => ['Nom & prénom', 'Date anniversaire'],
                'lignes' => $anniv->map(fn ($a) => [$a['e']->nomComplet(), $a['date']->format('d/m/Y').' ('.(int) $a['e']->date_naissance->diffInYears($a['date']).' ans)'.($a['date']->isSameDay($auj) ? ' — aujourd’hui' : '')])->all()],
            'echelons_expires' => ['titre' => 'Échelons expirés', 'colonnes' => ['Nom & prénom', 'Échelon', 'Date début', 'Date fin'], 'lignes' => $expires('echelon')->map($periode('echelon'))->all()],
            'categories_expirees' => ['titre' => 'Catégories expirées', 'colonnes' => ['Nom & prénom', 'Catégorie', 'Date début', 'Date fin'], 'lignes' => $expires('categorie')->map($periode('categorie'))->all()],
        ];
    }

    /** @return array<int, array{niveau: string, texte: string, lien: ?string}> niveau : err | warn | info */
    public static function pour(Client $client): array
    {
        $a = [];
        $sal = $client->salaries()->orderBy('nom')->get();
        $fiche = route('paie.index', ['tab' => 'sal']);
        $ficheDe = fn ($e) => route('paie.employes.edit', $e);

        $k = $client->paie_config ?? [];
        $manque = array_keys(array_filter([
            'adresse' => ! $client->adresse, 'ville' => empty($k['ville']), 'n° employeur CNSS' => ! $client->matricule_cnss, 'activité' => ! $client->paie_activite,
            'responsable' => empty($k['responsable_nom']), 'statut' => ! $client->forme, 'matricule fiscal' => ! $client->matricule_fiscal,
            'SMIG' => empty($k['smig_mensuel']),
        ]));
        if ($manque) $a[] = ['niveau' => 'err', 'texte' => 'Renseignements employeur incomplets : '.implode(', ', $manque).'.', 'lien' => null];
        if (! $client->paie_rib && $sal->contains('mode_paiement', 'virement')) $a[] = ['niveau' => 'warn', 'texte' => 'Compte de l’employeur à débiter non renseigné (ordre de virement).', 'lien' => null];

        $smig = (float) ($k['smig_mensuel'] ?? 0);
        $profils = \App\Models\PaieProfil::parNom($client);
        foreach ($sal as $e) {
            $manque = array_keys(array_filter([
                'CIN' => ! $e->cin,
                // Pas de n° CNSS exigé des CIVP, gérants et autres profils non déclarés à la CNSS.
                'n° CNSS' => ! $e->cnss && ! $e->cnssFacultatif($profils),
                'RIB' => $e->mode_paiement === 'virement' && ! $e->rib,
                'salaire de base' => (float) $e->salaire_base <= 0,
            ]));
            if ($manque) $a[] = ['niveau' => 'warn', 'texte' => $e->nomComplet().' : '.implode(', ', $manque).' manquant(s).', 'lien' => $ficheDe($e)];
            if ($smig > 0 && (float) $e->salaire_base > 0 && (float) $e->salaire_base < $smig) {
                $a[] = ['niveau' => 'warn', 'texte' => $e->nomComplet().' : salaire de base ('.tnd($e->salaire_base).') inférieur au SMIG mensuel ('.tnd($smig).').', 'lien' => $ficheDe($e)];
            }
        }

        // Paies non clôturées de l'année jusqu'au mois précédent, à partir de la première embauche connue.
        if ($sal->isNotEmpty()) {
            $debut = Carbon::now()->startOfYear();
            $emb = $sal->pluck('date_embauche')->filter()->min();
            if ($emb && $emb->gt($debut)) $debut = $emb->copy()->startOfMonth();
            $closes = Paie::where('client_id', $client->id)->where('statut', '!=', 'brouillon')->pluck('mois')->all();
            $ouverts = [];
            for ($m = $debut->copy(); $m->lt(Carbon::now()->startOfMonth()); $m->addMonth()) {
                if (! in_array($m->format('Y-m'), $closes, true)) $ouverts[] = $m->format('Y-m');
            }
            foreach ($ouverts as $m) $a[] = ['niveau' => 'info', 'texte' => 'Paie de '.mois_fr($m).' non clôturée.', 'lien' => route('paie.index', ['mois' => $m])];

            // Déclaration trimestrielle CNSS : rappel le premier mois de chaque trimestre pour le trimestre écoulé.
            $now = Carbon::now();
            if (in_array($now->month, [1, 4, 7, 10], true)) {
                $prec = $now->copy()->subMonth()->format('Y-m');
                $a[] = ['niveau' => 'info', 'texte' => 'Déclaration CNSS du '.EtatsPaie::libelleTrimestre($prec).' à déposer (échéance indicative : 15 '.$now->locale('fr')->translatedFormat('F').').',
                    'lien' => route('paie.edition', ['dcnss', 'imprimer', 'mois' => $prec])];
            }
        }
        return $a;
    }
}
