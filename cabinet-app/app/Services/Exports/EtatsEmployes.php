<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Models\Salarie;
use App\Models\SalarieHistorique;
use App\Services\PaieCalc;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** États du menu Employé : listes du personnel, entrées et sorties, historiques, contrats, profils, catégories et échelons. */
class EtatsEmployes
{
    public const ETATS = [
        'liste' => 'Liste des employés',
        'par-salarie' => 'Liste des employés par salarié',
        'par-departement' => 'Liste des employés par département',
        'par-annee' => 'Liste des employés par année d’embauche',
        'par-profil' => 'Liste des employés par profil',
        'entrees' => 'Entrées du personnel',
        'sorties' => 'Sorties du personnel',
        'modifications' => 'Historique des modifications',
        'contrats' => 'État des contrats',
        'profils' => 'État des profils',
        'categories-echelons' => 'État des catégories et échelons',
        'historique-contrat' => 'État historique des contrats',
    ];

    public const STATUTS = ['tous' => 'Tous les employés', 'actifs' => 'Employés actifs', 'inactifs' => 'Employés inactifs'];

    public static function etat(string $etat, Client $client, string $statut, string $mois): Etat
    {
        $e = match ($etat) {
            'liste', 'par-salarie', 'par-departement', 'par-annee', 'par-profil' => self::liste($etat, $client, $statut),
            'entrees', 'sorties' => self::mouvements($etat, $client, (int) substr($mois, 0, 4)),
            'modifications', 'historique-contrat' => self::historique($etat, $client),
            'contrats' => self::contrats($client, $statut),
            'profils' => self::profils($client, $statut),
            'categories-echelons' => self::categories($client, $statut),
        };
        $k = $client->paie_config ?? [];
        if (! empty($k['afficher_logo']) && ! empty($k['logo'])) $e->logo = $k['logo'];
        return $e;
    }

    private static function salaries(Client $client, string $statut): Collection
    {
        $s = PaieCalc::trier($client->salaries()->get(), PaieCalc::parametres($client)['tri']);
        return match ($statut) {
            'actifs' => $s->filter(fn ($e) => $e->estActif())->values(),
            'inactifs' => $s->reject(fn ($e) => $e->estActif())->values(),
            default => $s,
        };
    }

    private static function liste(string $etat, Client $client, string $statut): Etat
    {
        $sal = self::salaries($client, $statut);
        $titre = self::ETATS[$etat].($statut !== 'tous' ? ' — '.mb_strtolower(self::STATUTS[$statut]) : '');
        if ($etat === 'par-salarie') {
            $e = new Etat($titre, $client->nom, 'Au '.now()->format('d/m/Y'), [
                ['Matricule', 'text'], ['Nom et prénom', 'text'], ['CIN', 'text'], ['N° CNSS', 'text'], ['Naissance', 'date'], ['Situation', 'text'],
                ['Adresse', 'text'], ['Téléphone', 'text'], ['Qualification', 'text'], ['Contrat', 'text'], ['Embauche', 'date'], ['Salaire de base', 'num'],
            ]);
            foreach ($sal->sortBy(fn ($x) => mb_strtolower($x->nomComplet())) as $x) {
                $e->ligne([$x->matricule, $x->nomComplet(), $x->cin, $x->cnss, $x->date_naissance, trim((Salarie::SITUATIONS[$x->situation_familiale] ?? '').' · '.$x->enfants.' enf.', ' ·'),
                    trim($x->adresse.' '.$x->code_postal.' '.$x->ville), $x->tel_mobile ?: $x->tel_fixe, $x->poste, Salarie::CONTRATS[$x->type_contrat] ?? $x->type_contrat,
                    $x->date_embauche, (float) $x->salaire_base]);
            }
            return $e;
        }
        $cols = [['Matricule', 'text'], ['Nom et prénom', 'text'], ['CIN', 'text'], ['Qualification', 'text'], ['Département', 'text'], ['Contrat', 'text'],
            ['Embauche', 'date'], ['Sortie', 'date'], ['Salaire de base', 'num'], ['État', 'text']];
        $e = new Etat($titre, $client->nom, 'Au '.now()->format('d/m/Y'), $cols);
        $ligne = fn ($x) => [$x->matricule, $x->nomComplet(), $x->cin, $x->poste, $x->departement, Salarie::CONTRATS[$x->type_contrat] ?? $x->type_contrat,
            $x->date_embauche, $x->date_sortie, (float) $x->salaire_base, Salarie::ETATS[$x->etat_presence] ?? $x->etat_presence];
        $vide = array_fill(0, count($cols), null);
        $groupe = match ($etat) {
            'par-departement' => fn ($x) => $x->departement ?: 'Sans département',
            'par-annee' => fn ($x) => $x->date_embauche ? (string) $x->date_embauche->year : 'Date d’embauche non renseignée',
            'par-profil' => fn ($x) => $x->profil ?: 'Sans profil',
            default => null,
        };
        if (! $groupe) {
            foreach ($sal as $x) $e->ligne($ligne($x));
        } else {
            foreach ($sal->groupBy($groupe)->sortKeys() as $g => $liste) {
                $e->ligne([$g] + $vide, 'groupe');
                foreach ($liste as $x) $e->ligne($ligne($x));
                $e->ligne(array_replace($vide, [1 => 'Effectif : '.count($liste), 8 => r3($liste->sum('salaire_base'))]), 'soustotal');
            }
        }
        $e->ligne(array_replace($vide, [1 => 'TOTAL : '.count($sal).' employé(s)', 8 => r3($sal->sum('salaire_base'))]), 'total');
        return $e;
    }

    /** Entrées (embauches) ou sorties de l'année. */
    private static function mouvements(string $etat, Client $client, int $annee): Etat
    {
        $champ = $etat === 'entrees' ? 'date_embauche' : 'date_sortie';
        $sal = $client->salaries()->whereYear($champ, $annee)->orderBy($champ)->get();
        $cols = [['Matricule', 'text'], ['Nom et prénom', 'text'], ['CIN', 'text'], ['Qualification', 'text'], ['Contrat', 'text'], ['Embauche', 'date']];
        if ($etat === 'sorties') $cols = [...$cols, ['Sortie', 'date'], ['Motif', 'text']];
        $e = new Etat(self::ETATS[$etat], $client->nom, 'Année '.$annee, $cols, false);
        foreach ($sal as $x) {
            $l = [$x->matricule, $x->nomComplet(), $x->cin, $x->poste, Salarie::CONTRATS[$x->type_contrat] ?? $x->type_contrat, $x->date_embauche];
            if ($etat === 'sorties') $l = [...$l, $x->date_sortie, Salarie::MOTIFS_SORTIE[$x->motif_sortie] ?? $x->motif_sortie];
            $e->ligne($l);
        }
        $e->ligne(['', 'TOTAL : '.$sal->count().' employé(s)'], 'total');
        return $e;
    }

    /** Historique des modifications de toutes les fiches, ou des seuls changements de contrat. */
    private static function historique(string $etat, Client $client): Etat
    {
        $q = SalarieHistorique::with(['salarie', 'user'])->whereHas('salarie', fn ($q) => $q->where('client_id', $client->id))->orderByDesc('created_at')->orderByDesc('id');
        if ($etat === 'historique-contrat') $q->whereIn('champ', [...Salarie::SUIVIS_CONTRAT, 'creation']);
        $e = new Etat(self::ETATS[$etat], $client->nom, 'Au '.now()->format('d/m/Y'), [
            ['Date', 'text'], ['Matricule', 'text'], ['Nom et prénom', 'text'], ['Modification', 'text'], ['Ancienne valeur', 'text'], ['Nouvelle valeur', 'text'], ['Par', 'text'],
        ]);
        foreach ($q->get() as $h) {
            $e->ligne([$h->created_at->format('d/m/Y H:i'), $h->salarie?->matricule, $h->salarie?->nomComplet(), $h->libelle, $h->ancien, $h->nouveau, $h->user?->name]);
        }
        return $e;
    }

    private static function contrats(Client $client, string $statut): Etat
    {
        $auj = Carbon::today();
        $e = new Etat(self::ETATS['contrats'], $client->nom, 'Au '.$auj->format('d/m/Y'), [
            ['Matricule', 'text'], ['Nom et prénom', 'text'], ['Contrat', 'text'], ['Date début', 'date'], ['Date fin', 'date'], ['Durée (mois)', 'text'], ['Situation', 'text'],
        ]);
        foreach (self::salaries($client, $statut) as $x) {
            $situation = ! $x->contrat_fin ? 'En cours (durée indéterminée)'
                : ($x->contrat_fin->lt($auj) ? 'Expiré' : ($x->contrat_fin->lte($auj->copy()->addDays(30)) ? 'Expire dans '.(int) $auj->diffInDays($x->contrat_fin).' jour(s)' : 'En cours'));
            $duree = $x->contrat_debut && $x->contrat_fin ? (string) (int) round($x->contrat_debut->diffInMonths($x->contrat_fin->copy()->addDay())) : '';
            $e->ligne([$x->matricule, $x->nomComplet(), Salarie::CONTRATS[$x->type_contrat] ?? $x->type_contrat, $x->contrat_debut, $x->contrat_fin, $duree, $situation]);
        }
        return $e;
    }

    private static function profils(Client $client, string $statut): Etat
    {
        $e = new Etat(self::ETATS['profils'], $client->nom, 'Au '.now()->format('d/m/Y'), [
            ['Matricule', 'text'], ['Nom et prénom', 'text'], ['Profil', 'text'], ['Date début', 'date'], ['Date fin', 'date'], ['Situation', 'text'],
        ], false);
        foreach (self::salaries($client, $statut)->sortBy(fn ($x) => [$x->profil, mb_strtolower($x->nomComplet())]) as $x) {
            $e->ligne([$x->matricule, $x->nomComplet(), $x->profil, $x->profil_debut, $x->profil_fin, self::situation($x->profil_fin)]);
        }
        return $e;
    }

    private static function categories(Client $client, string $statut): Etat
    {
        $e = new Etat(self::ETATS['categories-echelons'], $client->nom, 'Au '.now()->format('d/m/Y'), [
            ['Matricule', 'text'], ['Nom et prénom', 'text'], ['Catégorie', 'text'], ['Début', 'date'], ['Fin', 'date'], ['Échelon', 'text'], ['Début', 'date'], ['Fin', 'date'], ['Situation', 'text'],
        ]);
        foreach (self::salaries($client, $statut)->sortBy(fn ($x) => [$x->categorie, $x->echelon, mb_strtolower($x->nomComplet())]) as $x) {
            $sit = array_filter(['catégorie '.mb_strtolower(self::situation($x->categorie_fin)), 'échelon '.mb_strtolower(self::situation($x->echelon_fin))], fn ($s) => str_contains($s, 'expir'));
            $e->ligne([$x->matricule, $x->nomComplet(), $x->categorie, $x->categorie_debut, $x->categorie_fin, $x->echelon, $x->echelon_debut, $x->echelon_fin,
                $sit ? ucfirst(implode(', ', $sit)) : 'En cours']);
        }
        return $e;
    }

    private static function situation($fin): string
    {
        if (! $fin) return 'En cours';
        return $fin->lt(Carbon::today()) ? 'Expiré' : 'En cours';
    }
}
