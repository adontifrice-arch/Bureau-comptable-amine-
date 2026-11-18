<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Compte;
use App\Models\Ecriture;
use App\Services\Compta;
use App\Services\Ecritures;
use App\Services\LecteurBalance;
use App\Services\Saisie;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Reprise d'un dossier : import de la balance N-1 (PDF, Excel, Word), contrôlée à l'écran puis passée en une écriture
 * au journal des à-nouveaux le 31/12/N-1. La balance N en reprend les comptes de bilan (classes 1 à 5) et le résultat N-1
 * (compte 121) ; la balance N-1 sert de colonne N-1 aux états financiers et à la liasse.
 */
class BalanceN1Controller extends Controller
{
    public const FICHIER = 'required|file|max:20480|mimes:pdf,xlsx,xls,ods,csv,txt,docx,doc,rtf';

    private static function cle(int $client): string
    {
        return 'balance_n1.'.$client;
    }

    /** Source de l'écriture d'import : une seule par exercice, remplacée par un nouvel import. */
    public static function source(int $annee): string
    {
        return 'bal:'.($annee - 1);
    }

    public function index(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $import = $r->session()->get(self::cle($client->id));
        $annee = (int) ($import['annee'] ?? $r->input('annee', now()->year));
        $existante = Ecriture::where('client_id', $client->id)->where('source', self::source($annee))->first();
        $autres = Ecriture::where('client_id', $client->id)->whereDate('date', '<=', ($annee - 1).'-12-31')
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', self::source($annee)))->count();
        return view('compta.balance-n1', ['client' => $client, 'import' => $import, 'annee' => $annee, 'existante' => $existante, 'autres' => $autres,
            'plan' => Compta::plan(), 'attente' => Saisie::banque($client->id)['attente'], 'chef' => $r->user()->estChef()]);
    }

    public function lire(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $d = $r->validate(['fichier' => self::FICHIER, 'annee' => 'required|integer|min:2000|max:2100'], self::messages());
        if ($err = self::lireFichier($r, $client, $d['fichier'], (int) $d['annee'])) return back()->with('err', $err);
        return redirect()->route('compta.balance-n1');
    }

    /** Lit le fichier et garde le résultat en session pour le contrôle à l'écran ; renvoie un message d'erreur, ou null. */
    public static function lireFichier(Request $r, Client $client, UploadedFile $f, int $annee): ?string
    {
        try {
            $res = LecteurBalance::lire($f->getRealPath(), $f->getClientOriginalExtension() ?: $f->extension());
        } catch (\Throwable $e) {
            return 'Balance non lue : '.($e instanceof \RuntimeException ? $e->getMessage() : 'le fichier n’a pas pu être lu.');
        }
        if (! $res['lignes']) {
            return 'Aucun compte n’a été reconnu dans « '.$f->getClientOriginalName().' ». '
                .($res['methode'] === 'pdf' ? 'S’il s’agit d’un PDF scanné, exportez la balance en Excel depuis le logiciel d’origine.' : 'Vérifiez que la balance comporte les numéros de compte et les colonnes Débit / Crédit.');
        }
        $r->session()->put(self::cle($client->id), ['annee' => $annee, 'fichier' => $f->getClientOriginalName(), 'methode' => $res['methode'], 'lignes' => $res['lignes']]);
        return null;
    }

    public function abandonner(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $r->session()->forget(self::cle($client->id));
        return redirect()->route('compta.balance-n1')->with('ok', 'Import abandonné.');
    }

    public function enregistrer(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $d = $r->validate(['annee' => 'required|integer|min:2000|max:2100', 'lignes' => 'required|json', 'ecart' => 'nullable|boolean',
            'compte_ecart' => 'nullable|regex:/^\d{1,10}$/', 'ajouter_comptes' => 'nullable|boolean']);
        $annee = (int) $d['annee'];
        $L = collect(json_decode($d['lignes'], true))->map(fn ($l) => ['compte' => trim((string) ($l['compte'] ?? '')), 'libelle' => trim((string) ($l['libelle'] ?? '')),
            'debit' => r3(abs((float) ($l['debit'] ?? 0))), 'credit' => r3(abs((float) ($l['credit'] ?? 0)))])
            ->filter(fn ($l) => $l['compte'] !== '' && ($l['debit'] > 0 || $l['credit'] > 0))->values();
        if ($bad = $L->first(fn ($l) => ! preg_match('/^\d{1,10}$/', $l['compte']))) return back()->with('err', 'Compte invalide : « '.$bad['compte'].' » (chiffres uniquement).');
        if ($L->count() < 2) return back()->with('err', 'La balance doit comporter au moins deux comptes soldés.');

        $ecart = r3($L->sum('debit') - $L->sum('credit'));
        if (abs($ecart) > 0.0005) {
            if (! $r->boolean('ecart') || empty($d['compte_ecart'])) {
                return back()->with('err', 'Balance déséquilibrée : total débit '.tnd($L->sum('debit')).' ≠ total crédit '.tnd($L->sum('credit'))
                    .'. Corrigez les lignes ou cochez « passer l’écart sur un compte d’attente ».');
            }
            $L->push(['compte' => $d['compte_ecart'], 'libelle' => 'Écart de reprise de la balance '.($annee - 1), 'debit' => max(-$ecart, 0), 'credit' => max($ecart, 0)]);
        }

        $ajoutes = 0;
        DB::transaction(function () use ($r, $client, $annee, $L, &$ajoutes) {
            // Un nouvel import remplace le précédent (conservé dans « Pièces supprimées »)
            foreach (Ecriture::where('client_id', $client->id)->where('source', self::source($annee))->get() as $old) {
                Ecritures::supprimer($old, $r->user(), 'Remplacée par un nouvel import de la balance '.($annee - 1));
            }
            if ($r->boolean('ajouter_comptes')) {
                $plan = Compta::plan();
                foreach ($L->unique('compte') as $l) {
                    if (isset($plan[$l['compte']]) || $l['libelle'] === '') continue;
                    Compte::create(['numero' => $l['compte'], 'libelle' => \Illuminate\Support\Str::limit($l['libelle'], 255, ''), 'personnalise' => true]);
                    $ajoutes++;
                }
            }
            Compta::passer($client->id, ($annee - 1).'-12-31', 'AN', 'Balance '.($annee - 1).' reprise (à-nouveaux '.$annee.')',
                $L->map(fn ($l) => ['compte' => $l['compte'], 'debit' => $l['debit'], 'credit' => $l['credit']])->all(), self::source($annee), $r->user()->id);
        });
        $r->session()->forget(self::cle($client->id));
        $this->log('Import de la balance '.($annee - 1).' ('.$L->count().' comptes)', $client->id);
        return redirect()->route('compta.index', ['tab' => 'bal', 'annee' => $annee])->with('ok', 'Balance '.($annee - 1).' reprise : '.$L->count().' comptes passés au journal des à-nouveaux le 31/12/'.($annee - 1)
            .($ajoutes ? ', '.$ajoutes.' compte(s) ajouté(s) au plan comptable' : '').'. La balance '.$annee.' part de ces soldes.');
    }

    public static function messages(): array
    {
        return ['fichier.mimes' => 'Formats acceptés : PDF, Excel (.xlsx, .xls, .csv) ou Word (.docx, .doc, .rtf).', 'fichier.max' => 'Le fichier dépasse 20 Mo.'];
    }
}
