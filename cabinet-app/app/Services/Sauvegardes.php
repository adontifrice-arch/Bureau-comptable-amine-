<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\Sauvegarde;
use App\Models\Setting;
use App\Support\CabinetCourant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Sauvegardes du cabinet : une archive ZIP par sauvegarde, avec un fichier JSON par élément choisi
 * (les données du cabinet seulement) et, pour l'élément « base », une copie complète de la base SQLite
 * (seulement si elle ne contient qu'un cabinet).
 *
 * Programmation (Paramètres → Sauvegardes) : quotidienne, hebdomadaire, mensuelle ou annuelle, à une heure donnée.
 * Exécution : commande « sauvegardes:auto » lancée chaque minute par le planificateur (schedule:run) ; à défaut,
 * une sauvegarde en retard est faite à la première page ouverte ensuite par un utilisateur du cabinet.
 */
class Sauvegardes
{
    /** Éléments sauvegardables : clé => [libellé, tables]. */
    public const ELEMENTS = [
        'base' => ['Base de données complète', []],
        'clients' => ['Clients (fiches, associés, échéances, documents, actes)', ['clients', 'associes', 'exigences', 'echeances', 'actes', 'modeles_actes', 'documents', 'client_users']],
        'compta' => ['Dossiers comptables', ['comptes', 'ecritures', 'ecriture_lignes', 'factures_recues', 'factures_ventes', 'modeles_saisie', 'modeles_factures', 'releves', 'releve_lignes']],
        'paie' => ['Paie', ['salaries', 'salarie_documents', 'salarie_historique', 'paies', 'paie_profils', 'paie_rubriques', 'conges', 'acomptes', 'prets']],
        'liasse' => ['Liasse fiscale', ['liasses', 'passages_fiscaux']],
        'immobilisations' => ['Immobilisations', ['immobilisations', 'amortissements_comptabilises']],
        'declarations' => ['Déclarations', ['declarations']],
        'audit' => ['Audit & CAC', ['missions_audit', 'constats_audit']],
        'facturation' => ['Facturation', ['factures', 'facture_lignes', 'services']],
        'frais' => ['Notes de frais et dépenses', ['notes_frais', 'notes_frais_documents', 'depenses']],
    ];

    public const FREQUENCES = ['jour' => 'Chaque jour', 'semaine' => 'Chaque semaine', 'mois' => 'Chaque mois', 'annee' => 'Chaque année'];

    /** Colonnes jamais copiées dans les fichiers JSON (secrets). */
    private const SECRETS = ['client_users' => ['password', 'jeton', 'remember_token']];

    public static function reglages(): array
    {
        return array_merge(['actif' => false, 'frequence' => 'jour', 'jour_semaine' => 1, 'jour_mois' => 1, 'date_annee' => '12-31', 'heure' => '20:00',
            'elements' => array_keys(self::ELEMENTS), 'conserver' => 10, 'depuis' => null], (array) Setting::get('sauvegarde', []));
    }

    /** Dossier des sauvegardes d'un cabinet (SAUVEGARDE_DOSSIER dans .env pour un autre disque, sinon storage/app/sauvegardes). */
    public static function dossier(int $cabinet): string
    {
        $racine = rtrim((string) (config('filesystems.sauvegardes') ?: storage_path('app'.DIRECTORY_SEPARATOR.'sauvegardes')), '\\/');
        return $racine.DIRECTORY_SEPARATOR.'cabinet-'.$cabinet;
    }

    // ---------- Programmation ----------

    /** Dernière date prévue au plus tard à $now. */
    public static function dernierePrevue(array $cfg, CarbonInterface $now): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $cfg['heure'] ?: '20:00') + [1 => 0]);
        $now = Carbon::instance($now);
        switch ($cfg['frequence']) {
            case 'semaine':
                $d = $now->copy()->startOfWeek()->addDays(max(1, min(7, (int) $cfg['jour_semaine'])) - 1)->setTime($h, $m);
                return $d->gt($now) ? $d->subWeek() : $d;
            case 'mois':
                $jour = max(1, min(31, (int) $cfg['jour_mois']));
                $d = $now->copy()->startOfMonth()->addDays(min($jour, $now->daysInMonth) - 1)->setTime($h, $m);
                if ($d->gt($now)) { $p = $now->copy()->startOfMonth()->subMonth(); $d = $p->addDays(min($jour, $p->daysInMonth) - 1)->setTime($h, $m); }
                return $d;
            case 'annee':
                [$mo, $jo] = array_map('intval', explode('-', $cfg['date_annee'] ?: '12-31') + [1 => 31]);
                $d = self::dateAnnuelle($now->year, $mo, $jo, $h, $m);
                return $d->gt($now) ? self::dateAnnuelle($now->year - 1, $mo, $jo, $h, $m) : $d;
            default:
                $d = $now->copy()->setTime($h, $m);
                return $d->gt($now) ? $d->subDay() : $d;
        }
    }

    private static function dateAnnuelle(int $y, int $mo, int $jo, int $h, int $m): Carbon
    {
        $debut = Carbon::create($y, max(1, min(12, $mo)), 1, $h, $m);
        return $debut->addDays(min(max(1, $jo), $debut->daysInMonth) - 1);
    }

    /** Prochaine date prévue après $now. */
    public static function prochaine(array $cfg, CarbonInterface $now): Carbon
    {
        $d = self::dernierePrevue($cfg, $now);
        return match ($cfg['frequence']) {
            'semaine' => $d->addWeek(), 'mois' => self::dernierePrevue($cfg, $d->copy()->addDays(32)),
            'annee' => self::dernierePrevue($cfg, $d->copy()->addYear()->addDay()), default => $d->addDay(),
        };
    }

    /** Vrai si une sauvegarde automatique est due (prévue depuis l'activation et pas encore faite). */
    public static function echue(array $cfg, CarbonInterface $now, ?CarbonInterface $derniere): bool
    {
        if (! $cfg['actif'] || ! $cfg['elements']) return false;
        $prevue = self::dernierePrevue($cfg, $now);
        if ($cfg['depuis'] && $prevue->lt(Carbon::parse($cfg['depuis']))) return false;
        return $derniere === null || $derniere->lt($prevue);
    }

    /** Sauvegarde automatique du cabinet si elle est due. Renvoie la sauvegarde faite, sinon null. */
    public static function executerSiEchue(int $cabinet): ?Sauvegarde
    {
        return CabinetCourant::pour($cabinet, function () use ($cabinet) {
            $cfg = self::reglages();
            if (! $cfg['actif']) return null;
            $derniere = Sauvegarde::where('declencheur', 'auto')->latest('id')->value('created_at');
            if (! self::echue($cfg, now(), $derniere ? Carbon::parse($derniere) : null)) return null;
            $verrou = Cache::lock('sauvegarde-auto-'.$cabinet, 600);
            if (! $verrou->get()) return null;                     // déjà en cours (planificateur et page ouverte en même temps)
            try {
                $derniere = Sauvegarde::where('declencheur', 'auto')->latest('id')->value('created_at');
                if (! self::echue($cfg, now(), $derniere ? Carbon::parse($derniere) : null)) return null;
                $s = self::creer($cabinet, $cfg['elements'], 'auto');
                self::purger($cabinet, max(1, (int) $cfg['conserver']));
                return $s;
            } finally {
                $verrou->release();
            }
        });
    }

    // ---------- Création ----------

    /** Crée une sauvegarde (archive ZIP) et l'inscrit dans l'historique, même en cas d'échec (statut « erreur »). */
    public static function creer(int $cabinet, array $elements, string $declencheur, ?int $user = null): Sauvegarde
    {
        return CabinetCourant::pour($cabinet, function () use ($cabinet, $elements, $declencheur, $user) {
            $elements = array_values(array_intersect(array_keys(self::ELEMENTS), $elements));
            $nom = 'sauvegarde-'.now()->format('Y-m-d-His').'-'.$declencheur.'.zip';
            $dir = self::dossier($cabinet);
            $chemin = $dir.DIRECTORY_SEPARATOR.$nom;
            $notes = [];
            $tmpBase = null;
            try {
                if (! is_dir($dir) && ! @mkdir($dir, 0775, true)) throw new RuntimeException('Dossier de sauvegarde impossible à créer : '.$dir);
                $zip = new ZipArchive();
                if ($zip->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Archive impossible à créer : '.$chemin);
                $resume = [];
                foreach ($elements as $el) {
                    if ($el === 'base') {
                        [$tmpBase, $note] = self::copieBase();
                        if ($tmpBase) $zip->addFile($tmpBase, 'base.sqlite');
                        if ($note) $notes[] = $note;
                        continue;
                    }
                    $data = [];
                    foreach (self::ELEMENTS[$el][1] as $table) {
                        if (! Schema::hasTable($table)) continue;
                        $rows = DB::table($table)->where('cabinet_id', $cabinet)->orderBy(Schema::hasColumn($table, 'id') ? 'id' : 'cabinet_id')->get()
                            ->map(fn ($r) => array_diff_key((array) $r, array_flip(self::SECRETS[$table] ?? [])))->all();
                        $data[$table] = $rows;
                        $resume[$el][$table] = count($rows);
                    }
                    $zip->addFromString($el.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
                $parametres = DB::table('settings')->where('cabinet_id', $cabinet)->get(['key', 'value'])->all();
                $zip->addFromString('parametres.json', json_encode($parametres, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $zip->addFromString('manifeste.json', json_encode(['application' => 'cabinet', 'cabinet' => ['id' => $cabinet, 'nom' => Cabinet::find($cabinet)?->nom],
                    'cree_le' => now()->toIso8601String(), 'declencheur' => $declencheur, 'elements' => $elements, 'lignes' => $resume, 'notes' => $notes],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                if (! $zip->close()) throw new RuntimeException('Écriture de l’archive impossible.');
                return Sauvegarde::create(['fichier' => $nom, 'taille' => filesize($chemin) ?: 0, 'elements' => $elements, 'declencheur' => $declencheur,
                    'user_id' => $user, 'statut' => 'ok', 'message' => $notes ? implode(' ', $notes) : null]);
            } catch (\Throwable $e) {
                report($e);
                if (is_file($chemin)) @unlink($chemin);
                return Sauvegarde::create(['fichier' => $nom, 'taille' => 0, 'elements' => $elements, 'declencheur' => $declencheur, 'user_id' => $user,
                    'statut' => 'erreur', 'message' => $e->getMessage()]);
            } finally {
                if ($tmpBase && is_file($tmpBase)) @unlink($tmpBase);
            }
        });
    }

    /** Copie cohérente de la base SQLite : [fichier temporaire|null, note|null]. */
    private static function copieBase(): array
    {
        if (config('database.default') !== 'sqlite') return [null, 'Base complète non incluse : base autre que SQLite (utilisez les outils de sauvegarde du serveur de base de données).'];
        if (Cabinet::query()->count() > 1) return [null, 'Base complète non incluse : elle contient d’autres cabinets (les données de ce cabinet sont dans les fichiers JSON).'];
        $tmp = tempnam(sys_get_temp_dir(), 'base');
        @unlink($tmp);
        try {
            DB::statement('VACUUM INTO ?', [$tmp]);
        } catch (\Illuminate\Database\QueryException) {
            $fichier = (string) config('database.connections.sqlite.database');
            if (! is_file($fichier) || ! copy($fichier, $tmp)) return [null, 'Base complète non incluse : copie impossible pour le moment.'];
        }
        return [$tmp, null];
    }

    /** Ne garde que les $n dernières sauvegardes automatiques réussies. */
    public static function purger(int $cabinet, int $n): void
    {
        CabinetCourant::pour($cabinet, function () use ($cabinet, $n) {
            Sauvegarde::where('declencheur', 'auto')->latest('id')->skip($n)->take(PHP_INT_MAX)->get()
                ->each(fn (Sauvegarde $s) => self::supprimer($s, $cabinet));
        });
    }

    public static function supprimer(Sauvegarde $s, int $cabinet): void
    {
        $f = self::dossier($cabinet).DIRECTORY_SEPARATOR.basename($s->fichier);
        if (is_file($f)) @unlink($f);
        $s->delete();
    }

    public static function chemin(Sauvegarde $s): string
    {
        return self::dossier((int) $s->cabinet_id).DIRECTORY_SEPARATOR.basename($s->fichier);
    }
}
