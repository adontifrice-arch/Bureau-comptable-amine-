<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Compte;
use App\Models\Ecriture;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sauvegarde de la comptabilité :
 * - du dossier ouvert : fichier JSON (écritures et lignes avec leur lettrage, pièces supprimées comprises, paramétrage de la saisie,
 *   journaux ajoutés et leurs comptes associés, comptes ajoutés au plan comptable), restaurable par le chef comptable ;
 * - de toute la base (chef comptable) : copie du fichier SQLite, seulement si la base ne contient qu'un cabinet.
 */
class SauvegardeComptaController extends Controller
{
    public const FORMAT = 'cabinet-compta';
    private const REGLAGES = ['saisie', 'journaux', 'journaux_comptes', 'rapprochement'];

    /** Contenu de la sauvegarde d'un dossier. */
    public static function contenu(Client $client): array
    {
        return [
            'format' => self::FORMAT, 'version' => 1, 'exporte_le' => now()->toIso8601String(),
            'dossier' => ['id' => $client->id, 'nom' => $client->nom, 'matricule_fiscal' => $client->matricule_fiscal],
            'reglages' => collect(self::REGLAGES)->mapWithKeys(fn ($k) => [$k => Setting::get($k.'.'.$client->id)])->all(),
            'comptes' => Compte::where('personnalise', true)->orderBy('numero')->get(['numero', 'libelle'])->toArray(),
            'ecritures' => Ecriture::withTrashed()->with('lignes')->where('client_id', $client->id)->orderBy('date')->orderBy('id')->get()
                ->map(fn ($e) => ['date' => $e->date->format('Y-m-d'), 'journal' => $e->journal, 'libelle' => $e->libelle, 'source' => $e->source,
                    'supprimee_le' => $e->deleted_at?->toIso8601String(), 'motif_suppression' => $e->motif_suppression, 'saisie_le' => $e->created_at?->toIso8601String(),
                    'lignes' => $e->lignes->map(fn ($l) => ['compte' => $l->compte, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit,
                        'lettre' => $l->lettre, 'lettre_le' => $l->lettre_le, 'pointe_le' => $l->pointe_le])->all()])->all(),
        ];
    }

    public function telecharger(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $data = self::contenu($client);
        $this->log('Comptabilité : sauvegarde du dossier ('.count($data['ecritures']).' écritures)', $client->id);
        return response()->streamDownload(fn () => print(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            'sauvegarde-compta-'.Str::slug($client->nom).'-'.now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json']);
    }

    /**
     * Restauration dans le dossier ouvert. Un dossier qui a déjà des écritures n'est remplacé que sur demande expresse ;
     * sa comptabilité actuelle est d'abord sauvegardée sur le serveur (storage/app/sauvegardes).
     * Les références aux pièces d'origine (factures, relevés, paies) ne sont gardées que dans le dossier sauvegardé.
     */
    public function restaurer(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $r->validate(['fichier' => 'required|file|max:51200', 'remplacer' => 'nullable|boolean']);
        $data = json_decode((string) file_get_contents($r->file('fichier')->getRealPath()), true);
        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ! is_array($data['ecritures'] ?? null)) {
            return back()->with('err', 'Ce fichier n’est pas une sauvegarde de comptabilité.');
        }
        $existantes = Ecriture::withTrashed()->where('client_id', $client->id)->count();
        if ($existantes && ! $r->boolean('remplacer')) {
            return back()->with('err', 'Le dossier '.$client->nom.' a déjà '.$existantes.' écriture(s). Cochez « remplacer la comptabilité actuelle » pour restaurer la sauvegarde à sa place.');
        }

        $copie = null;
        if ($existantes) {
            $copie = 'sauvegardes/compta-'.Str::slug($client->nom).'-avant-restauration-'.now()->format('Y-m-d-His').'.json';
            Storage::put($copie, json_encode(self::contenu($client), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        $memeDossier = (int) ($data['dossier']['id'] ?? 0) === $client->id;
        $n = DB::transaction(function () use ($r, $client, $data, $memeDossier) {
            Ecriture::withTrashed()->where('client_id', $client->id)->get()->each(fn ($e) => $e->forceDelete());
            foreach (self::REGLAGES as $k) if (array_key_exists($k, (array) ($data['reglages'] ?? []))) Setting::put($k.'.'.$client->id, $data['reglages'][$k] ?? []);
            $plan = Compte::pluck('numero')->flip();
            foreach ((array) ($data['comptes'] ?? []) as $c) {
                if (! preg_match('/^\d{1,10}$/', (string) ($c['numero'] ?? '')) || isset($plan[$c['numero']]) || trim((string) ($c['libelle'] ?? '')) === '') continue;
                Compte::create(['numero' => $c['numero'], 'libelle' => Str::limit(trim($c['libelle']), 255, ''), 'personnalise' => true]);
            }
            $n = 0;
            foreach ($data['ecritures'] as $x) {
                $L = collect($x['lignes'] ?? [])->filter(fn ($l) => preg_match('/^\d{1,10}$/', (string) ($l['compte'] ?? '')) && ((float) ($l['debit'] ?? 0) || (float) ($l['credit'] ?? 0)));
                if ($L->count() < 2 || ! strtotime((string) ($x['date'] ?? ''))) continue;
                $source = $x['source'] ?? null;
                if ($source && ! $memeDossier && ! preg_match('/^(bal|amo):/', $source)) $source = null;   // pièces d'un autre dossier
                $e = Ecriture::create(['client_id' => $client->id, 'date' => $x['date'], 'journal' => Str::limit((string) ($x['journal'] ?? 'OD'), 10, ''),
                    'libelle' => Str::limit((string) ($x['libelle'] ?? ''), 255, '') ?: 'Écriture', 'source' => $source, 'valide_par' => $r->user()->id]);
                foreach ($L as $l) $e->lignes()->create(['compte' => $l['compte'], 'debit' => r3($l['debit'] ?? 0), 'credit' => r3($l['credit'] ?? 0),
                    'lettre' => preg_match('/^[A-Z]{1,6}$/', (string) ($l['lettre'] ?? '')) ? $l['lettre'] : null, 'lettre_le' => $l['lettre_le'] ?? null,
                    'pointe_le' => $l['pointe_le'] ?? null]);
                if (! empty($x['supprimee_le'])) {
                    $e->forceFill(['supprime_par' => $r->user()->id, 'motif_suppression' => $x['motif_suppression'] ?? null])->save();
                    $e->delete();
                }
                $n++;
            }
            return $n;
        });
        \App\Services\Compta::oublierPlan();
        $this->log('Comptabilité : restauration d’une sauvegarde ('.$n.' écritures)'.($copie ? ', ancienne comptabilité conservée dans '.$copie : ''), $client->id);
        return redirect()->route('compta.index', ['tab' => 'jnl'])->with('ok', $n.' écriture(s) restaurée(s) dans '.$client->nom.'.'
            .($copie ? ' La comptabilité remplacée a été sauvegardée sur le serveur ('.$copie.').' : ''));
    }

    /** Copie de toute la base (SQLite), réservée au chef comptable d'une installation à un seul cabinet. */
    public function base(Request $r)
    {
        abort_unless($r->user()->estChef(), 403);
        if (config('database.default') !== 'sqlite') return back()->with('err', 'La copie de la base n’est disponible qu’avec une base SQLite. Utilisez les outils de sauvegarde de votre serveur de base de données.');
        if (Cabinet::query()->count() > 1) return back()->with('err', 'La base contient plusieurs cabinets : sa copie complète n’est pas proposée. Utilisez la sauvegarde de chaque dossier.');
        $tmp = tempnam(sys_get_temp_dir(), 'base');
        @unlink($tmp);                                           // VACUUM INTO exige un fichier inexistant
        try {
            DB::statement('VACUUM INTO ?', [$tmp]);              // copie cohérente, même pendant l'utilisation de l'application
        } catch (\Illuminate\Database\QueryException) {
            // VACUUM refusé (transaction en cours) : copie directe du fichier de la base
            $fichier = (string) config('database.connections.sqlite.database');
            if (! is_file($fichier) || ! copy($fichier, $tmp)) return back()->with('err', 'La copie de la base n’a pas pu être faite. Réessayez dans un instant.');
        }
        $this->log('Sauvegarde complète de la base');
        return response()->download($tmp, 'sauvegarde-base-cabinet-'.now()->format('Y-m-d-His').'.sqlite', ['Content-Type' => 'application/vnd.sqlite3'])->deleteFileAfterSend();
    }
}
