<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Declaration;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\Paie;
use App\Models\PassageFiscal;
use App\Models\Salarie;
use App\Models\Setting;
use App\Services\Compta;
use App\Services\Exports\BonsCommandeSuspension;
use App\Services\Exports\FacturesSuspension;
use App\Services\Exports\FichierDgi;
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

    /**
     * Opérations en suspension de TVA (lignes stockées dans annexes['l']).
     * Listes trimestrielles déposées sur support magnétique (cahiers des charges DGI) :
     * achats → bons de commandes et factures (fichier BCD_Tn_AA), ventes → factures émises (fichier FAC_Tn_AA).
     */
    public const SUSPENSION = [
        'sach' => 'Liste des bons de commandes et des factures d’achats en suspension de TVA',
        'sven' => 'Liste détaillée des factures émises en suspension de TVA',
    ];

    public const TYPES = ['men', 'ann', 'emp', 'sach', 'sven'];

    public static function mensuelle(string $type): bool
    {
        return $type === 'men';
    }

    public static function trimestrielle(string $type): bool
    {
        return isset(self::SUSPENSION[$type]);
    }

    /** Format DGI du fichier de la liste : BCD (achats) ou FAC (ventes). */
    public static function format(string $type): string
    {
        return $type === 'sach' ? BonsCommandeSuspension::class : FacturesSuspension::class;
    }

    /** Libellé de la période : « octobre 2026 », « 4e trimestre 2026 » ou « 2026 ». */
    public static function libellePeriode(string $type, string $periode): string
    {
        if (static::mensuelle($type)) return mois_fr($periode);
        if (static::trimestrielle($type)) { [$an, $t] = BonsCommandeSuspension::trimestre($periode); return ($t === 1 ? '1er' : $t.'e').' trimestre '.$an; }
        return $periode;
    }

    /** Premier et dernier jour d'une période mensuelle ou trimestrielle. */
    public static function bornes(string $type, string $periode): array
    {
        if (static::trimestrielle($type)) {
            [$an, $t] = BonsCommandeSuspension::trimestre($periode);
            $from = Carbon::create($an, 3 * $t - 2, 1);
            return [$from->format('Y-m-d'), $from->copy()->addMonths(2)->endOfMonth()->format('Y-m-d')];
        }
        return [$periode.'-01', Carbon::parse($periode.'-01')->endOfMonth()->format('Y-m-d')];
    }

    private function ctx(Request $r): array
    {
        $client = $this->dossier($r);
        $type = in_array($r->input('type'), self::TYPES) ? $r->input('type') : 'men';
        $p = (string) $r->input('periode');
        $periode = match (true) {
            static::trimestrielle($type) => preg_match('/^\d{4}-T[1-4]$/', $p) ? $p : now()->subQuarter()->year.'-T'.now()->subQuarter()->quarter,
            static::mensuelle($type) => $r->input('periode', now()->subMonth()->format('Y-m')),
            default => (string) $r->input('periode', now()->year - ($type === 'ann' ? 1 : 0)),
        };
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
        if (static::trimestrielle($type)) $d->champs = $this->entete($client, $d);
        return view('declarations.index', ['client' => $client, 'type' => $type, 'periode' => $periode, 'd' => $d, 'ax' => $ax,
            'calc' => static::calc($type, $d->champs ?? [], $client), 'rec' => $this->recap($d), 'clients' => Client::orderBy('nom')->get(['id', 'nom'])]);
    }

    public function save(Request $r)
    {
        [$client, $type, $periode, $d] = $this->ctx($r);
        if ($d->exists && $d->verrouillee()) return back()->with('err', 'Déclaration verrouillée : rouvrez-la en brouillon pour la modifier.');
        if (static::trimestrielle($type)) {
            $d->champs = $r->validate(['f.aut' => 'nullable|max:30', 'f.activite' => 'nullable|max:40', 'f.adresse' => 'nullable|max:120'])['f'] ?? [];
            $d->save();
            return back()->with('ok', 'Renseignements du déclarant enregistrés.');
        }
        $d->champs = collect((array) $r->input('f'))->map(fn ($v) => $v === null || $v === '' ? null : (float) str_replace(',', '.', $v))->all();
        $d->save();
        return back()->with('ok', 'Déclaration enregistrée.');
    }

    public function prefill(Request $r)
    {
        [$c, $type, $periode, $d] = $this->ctx($r);
        if ($d->exists && $d->verrouillee()) return back()->with('err', 'Déclaration verrouillée.');
        $msg = match ($type) { 'men' => $this->fillMen($c, $periode, $d), 'ann' => $this->fillAnn($c, (int) $periode, $d), 'sach', 'sven' => $this->fillSuspension($c, $type, $periode, $d), default => $this->fillEmp($c, (int) $periode, $d) };
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

    public function suspensionStore(Request $r)
    {
        [$c, $type, $periode, $d] = $this->ctx($r);
        if (! isset(self::SUSPENSION[$type])) abort(404);
        if ($d->verrouillee()) return back()->with('err', 'Déclaration verrouillée.');
        $sansDelim = 'not_regex:/<|\/>/';
        $l = $r->validate($type === 'sach'
            // Zones du cahier des charges DGI (enregistrement BCDDET)
            ? ['aut' => 'nullable|size:12', 'att' => 'required|regex:/^\d{1,13}$/', 'att_date' => 'required|date', 'id' => 'required|max:20', 'nom' => ['required', 'max:40', $sansDelim],
                'fac' => ['required', 'max:30', $sansDelim], 'fac_date' => 'required|date', 'ht' => 'required|numeric|gt:0', 'taux' => 'nullable|numeric|min:0|max:100',
                'tva' => 'nullable|numeric|min:0|lte:ht', 'objet' => ['nullable', 'max:320', $sansDelim]]
            // Zones du cahier des charges DGI (enregistrement FACDET)
            : ['fac' => 'required|max:20', 'fac_date' => 'required|date', 'idk' => 'required|in:1,2,3,4', 'id' => 'required|max:20', 'nom' => 'required|max:40',
                'adr' => 'nullable|max:120', 'att' => 'required|max:20', 'att_date' => 'required|date', 'ht' => 'required|numeric|gt:0',
                'fodec_taux' => 'nullable|numeric|min:0|max:100', 'fodec' => 'nullable|numeric|min:0', 'dc_taux' => 'nullable|numeric|min:0|max:100', 'dc' => 'nullable|numeric|min:0',
                'taux' => 'nullable|numeric|min:0|max:100', 'tva' => 'nullable|numeric|min:0'],
            ['att.regex' => 'Le numéro de bon de commande ne comporte que des chiffres (13 au plus).', 'aut.size' => 'Le numéro d’autorisation comporte 12 caractères.',
                'not_regex' => 'Les caractères « < » et « /> » ne sont pas admis.']);
        if ($type === 'sach' || $l['idk'] === '1') {
            if ($m = FichierDgi::erreurMatricule($l['id'])) throw \Illuminate\Validation\ValidationException::withMessages(['id' => $m]);
            $l['id'] = FichierDgi::matricule($l['id'])['brut'];
        } elseif (! preg_match('/^\d{1,8}$/', $l['id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['id' => 'L’identifiant (CIN, carte de séjour, non-résident) comporte 8 chiffres au plus.']);
        }
        $l['taux'] = $r->filled('taux') ? (float) $l['taux'] : 19.0;
        $l['ht'] = r3((float) $l['ht']);
        if ($type === 'sven') {
            // FODEC et droit de consommation sur le HT ; TVA sur le HT majoré du FODEC et du droit de consommation.
            foreach (['fodec', 'dc'] as $k) {
                $l[$k.'_taux'] = (float) ($l[$k.'_taux'] ?? 0);
                $l[$k] = $r->filled($k) ? r3((float) $l[$k]) : r3($l['ht'] * $l[$k.'_taux'] / 100);
            }
            $base = $l['ht'] + $l['fodec'] + $l['dc'];
            $l['tva'] = $r->filled('tva') ? r3((float) $l['tva']) : r3($base * $l['taux'] / 100);
            if ($l['tva'] + $l['fodec'] + $l['dc'] > $l['ht']) throw \Illuminate\Validation\ValidationException::withMessages(['tva' => 'Les taxes suspendues ne doivent pas dépasser le prix HT.']);
        } else {
            $l['tva'] = $r->filled('tva') ? r3((float) $l['tva']) : r3($l['ht'] * $l['taux'] / 100);
        }
        $A = $d->annexes ?? [];
        $A['l'][] = $l;
        $A['l'] = collect($A['l'])->sortBy('fac_date')->values()->all();
        $d->annexes = $A;
        $d->save();
        return back()->with('ok', 'Ligne ajoutée.');
    }

    public function suspensionDestroy(Request $r)
    {
        [$c, $type, $periode, $d] = $this->ctx($r);
        if ($d->verrouillee()) return back()->with('err', 'Déclaration verrouillée.');
        $A = $d->annexes ?? [];
        array_splice($A['l'], (int) $r->input('i'), 1);
        $d->annexes = $A; $d->save();
        return back()->with('ok', 'Ligne supprimée.');
    }

    /** Fichier à graver et déposer : BCD_Tn_AA (bons de commandes et factures d'achats) ou FAC_Tn_AA (factures de vente) en suspension. */
    public function fichierDgi(Request $r)
    {
        [$client, $type, $periode, $d] = $this->ctx($r);
        abort_unless(static::trimestrielle($type), 404);
        $d->champs = $this->entete($client, $d);
        $f = static::format($type);
        return response($f::fichier($client, $d), 200, ['Content-Type' => 'text/plain; charset=US-ASCII',
            'Content-Disposition' => 'attachment; filename="'.$f::nomFichier($periode).'"']);
    }

    /** Bordereau de transmission du support magnétique (جدول إحالة على حامل ممغنط). */
    public function bordereau(Request $r)
    {
        [$client, $type, $periode, $d] = $this->ctx($r);
        abort_unless(static::trimestrielle($type), 404);
        $d->champs = $this->entete($client, $d);
        return view('declarations.bordereau', ['client' => $client, 'type' => $type, 'periode' => $periode, 'd' => $d, 'mf' => FichierDgi::matricule($client->matricule_fiscal),
            'tot' => static::format($type)::totaux($d), 'date' => $r->date('date') ?? now()]);
    }

    /** Liste détaillée des factures émises en suspension, modèle papier en arabe, avec le reçu de dépôt. */
    public function liste(Request $r)
    {
        [$client, $type, $periode, $d] = $this->ctx($r);
        abort_unless($type === 'sven', 404);
        $d->champs = $this->entete($client, $d);
        return view('declarations.liste_factures', ['client' => $client, 'periode' => $periode, 'd' => $d, 'mf' => FichierDgi::matricule($client->matricule_fiscal),
            'tot' => FacturesSuspension::totaux($d)]);
    }

    /**
     * Renseignements du déclarant (listes en suspension) : saisis, sinon repris de la liste précédente (même type, puis l'autre liste)
     * et de la fiche du dossier. Le n° d'autorisation d'achat ne concerne que les achats.
     */
    private function entete(Client $c, Declaration $d): array
    {
        $prev = fn ($types) => Declaration::where('client_id', $c->id)->whereIn('type', $types)->where('id', '!=', $d->id ?? 0)
            ->where('periode', '<=', $d->periode)->orderByDesc('periode')->get()->first(fn ($x) => ! empty(array_filter($x->champs ?? [])))?->champs ?? [];
        $p = $prev([$d->type]) ?: $prev(array_keys(self::SUSPENSION));
        $h = $d->champs ?? [];
        return ['aut' => $d->type === 'sach' ? (($h['aut'] ?? null) ?: ($prev(['sach'])['aut'] ?? null)) : null,
            'activite' => ($h['activite'] ?? null) ?: (($p['activite'] ?? null) ?: $c->paie_activite),
            'adresse' => ($h['adresse'] ?? null) ?: (($p['adresse'] ?? null) ?: $c->adresse)];
    }

    /** Totaux d'une déclaration en suspension de TVA. */
    public static function totauxSuspension(Declaration $d): array
    {
        $L = collect($d->annexes['l'] ?? []);
        return ['n' => $L->count(), 'ht' => r3($L->sum('ht')), 'fodec' => r3($L->sum('fodec')), 'dc' => r3($L->sum('dc')), 'tva' => r3($L->sum('tva'))];
    }

    public function print(Request $r)
    {
        [$client, $type, $periode, $d] = $this->ctx($r);
        if (static::trimestrielle($type)) $d->champs = $this->entete($client, $d);
        return view('declarations.print', ['client' => $client, 'type' => $type, 'periode' => $periode, 'd' => $d, 'calc' => static::calc($type, $d->champs ?? [], $client), 'rec' => $this->recap($d)]);
    }

    public function csv(Request $r)
    {
        [$client, $type, $periode, $d] = $this->ctx($r);
        $nn = fn ($v) => number_format((float) $v, 3, ',', '');
        $out = "\xEF\xBB\xBF";
        if ($type === 'sach') {
            // Colonnes de la feuille « BCD » de l'outil officiel : montants en millimes, dates JJMMAAAA.
            $h = $this->entete($client, $d); $mil = fn ($v) => (string) (int) round((float) $v * 1000); $dt = fn ($v) => $v ? Carbon::parse($v)->format('dmY') : '';
            $out .= self::SUSPENSION[$type].' · '.static::libellePeriode($type, $periode)."\r\n";
            $out .= "Numero d'ordre;Num autorisation d'achat en suspension de TVA;Bon de commande numero;Bon de commande date;Matricule fiscal fournisseur;Nom et prenom ou raison sociale;Facture numero;Facture date;Prix HT (millimes);TVA suspendue (millimes);Objet\r\n";
            foreach (array_values($d->annexes['l'] ?? []) as $i => $l) $out .= implode(';', [$i + 1, '"'.(($l['aut'] ?? '') ?: ($h['aut'] ?? '')).'"', '"'.($l['att'] ?? '').'"', '"'.$dt($l['att_date'] ?? null).'"',
                '"'.($l['id'] ?? '').'"', '"'.$l['nom'].'"', '"'.$l['fac'].'"', '"'.$dt($l['fac_date']).'"', $mil($l['ht']), $mil($l['tva']), '"'.($l['objet'] ?? '').'"'])."\r\n";
            $t = static::totauxSuspension($d);
            $out .= 'Total;;;;;;;;'.$mil($t['ht']).';'.$mil($t['tva']).";\r\n";
        } elseif ($type === 'sven') {
            // Colonnes de la feuille « FAC » de l'outil officiel : montants en millimes, dates JJMMAAAA, taux en %.
            $mil = fn ($v) => (string) (int) round((float) $v * 1000); $dt = fn ($v) => $v ? Carbon::parse($v)->format('dmY') : '';
            $out .= self::SUSPENSION[$type].' · '.static::libellePeriode($type, $periode)."\r\n";
            $out .= "Numero d'ordre;Facture numero;Facture date;Type identifiant;Identifiant;Nom et prenom ou raison sociale;Adresse;Autorisation numero;Autorisation date;Prix HT (millimes);Taux FODEC;Montant FODEC;Taux droit de consommation;Montant droit de consommation;Taux TVA;Montant TVA\r\n";
            foreach (array_values($d->annexes['l'] ?? []) as $i => $l) $out .= implode(';', [$i + 1, '"'.$l['fac'].'"', '"'.$dt($l['fac_date']).'"', $l['idk'] ?? '', '"'.($l['id'] ?? '').'"', '"'.$l['nom'].'"',
                '"'.($l['adr'] ?? '').'"', '"'.($l['att'] ?? '').'"', '"'.$dt($l['att_date'] ?? null).'"', $mil($l['ht']), (float) ($l['fodec_taux'] ?? 0), $mil($l['fodec'] ?? 0),
                (float) ($l['dc_taux'] ?? 0), $mil($l['dc'] ?? 0), (float) $l['taux'], $mil($l['tva'])])."\r\n";
            $t = static::totauxSuspension($d);
            $out .= 'Total;;;;;;;;;'.$mil($t['ht']).';;'.$mil($t['fodec']).';;'.$mil($t['dc']).';;'.$mil($t['tva'])."\r\n";
        } elseif ($type === 'emp') {
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
        // Ventes en suspension de TVA (factures de vente validées du mois) : CA suspendu, hors répartition par taux.
        $susp = r3((float) FactureVente::where('client_id', $c->id)->where('statut', 'val')->where('tva_suspension', true)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum('ht'));
        $ca = r3(max(0, $ca - $susp));
        $ratio = $ca > 0 ? $tvac / $ca * 100 : 0;
        $k = abs($ratio - 19) < .6 ? 'ca19' : (abs($ratio - 13) < .6 ? 'ca13' : (abs($ratio - 7) < .6 ? 'ca7' : ($tvac == 0 && $ca > 0 ? 'caex' : 'ca19')));
        $f[$k] = $ca; $f['caex'] = r3($f['caex'] + $susp); $f['tvac'] = $tvac; $f['tvad2'] = r3($immo); $f['tvad1'] = r3($dtot - $immo);
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

    /**
     * Achats / ventes en suspension de TVA : une ligne par facture validée du mois cochée « en suspension de TVA ».
     * Les lignes saisies à la main sont conservées, les lignes reprises des factures sont remplacées.
     */
    private function fillSuspension(Client $c, string $type, string $periode, Declaration $d): string
    {
        [$from, $to] = static::bornes($type, $periode);
        $achat = $type === 'sach';
        $q = ($achat ? FactureRecue::query() : FactureVente::query())->where('client_id', $c->id)->where('tva_suspension', true)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to);
        $attente = (clone $q)->where('statut', 'prop')->count();
        $L = array_values(array_filter($d->annexes['l'] ?? [], fn ($l) => empty($l['auto'])));
        $n = 0;
        foreach ($q->where('statut', 'val')->orderBy('date')->get() as $f) {
            $mf = (string) $f->matricule_fiscal;
            if ($achat) {
                $L[] = ['auto' => 1, 'aut' => null, 'att' => preg_replace('/\D/', '', (string) $f->suspension_ref), 'att_date' => $f->suspension_date?->format('Y-m-d'),
                    'id' => BonsCommandeSuspension::matricule($mf)['brut'] ?? ($mf ?: null), 'nom' => mb_substr($f->fournisseur, 0, 40), 'fac' => mb_substr($f->reference ?: '—', 0, 30),
                    'fac_date' => $f->date->format('Y-m-d'), 'ht' => r3((float) $f->ht), 'taux' => (float) $f->tva_taux, 'tva' => r3((float) $f->tva_suspendue),
                    'objet' => mb_substr(str_replace(['<', '/>'], '', (string) $f->description), 0, 320) ?: null];
                $n++;
                continue;
            }
            // Vente : identifiant matricule fiscal (type 1) ou CIN à 8 chiffres (type 2, à corriger s'il s'agit d'une carte de séjour ou d'un non-résident).
            $p = FichierDgi::matricule($mf); $chiffres = preg_replace('/\D/', '', $mf);
            [$idk, $id] = $p ? ['1', $p['brut']] : ($chiffres !== '' && strlen($chiffres) <= 8 && $chiffres === $mf ? ['2', $chiffres] : [null, $mf ?: null]);
            $adresses ??= $this->adressesClients($c);
            $L[] = ['auto' => 1, 'fac' => mb_substr($f->reference ?: '—', 0, 20), 'fac_date' => $f->date->format('Y-m-d'), 'idk' => $idk, 'id' => $id,
                'nom' => mb_substr($f->tiers, 0, 40), 'adr' => $adresses[$id ?? ''] ?? $adresses[mb_strtolower($f->tiers)] ?? null,
                'att' => $f->suspension_ref, 'att_date' => $f->suspension_date?->format('Y-m-d'), 'ht' => r3((float) $f->ht),
                'fodec_taux' => 0.0, 'fodec' => 0.0, 'dc_taux' => 0.0, 'dc' => 0.0, 'taux' => (float) $f->tva_taux, 'tva' => r3((float) $f->tva_suspendue)];
            $n++;
        }
        $d->annexes = ['l' => collect($L)->sortBy('fac_date')->values()->all()] + ($d->annexes ?? []);
        return "Pré-remplissage : $n facture(s) ".($achat ? 'd’achat' : 'de vente').' en suspension de TVA reprise(s)'
            .($attente ? ", $attente facture(s) en attente de validation non reprise(s)" : '').'.';
    }

    /** Adresses des clients déjà saisies dans les listes de ventes en suspension du dossier, par identifiant et par nom. */
    private function adressesClients(Client $c): array
    {
        $A = [];
        foreach (Declaration::where(['client_id' => $c->id, 'type' => 'sven'])->orderBy('periode')->get() as $x)
            foreach ($x->annexes['l'] ?? [] as $l) if (! empty($l['adr'])) {
                if (! empty($l['id'])) $A[$l['id']] = $l['adr'];
                $A[mb_strtolower($l['nom'])] = $l['adr'];
            }
        return $A;
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
