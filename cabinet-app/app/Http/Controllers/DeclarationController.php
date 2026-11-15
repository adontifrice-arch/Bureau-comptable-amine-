<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Declaration;
use App\Models\FactureRecue;
use App\Models\Paie;
use App\Models\PassageFiscal;
use App\Models\Salarie;
use App\Models\Setting;
use App\Services\Compta;
use App\Services\PaieCalc;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DeclarationController extends Controller
{
    public const ANNEXES = [
        'a1' => 'Annexe 1 : traitements, salaires, pensions et rentes viagères',
        'a2' => 'Annexe 2 : honoraires, commissions, courtages, loyers servis à des résidents',
        'a3' => 'Annexe 3 : revenus des capitaux mobiliers et intérêts',
        'a4' => 'Annexe 4 : montants servis à des non-résidents ou non établis',
        'a5' => 'Annexe 5 : acquisitions et marchés soumis à la retenue (≥ 1 000 TND)',
        'a6' => 'Annexe 6 : ristournes, ventes aux forfaitaires, avances',
        'a7' => 'Annexe 7 : montants payés pour autrui',
    ];

    private function ctx(Request $r): array
    {
        $client = $this->dossier($r);
        $type = in_array($r->input('type'), ['men', 'ann', 'emp']) ? $r->input('type') : 'men';
        $periode = $type === 'men' ? $r->input('periode', now()->subMonth()->format('Y-m')) : (string) $r->input('periode', now()->year - ($type === 'ann' ? 1 : 0));
        $d = Declaration::firstOrNew(['client_id' => $client->id, 'type' => $type, 'periode' => $periode], ['champs' => [], 'annexes' => [], 'statut' => 'brouillon']);
        return [$client, $type, $periode, $d];
    }

    public static function calc(string $type, array $f, Client $c): array
    {
        $n = fn ($k) => (float) ($f[$k] ?? 0); $o = [];
        if ($type === 'men') {
            $o['tvap'] = r3($n('tvac') - $n('tvad1') - $n('tvad2') - $n('tvacr'));
            $o['tot'] = r3(max(0, $o['tvap']) + $n('rsal') + $n('rhon') + $n('rmar') + $n('raut') + $n('css') + $n('tfp') + $n('fop') + $n('tim') + $n('autr'));
        } elseif ($type === 'ann') {
            $o['rf'] = r3($n('rc') + $n('rei') - $n('ded'));
            $o['ist'] = $c->estPhysique() ? r3(PaieCalc::bareme(max(0, $o['rf']), Setting::paie()['bar'])) : r3(max(0, $o['rf']) * $n('taux') / 100);
            $o['isd'] = r3(max($o['ist'], $n('min')));
            $o['sol'] = r3($o['isd'] - $n('acp') - $n('rsub') - $n('crep'));
        }
        return $o;
    }

    public function index(Request $r)
    {
        if (! $this->dossier($r)) return view('compta.vide');
        [$client, $type, $periode, $d] = $this->ctx($r);
        $ax = $r->input('ax', 'rec');
        return view('declarations.index', ['client' => $client, 'type' => $type, 'periode' => $periode, 'd' => $d, 'ax' => $ax,
            'calc' => static::calc($type, $d->champs ?? [], $client), 'rec' => $this->recap($d), 'clients' => Client::orderBy('nom')->get(['id', 'nom'])]);
    }

    public function save(Request $r)
    {
        [$client, $type, $periode, $d] = $this->ctx($r);
        if ($d->exists && $d->verrouillee()) return back()->with('err', 'Déclaration verrouillée : rouvrez-la en brouillon pour la modifier.');
        $d->champs = collect((array) $r->input('f'))->map(fn ($v) => $v === null || $v === '' ? null : (float) str_replace(',', '.', $v))->all();
        $d->save();
        return back()->with('ok', 'Déclaration enregistrée.');
    }

    public function prefill(Request $r)
    {
        [$c, $type, $periode, $d] = $this->ctx($r);
        if ($d->exists && $d->verrouillee()) return back()->with('err', 'Déclaration verrouillée.');
        $msg = match ($type) { 'men' => $this->fillMen($c, $periode, $d), 'ann' => $this->fillAnn($c, (int) $periode, $d), default => $this->fillEmp($c, (int) $periode, $d) };
        $d->save();
        return back()->with('ok', $msg);
    }

    public function statut(Request $r)
    {
        [$c, $type, $periode, $d] = $this->ctx($r);
        $a = $r->input('action');
        if ($a === 'valider' && $d->statut === 'brouillon') $d->fill(['statut' => 'validee', 'validee_le' => now()]);
        elseif ($a === 'deposer' && $d->statut === 'validee') $d->fill(['statut' => 'deposee', 'deposee_le' => now()]);
        elseif ($a === 'rouvrir') $d->fill(['statut' => 'brouillon']);
        $d->save();
        return back()->with('ok', ['valider' => 'Déclaration validée : les champs sont verrouillés.', 'deposer' => 'Déclaration marquée comme déposée.', 'rouvrir' => 'Déclaration rouverte en brouillon.'][$a] ?? 'OK');
    }

    public function annexeStore(Request $r)
    {
        [$c, $type, $periode, $d] = $this->ctx($r);
        if ($d->verrouillee()) return back()->with('err', 'Déclaration verrouillée.');
        $ax = $r->validate(['ax' => 'required|in:a1,a2,a3,a4,a5,a6,a7'])['ax'];
        $l = $r->validate(['idk' => 'nullable|max:2', 'id' => 'nullable|max:30', 'nom' => 'required|max:255', 'adr' => 'nullable|max:255', 'act' => 'nullable|max:255',
            'code' => 'nullable|max:3', 'brut' => 'required|numeric', 'taux' => 'nullable|numeric', 'ret' => 'nullable|numeric']);
        $l['ret'] = $r->filled('ret') ? (float) $l['ret'] : r3($l['brut'] * ($l['taux'] ?? 0) / 100);
        $l['net'] = r3($l['brut'] - $l['ret']);
        $A = $d->annexes ?? [];
        $A[$ax][] = $l;
        $d->annexes = $A;
        $d->save();
        return back()->with('ok', 'Ligne ajoutée à l’'.strtolower(substr(self::ANNEXES[$ax], 0, 8)).'.');
    }

    public function annexeDestroy(Request $r)
    {
        [$c, $type, $periode, $d] = $this->ctx($r);
        if ($d->verrouillee()) return back()->with('err', 'Déclaration verrouillée.');
        $A = $d->annexes ?? [];
        array_splice($A[$r->input('ax')], (int) $r->input('i'), 1);
        $d->annexes = $A; $d->save();
        return back()->with('ok', 'Ligne supprimée.');
    }

    public function print(Request $r)
    {
        [$client, $type, $periode, $d] = $this->ctx($r);
        return view('declarations.print', ['client' => $client, 'type' => $type, 'periode' => $periode, 'd' => $d, 'calc' => static::calc($type, $d->champs ?? [], $client), 'rec' => $this->recap($d)]);
    }

    public function csv(Request $r)
    {
        [$client, $type, $periode, $d] = $this->ctx($r);
        $nn = fn ($v) => number_format((float) $v, 3, ',', '');
        $out = "\xEF\xBB\xBF";
        if ($type === 'emp') {
            foreach (self::ANNEXES as $k => $t) {
                $out .= "$t\r\nType id;Identifiant;Nom;Adresse;Montant brut;Taux;Retenue;Net servi\r\n";
                foreach ($d->annexes[$k] ?? [] as $l) $out .= implode(';', [$l['idk'] ?? '', $l['id'] ?? '', '"'.$l['nom'].'"', '"'.($l['adr'] ?? '').'"', $nn($l['brut']), $l['taux'] ?? '', $nn($l['ret']), $nn($l['net'])])."\r\n";
                $out .= "\r\n";
            }
        } else {
            $c = static::calc($type, $d->champs ?? [], $client);
            $out .= "Rubrique;Montant\r\n";
            foreach (config('cabinet.'.($type === 'men' ? 'MEN' : 'ANN')) as $row) if ($row[0] !== 'h') $out .= '"'.$row[1].'";'.$nn(($row[2] ?? '') === 'c' ? ($c[$row[0]] ?? 0) : ($d->champs[$row[0]] ?? 0))."\r\n";
        }
        return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"declaration-$type-$periode.csv\""]);
    }

    /** Récapitulatif de la déclaration d'employeur par annexe. */
    private function recap(Declaration $d): array
    {
        $R = [];
        foreach (self::ANNEXES as $k => $t) {
            $L = collect($d->annexes[$k] ?? []);
            $R[$k] = ['t' => $t, 'n' => $L->count(), 'brut' => r3($L->sum('brut')), 'ret' => r3($L->sum('ret')), 'net' => r3($L->sum('net'))];
        }
        return $R;
    }

    private function fillMen(Client $c, string $mo, Declaration $d): string
    {
        $from = $mo.'-01'; $to = Carbon::parse($from)->endOfMonth()->format('Y-m-d');
        $S = fn ($pre, $side) => Compta::somme($c->id, $from, $to, $pre, $side);
        $ca = r3($S('70', 'credit') - $S('70', 'debit'));
        $tvac = r3($S('4367', 'credit') - $S('4367', 'debit'));     // 4367 TVA collectée
        $dtot = r3($S('4366', 'debit') - $S('4366', 'credit'));       // 4366 TVA déductible
        $ks = FactureRecue::where('client_id', $c->id)->where('statut', 'val')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get();
        $immo = 0; $rh = 0; $rm = 0;
        foreach ($ks as $x) {
            if ($x->tva_deductible) $immo += array_sum(array_column($x->lignesImmobilisations(), 'tva'));    // lignes de la facture imputées en classe 2
            if (($rs = $x->retenue()) > 0) { if (in_array(Compta::categorieRetenue($x), ['hon', 'loy', 'com', 'cou'])) $rh += $rs; else $rm += $rs; }
        }
        $f = $d->champs ?? [];
        foreach (['ca19', 'ca13', 'ca7', 'caex', 'caxp'] as $k) $f[$k] = 0;
        $ratio = $ca > 0 ? $tvac / $ca * 100 : 0;
        $k = abs($ratio - 19) < .6 ? 'ca19' : (abs($ratio - 13) < .6 ? 'ca13' : (abs($ratio - 7) < .6 ? 'ca7' : ($tvac == 0 && $ca > 0 ? 'caex' : 'ca19')));
        $f[$k] = $ca; $f['tvac'] = $tvac; $f['tvad2'] = r3($immo); $f['tvad1'] = r3($dtot - $immo);
        $prev = Declaration::where(['client_id' => $c->id, 'type' => 'men', 'periode' => Carbon::parse($from)->subMonth()->format('Y-m')])->first();
        $f['tvacr'] = $prev ? max(0, -static::calc('men', $prev->champs ?? [], $c)['tvap']) : 0;
        $p = Paie::where('client_id', $c->id)->where('mois', $mo)->where('statut', '!=', 'brouillon')->first();
        $f['rsal'] = $p->totaux['ir'] ?? 0; $f['css'] = $p->totaux['css'] ?? 0; $f['tfp'] = $p->totaux['tfp'] ?? 0; $f['fop'] = $p->totaux['fop'] ?? 0;
        $f['rhon'] = r3($rh); $f['rmar'] = r3($rm);
        $d->champs = $f;
        return 'Pré-remplissage : CA '.tnd($ca).', TVA collectée '.tnd($tvac).', TVA déductible '.tnd($dtot).', '.($p ? 'paie du mois reprise' : 'aucune paie clôturée').', '.$ks->count().' facture(s) reçue(s). Vérifiez la répartition par taux.';
    }

    private function fillAnn(Client $c, int $y, Declaration $d): string
    {
        $F = Compta::etats($c->id, $y); $P = $F['P'];
        $L = PassageFiscal::where('client_id', $c->id)->where('annee', $y)->first()?->donnees ?? [];
        $f = $d->champs ?? [];
        $f['ca'] = $P['ca']; $f['prod'] = r3($P['prod'] + $P['pfi']); $f['chg'] = r3($P['chg'] + $P['fin']); $f['rc'] = $P['rav'];
        foreach (['rei' => 'rei', 'ded' => 'ded', 'taux' => 'taux', 'min' => 'min', 'acp' => 'acp', 'rsub' => 'rs'] as $k => $s) if (isset($L[$s])) $f[$k] = $L[$s];
        $f['taux'] = $f['taux'] ?? 15;
        $d->champs = $f;
        return 'Pré-remplissage depuis les états financiers '.$y.($L ? ' et le tableau de passage de la liasse.' : ' (tableau de passage non renseigné).');
    }

    private function fillEmp(Client $c, int $y, Declaration $d): string
    {
        $A = collect($d->annexes ?? [])->map(fn ($L) => array_values(array_filter($L, fn ($l) => empty($l['auto']))))->all();
        $paies = Paie::where('client_id', $c->id)->where('mois', 'like', "$y-%")->where('statut', '!=', 'brouillon')->get();
        $tot = [];
        foreach ($paies as $p) foreach ($p->resultats ?? [] as $x) {
            $k = $x['salarie_id']; $tot[$k] ??= ['brut' => 0, 'ret' => 0];
            $tot[$k]['brut'] += $x['brut']; $tot[$k]['ret'] += $x['ir'] + $x['css'];
        }
        $n1 = 0;
        foreach (Salarie::where('client_id', $c->id)->get() as $e) {
            if (! isset($tot[$e->id])) continue;
            $A['a1'][] = ['auto' => 1, 'idk' => '2', 'id' => $e->cin, 'nom' => $e->nom, 'adr' => '', 'act' => $e->poste, 'brut' => r3($tot[$e->id]['brut']), 'taux' => null, 'ret' => r3($tot[$e->id]['ret']), 'net' => r3($tot[$e->id]['brut'] - $tot[$e->id]['ret'])];
            $n1++;
        }
        $n2 = 0; $n5 = 0;
        foreach (FactureRecue::where('client_id', $c->id)->where('statut', 'val')->whereYear('date', $y)->get() as $f) {
            if (($rs = $f->retenue()) <= 0) continue;
            $cat = Compta::categorieRetenue($f); $ax = $cat === 'mar' ? 'a5' : 'a2';
            $brut = r3($f->ht + $f->tva);
            $A[$ax][] = ['auto' => 1, 'idk' => preg_match('/\d{7}/', (string) $f->matricule_fiscal) ? '1' : '', 'id' => $f->matricule_fiscal, 'nom' => $f->fournisseur, 'adr' => '', 'act' => $f->description,
                'brut' => $brut, 'taux' => (float) $f->retenue_taux, 'ret' => $rs, 'net' => r3($brut - $rs)];
            $ax === 'a5' ? $n5++ : $n2++;
        }
        $d->annexes = $A;
        return "Pré-remplissage : annexe 1 depuis la paie ($n1 salarié(s), ".$paies->count()." mois clôturé(s)), annexe 2 : $n2 ligne(s), annexe 5 : $n5 ligne(s) depuis les factures reçues avec retenue.";
    }
}
