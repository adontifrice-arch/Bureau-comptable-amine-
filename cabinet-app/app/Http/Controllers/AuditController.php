<?php

namespace App\Http\Controllers;

use App\Models\Activite;
use App\Models\Client;
use App\Models\ConstatAudit;
use App\Models\Declaration;
use App\Models\Document;
use App\Models\Ecriture;
use App\Models\EcritureLigne;
use App\Models\Echeance;
use App\Models\FactureRecue;
use App\Models\MissionAudit;
use App\Models\Paie;
use App\Models\Salarie;
use App\Services\Compta;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $r)
    {
        $client = $this->dossier($r);
        if (! $client) return view('compta.vide');
        $annee = (int) $r->input('annee', now()->year);
        $tab = $r->input('tab', 'mis');
        $missions = MissionAudit::where('client_id', $client->id)->where('annee', $annee)->orderBy('id')->get();
        $m = $missions->firstWhere('id', (int) $r->input('mission')) ?? $missions->last();
        $data = compact('client', 'annee', 'tab', 'missions', 'm') + ['clients' => Client::orderBy('nom')->get(['id', 'nom'])];
        if ($tab === 'ctl') $data['checks'] = $this->controles($client, $annee);
        if ($tab === 'con') $data['constats'] = ConstatAudit::with('user')->where('client_id', $client->id)->where('annee', $annee)->orderByRaw("statut = 'Résolu'")->latest()->get();
        if ($tab === 'hon') $data['hon'] = $this->honoraires($r, $client, $annee);
        if ($tab === 'tr') $data['trail'] = Activite::with('user')->where('client_id', $client->id)->whereYear('created_at', '>=', $annee)->latest()->limit(200)->get();
        if ($tab === 'rap' && $m) $data['F'] = Compta::etats($client->id, $annee);
        return view('audit.index', $data);
    }

    // ---------- Missions ----------
    public function missionStore(Request $r)
    {
        $client = $this->dossier($r);
        $m = MissionAudit::create($this->mission($r) + ['client_id' => $client->id]);
        $this->log('Création de la mission d’audit '.$m->type.' '.$m->annee, $client->id);
        return redirect()->route('audit.index', ['annee' => $m->annee, 'mission' => $m->id])->with('ok', 'Mission créée.');
    }

    public function missionUpdate(Request $r, MissionAudit $mission)
    {
        $mission->update($this->mission($r));
        return back()->with('ok', 'Mission modifiée.');
    }

    public function missionDestroy(MissionAudit $mission)
    {
        $mission->delete();
        return back()->with('ok', 'Mission supprimée.');
    }

    public function qciSave(Request $r, MissionAudit $mission)
    {
        $mission->update(['qci' => collect((array) $r->input('q'))->map(fn ($x) => ['a' => $x['a'] ?? '', 'n' => $x['n'] ?? ''])->all()]);
        return back()->with('ok', 'Questionnaire enregistré.');
    }

    public function travauxSave(Request $r, MissionAudit $mission)
    {
        $mission->update(['travaux' => collect((array) $r->input('w'))->map(fn ($x) => ['s' => $x['s'] ?? 'À faire', 'ref' => $x['ref'] ?? '', 'n' => $x['n'] ?? ''])->all()]);
        return back()->with('ok', 'Programme de travail enregistré.');
    }

    /** Transforme les réponses « Non » du questionnaire en constats. */
    public function faiblessesToConstats(MissionAudit $mission)
    {
        $Q = collect(config('cabinet.QCI'))->flatMap(fn ($c) => collect($c['q'])->map(fn ($q) => ['id' => $q[0], 't' => $q[1], 'cy' => $c['t']]));
        $n = 0;
        foreach ($Q as $q) {
            if ((($mission->qci ?? [])[$q['id']]['a'] ?? '') !== 'N') continue;
            $src = 'qci:'.$q['id'];
            if (ConstatAudit::where('mission_id', $mission->id)->where('source', $src)->exists()) continue;
            ConstatAudit::create(['client_id' => $mission->client_id, 'mission_id' => $mission->id, 'annee' => $mission->annee, 'source' => $src, 'gravite' => 'Moyenne',
                'titre' => 'Faiblesse de contrôle interne – '.$q['cy'], 'detail' => $q['t'].' → Non. '.(($mission->qci[$q['id']]['n'] ?? '') ?: ''), 'user_id' => auth()->id()]);
            $n++;
        }
        return back()->with('ok', "$n constat(s) créé(s) à partir des faiblesses.");
    }

    public function rapport(Request $r, MissionAudit $mission)
    {
        $rp = $mission->rapport ?? [];
        foreach (['opinion', 'obs', 'lieu', 'date'] as $k) if ($r->has($k)) $rp[$k] = $r->input($k);
        if ($r->boolean('generer')) $rp['html'] = $this->texteRapport($mission, $rp);
        elseif ($r->has('html')) $rp['html'] = $r->input('html');
        $mission->update(['rapport' => $rp]);
        return back()->with('ok', $r->boolean('generer') ? 'Projet de rapport généré : relisez-le et adaptez-le.' : 'Rapport enregistré.');
    }

    public function rapportPrint(MissionAudit $mission)
    {
        return view('audit.rapport', ['m' => $mission->load('client')]);
    }

    // ---------- Constats ----------
    public function constatStore(Request $r)
    {
        $client = $this->dossier($r);
        ConstatAudit::create($this->constat($r) + ['client_id' => $client->id, 'user_id' => auth()->id()]);
        return back()->with('ok', 'Constat ajouté.');
    }

    public function constatUpdate(Request $r, ConstatAudit $constat)
    {
        if ($r->boolean('toggle')) $constat->update(['statut' => $constat->statut === 'Résolu' ? 'Ouvert' : 'Résolu']);
        else $constat->update($this->constat($r));
        return back()->with('ok', 'Constat mis à jour.');
    }

    public function constatDestroy(ConstatAudit $constat) { $constat->delete(); return back()->with('ok', 'Constat supprimé.'); }

    // ---------- Contrôles automatiques (audChecks du prototype) ----------
    private function controles(Client $c, int $y): array
    {
        $K = []; $add = function ($l, $st, $d = '') use (&$K) { $K[] = compact('l', 'st', 'd'); };
        $from = "$y-01-01"; $to = "$y-12-31";
        $lig = EcritureLigne::join('ecritures', 'ecritures.id', '=', 'ecriture_lignes.ecriture_id')->whereNull('ecritures.deleted_at')->where('client_id', $c->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
        $D = (clone $lig)->sum('debit'); $C = (clone $lig)->sum('credit');
        if (! (clone $lig)->count()) $add('Équilibre du journal : débits = crédits', 'info', "Aucune écriture comptabilisée sur $y.");
        else $add('Équilibre du journal : débits = crédits', abs($D - $C) < .001 ? 'ok' : 'ko', 'Total débits '.tnd($D).' · total crédits '.tnd($C).'.');
        $F = Compta::etats($c->id, $y);
        if ($F['n']) $add('Équilibre du bilan : total actif = total passif', abs($F['tA'] - $F['tP']) < .001 ? 'ok' : 'ko', 'Actif '.tnd($F['tA']).' · passif '.tnd($F['tP']).'.');
        $KA = FactureRecue::where('client_id', $c->id)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get();
        $old = $KA->where('statut', 'prop')->filter(fn ($f) => $f->date->lt(now()->subDays(15)));
        $add('Factures reçues en attente de validation', $old->count() ? 'warn' : 'ok', $KA->where('statut', 'prop')->count().' en attente, dont '.$old->count().' depuis plus de 15 jours.');
        if ($rej = $KA->where('statut', 'rej')->count()) $add('Factures reçues rejetées', 'info', "$rej facture(s) rejetée(s).");
        $nomf = $KA->where('statut', '!=', 'rej')->filter(fn ($f) => ! preg_match('/\d{7}\s*\/?\s*[A-Za-z]/', (string) $f->matricule_fiscal));
        $add('Matricule fiscal des fournisseurs (7 chiffres et une lettre)', $nomf->count() ? 'warn' : 'ok', $nomf->count() ? $nomf->count().' facture(s) sans matricule valide : '.$nomf->take(4)->pluck('fournisseur')->implode(', ').'.' : 'Tous les matricules sont renseignés.');
        $dup = $KA->where('statut', '!=', 'rej')->groupBy(fn ($f) => mb_strtolower($f->fournisseur).'|'.($f->reference ?: $f->ht.'|'.$f->date->format('Y-m-d')))->filter(fn ($g) => $g->count() > 1);
        $add('Doublons possibles de factures fournisseurs', $dup->count() ? 'ko' : 'ok', $dup->count() ? $dup->keys()->map(fn ($k) => explode('|', $k)[0])->implode(', ') : 'Aucun doublon détecté.');
        $an = [];
        foreach (Compta::balance($c->id, $y) as $b) {
            $s = $b['sd'] - $b['sc'];
            if (str_starts_with($b['compte'], '411') && $s < -0.0005) $an[] = 'Clients créditeurs ('.$b['compte'].' : '.tnd(-$s).')';
            if (str_starts_with($b['compte'], '401') && $s > 0.0005) $an[] = 'Fournisseurs débiteurs ('.$b['compte'].' : '.tnd($s).')';
            if (preg_match('/^5[34]/', $b['compte']) && $s < -0.0005) $an[] = 'Trésorerie créditrice ('.$b['compte'].' : '.tnd(-$s).')';
            if (str_starts_with($b['compte'], '432') && -$s > 0.0005) $add('Retenues à la source à reverser (compte 432)', 'info', 'Solde à reverser : '.tnd(-$s).'.');
        }
        $add('Soldes anormaux (clients, fournisseurs, trésorerie)', $an ? 'warn' : 'ok', $an ? implode(' · ', $an) : 'Aucun solde anormal.');
        $pt = Paie::where('client_id', $c->id)->where('mois', 'like', "$y-%")->where('statut', 'transmise')->pluck('mois');
        $add('Paies transmises non comptabilisées', $pt->count() ? 'warn' : 'ok', $pt->count() ? $pt->map(fn ($m) => mois_fr($m))->implode(', ') : 'Toutes les paies clôturées sont comptabilisées.');
        $PE = Salarie::where('client_id', $c->id)->get();
        if ($PE->count()) { $pm = $PE->filter(fn ($e) => ! $e->cin || ! $e->cnss); $add('Fiches des salariés complètes (CIN et numéro CNSS)', $pm->count() ? 'warn' : 'ok', $pm->count() ? $pm->pluck('nom')->implode(', ') : 'Fiches complètes.'); }
        $late = [];
        for ($m = 1; $m <= 12; $m++) {
            $mo = sprintf('%d-%02d', $y, $m); if ($mo >= now()->format('Y-m')) break;
            $act = Ecriture::where('client_id', $c->id)->where('date', 'like', "$mo%")->exists();
            $dc = Declaration::where(['client_id' => $c->id, 'type' => 'men', 'periode' => $mo])->first();
            if ($act && (! $dc || $dc->statut !== 'deposee')) $late[] = mois_fr($mo);
        }
        $add('Déclarations mensuelles déposées', $late ? 'warn' : 'ok', $late ? 'Non déposées : '.implode(', ', $late) : 'À jour.');
        $tl = Echeance::where('client_id', $c->id)->whereNull('accomplie_le')->where('date_echeance', '<', now()->startOfDay())->get();
        $add('Échéances fiscales et sociales en retard', $tl->count() ? 'ko' : 'ok', $tl->count() ? $tl->take(4)->pluck('intitule')->implode(', ') : 'Aucune.');
        $miss = collect($c->piecesRequises())->reject(fn ($k) => Document::where('client_id', $c->id)->where('categorie', $k)->exists());
        $add('Pièces de base du dossier juridique', $miss->count() ? 'info' : 'ok', $miss->count() ? $miss->count().' pièce(s) non classée(s) : '.$miss->take(5)->implode(', ') : 'Dossier complet.');
        return $K;
    }

    // ---------- Honoraires CAC (barème des auditeurs, arrêtés de 2003) ----------
    public static function bareme(string $t, float $v): array
    {
        $T = config('cabinet.BAR')[$t]; $tot = $T['f']; $pl = $T['f']; $fr = 0;
        foreach ($T['r'] as [$a, $b, $rate]) {
            $b ??= INF;
            if ($v > $a) { if ($v >= $b) { $tot += ($b - $a) * $rate; $pl = $tot; $fr = 0; } else { $fr = ($v - $a) * $rate; $tot += $fr; } }
        }
        return ['pl' => r3($pl), 'fr' => r3($fr), 'tot' => r3($tot)];
    }

    private function honoraires(Request $r, Client $c, int $y): array
    {
        $F = Compta::etats($c->id, $y);
        $o = ['bil' => (float) $r->input('bil', $F['tA']), 'prod' => (float) $r->input('prod', $F['P']['prod'] + $F['P']['pfi']), 'eff' => (int) $r->input('eff', Salarie::where('client_id', $c->id)->count()), 'coef' => (float) $r->input('coef', 1)];
        $A = static::bareme('bil', $o['bil'] / 1000); $B = static::bareme('prod', $o['prod'] / 1000); $C = static::bareme('eff', $o['eff']);
        $base = r3($A['tot'] + $B['tot'] + $C['tot']);
        return $o + compact('A', 'B', 'C', 'base') + ['ht' => r3($base * ($o['coef'] ?: 1))];
    }

    private function texteRapport(MissionAudit $m, array $R): string
    {
        $c = $m->client; $y = $m->annee; $F = Compta::etats($c->id, $y);
        $op = $R['opinion'] ?? 'sans'; $e = fn ($s) => e($s);
        $res = ConstatAudit::where('client_id', $c->id)->where('annee', $y)->where('statut', '!=', 'Résolu')->where('gravite', '!=', 'Faible')->pluck('titre');
        $titre = $m->type === 'cac' ? 'RAPPORT DU COMMISSAIRE AUX COMPTES' : ($m->type === 'exl' ? 'RAPPORT D’EXAMEN LIMITÉ' : 'RAPPORT D’AUDIT');
        $opinions = [
            'sans' => 'À notre avis, les états financiers ci-joints sont réguliers et sincères et donnent, dans tous leurs aspects significatifs, une image fidèle de la situation financière de la société '.$e($c->nom).' au 31 décembre '.$y.', ainsi que de sa performance financière et de ses flux de trésorerie pour l’exercice clos à cette date, conformément au système comptable des entreprises.',
            'avec' => 'À notre avis, sous réserve de l’incidence des points décrits ci-après, les états financiers ci-joints donnent, dans tous leurs aspects significatifs, une image fidèle de la situation financière de la société '.$e($c->nom).' au 31 décembre '.$y.' conformément au système comptable des entreprises.',
            'refus' => 'En raison de l’importance des points décrits ci-après, nous n’avons pas été en mesure d’obtenir des éléments probants suffisants et appropriés pour fonder une opinion d’audit. Nous n’exprimons donc pas d’opinion sur les états financiers de la société '.$e($c->nom).'.',
            'defav' => 'En raison de l’importance des points décrits ci-après, les états financiers de la société '.$e($c->nom).' ne donnent pas une image fidèle de sa situation financière au 31 décembre '.$y.'.',
        ];
        $h = '<p><b>PROJET</b> — '.$e($R['lieu'] ?? '').', le '.dlong($R['date'] ?? now()).'</p>';
        $h .= '<h3 style="text-align:center">'.$titre.'<br>SUR LES ÉTATS FINANCIERS DE L’EXERCICE CLOS LE 31 DÉCEMBRE '.$y.'</h3>';
        $h .= '<p>Aux associés de la société '.$e($c->nom).',</p>';
        $h .= '<h3>I. Rapport sur l’audit des états financiers</h3><p><b>Opinion</b></p>';
        $h .= '<p>En exécution de la mission qui nous a été confiée'.($m->date_designation ? ' le '.dlong($m->date_designation) : '').', nous avons effectué l’audit des états financiers de la société '.$e($c->nom).', qui comprennent le bilan au 31 décembre '.$y.', l’état de résultat, l’état des flux de trésorerie et les notes aux états financiers. Ces états font ressortir un total bilan de '.tnd($F['tA']).' et un résultat '.($F['P']['res'] >= 0 ? 'bénéficiaire' : 'déficitaire').' de '.tnd(abs($F['P']['res'])).'.</p>';
        $h .= '<p>'.$opinions[$op].'</p>';
        if ($op !== 'sans') $h .= '<p><b>Fondement de l’opinion</b></p><ul>'.($res->count() ? $res->map(fn ($t) => '<li>'.$e($t).'</li>')->implode('') : '<li>[Décrire les points qui fondent l’opinion]</li>').'</ul>';
        else $h .= '<p><b>Fondement de l’opinion</b></p><p>Nous avons effectué notre audit selon les normes internationales d’audit applicables en Tunisie. Nous sommes indépendants de la société conformément aux règles de déontologie qui s’appliquent à l’audit des états financiers en Tunisie. Nous estimons que les éléments probants recueillis sont suffisants et appropriés pour fonder notre opinion.</p>';
        if (! empty($R['obs'])) $h .= '<p><b>Paragraphe d’observation</b></p><p>'.nl2br($e($R['obs'])).'</p>';
        $h .= '<p><b>Responsabilités de la direction</b></p><p>Il incombe à la direction d’établir et de présenter sincèrement les états financiers conformément au système comptable des entreprises, ainsi que de mettre en place le contrôle interne qu’elle estime nécessaire.</p>';
        $h .= '<p><b>Responsabilités de l’auditeur</b></p><p>Nos objectifs sont d’obtenir l’assurance raisonnable que les états financiers pris dans leur ensemble sont exempts d’anomalies significatives, et d’émettre un rapport contenant notre opinion.</p>';
        $h .= '<h3>II. Rapport relatif aux obligations légales et réglementaires</h3><p>Dans le cadre de notre mission, nous avons procédé aux vérifications spécifiques prévues par la loi. Nous n’avons pas d’observation à formuler sur la sincérité et la concordance avec les états financiers des informations données dans le rapport de gestion.</p>';
        $h .= '<p style="margin-top:24px">Le '.($m->type === 'cac' ? 'commissaire aux comptes' : 'cabinet').'<br><b>'.$e(\App\Models\Setting::cabinet()['nom']).'</b><br>'.$e($m->associe ?? '').'</p>';
        return $h;
    }

    private function mission(Request $r): array
    {
        return $r->validate(['annee' => 'required|integer', 'type' => 'required|max:10', 'date_designation' => 'nullable|date', 'duree' => 'nullable|max:100', 'associe' => 'nullable|max:255',
            'chef_mission' => 'nullable|max:255', 'equipe' => 'nullable|max:255', 'honoraires' => 'nullable|numeric', 'statut' => 'required|max:50', 'objet' => 'nullable|max:5000']);
    }

    private function constat(Request $r): array
    {
        return $r->validate(['annee' => 'required|integer', 'mission_id' => 'nullable|'.existe_cab('missions_audit'), 'titre' => 'required|max:255',
            'gravite' => 'required|in:Faible,Moyenne,Élevée', 'detail' => 'nullable|max:10000', 'statut' => 'required|in:Ouvert,Résolu']);
    }
}
