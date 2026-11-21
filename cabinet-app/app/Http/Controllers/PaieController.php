<?php

namespace App\Http\Controllers;

use App\Models\Activite;
use App\Models\Client;
use App\Services\PaieAlertes;
use App\Models\Paie;
use App\Models\PaieProfil;
use App\Models\PaieRubrique;
use App\Models\Salarie;
use App\Models\Setting;
use App\Services\Exports\EtatsPaie;
use App\Services\PaieCalc;
use Illuminate\Http\Request;

class PaieController extends Controller
{
    public function index(Request $r)
    {
        $client = $this->dossier($r);
        if (! $client) return view('compta.vide');
        return view('paie.index', static::contexte($r, $client, $r->input('tab', 'calc'), $r->input('mois', now()->format('Y-m'))));
    }

    /** Données communes aux écrans du module Paie (barre de menus, fenêtres du menu Fichier, notifications). */
    public static function contexte(Request $r, Client $client, string $tab, string $mois): array
    {
        // Premier passage dans le module : le dossier reçoit les profils standard (Ordinaire, CIVP, DIGNITE, Exo 2 ans, Gérant).
        PaieProfil::initialiserStandard($client);
        $P = PaieCalc::parametres($client);
        $salaries =PaieCalc::trier(Salarie::where('client_id', $client->id)->get(), $P['tri']);
        $paie = Paie::where('client_id', $client->id)->where('mois', $mois)->normale()->first();
        $R = PaieCalc::mois($client, $mois, true);
        $alertes = PaieAlertes::pour($client);
        $notifications = PaieAlertes::tableaux($client);
        $nbNotifs = count($alertes) + array_sum(array_map(fn ($t) => count($t['lignes']), $notifications));
        // Notification au démarrage : une fois par dossier et par session, s'il y a quelque chose à signaler.
        $notifAuto = $nbNotifs > 0 && ! empty($client->paie_config['notif_demarrage']) && ! $r->session()->has('paie_notif.'.$client->id);
        if ($notifAuto) $r->session()->put('paie_notif.'.$client->id, true);
        return ['client' => $client, 'clients' => Client::orderBy('nom')->get(['id', 'nom']), 'tab' => $tab, 'mois' => $mois, 'P' => PaieCalc::parametresMois($P, $paie),
            'salaries' => $salaries, 'paie' => $paie, 'rubriques' => PaieRubrique::duDossier($client), 'R' => $R, 'T' => PaieCalc::totaux($R),
            'historique' => Paie::where('client_id', $client->id)->where('statut', '!=', 'brouillon')->orderByDesc('mois')->get(),
            'alertes' => $alertes, 'notifications' => $notifications, 'nbNotifs' => $nbNotifs, 'notifAuto' => $notifAuto,
            'journal' => Activite::with('user:id,name')->where('client_id', $client->id)->where('action', 'like', '%paie%')->latest()->take(30)->get()]
            + ($tab === 'autre' ? static::contexteSpecial($r, $client, $mois) : [])
            + static::contextePrime($r, $client, $tab, $mois)
            + static::contexteRappel($r, $client, $tab, $mois)
            + static::contexteStc($r, $client, $tab, $mois);
    }

    /**
     * Menu Solde de tout compte : le STC ouvert (Ouvrir STC Saisi, ou le dernier enregistré) sert aux éditions du menu.
     * Saisie Solde de tout compte (tab=stc) : un STC existant (stc=numéro) ou un nouveau (salarie, decl : mois de sortie par défaut).
     */
    public static function contexteStc(Request $r, Client $client, string $tab, string $mois): array
    {
        $cle = 'paie_stc.'.$client->id;
        $stcs = Paie::where('client_id', $client->id)->where('type', 'stc')->orderByDesc('mois')->orderBy('libelle')->get();
        if ($tab === 'stc' && $r->filled('stc') && ($p = $stcs->firstWhere('id', (int) $r->input('stc')))) $r->session()->put($cle, $p->id);
        $ouvert = $stcs->firstWhere('id', (int) $r->session()->get($cle));
        $ctx = ['stcs' => $stcs, 'stcOuvert' => $ouvert];
        if ($tab !== 'stc') return $ctx;
        // Salariés proposés : sortis ou avec une date de sortie d'abord.
        $candidats = Salarie::where('client_id', $client->id)->get()->sortBy(fn ($e) => [$e->date_sortie || $e->etat_presence === 'sorti' ? 0 : 1, mb_strtolower($e->nom)])->values();
        if ($r->boolean('nouveau') || ! $ouvert) {
            $s = $candidats->firstWhere('id', (int) $r->input('salarie')) ?? $candidats->first();
            $decl = preg_match('/^\d{4}-\d{2}$/', (string) $r->input('decl')) ? $r->input('decl') : ($s?->date_sortie?->format('Y-m') ?? $mois);
            $stc = $s ? ($stcs->first(fn ($p) => $p->mois === $decl && ($p->options['salarie_id'] ?? null) == $s->id)
                ?? new Paie(['client_id' => $client->id, 'mois' => $decl, 'type' => 'stc', 'libelle' => 'STC — '.$s->nomComplet(), 'statut' => 'brouillon', 'options' => ['salarie_id' => $s->id]])) : null;
        } else {
            $stc = $ouvert;
        }
        $RT = $stc ? PaieCalc::moisSpecial($client, $stc) : [];
        return $ctx + ['stc' => $stc, 'RT' => $RT, 'candidatsStc' => $candidats, 'PT' => PaieCalc::parametresMois(PaieCalc::parametres($client), $stc?->exists ? $stc : null),
            'salarieStc' => $stc ? $candidats->firstWhere('id', (int) ($stc->options['salarie_id'] ?? 0)) : null];
    }

    /** Solde de tout compte → Quittance : reçu pour solde de tout compte, à faire signer par le salarié (texte retouchable avant impression). */
    public function quittance(Request $r, Paie $paie)
    {
        $client = $this->dossier($r);
        abort_unless($client && $paie->client_id === $client->id && $paie->estStc(), 404);
        $x = PaieCalc::moisSpecial($client, $paie)[0] ?? null;
        abort_unless($x, 404);
        $V = ['client' => $client, 'paie' => $paie, 'x' => $x, 's' => Salarie::find($x['salarie_id'])];
        if ($r->input('modele') !== 'contrat') return view('paie.quittance', $V + ['langue' => $r->input('langue', 'fr')]);
        // Modèle « Quittance de solde de tout compte relative à un contrat de travail » : aperçu retouchable ou PDF.
        if ($r->input('format') !== 'pdf') return view('paie.quittance-contrat', $V + ['impression' => true]);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('paie.quittance-contrat', $V + ['impression' => false])->setPaper('a4', 'portrait')
            ->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Serif']);
        $nom = \Illuminate\Support\Str::slug('quittance stc '.$x['nom']).'.pdf';
        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$nom.'"']);
    }

    /**
     * Menu Rappel : le rappel ouvert (Ouvrir rappel Saisi, ou le dernier enregistré) est retenu pour la session et sert aux éditions du menu.
     * Saisie Rappel (tab=rappel) : un rappel existant (rappel=numéro) ou un nouveau rappel (pas encore enregistré).
     */
    public static function contexteRappel(Request $r, Client $client, string $tab, string $mois): array
    {
        $cle = 'paie_rappel.'.$client->id;
        $rappels = Paie::where('client_id', $client->id)->where('type', 'rappel')->orderByDesc('mois')->orderBy('libelle')->get();
        if ($tab === 'rappel' && $r->filled('rappel') && ($p = $rappels->firstWhere('id', (int) $r->input('rappel')))) $r->session()->put($cle, $p->id);
        $ouvert = $rappels->firstWhere('id', (int) $r->session()->get($cle));
        $ctx = ['rappels' => $rappels, 'rappelOuvert' => $ouvert];
        if ($tab !== 'rappel') return $ctx;
        $rappel = $r->boolean('nouveau') || ! $ouvert
            ? new Paie(['client_id' => $client->id, 'mois' => $mois, 'type' => 'rappel', 'libelle' => '', 'statut' => 'brouillon',
                'options' => ['du' => substr($mois, 0, 4).'-01', 'au' => (\Carbon\Carbon::parse($mois.'-01')->subMonth()->format('Y-m') >= substr($mois, 0, 4).'-01' ? \Carbon\Carbon::parse($mois.'-01')->subMonth()->format('Y-m') : substr($mois, 0, 4).'-01'), 'augmentation' => 0, 'unite' => '%']])
            : $ouvert;
        $RR = PaieCalc::moisSpecial($client, $rappel);
        return $ctx + ['rappel' => $rappel, 'RR' => $RR, 'TR' => PaieCalc::totaux($RR), 'PR' => PaieCalc::parametresMois(PaieCalc::parametres($client), $rappel->exists ? $rappel : null)];
    }

    /**
     * Menu Prime. La prime ouverte (Ouvrir Prime Saisie, ou la dernière enregistrée) est retenue pour la session : les éditions du menu
     * Prime portent sur elle. Saisie Prime (tab=prime) : une prime existante (prime=numéro) ou une nouvelle prime (rubrique, decl).
     */
    public static function contextePrime(Request $r, Client $client, string $tab, string $mois): array
    {
        $cle = 'paie_prime.'.$client->id;
        $primes = Paie::where('client_id', $client->id)->where('type', 'prime')->orderByDesc('mois')->orderBy('libelle')->get();
        if ($tab === 'prime' && $r->filled('prime') && ($p = $primes->firstWhere('id', (int) $r->input('prime')))) $r->session()->put($cle, $p->id);
        $ouverte = $primes->firstWhere('id', (int) $r->session()->get($cle));
        $ctx = ['primes' => $primes, 'primeOuverte' => $ouverte,
            'rubriquesPrime' => PaieRubrique::duDossier($client)->reject(fn ($rb) => $rb->note || $rb->a_deduire)->sortBy(fn ($rb) => [$rb->categorie === 'prime' ? 0 : 1, $rb->nom])->values()];
        if ($tab !== 'prime') return $ctx;
        // Nouvelle prime (Saisie Prime) : rubrique et mois de déclaration choisis, rien d'enregistré tant qu'on ne sauvegarde pas.
        $nouvelle = $r->boolean('nouvelle') || ! $ouverte;
        $rub = $ctx['rubriquesPrime']->firstWhere('id', (int) $r->input('rubrique')) ?? $ctx['rubriquesPrime']->first();
        $decl = preg_match('/^\d{4}-\d{2}$/', (string) $r->input('decl')) ? $r->input('decl') : $mois;
        $prime = $nouvelle
            ? ($rub ? ($primes->first(fn ($p) => $p->mois === $decl && $p->libelle === $rub->nom) ?? new Paie(['client_id' => $client->id, 'mois' => $decl, 'type' => 'prime', 'libelle' => $rub->nom, 'rubrique_id' => $rub->id, 'statut' => 'brouillon'])) : null)
            : $ouverte;
        $RP = $prime ? PaieCalc::moisSpecial($client, $prime) : [];
        return $ctx + ['prime' => $prime, 'RP' => $RP, 'TP' => PaieCalc::totaux($RP), 'PP' => PaieCalc::parametresMois(PaieCalc::parametres($client), $prime?->exists ? $prime : null)];
    }

    /**
     * Saisie Autre : paie spéciale choisie par son type (Mois : congé, 13ème… 18ème mois), son année et le mois avec lequel elle est déclarée.
     * Sans « À déclarer avec », la dernière paie de ce type de l'année est ouverte, sinon le mois en cours de l'année.
     */
    public static function contexteSpecial(Request $r, Client $client, string $mois): array
    {
        $type = in_array($r->input('type'), Paie::SPECIALES, true) ? $r->input('type') : 'm13';
        $annee = (int) ($r->input('annee') ?: substr($mois, 0, 4));
        $decl = preg_match('/^\d{4}-\d{2}$/', (string) $r->input('decl')) && str_starts_with($r->input('decl'), $annee.'-') ? $r->input('decl') : null;
        $existantes = Paie::where('client_id', $client->id)->where('type', $type)->where('mois', 'like', $annee.'-%')->orderBy('mois')->get();
        $decl ??= $existantes->last()?->mois ?? $annee.'-'.substr($mois, 5, 2);
        $speciale = $existantes->firstWhere('mois', $decl);
        $RS = PaieCalc::moisSpecial($client, $speciale ?? new Paie(['client_id' => $client->id, 'mois' => $decl, 'type' => $type, 'statut' => 'brouillon']));
        return ['type' => $type, 'annee' => $annee, 'decl' => $decl, 'speciale' => $speciale, 'RS' => $RS, 'TS' => PaieCalc::totaux($RS), 'existantes' => $existantes,
            'PS' => PaieCalc::parametresMois(PaieCalc::parametres($client), $speciale)];
    }

    /** Paie (ordinaire ou spéciale) visée par une requête : mois (mois de déclaration pour une paie spéciale) et type. */
    private function paieDemandee(Request $r, Client $client): Paie
    {
        // Paie désignée par son numéro (prime déjà enregistrée).
        if ($r->filled('paie_id')) return Paie::where('client_id', $client->id)->findOrFail((int) $r->input('paie_id'));
        $d = $r->validate(['mois' => 'required|date_format:Y-m', 'type' => 'nullable|in:'.implode(',', array_keys(Paie::TYPES)), 'rubrique_id' => 'nullable|required_if:type,prime|integer']);
        $type = $d['type'] ?? 'normale';
        // Nouveau solde de tout compte : un par salarié et par mois de déclaration.
        if ($type === 'stc') {
            $s = Salarie::where('client_id', $client->id)->findOrFail((int) $r->validate(['salarie_id' => 'required|integer'], ['salarie_id.required' => 'Choisissez le salarié.'])['salarie_id']);
            $p = Paie::firstOrNew(['client_id' => $client->id, 'mois' => $d['mois'], 'type' => 'stc', 'libelle' => 'STC — '.$s->nomComplet()]);
            $p->options = ['salarie_id' => $s->id];
            return $p;
        }
        // Nouveau rappel : un par libellé et par mois de déclaration.
        if ($type === 'rappel') {
            $libelle = trim((string) $r->validate(['libelle' => 'required|string|max:120'], ['libelle.required' => 'Donnez un libellé au rappel.'])['libelle']);
            return Paie::firstOrNew(['client_id' => $client->id, 'mois' => $d['mois'], 'type' => 'rappel', 'libelle' => $libelle]);
        }
        if ($type !== 'prime') return Paie::firstOrNew(['client_id' => $client->id, 'mois' => $d['mois'], 'type' => $type]);
        // Nouvelle prime : une paie par rubrique de prime et par mois de déclaration.
        $rub = PaieRubrique::where('client_id', $client->id)->findOrFail((int) $d['rubrique_id']);
        $p = Paie::firstOrNew(['client_id' => $client->id, 'mois' => $d['mois'], 'type' => 'prime', 'libelle' => $rub->nom]);
        $p->rubrique_id = $rub->id;
        return $p;
    }

    /** Retour à l'écran de saisie de la paie concernée. */
    private function versSaisie(Paie $p, ?string $tab = null)
    {
        if ($p->estRappel()) return redirect()->route('paie.index', ['tab' => 'rappel', 'rappel' => $p->id, 'mois' => $p->mois]);
        if ($p->estStc()) return redirect()->route('paie.index', ['tab' => 'stc', 'stc' => $p->id, 'mois' => $p->mois]);
        if ($p->estPrime()) return $p->exists
            ? redirect()->route('paie.index', ['tab' => 'prime', 'prime' => $p->id, 'mois' => $p->mois])
            : redirect()->route('paie.index', ['tab' => 'prime', 'nouvelle' => 1, 'rubrique' => $p->rubrique_id, 'decl' => $p->mois, 'mois' => $p->mois]);
        return $p->estSpeciale()
            ? redirect()->route('paie.index', ['tab' => 'autre', 'type' => $p->type, 'annee' => substr($p->mois, 0, 4), 'decl' => $p->mois, 'mois' => $p->mois])
            : redirect()->route('paie.index', ['mois' => $p->mois, 'tab' => $tab ?? 'calc']);
    }

    /**
     * Enregistre la saisie (brouillon). Saisie Paie envoie les jours du mois, le pointage (J/H ouvrables et travaillés, congés, fériés,
     * heures supplémentaires, acompte) et les primes ; Variable envoie les rubriques variables ; Saisie Autre (type ≠ normale) envoie
     * la paie spéciale (jours, montant, acompte, primes). Seules les parties envoyées (marqueur « parties ») sont remplacées.
     */
    public function calcul(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate(['jours_ouvrables' => 'nullable|numeric|min:0|max:31', 'jours_feries' => 'nullable|numeric|min:0|max:31',
            'heures_jour' => 'nullable|numeric|min:0|max:24', 'heures_ouvrables' => 'nullable|numeric|min:0|max:744']);
        $paie = $this->paieDemandee($r, $client);
        if ($paie->exists && $paie->statut !== 'brouillon') return back()->with('err', 'Cette paie est déjà clôturée.');
        $parties = (array) $r->input('parties', ['saisie', 'variable']);
        if (in_array('saisie', $parties, true)) {
            $paie->primes = collect((array) $r->input('primes'))->map(fn ($v) => (float) $v)->filter()->all();
            $paie->pointage = $paie->estSpeciale() ? static::pointageSpecial((array) $r->input('pointage')) : PaieCalc::pointage((array) $r->input('pointage'));
            foreach (['jours_ouvrables', 'jours_feries', 'heures_jour', 'heures_ouvrables'] as $k) if ($r->has($k)) $paie->$k = $d[$k] ?? null;
            // Rappel : période rappelée et augmentation de tête.
            if ($paie->estRappel()) {
                $o = $r->validate(['du' => 'required|date_format:Y-m', 'au' => 'required|date_format:Y-m|after_or_equal:du', 'augmentation' => 'nullable|numeric|min:0', 'unite' => 'nullable|in:%,TND'],
                    ['du.required' => 'Indiquez le premier mois rappelé.', 'au.after_or_equal' => 'Le dernier mois rappelé doit suivre le premier.']);
                $paie->options = ['du' => $o['du'], 'au' => $o['au'], 'augmentation' => (float) ($o['augmentation'] ?? 0), 'unite' => $o['unite'] ?? '%'];
                if ($r->filled('libelle')) $paie->libelle = mb_substr(trim($r->input('libelle')), 0, 120);
            }
        }
        if (in_array('variable', $parties, true) || in_array('autre', $parties, true)) $paie->rubriques = PaieCalc::saisieRubriques((array) $r->input('rubriques'));
        // Colonnes Reg et RegP de Saisie Paie : cases de régularisation de la fiche de chaque salarié.
        foreach ((array) $r->input('regul') as $id => $v) {
            $s = Salarie::where('client_id', $client->id)->find((int) $id);
            if ($s) $s->update(['regul_irpp' => (bool) ($v['irpp'] ?? false), 'regul_primes' => (bool) ($v['primes'] ?? false)]);
        }
        $paie->save();
        return $this->versSaisie($paie, $r->input('tab'))->with('ok', 'Saisie enregistrée, paie recalculée.');
    }

    /** Saisie d'une paie spéciale, nettoyée : {salarie_id: {jours, montant (vide = valeur par défaut), acompte}}. */
    private static function pointageSpecial(array $saisie): array
    {
        $num = fn ($v) => $v !== '' && $v !== null && is_numeric($v) ? max(0.0, (float) $v) : null;
        $out = [];
        foreach ($saisie as $id => $v) {
            $l = array_filter(['jours' => $num($v['jours'] ?? null), 'montant' => $num($v['montant'] ?? null), 'acompte' => $num($v['acompte'] ?? null),
                'ancien' => $num($v['ancien'] ?? null), 'nouveau' => $num($v['nouveau'] ?? null), 'nb' => $num($v['nb'] ?? null),
                ...array_map($num, array_intersect_key((array) $v, array_flip(['jours_trav', 'conge_jours', 'conge_montant', 'preavis', 'autres', 'licenciement', 'retenues', 'pret_societe', 'pret_cnss'])))], fn ($x) => $x !== null);
            if ($l) $out[(int) $id] = $l;
        }
        return $out;
    }

    /** Saisie Paie / Saisie Autre → Supprimer : efface la saisie tant que la paie n'est pas clôturée. */
    public function supprimer(Request $r)
    {
        $client = $this->dossier($r);
        $paie = $this->paieDemandee($r, $client);
        if (! $paie->exists) return back()->with('err', 'Aucune saisie enregistrée : '.mb_strtolower($paie->libelle()).'.');
        if ($paie->statut !== 'brouillon') return back()->with('err', 'Cette paie est clôturée : rouvrez-la d’abord (Fichier → Sécurité).');
        $paie->delete();
        $this->log('Paie : suppression de la saisie — '.$paie->libelle(), $client->id);
        return $this->versSaisie($paie)->with('ok', $paie->libelle().' : saisie supprimée.');
    }

    /** Clôture : fige les résultats et transmet à la comptabilité. */
    public function cloturer(Request $r)
    {
        $client = $this->dossier($r);
        $paie = $this->paieDemandee($r, $client);
        if ($paie->statut === 'val') return back()->with('err', 'Cette paie est déjà comptabilisée.');
        $R = $paie->estSpeciale() ? PaieCalc::moisSpecial($client, $paie) : PaieCalc::mois($client, $paie->mois, true);
        if (! $R) return back()->with('err', 'Aucun salarié à payer.');
        $paie->fill(['resultats' => $R, 'totaux' => PaieCalc::totaux($R), 'statut' => 'transmise'])->save();
        $this->log('Clôture — '.$paie->libelle(), $client->id);
        return back()->with('ok', $paie->libelle().' : paie clôturée et transmise à la comptabilité pour validation.');
    }

    public function bulletins(Request $r)
    {
        $client = $this->dossier($r);
        [$mois, $periode, $R] = $this->selectionBulletins($r, $client);
        $format = $r->input('format', $client->paie_config['format_fiche'] ?? 'a4');
        if (! isset(self::FORMATS_BULLETIN[$format])) $format = 'a4';
        if ($format === 'a5c') $R = $this->avecConges($client, $R);
        // Chaque bulletin est édité autant de fois que demandé sur la fiche employé (Nb bulletin).
        $copies = Salarie::whereIn('id', array_column($R, 'salarie_id'))->pluck('nb_bulletin', 'id');
        $R = collect($R)->flatMap(fn ($x) => array_fill(0, max(1, (int) ($copies[$x['salarie_id']] ?? 1)), $x))->all();
        return view('paie.bulletins', ['client' => $client, 'mois' => $mois, 'periode' => $periode, 'R' => $R, 'P' => PaieCalc::parametres($client), 'format' => $format]);
    }

    /** Modes d'impression des bulletins (Paie → Bulletin → critère → mode). */
    public const FORMATS_BULLETIN = ['a4' => 'A4', 'a5' => 'A5', 'a5d' => 'A5 Double', 'mat' => 'Matricielle', 'a5j' => 'A5 Double en jrs', 'a5c' => 'A5 Double reste congé', 'a5cin' => 'A5 Double CIN'];

    /**
     * Congés de l'année jusqu'au mois du bulletin (_conge) : acquis = droit mensuel de la fiche (« acquis / mois ») × mois de présence
     * depuis janvier ou l'embauche ; pris = jours de congé saisis dans la paie de ces mois ; reste = acquis − pris.
     */
    private function avecConges(Client $client, array $R): array
    {
        $sal = Salarie::where('client_id', $client->id)->get()->keyBy('id');
        foreach ($R as &$x) {
            if ($s = $sal[$x['salarie_id']] ?? null) $x['_conge'] = PaieCalc::soldeConges($s, $x['_mois']);
        }
        return $R;
    }

    /**
     * Paie → Bulletin → Générer les bulletins (CIN) : un PDF par bulletin, réunis dans une archive ZIP.
     * Fichiers nommés « AAAA-MM Nom Prénom.pdf », ou « CIN AAAA-MM.pdf » avec nommage=cin.
     */
    public function bulletinsZip(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        [$mois, $periode, $R] = $this->selectionBulletins($r, $client);
        if (! $R) return back()->with('err', 'Aucun bulletin à générer pour '.$periode.'.');
        $format = str_starts_with((string) $r->input('format'), 'a5') ? 'a5' : 'a4';
        $zipPath = static::zipBulletins($client, $R, $format, $r->input('nommage') === 'cin');
        $this->log('Paie : génération de '.count($R).' bulletin(s) PDF ('.$periode.')', $client->id);
        return response()->download($zipPath, \Illuminate\Support\Str::slug('bulletins '.$client->nom.' '.$periode).'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Archive ZIP des bulletins ($R : résultats avec _mois) en PDF, nommés « AAAA-MM Nom » ou « CIN AAAA-MM ». Renvoie le chemin du fichier temporaire. */
    public static function zipBulletins(Client $client, array $R, string $format = 'a4', bool $parCin = false): string
    {
        $P = PaieCalc::parametres($client);
        $zipPath = tempnam(sys_get_temp_dir(), 'bul');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $noms = [];
        foreach ($R as $x) {
            $nom = $parCin ? (($x['cin'] ?: 'sans-CIN-'.$x['salarie_id']).' '.$x['_mois']) : ($x['_mois'].' '.$x['nom']);
            $nom = trim(preg_replace('#[\\\\/:*?"<>|]+#', '-', $nom));
            $noms[$nom] = ($noms[$nom] ?? 0) + 1;
            if ($noms[$nom] > 1) $nom .= ' ('.$noms[$nom].')';
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('paie.bulletin_pdf', ['client' => $client, 'x' => $x, 'P' => $P, 'lignes' => PaieCalc::lignesBulletin($x, $P)])
                ->setPaper($format, 'portrait')->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
            $zip->addFromString($nom.'.pdf', $pdf->output());
        }
        $zip->close();
        return $zipPath;
    }

    /** Edition → Envoi par mail : éditions du mois choisies (PDF) et bulletins (ZIP) envoyés à un destinataire. */
    public const ENVOIS = ['journal' => 'Journal global', 'recap' => 'Récapitulation global', 'bordereau' => 'Bordereau payeur global', 'virement' => 'Ordre de virement global',
        'dcnss' => 'Déclaration CNSS du trimestre', 'dcnss-patronal' => 'Déclaration CNSS patronale', 'bulletins' => 'Bulletins de paie (ZIP de PDF)'];

    public function envoiMail(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate(['mois' => 'required|date_format:Y-m', 'email' => 'required|email|max:190', 'cc' => 'nullable|email|max:190',
            'pieces' => 'required|array|min:1', 'pieces.*' => 'in:'.implode(',', array_keys(self::ENVOIS)), 'message' => 'nullable|string|max:5000'],
            ['pieces.required' => 'Choisissez au moins une pièce à envoyer.']);
        $mois = $d['mois'];
        $global = ['journal', 'recap', 'bordereau', 'virement'];
        $jointes = [];
        foreach ($d['pieces'] as $p) {
            if ($p === 'bulletins') {
                $R = array_map(fn ($x) => $x + ['_mois' => $mois], PaieCalc::mois($client, $mois));
                foreach (PaieCalc::speciales($client, $mois) as $sp) foreach (PaieCalc::moisSpecial($client, $sp) as $x) $R[] = $x + ['_mois' => $mois, '_periode' => $sp->libelle()];
                if ($R) $jointes[] = [file_get_contents($zp = static::zipBulletins($client, $R)), \Illuminate\Support\Str::slug('bulletins '.$client->nom.' '.$mois).'.zip', 'application/zip'];
                if (isset($zp)) @unlink($zp);
                continue;
            }
            $etat = EtatsPaie::etat($p, $client, $mois, in_array($p, $global, true) ? ['paie' => 'tous'] : []);
            $jointes[] = [$etat->reponse('pdf')->getContent(), $etat->nomFichier('pdf'), 'application/pdf'];
        }
        $sujet = 'Paie de '.mois_fr($mois).' — '.$client->nom;
        $corps = trim((string) ($d['message'] ?? '')) ?: "Bonjour,\n\nVeuillez trouver ci-joint les éditions de la paie de ".mois_fr($mois).' du dossier '.$client->nom.".\n\nCordialement,\n".\App\Models\Setting::cabinet()['nom'];
        \Illuminate\Support\Facades\Mail::raw($corps, function ($m) use ($d, $sujet, $jointes) {
            $m->to($d['email'])->subject($sujet);
            if (! empty($d['cc'])) $m->cc($d['cc']);
            foreach ($jointes as [$contenu, $nom, $mime]) $m->attachData($contenu, $nom, ['mime' => $mime]);
        });
        $this->log('Paie : envoi par mail de '.count($jointes).' pièce(s) de '.$mois.' à '.$d['email'], $client->id);
        $note = config('mail.default') === 'log' ? ' (serveur d’envoi non configuré : le message a été écrit dans le journal de l’application, voir MAIL_MAILER dans .env)' : '';
        return back()->with('ok', count($jointes).' pièce(s) envoyée(s) à '.$d['email'].$note.'.');
    }

    /**
     * Bulletins demandés : un mois (mois), une année (annee) ou une période (du … au, 24 mois au plus), filtrés par employé (salarie),
     * régime salarial (regime), département ou profil. Renvoie [mois de référence, libellé de la période, résultats avec leur mois (_mois)].
     */
    private function selectionBulletins(Request $r, Client $client): array
    {
        $d = $r->validate(['mois' => 'nullable|date_format:Y-m', 'du' => 'nullable|date_format:Y-m', 'au' => 'nullable|date_format:Y-m|after_or_equal:du',
            'annee' => 'nullable|integer|min:2000|max:2100', 'salarie' => 'nullable|integer', 'regime' => 'nullable|in:mensuel,horaire',
            'departement' => 'nullable|string|max:100', 'profil' => 'nullable|string|max:100']);
        $mois = $d['mois'] ?? now()->format('Y-m');
        // Paie désignée par son numéro (menu Prime → Bulletin) : ses bulletins.
        if ($p = EtatsPaie::paieParNumero($client, $r->input('paie'))) {
            $R = array_map(fn ($x) => $x + ['_mois' => $p->mois, '_periode' => $p->libelle()], PaieCalc::moisSpecial($client, $p));
            if (! empty($d['salarie'])) $R = array_values(array_filter($R, fn ($x) => $x['salarie_id'] == $d['salarie']));
            return [$p->mois, $p->libelle(), $R];
        }
        // Paie spéciale (Saisie Autre → Imprimer) : ses bulletins, rattachés au mois de déclaration.
        if (in_array($r->input('type'), Paie::SPECIALES, true)) {
            $p = Paie::where('client_id', $client->id)->where('mois', $mois)->where('type', $r->input('type'))->first()
                ?? new Paie(['client_id' => $client->id, 'mois' => $mois, 'type' => $r->input('type'), 'statut' => 'brouillon']);
            $R = array_map(fn ($x) => $x + ['_mois' => $mois, '_periode' => $p->libelle()], PaieCalc::moisSpecial($client, $p));
            if (! empty($d['salarie'])) $R = array_values(array_filter($R, fn ($x) => $x['salarie_id'] == $d['salarie']));
            return [$mois, $p->libelle(), $R];
        }
        if (! empty($d['annee'])) {
            $liste = array_map(fn ($i) => sprintf('%d-%02d', $d['annee'], $i), range(1, 12));
            $periode = 'année '.$d['annee'];
        } elseif (! empty($d['du'])) {
            $liste = [];
            for ($m = \Carbon\Carbon::parse($d['du'].'-01'), $fin = $d['au'] ?? $d['du']; $m->format('Y-m') <= $fin && count($liste) < 24; $m->addMonth()) $liste[] = $m->format('Y-m');
            $periode = count($liste) > 1 ? 'de '.mois_fr($liste[0]).' à '.mois_fr(end($liste)) : mois_fr($liste[0]);
        } else {
            $liste = [$mois];
            $periode = mois_fr($mois);
        }
        $sal = Salarie::where('client_id', $client->id)->get()->keyBy('id');
        $garder = function ($x) use ($d, $sal) {
            $s = $sal[$x['salarie_id']] ?? null;
            if (! empty($d['salarie']) && $x['salarie_id'] != $d['salarie']) return false;
            if (! empty($d['regime']) && ($s?->regime ?: 'mensuel') !== $d['regime']) return false;
            if (isset($d['departement']) && $d['departement'] !== '' && mb_strtolower(trim((string) $s?->departement)) !== mb_strtolower(trim($d['departement']))) return false;
            if (isset($d['profil']) && $d['profil'] !== '' && mb_strtolower(trim((string) ($x['profil'] ?? $s?->profil))) !== mb_strtolower(trim($d['profil']))) return false;
            return true;
        };
        $R = [];
        foreach ($liste as $m) {
            foreach (PaieCalc::mois($client, $m) as $x) if ($garder($x)) $R[] = $x + ['_mois' => $m];
        }
        return [$mois, $periode, $R];
    }

    /** Éditions de la barre d'outils : journal global, déclarations CNSS, déclaration employeur, ordre de virement, bordereau. */
    public function edition(Request $r, string $etat, string $format)
    {
        $client = $this->dossier($r);
        abort_unless($client && isset((EtatsPaie::EDITIONS + EtatsPaie::EXPORTS + EtatsPaie::CONGES + EtatsPaie::ACOMPTES + EtatsPaie::PRETS)[$etat]) && in_array($format, ['imprimer', 'pdf', 'xlsx', 'docx'], true), 404);
        $mois = $r->validate(['mois' => 'nullable|date_format:Y-m'])['mois'] ?? now()->format('Y-m');
        if (isset(self::MODELES_CNSS[$etat]) && in_array($format, ['imprimer', 'pdf'], true)) return $this->modeleCnss($client, $mois, $etat, $format,
            $r->validate(['code' => ['nullable', 'regex:/^[0-9A-Za-z]{1,10}$/']])['code'] ?? null);
        return EtatsPaie::etat($etat, $client, $mois, $r->only('mode', 'modele', 'groupe', 'banque', 'paie', 'type'))->reponse($format);
    }

    /** Éditions au modèle CNSS : vue et orientation du papier. */
    private const MODELES_CNSS = ['dcnss' => ['paie.cnss.declaration', 'landscape'], 'recap-cnss' => ['paie.cnss.recap', 'portrait']];

    /** Déclaration trimestrielle des salaires et état récapitulatif au modèle CNSS (impression ou PDF). */
    private function modeleCnss(Client $client, string $mois, string $etat, string $format, ?string $code = null)
    {
        [$vue, $orientation] = self::MODELES_CNSS[$etat];
        // Une déclaration par code d'exploitation CNSS (régime général, contrats aidés)
        $D = EtatsPaie::modeleCnss($client, $mois, $code);
        if ($format === 'imprimer') return view($vue, $D + ['impression' => true, 'mois' => $mois, 'etat' => $etat]);
        $nom = \Illuminate\Support\Str::slug(($etat === 'dcnss' ? 'declaration cnss' : 'recap cnss').' '.$client->nom.(count($D['codes']) > 1 ? ' code '.$D['code'] : '').' T'.$D['trimestre'].' '.$D['annee']).'.pdf';
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($vue, $D + ['impression' => false])->setPaper('a4', $orientation)
            ->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$nom.'"']);
    }

    /**
     * Fichier de télédéclaration des salaires à la CNSS (DS…), un par code d'exploitation du trimestre : celui du code demandé,
     * sinon le seul code du trimestre, sinon une archive .zip réunissant le fichier de chaque code.
     */
    public function fichierCnss(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $v = $r->validate(['mois' => 'nullable|date_format:Y-m', 'code' => ['nullable', 'regex:/^[0-9A-Za-z]{1,10}$/']]);
        $mois = $v['mois'] ?? now()->format('Y-m');
        $codes = $v['code'] ?? null ? [$v['code']] : array_keys(EtatsPaie::codesCnss($client, $mois));
        $F = array_map(fn ($c) => EtatsPaie::fichierCnss($client, $mois, $c), $codes);
        $F = array_values(array_filter($F, fn ($f) => $f['assures'] > 0));
        // Aucun salaire ce trimestre : pas de fichier, la déclaration néant s'affiche.
        if (! $F) return redirect()->route('paie.edition', ['dcnss', 'imprimer', 'mois' => $mois]);
        if (count($F) === 1) {
            return response($F[0]['contenu'], 200, ['Content-Type' => 'text/plain; charset=US-ASCII', 'Content-Disposition' => 'attachment; filename="'.$F[0]['nom'].'"']);
        }
        $zip = new \ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'cnss');
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($F as $f) $zip->addFromString($f['nom'], $f['contenu']);
        $zip->close();
        $nom = 'teledeclaration-cnss-'.\Illuminate\Support\Str::slug($client->nom).'-'.substr($F[0]['nom'], -5).'.zip';
        return response()->download($tmp, $nom, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Fichier des salaires du régime complémentaire de retraite (CNSS) du trimestre. */
    public function fichierRc(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $mois = $r->validate(['mois' => 'nullable|date_format:Y-m'])['mois'] ?? now()->format('Y-m');
        $f = EtatsPaie::fichierRc($client, $mois);
        if (! $f['assures']) return redirect()->route('paie.edition', ['drc', 'imprimer', 'mois' => $mois]);   // déclaration néant
        return response($f['contenu'], 200, ['Content-Type' => 'text/plain; charset=US-ASCII', 'Content-Disposition' => 'attachment; filename="'.$f['nom'].'"']);
    }

    /**
     * Ordre de virement → fichier pour la banque : .txt (une ligne par bénéficiaire) ou .xls (récapitulatif Excel),
     * pour une banque (récapitulatif de virement) ou pour tous les salariés payés par virement (UIB — virement en masse).
     */
    public function fichierVirement(Request $r, string $format)
    {
        $client = $this->dossier($r);
        abort_unless($client && in_array($format, ['txt', 'xls'], true), 404);
        $mois = $r->validate(['mois' => 'nullable|date_format:Y-m'])['mois'] ?? now()->format('Y-m');
        $banque = isset(EtatsPaie::BANQUES[$r->input('banque')]) ? $r->input('banque') : null;
        if ($format === 'xls') return EtatsPaie::virement($client, $mois, $banque, $r->input('paie'))->reponse('xlsx');
        $nom = \Illuminate\Support\Str::slug('virement '.($banque ? EtatsPaie::BANQUES[$banque]['nom'] : ($r->boolean('masse') ? 'uib masse' : 'salaires')).' '.$client->nom.' '.$mois).'.txt';
        $this->log('Paie : fichier de virement '.$nom, $client->id);
        return response(EtatsPaie::fichierVirement($client, $mois, $banque, $r->input('paie')), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$nom.'"']);
    }

    /**
     * Fichier de virement au modèle de la banque de l'employeur (BT, STB, UIB, BIAT, ATB, UBCI, Zitouna, BH Bank) :
     * tous les salariés payés par virement. Refusé si un RIB (employeur ou salarié) est absent ou incomplet.
     */
    public function fichierBanque(Request $r, string $modele)
    {
        $client = $this->dossier($r);
        abort_unless($client && isset(\App\Services\Exports\FichiersVirement::MODELES[$modele]), 404);
        $mois = $r->validate(['mois' => 'nullable|date_format:Y-m'])['mois'] ?? now()->format('Y-m');
        [$D, $erreurs] = \App\Services\Exports\FichiersVirement::donnees($client, $mois, $r->input('paie'));
        if ($erreurs) return back()->with('err', 'Fichier de virement '.\App\Services\Exports\FichiersVirement::MODELES[$modele]['nom'].' non généré. '.implode(' ', $erreurs));
        $nom = \App\Services\Exports\FichiersVirement::nomFichier($modele, $D);
        $this->log('Paie : fichier de virement '.$nom.' ('.count($D['lignes']).' bénéficiaires)', $client->id);
        $type = $modele === 'bt' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/plain; charset=UTF-8';
        return response(\App\Services\Exports\FichiersVirement::contenu($modele, $D), 200, ['Content-Type' => $type, 'Content-Disposition' => 'attachment; filename="'.$nom.'"']);
    }

    /** Ordre de virement → Par employé : un ordre de virement par salarié payé par virement, à remettre à la banque. */
    public function virementEmployes(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $mois = $r->validate(['mois' => 'nullable|date_format:Y-m'])['mois'] ?? now()->format('Y-m');
        return view('paie.virement_employes', ['client' => $client, 'mois' => $mois, 'V' => EtatsPaie::aVirer($client, $mois, null, $r->input('paie')), 'periode' => EtatsPaie::periode($mois, $r->input('paie'), $client)]);
    }

    /** Paie → Enveloppe Paie : une enveloppe par salarié (net à payer) avec le billetage des paiements en espèces. */
    public function enveloppes(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $mois = $r->validate(['mois' => 'nullable|date_format:Y-m'])['mois'] ?? now()->format('Y-m');
        $mode = isset(EtatsPaie::MODES[$r->input('mode')]) ? $r->input('mode') : null;
        $sal = Salarie::where('client_id', $client->id)->get()->keyBy('id');
        $E = [];
        foreach (EtatsPaie::resultats($client, $mois, $r->input('paie')) as $x) {
            $s = $sal[$x['salarie_id']] ?? null;
            $m = $s?->mode_paiement ?: 'virement';
            if ($mode && $m !== $mode) continue;
            $E[] = $x + ['mode' => $m, 'billets' => $m === 'especes' ? static::billetage($x['net']) : []];
        }
        // Billetage global des paiements en espèces (retrait à la banque).
        $total = [];
        foreach ($E as $x) foreach ($x['billets'] as $v => $n) $total[$v] = ($total[$v] ?? 0) + $n;
        krsort($total, SORT_NUMERIC);
        return view('paie.enveloppes', ['client' => $client, 'mois' => $mois, 'mode' => $mode, 'E' => $E, 'total' => $total, 'paie' => EtatsPaie::paieChoisie($r->input('paie')), 'periode' => EtatsPaie::periode($mois, $r->input('paie'), $client)]);
    }

    /** Coupures et pièces en dinars (en millimes pour éviter les arrondis) : 50, 20, 10, 5 DT, puis pièces jusqu'à 10 millimes. */
    public const COUPURES = [50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10];

    /** Nombre de chaque coupure pour payer $montant (le reste inférieur à 10 millimes est arrondi à la coupure inférieure). */
    public static function billetage(float $montant): array
    {
        $reste = (int) round($montant * 1000);
        $b = [];
        foreach (static::COUPURES as $c) {
            if ($n = intdiv($reste, $c)) { $b[$c] = $n; $reste -= $n * $c; }
        }
        return $b;
    }

    public function parametres(Request $r)
    {
        if ($r->boolean('reset')) { Setting::put('paie', []); return back()->with('ok', 'Valeurs de départ rétablies.'); }
        $d = $r->validate(['cnssS' => 'required|numeric', 'cnssP' => 'required|numeric', 'at' => 'required|numeric|min:0', 'tfp' => 'required|numeric', 'fop' => 'required|numeric', 'css' => 'required|numeric',
            'frais' => 'required|numeric', 'fraisMax' => 'required|numeric', 'chef' => 'required|numeric', 'enf' => 'required|numeric', 'enfMax' => 'required|integer', 'enfEtu' => 'required|numeric', 'enfInf' => 'required|numeric', 'parentTaux' => 'required|numeric', 'parentMax' => 'required|numeric', 'bar' => 'required|string']);
        Setting::put('paie', array_map(fn ($v) => is_numeric($v) ? +$v : $v, $d));
        return back()->with('ok', 'Paramètres de paie enregistrés.');
    }

}
