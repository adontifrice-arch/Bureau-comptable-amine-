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
use App\Services\DeclarationAcompte;
use App\Services\DeclarationAnnuelle;
use App\Services\DeclarationMensuelle;
use App\Services\Exports\BonsCommandeSuspension;
use App\Services\Exports\DeclarationEmployeur;
use App\Services\Exports\FacturesSuspension;
use App\Services\Exports\FichierDgi;
use App\Services\PaieCalc;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DeclarationController extends Controller
{
    /** Annexes de la déclaration d'employeur (cahier des charges DGI : voir DeclarationEmployeur). */
    public const ANNEXES = DeclarationEmployeur::ANNEXES;

    /**
     * Opérations en suspension de TVA (lignes stockées dans annexes['l']).
     * Listes trimestrielles déposées sur support magnétique (cahiers des charges DGI) :
     * achats → bons de commandes et factures (fichier BCD_Tn_AA), ventes → factures émises (fichier FAC_Tn_AA).
     */
    public const SUSPENSION = [
        'sach' => 'Liste des bons de commandes et des factures d’achats en suspension de TVA',
        'sven' => 'Liste détaillée des factures émises en suspension de TVA',
    ];

    public const TYPES = ['men', 'ann', 'emp', 'acp', 'sach', 'sven'];

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
        if ($type === 'acp') return DeclarationAcompte::libelle($periode);
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
            $type === 'acp' => preg_match('/^\d{4}-A[1-3]$/', $p) ? $p : DeclarationAcompte::periodeParDefaut(),
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
            return DeclarationMensuelle::calcul($f);
        } elseif ($type === 'ann') {
            $o['rf'] = r3($n('rc') + $n('rei') - $n('ded'));
            $o['ist'] = $c->estPhysique() ? r3(PaieCalc::bareme(\App\Services\DeclarationAnnuelle::arrondiImposable($o['rf']), Setting::paie()['bar'])) : r3(max(0, $o['rf']) * $n('taux') / 100);
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
        if ($type === 'emp') $d->champs = $this->enteteEmp($client, $d);
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
        if ($type === 'emp') {
            $e = $r->validate(['e.acte' => 'nullable|in:0,1,2', 'e.activite' => 'nullable|max:40', 'e.ville' => 'nullable|max:40', 'e.rue' => 'nullable|max:72',
                'e.num' => 'nullable|digits_between:1,4', 'e.cp' => 'nullable|digits:4', 'r' => 'nullable|array', 'r.*.a' => 'nullable|numeric|min:0', 'r.*.r' => 'nullable|numeric|min:0'])['e'] ?? null;
            $champs = $this->enteteEmp($client, $d);
            if ($e !== null) $champs = array_merge($champs, array_map(fn ($v) => $v ?? '', $e));
            if ($r->boolean('reprendre')) unset($champs['r']);
            elseif ($r->has('r')) {
                $champs['r'] = collect((array) $r->input('r'))->only(array_keys(DeclarationEmployeur::RECAP))
                    ->map(fn ($x) => ['a' => r3((float) str_replace(',', '.', $x['a'] ?? 0)), 'r' => r3((float) str_replace(',', '.', $x['r'] ?? 0))])->all();
            }
            $d->champs = $champs;
            $d->save();
            return back()->with('ok', $r->boolean('reprendre') ? 'Récapitulatif recalculé depuis les annexes.' : 'Déclaration enregistrée.');
        }
        if ($type === 'acp') {
            $num = fn ($v) => $v === null || $v === '' ? null : (float) str_replace([' ', ','], ['', '.'], (string) $v);
            $f = ['acte' => in_array((string) $r->input('acte'), array_map('strval', array_keys(DeclarationAcompte::ACTES)), true) ? (string) $r->input('acte') : '0',
                'rc' => trim((string) $r->input('rc')) ?: null, 'activite' => trim((string) $r->input('activite')) ?: null];
            foreach (DeclarationAcompte::LIGNES as $k => [, , $nat]) if ($nat !== 'c' && ($v = $num($r->input("f.$k"))) !== null) $f[$k] = $v;
            foreach (["regime", "taux", "min"] as $k) if (isset($d->champs[$k])) $f[$k] = $d->champs[$k];   // paramètres repris de la déclaration annuelle
            $d->champs = array_filter($f, fn ($v) => $v !== null);
            $d->save();
            return back()->with('ok', 'Déclaration enregistrée.');
        }
        if ($type === 'ann') {
            // Modèle officiel (IS ou IR selon la fiche client) : entête, rubriques, tableaux à lignes, cases des impôts concernés
            $mod = DeclarationAnnuelle::modele($client);
            [$DEF, $TAB, $IMP, $ENT] = DeclarationAnnuelle::definitions($mod);
            $num = fn ($v) => $v === null || $v === '' ? null : (float) str_replace([' ', ','], ['', '.'], (string) $v);
            $f = ['modele' => $mod];
            foreach ($ENT as $k) { $v = $r->input("e.$k"); if ($v !== null && $v !== '') $f[$k] = is_string($v) ? trim($v) : $v; }
            $in = (array) $r->input('f');
            foreach ($DEF as [, , $L]) foreach ($L as $k => [, , $nat]) {
                if ($nat === 'c' || ! array_key_exists($k, $in)) continue;
                $v = $in[$k];
                $v = match ($nat) { 'm', 'r' => $num($v), 'x' => (bool) $v, default => ($v === null || trim((string) $v) === '') ? null : trim((string) $v) };
                if ($v !== null && $v !== false) $f[$k] = $v;
            }
            if (isset($in['taux'])) $f['taux'] = (float) $in['taux'];
            foreach ($TAB as $t => [, , $cols]) {
                $rows = [];
                foreach ((array) ($r->input("t.$t") ?? []) as $l) {
                    $l = array_intersect_key((array) $l, $cols);
                    foreach ($l as $c => $v) $l[$c] = in_array($cols[$c][2], ['m', 'r'], true) ? $num($v) : (trim((string) $v) === '' ? null : trim((string) $v));
                    if (array_filter($l, fn ($v) => $v !== null)) $rows[] = $l;
                }
                if ($rows) $f[$t] = $rows;
            }
            if ($r->boolean('imp_saisie')) $f['imp'] = array_values(array_intersect(array_keys($IMP), (array) $r->input('imp')));
            $d->champs = $f;
            $d->save();
            return back()->with('ok', 'Déclaration enregistrée.');
        }
        if ($type === 'men') {
            // Rubriques du modèle officiel : base, taux (gardé s'il diffère du taux par défaut) et montant (gardé s'il diffère du calcul)
            $num = fn ($v) => $v === null || $v === '' ? null : (float) str_replace([' ', ','], ['', '.'], (string) $v);
            $in = (array) $r->input('f');
            $f = ['acte' => in_array((string) $r->input('acte'), array_map('strval', array_keys(DeclarationMensuelle::ACTES)), true) ? (string) $r->input('acte') : '0'];
            if ($r->boolean('imp_saisie')) $f['imp'] = array_values(array_intersect(array_keys(DeclarationMensuelle::IMPOTS), (array) $r->input('imp')));
            foreach (DeclarationMensuelle::rubriques() as $k => [, $t0]) {
                $x = array_map($num, array_intersect_key((array) ($in[$k] ?? []), array_flip(['b', 't', 'm'])));
                if (isset($x['t']) && $t0 !== null && abs($x['t'] - $t0) < 0.00001) unset($x['t']);
                $x = array_filter($x, fn ($v) => $v !== null);
                if ($x) $f[$k] = $x;
            }
            $f['pen'] = array_filter(array_map($num, array_intersect_key((array) $r->input('pen'), DeclarationMensuelle::SECTIONS)), fn ($v) => $v !== null && $v != 0);
            $d->champs = $f;
            $d->save();
            return back()->with('ok', 'Déclaration enregistrée.');
        }
        $d->champs = collect((array) $r->input('f'))->map(fn ($v) => $v === null || $v === '' ? null : (float) str_replace(',', '.', $v))->all();
        $d->save();
        return back()->with('ok', 'Déclaration enregistrée.');
    }

    public function prefill(Request $r)
    {
        [$c, $type, $periode, $d] = $this->ctx($r);
        if ($d->exists && $d->verrouillee()) return back()->with('err', 'Déclaration verrouillée.');
        $msg = match ($type) { 'acp' => DeclarationAcompte::preremplir($c, $d), 'men' => $this->fillMen($c, $periode, $d), 'ann' => $this->fillAnn($c, (int) $periode, $d), 'sach', 'sven' => $this->fillSuspension($c, $type, $periode, $d), default => $this->fillEmp($c, (int) $periode, $d) };
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
        $regles = ['i' => 'nullable|integer|min:0', 'idk' => 'required|in:'.implode(',', DeclarationEmployeur::IDK[$ax]), 'id' => 'required|max:20', 'nom' => 'required|max:255',
            'adr' => 'nullable|max:255', 'act' => 'nullable|max:255', 'pm' => 'nullable|boolean'];
        foreach (DeclarationEmployeur::ZONES[$ax] as $k => [, $nat, $len]) {
            if ($nat === 'x') continue;
            $regles[$k] = match ($nat) {
                'm' => 'nullable|numeric|min:0|max:999999999999', 't' => 'nullable|numeric|min:0|max:100', 'n' => 'nullable|integer|min:0|max:'.(10 ** $len - 1),
                'd' => 'nullable|date', default => 'nullable|in:'.implode(',', array_keys(DeclarationEmployeur::CODES["$ax.$k"] ?? [])),
            };
        }
        $l = $r->validate($regles);
        $i = $l['i'] ?? null; unset($l['i']);
        $l['pm'] = $r->boolean('pm');
        if (array_key_exists('net', $l) && $l['net'] === null) $l['net'] = 0;   // net non saisi : montant servi − retenues
        $l = DeclarationEmployeur::normaliser($ax, $l);
        $A = $d->annexes ?? [];
        $L = $A[$ax] ?? [];
        if ($i !== null && isset($L[$i])) $L[$i] = $l; else $L[] = $l;
        $A[$ax] = array_values($L);
        $d->annexes = $A;
        $d->save();
        return back()->with('ok', ($i !== null ? 'Ligne modifiée dans l’' : 'Ligne ajoutée à l’').strtolower(trim(strtok(self::ANNEXES[$ax], ':'))).'.');
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
        if ($type === 'emp') {
            $d->champs = $this->enteteEmp($client, $d);
            return response()->download(DeclarationEmployeur::archive($client, $d), 'Declaration_employeur_'.$periode.'.zip')->deleteFileAfterSend();
        }
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

    /** Récapitulatif fiscal de l'année : montants des déclarations mensuelles, mois par mois (écran, impression, CSV). */
    public function recapitulatif(Request $r)
    {
        $client = $this->dossier($r);
        if (! $client) return view('compta.vide');
        $annee = (int) $r->input('annee', now()->year);
        $D = Declaration::where('client_id', $client->id)->where('type', 'men')->where('periode', 'like', "$annee-%")->get()->keyBy('periode');
        $lignes = []; $tot = ['sections' => array_fill_keys(array_keys(DeclarationMensuelle::SECTIONS), 0.0), 'bases' => array_fill_keys(array_keys(DeclarationMensuelle::SECTIONS), 0.0), 'pen' => 0.0, 'tot' => 0.0];
        for ($m = 1; $m <= 12; $m++) {
            $mo = sprintf('%d-%02d', $annee, $m);
            $d = $D[$mo] ?? null;
            if (! $d) { $lignes[$mo] = null; continue; }
            $c = DeclarationMensuelle::calcul($d->champs ?? []);
            $c['pen'] = r3(array_sum(array_column($c['sections'], 'pen')));
            $c['statut'] = $d->statut;
            foreach ($c['sections'] as $s => $x) { $tot['sections'][$s] = r3($tot['sections'][$s] + $x['du']); $tot['bases'][$s] = r3($tot['bases'][$s] + $x['base']); }
            $tot['pen'] = r3($tot['pen'] + $c['pen']); $tot['tot'] = r3($tot['tot'] + $c['tot']);
            $lignes[$mo] = $c;
        }
        if ($r->input('format') === 'csv') {
            $nn = fn ($v) => number_format((float) $v, 3, ',', '');
            $entetes = ['Mois'];
            foreach (DeclarationMensuelle::SECTIONS as $t) array_push($entetes, "$t – base", "$t – montant");
            $out = "\xEF\xBB\xBF".implode(';', array_merge($entetes, ['Crédit de TVA', 'Pénalités', 'Total à payer', 'Statut']))."\r\n";
            $paires = fn ($sec) => array_merge(...array_map(fn ($x) => [$nn($x['base']), $nn($x['du'])], array_values($sec)));
            foreach ($lignes as $mo => $l) $out .= implode(';', $l ? array_merge([$mo], $paires($l['sections']), [$nn($l['credit']), $nn($l['pen']), $nn($l['tot']), $l['statut']]) : [$mo, 'non établie'])."\r\n";
            $T = array_map(fn ($s) => ['base' => $tot['bases'][$s], 'du' => $tot['sections'][$s]], array_keys(DeclarationMensuelle::SECTIONS));
            $out .= implode(';', array_merge(['Total'], $paires($T), ['', $nn($tot['pen']), $nn($tot['tot'])]))."\r\n";
            return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"recapitulatif-fiscal-$annee.csv\""]);
        }
        return view('declarations.recap', ['client' => $client, 'annee' => $annee, 'lignes' => $lignes, 'tot' => $tot, 'clients' => Client::orderBy('nom')->get(['id', 'nom'])]);
    }

    /** Déclaration mensuelle sur le modèle officiel en arabe (التصريح الشهري بالأداءات), à imprimer. */
    public function arabe(Request $r)
    {
        [$client, $type, $periode, $d] = $this->ctx($r);
        abort_unless(in_array($type, ['men', 'ann', 'acp'], true), 404);
        if ($type === 'acp') return view('declarations.acompte_ar', ['client' => $client, 'periode' => $periode, 'd' => $d]);
        if ($type === 'ann') return view('declarations.annuelle_'.DeclarationAnnuelle::modele($client).'_ar', ['client' => $client, 'periode' => $periode, 'd' => $d]);
        return view('declarations.mensuelle_ar', ['client' => $client, 'periode' => $periode, 'd' => $d]);
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
            $out .= "Tableau récapitulatif\r\nCode;Libellé;Assiette;Taux;Retenue\r\n";
            foreach (DeclarationEmployeur::lignesRecap($d) as $code => $x) {
                $tx = DeclarationEmployeur::RECAP[$code][1];
                $out .= implode(';', ['="'.$code.'"', '"'.DeclarationEmployeur::RECAP[$code][0].'"', $nn($x['a']), is_numeric($tx) ? $tx : '', $nn($x['r'])])."\r\n";
            }
            $out .= ';Total des retenues;;;'.$nn(DeclarationEmployeur::totalRetenues($d))."\r\n\r\n";
            foreach (self::ANNEXES as $k => $t) {
                $z = array_filter(DeclarationEmployeur::ZONES[$k], fn ($x) => $x[1] !== 'x');
                $out .= "$t\r\n".implode(';', array_merge(['N°', 'Type id', 'Identifiant', 'Nom', 'Activité', 'Adresse'], array_map(fn ($x) => $x[0], $z)))."\r\n";
                foreach (array_values($d->annexes[$k] ?? []) as $i => $l) {
                    $l = DeclarationEmployeur::normaliser($k, $l);
                    $val = fn ($kk, $x) => match ($x[1]) { 'm' => $nn($l[$kk] ?? 0), 'd' => ! empty($l[$kk]) ? dfr($l[$kk]) : '', default => $l[$kk] ?? '' };
                    $out .= implode(';', array_merge([$i + 1, $l['idk'] ?? '', '"'.($l['id'] ?? '').'"', '"'.$l['nom'].'"', '"'.($l['act'] ?? '').'"', '"'.($l['adr'] ?? '').'"'],
                        array_map($val, array_keys($z), $z)))."\r\n";
                }
                $out .= "\r\n";
            }
        } else {
            if ($type === 'acp') {
                $f = $d->champs ?? []; $C = DeclarationAcompte::calcul($f);
                $out .= DeclarationAcompte::libelle($periode)."\r\nRubrique;Montant\r\n";
                foreach (DeclarationAcompte::LIGNES as $k => [$lib, , $nat]) $out .= '"'.$lib.'";'.$nn($nat === 'c' ? $C[$k] : ($f[$k] ?? 0))."\r\n";
                $out .= '"Total des déductions (II)";'.$nn($C['II'])."\r\n".'"Reste (III = I − II)";'.$nn($C['III'])."\r\n".'"Total à payer";'.$nn($C['tot'])."\r\n";
                return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"acompte-provisionnel-$periode.csv\""]);
            }
            if ($type === 'ann') {
                $mod = DeclarationAnnuelle::modele($client);
                $f = DeclarationAnnuelle::champs($d->champs ?? [], $client, (int) $periode);
                [$DEF, $TAB] = DeclarationAnnuelle::definitions($mod);
                $C = $mod === 'is' ? DeclarationAnnuelle::calculIs($f) : DeclarationAnnuelle::calculIr($f);
                $out .= ($mod === 'is' ? 'Impôt sur les sociétés' : 'Impôt sur le revenu')."\r\nSection;Rubrique;Valeur\r\n";
                foreach ($DEF as [$titre, , $L]) foreach ($L as $k => [$lib, , $nat]) {
                    $v = $k === 'ded_acp' ? ($C['acomptes'] ?? 0) : ($f[$k] ?? null);
                    if ($v === null || $v === '' || $v === false || (in_array($nat, ['m', 'r', 'c'], true) && ! (float) $v)) continue;
                    $out .= '"'.$titre.'";"'.$lib.'";'.(in_array($nat, ['m', 'r', 'c'], true) ? $nn($v) : ($nat === 'x' ? 'oui' : '"'.$v.'"'))."\r\n";
                }
                foreach ($TAB as $t => [$tt, , $cols]) if (! empty($f[$t])) {
                    $out .= "\r\n\"$tt\"\r\n".implode(';', array_map(fn ($x) => '"'.$x[0].'"', $cols))."\r\n";
                    foreach ($f[$t] as $l) $out .= implode(';', array_map(fn ($c) => in_array($cols[$c][2], ['m', 'r'], true) ? $nn($l[$c] ?? 0) : '"'.($l[$c] ?? '').'"', array_keys($cols)))."\r\n";
                }
                $out .= "\r\nSynthèse;Dû;Pénalités;Total\r\n";
                foreach ($C['synthese'] as $k => $x) $out .= '"'.DeclarationAnnuelle::LIB_SYNTHESE[$k].'";'.$nn($x['III']).';'.$nn($x['pen']).';'.$nn($x['tot'])."\r\n";
                $out .= 'Total à payer;;;'.$nn($C['tot'])."\r\n";
                return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"declaration-ann-$periode.csv\""]);
            }
            if ($type === 'men') {
                $f = DeclarationMensuelle::convertir($d->champs ?? []);
                $out .= "Section;Rubrique;Base;Taux;Montant\r\n";
                foreach (DeclarationMensuelle::LIGNES as $sec => $L) foreach ($L as $k => [$lib, , $mode]) {
                    $v = DeclarationMensuelle::valeur($f, $k);
                    if ($v['b'] || $v['m']) $out .= implode(';', ['"'.DeclarationMensuelle::SECTIONS[$sec].'"', '"'.$lib.'"', $nn($v['b']), in_array($mode, ['bt', 'tm', 'q'], true) ? $v['t'] : '', $nn($v['m'])])."\r\n";
                }
                $c = DeclarationMensuelle::calcul($f);
                $out .= "\r\nSynthèse;Montant dû;Pénalités\r\n";
                foreach ($c['sections'] as $sec => $x) $out .= '"'.DeclarationMensuelle::SECTIONS[$sec].'";'.$nn($x['du']).';'.$nn($x['pen'])."\r\n";
                $out .= 'Total à payer;'.$nn($c['tot'])."\r\n";
                return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"declaration-men-$periode.csv\""]);
            }
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
            $L = collect($d->annexes[$k] ?? [])->map(fn ($l) => DeclarationEmployeur::normaliser($k, $l));
            $R[$k] = ['t' => $t, 'n' => $L->count(), 'brut' => r3($L->sum('brut')), 'ret' => r3($L->sum('retenues')), 'net' => r3($L->sum('net'))];
        }
        return $R;
    }

    /**
     * Déclaration mensuelle (modèle DGI) : bases et montants repris
     * - des factures de vente validées du mois (chiffre d'affaires par taux de TVA, ventes en suspension, timbre),
     *   à défaut de la comptabilité (classe 70, 4367) ;
     * - des factures reçues validées (TVA déductible, immobilisations, retenues à la source par nature) ;
     * - de la paie clôturée du mois (IRPP, CSS, TFP, FOPROLOS) ;
     * - de la déclaration du mois précédent (crédit de TVA).
     */
    private function fillMen(Client $c, string $mo, Declaration $d): string
    {
        $from = $mo.'-01'; $to = Carbon::parse($from)->endOfMonth()->format('Y-m-d');
        $f = ['acte' => (string) ((DeclarationMensuelle::convertir($d->champs ?? []))['acte'] ?? '0')];
        $add = function (string $k, float $b, ?float $m = null) use (&$f) {
            $f[$k]['b'] = r3(($f[$k]['b'] ?? 0) + $b);
            if ($m !== null) $f[$k]['m'] = r3(($f[$k]['m'] ?? 0) + $m);
        };

        // Chiffre d'affaires et TVA collectée
        $ventes = FactureVente::where('client_id', $c->id)->where('statut', 'val')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get();
        $ca = 0.0; $tvac = 0.0;
        if ($ventes->count()) {
            foreach ($ventes as $v) {
                if ($v->tva_suspension) { $add('inf_vsusp', (float) $v->ht); $ca += (float) $v->ht; continue; }
                foreach ($v->lignesSaisie() as $l) {
                    $k = match ((int) round($l['tva_taux'])) { 7 => 'tva7', 13 => 'tva13', 19 => 'tva19', 0 => 'inf_caexo', default => 'tvax1' };
                    if ($k === 'tvax1') { $f['tvax1']['t'] = $l['tva_taux']; $add($k, $l['ht'], $l['tva']); }
                    else $add($k, $l['ht']);
                    $ca += $l['ht']; $tvac += $l['tva'];
                }
            }
            $timbres = $ventes->filter(fn ($v) => (float) $v->timbre > 0)->count();
            if ($timbres) $add('tim4', $timbres);
        } else {
            $S = fn ($pre, $side) => Compta::somme($c->id, $from, $to, $pre, $side);
            $ca = r3($S('70', 'credit') - $S('70', 'debit'));
            $tvac = r3($S('4367', 'credit') - $S('4367', 'debit'));
            $ratio = $ca > 0 ? $tvac / $ca * 100 : 0;
            $k = abs($ratio - 19) < .6 ? 'tva19' : (abs($ratio - 13) < .6 ? 'tva13' : (abs($ratio - 7) < .6 ? 'tva7' : ($tvac == 0 && $ca > 0 ? 'inf_caexo' : 'tva19')));
            if ($ca) $add($k, $ca);
        }
        if ($ca > 0) $add('tcl_loc', r3($ca + $tvac));

        // Achats : TVA déductible et retenues à la source
        $achats = FactureRecue::where('client_id', $c->id)->where('statut', 'val')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get();
        $nrs = 0;
        foreach ($achats as $x) {
            if ($x->tva_suspension) $add('inf_susp', (float) $x->ht);
            elseif ($x->tva_deductible && (float) $x->tva > 0) {
                $immos = $x->lignesImmobilisations();
                $ti = array_sum(array_column($immos, 'tva')); $hi = array_sum(array_column($immos, 'ht'));
                if ($ti > 0) $add('tvd_eql', $hi, $ti);
                $add('tvd_al', r3((float) $x->ht - $hi), r3((float) $x->tva - $ti));
            } elseif ((float) $x->tva == 0) $add('inf_exo', (float) $x->ht);
            if (($rs = $x->retenue()) > 0) {
                $cat = Compta::categorieRetenue($x); $tx = (float) $x->retenue_taux;
                $pm = (\App\Services\Exports\FichierDgi::matricule($x->matricule_fiscal)['cat'] ?? '') === 'M';
                $k = match (true) {
                    $cat === 'hon' && $tx <= 3 => 'rs6', $cat === 'hon' => 'rs5',
                    in_array($cat, ['loy', 'com', 'cou'], true) => $pm ? 'rs4b' : 'rs4a',
                    $tx >= 1.5 => 'rs17d', $tx >= 1 => 'rs17a', default => 'rs17b',
                };
                $add($k, r3((float) $x->ht + (float) $x->tva), $rs);
                $nrs++;
            }
        }

        // Paie du mois et paies spéciales (congé, 13ème mois…) déclarées avec ce mois
        $ps = Paie::where('client_id', $c->id)->where('mois', $mo)->where('statut', '!=', 'brouillon')->get();
        if ($ps->count()) {
            $brut = 0.0; $imp = 0.0;
            foreach ($ps as $p) foreach ($p->resultats ?? [] as $x) { $brut += (float) ($x['brut'] ?? 0); $imp += max(0, (float) ($x['brut_imp'] ?? $x['brut'] ?? 0) - (float) ($x['cnssS'] ?? 0)); }
            $T = fn ($k) => r3($ps->sum(fn ($p) => $p->totaux[$k] ?? 0));
            $add('rs1', r3($imp), $T('ir')); $add('rs3', r3($imp), $T('css'));
            if ($T('tfp')) $add('tfp2', r3($brut), $T('tfp'));
            if ($T('fop')) $add('fop', r3($brut), $T('fop'));
        }

        // Crédit de TVA du mois précédent
        $prev = Declaration::where(['client_id' => $c->id, 'type' => 'men', 'periode' => Carbon::parse($from)->subMonth()->format('Y-m')])->first();
        $credit = $prev ? DeclarationMensuelle::calcul($prev->champs ?? [])['credit'] : 0;
        if ($credit > 0) $f['tva_prec'] = ['m' => $credit];

        // Montants arrondis au millime ; un montant égal au calcul (base × taux) n'est pas figé
        foreach ($f as $k => $x) if (is_array($x) && isset($x['m']) && isset(DeclarationMensuelle::rubriques()[$k])) {
            if (abs(DeclarationMensuelle::valeur(array_diff_key($f, [$k => 0]) + [$k => array_diff_key($x, ['m' => 0])], $k)['calc'] - $x['m']) < 0.0005) unset($f[$k]['m']);
        }
        $ancien = DeclarationMensuelle::convertir($d->champs ?? []);
        $f['pen'] = $ancien['pen'] ?? [];
        if (isset($ancien['imp'])) $f['imp'] = $ancien['imp'];
        $d->champs = $f;
        return 'Pré-remplissage : chiffre d’affaires '.tnd($ca).' ('.$ventes->count().' facture(s) de vente), TVA collectée '.tnd($tvac).', '
            .$achats->count().' facture(s) reçue(s) dont '.$nrs.' avec retenue, '.($ps->count() ? 'paie du mois reprise' : 'aucune paie clôturée').'. Vérifiez chaque rubrique.';
    }

    /**
     * Déclaration annuelle : états financiers de l'exercice (et stock de l'exercice précédent), TVA collectée,
     * résultat fiscal de la liasse (F6005) ou du tableau de passage, acomptes et retenues du tableau de passage.
     */
    /** Bénéfices déduits au titre de l'exploitation et du réinvestissement : ligne F6005 => libellé de la ligne reprise dans la déclaration. */
    public const AVANTAGES_F6005 = [93 => 'Bénéfices au titre de l’exportation', 94 => 'Bénéfices au titre du développement régional',
        95 => 'Bénéfices au titre du développement agricole', 96 => 'Autres déductions au titre de l’exploitation', 99 => 'Revenus réinvestis'];

    private function fillAnn(Client $c, int $y, Declaration $d): string
    {
        $mod = DeclarationAnnuelle::modele($c);
        $f = (array) ($d->champs ?? []);
        $F = Compta::etats($c->id, $y); $P = $F['P'];
        $F0 = Compta::etats($c->id, $y - 1);
        $L = PassageFiscal::where('client_id', $c->id)->where('annee', $y)->first()?->donnees ?? [];
        $tvac = r3(Compta::somme($c->id, "$y-01-01", "$y-12-31", '4367', 'credit') - Compta::somme($c->id, "$y-01-01", "$y-12-31", '4367', 'debit'));
        // Tableau F6005 (cahier des charges de la liasse) : celui de la liasse fiscale (saisies comprises), à défaut celui du tableau de passage
        $V = \App\Services\TableauPassage::calcul(\App\Services\TableauPassage::annexe($c), $P['res'], $P['is'], $L)['F'];
        $source = $L ? 'tableau de passage' : 'résultat comptable';
        try {
            $Vl = \App\Services\LiasseFiscale::calculer($c, $y)['docs']['F6005']['n'] ?? [];
            if (! empty($Vl[55]) || ! empty($Vl[101])) { $V = $Vl; $source = 'liasse fiscale (F6005)'; }
        } catch (\Throwable) {
        }
        $rf = r3((float) $V[55]);                         // résultat fiscal avant déduction des provisions
        $imposable = max(0, r3((float) $V[101]));         // résultat imposable
        $avantAvantages = max(0, r3((float) $V[80]));     // avant déduction des bénéfices d'exploitation et du réinvestissement
        // Bénéfices déduits au titre de l'exploitation (F6005 93 à 96) et du réinvestissement (99)
        $avantages = array_filter(array_map(fn ($lib, $l) => ['cadre' => $lib, 'deduit' => r3((float) ($V[$l] ?? 0))], self::AVANTAGES_F6005, array_keys(self::AVANTAGES_F6005)),
            fn ($a) => $a['deduit'] != 0);
        $sansAuto = fn ($lignes) => array_values(array_filter((array) $lignes, fn ($l) => ! in_array($l['cadre'] ?? null, self::AVANTAGES_F6005, true)));
        $f['modele'] = $mod;
        $set = function (array $x) use (&$f) { foreach ($x as $k => $v) $f[$k] = $v; };
        if ($mod === 'is') {
            $set(['stk_deb' => r3($F0['g']['stk']), 'stk_fin' => r3($F['g']['stk']), 'achats' => r3($P['ach']), 'ca_loc' => r3($P['ca']), 'ca_ttc' => r3($P['ca'] + $tvac),
                'res_compta' => r3($P['res']), 'res_fiscal' => $rf, 'benef_av' => $avantAvantages, 'benef_net' => $imposable, 'ca_min_ttc' => r3($P['ca'] + $tvac), 'ca_min' => r3($P['ca'] + $tvac)]);
            // Lignes reprises du tableau de passage remplacées à chaque pré-remplissage, lignes saisies conservées
            $f['reinv'] = [...$sansAuto($f['reinv'] ?? []), ...array_map(fn ($a) => ['code' => '', 'cadre' => $a['cadre'], 'investi' => '', 'mf' => '', 'nom' => '', 'deduit' => $a['deduit']], array_values($avantages))];
            if (isset($L['taux'])) $f['taux'] = (float) $L['taux'];
            if (! empty($L['rs'])) $f['ded_rs'] = (float) $L['rs'];
            if (! empty($L['acp']) && empty($f['acomptes'])) $f['acomptes'] = [['montant' => (float) $L['acp']]];
        } else {
            DeclarationAnnuelle::preremplirIr($f, $c, $y, $P, $tvac, $rf, $L, $V);
        }
        $d->champs = $f;
        return 'Pré-remplissage depuis les états financiers '.$y.' et le '.$source.'. Vérifiez chaque rubrique avant le dépôt.';
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

    /**
     * Déclaration d'employeur : annexe I depuis la paie clôturée de l'année (une ligne par salarié),
     * annexes II et V depuis les factures reçues validées avec retenue (une ligne par fournisseur et nature).
     * Les lignes saisies à la main sont conservées ; le récapitulatif est recalculé depuis les annexes.
     */
    private function fillEmp(Client $c, int $y, Declaration $d): string
    {
        $A = collect($d->annexes ?? [])->map(fn ($L) => array_values(array_filter($L, fn ($l) => empty($l['auto']))))->all();
        $paies = Paie::where('client_id', $c->id)->where('mois', 'like', "$y-%")->where('statut', '!=', 'brouillon')->orderBy('mois')->get();
        $tot = [];
        foreach ($paies as $p) foreach ($p->resultats ?? [] as $x) {
            $k = $x['salarie_id'];
            $tot[$k] ??= ['imp' => 0, 'nat' => 0, 'ir' => 0, 'css' => 0, 'net' => 0, 'mois' => []];
            $tot[$k]['imp'] += max(0, (float) ($x['brut_imp'] ?? $x['brut'] ?? 0) - (float) ($x['cnssS'] ?? 0));
            $tot[$k]['nat'] += (float) ($x['nature'] ?? 0);
            $tot[$k]['ir'] += (float) ($x['ir'] ?? 0); $tot[$k]['css'] += (float) ($x['css'] ?? 0); $tot[$k]['net'] += (float) ($x['net'] ?? 0);
            $tot[$k]['mois'][] = substr($p->mois, 0, 7);
        }
        $sit = ['celibataire' => '1', 'marie' => '2', 'divorce' => '3', 'veuf' => '4'];
        $n1 = 0;
        foreach (Salarie::where('client_id', $c->id)->get() as $e) {
            if (! isset($tot[$e->id])) continue;
            $t = $tot[$e->id];
            $deb = Carbon::parse(min($t['mois']).'-01'); $fin = Carbon::parse(max($t['mois']).'-01')->endOfMonth();
            if ($e->date_embauche && Carbon::parse($e->date_embauche)->year === $y && Carbon::parse($e->date_embauche)->gt($deb)) $deb = Carbon::parse($e->date_embauche);
            if ($e->date_sortie && Carbon::parse($e->date_sortie)->year === $y && Carbon::parse($e->date_sortie)->lt($fin)) $fin = Carbon::parse($e->date_sortie);
            $etr = (bool) $e->retenue_etranger;
            $A['a1'][] = DeclarationEmployeur::normaliser('a1', ['auto' => 1, 'idk' => ! $e->cin && $e->carte_sejour ? '3' : '2', 'id' => $e->cin ?: $e->carte_sejour,
                'nom' => trim($e->nom.' '.$e->prenom), 'act' => $e->poste, 'adr' => trim(implode(' ', array_filter([$e->adresse, $e->code_postal, $e->ville]))),
                'sit' => $sit[$e->situation_familiale] ?? ($e->chef_famille ? '2' : '1'), 'enf' => min(99, (int) $e->enfants),
                'deb' => $deb->format('Y-m-d'), 'fin' => $fin->format('Y-m-d'), 'jours' => (int) $deb->diffInDays($fin) + 1,
                'imp' => r3($t['imp']), 'nat' => r3($t['nat']), 'ret' => $etr ? 0 : r3($t['ir']), 'ret_etr' => $etr ? r3($t['ir']) : 0, 'css' => r3($t['css']), 'net' => r3($t['net'])]);
            $n1++;
        }

        // Factures reçues avec retenue : regroupées par fournisseur et nature du montant
        $g = [];
        foreach (FactureRecue::where('client_id', $c->id)->where('statut', 'val')->whereYear('date', $y)->get() as $f) {
            if (($rs = $f->retenue()) <= 0) continue;
            $cat = Compta::categorieRetenue($f); $tx = (float) $f->retenue_taux;
            [$ax, $col, $type] = match (true) {
                $cat === 'hon' && $tx <= 3 => ['a2', 'm_reel', '1'],
                $cat === 'hon' => ['a2', 'm_res', '1'],
                $cat === 'loy' => ['a2', 'm_res', '4'],
                $cat === 'com' => ['a2', 'm_res', '2'],
                $cat === 'cou' => ['a2', 'm_res', '3'],
                $tx >= 1.5 => ['a5', 'm_aut', null],
                $tx >= 1 => ['a5', 'm_15', null],
                default => ['a5', 'm_10', null],
            };
            $mf = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $f->matricule_fiscal));
            $idk = preg_match('/^\d{1,8}$/', $mf) ? '2' : '1';
            $k = "$ax|$col|$type|".($mf ?: mb_strtolower(trim($f->fournisseur)));
            $g[$k] ??= ['auto' => 1, 'ax' => $ax, 'idk' => $idk, 'id' => $mf, 'nom' => $f->fournisseur, 'act' => '', 'adr' => '', 'nat' => $type, $col => 0, 'ret' => 0];
            $g[$k][$col] = r3($g[$k][$col] + (float) $f->ht + (float) $f->tva);
            $g[$k]['ret'] = r3($g[$k]['ret'] + $rs);
        }
        $n = ['a2' => 0, 'a5' => 0];
        foreach ($g as $l) {
            $ax = $l['ax']; unset($l['ax']);
            if ($l['nat'] === null) unset($l['nat']);
            $A[$ax][] = DeclarationEmployeur::normaliser($ax, $l + ['net' => 0]);
            $n[$ax]++;
        }
        $d->annexes = $A;
        $champs = $this->enteteEmp($c, $d);
        unset($champs['r']);
        $d->champs = $champs;
        return "Pré-remplissage : annexe I depuis la paie ($n1 salarié(s), ".$paies->count()." paie(s) clôturée(s)), annexe II : {$n['a2']} ligne(s), annexe V : {$n['a5']} ligne(s) "
            .'depuis les factures reçues avec retenue. Le récapitulatif est recalculé ; complétez les zones manquantes signalées par le contrôle.';
    }

    /** Entête du déposant (déclaration d'employeur) : saisie, sinon déclaration de l'année précédente, sinon fiche client. */
    private function enteteEmp(Client $c, Declaration $d): array
    {
        $adr = trim((string) $c->adresse);
        preg_match('/\b(\d{4})\b(?!.*\b\d{4}\b)/', $adr, $cp);
        preg_match('/^\s*(\d{1,4})\s*(?:,|bis\b|\s)\s*/i', $adr, $num);
        $rue = trim(preg_replace(['/^\s*\d{1,4}\s*(?:,|bis\b)?\s*/i', '/\b\d{4}\b(?!.*\b\d{4}\b)/'], '', $adr), " ,-");
        $defaut = ['acte' => '0', 'activite' => $c->paie_activite ?: (string) (((array) Setting::get('ef.'.$c->id, []))['activite'] ?? ''), 'ville' => '', 'rue' => $rue, 'num' => $num[1] ?? '', 'cp' => $cp[1] ?? ''];
        $prev = Declaration::where('client_id', $c->id)->where('type', 'emp')->where('id', '!=', $d->id ?? 0)->where('periode', '<', $d->periode)->orderByDesc('periode')->first()?->champs ?? [];
        $h = (array) ($d->champs ?? []);
        $cles = array_keys($defaut);
        $e = array_merge($defaut, array_filter(array_intersect_key((array) $prev, array_flip($cles)), fn ($v) => $v !== null && $v !== ''),
            array_filter(array_intersect_key($h, array_flip($cles)), fn ($v) => $v !== null && $v !== ''));
        if (isset($h['r'])) $e['r'] = $h['r'];
        return $e;
    }
}
