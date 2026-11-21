<?php

namespace App\Models;

use App\Models\Concerns\AppartientAuCabinet;
use App\Support\CabinetCourant;
use App\Support\Droits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Suivi des opérations : chaque action enregistrée (ajout, modification, suppression, validation, export) et chaque connexion. */
class JournalOperation extends Model
{
    use AppartientAuCabinet;

    public const UPDATED_AT = null;

    protected $table = 'journal_operations';
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];

    /** Libellés des actions, d'après le dernier mot du nom de la route. */
    private const ACTIONS = [
        'store' => 'Ajout', 'update' => 'Modification', 'destroy' => 'Suppression', 'valider' => 'Validation', 'rejeter' => 'Rejet',
        'toggle' => 'Changement d’état', 'basculer' => 'Changement d’état', 'statut' => 'Changement de statut', 'restaurer' => 'Restauration',
        'sauvegarde' => 'Sauvegarde', 'importer' => 'Import', 'import' => 'Import', 'pdf' => 'Export PDF', 'docx' => 'Export Word', 'csv' => 'Export',
        'teif' => 'Export TEIF', 'tej' => 'Export TEJ', 'download' => 'Téléchargement', 'telecharger' => 'Téléchargement', 'fichier' => 'Export de fichier',
        'cloturer' => 'Clôture', 'rouvrir' => 'Réouverture', 'convertir' => 'Conversion', 'partager' => 'Partage', 'partage' => 'Partage',
        'traiter' => 'Traitement', 'comptabiliser' => 'Comptabilisation', 'annuler' => 'Annulation', 'generer' => 'Génération',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function client() { return $this->belongsTo(Client::class); }

    /** Enregistre une requête traitée par l'application (appelé par le middleware de traçage). */
    public static function tracer(Request $r, User $u, int $statut): void
    {
        $route = $r->route()?->getName() ?? 'inconnue';
        $module = Droits::moduleDeRoute($route);
        self::create(['user_id' => $u->id, 'client_id' => self::clientDe($r), 'module' => $module, 'action' => Str::limit($route, 100, ''),
            'libelle' => Str::limit(self::libelleDe($route, $r->method()), 255, ''), 'methode' => $r->method(), 'url' => Str::limit($r->path(), 500, ''),
            'ip' => $r->ip(), 'statut' => $statut]);
    }

    /** Connexion, refus ou déconnexion, enregistré dans le cabinet de l'utilisateur. */
    public static function evenement(User $u, string $action, string $libelle, ?string $ip): void
    {
        CabinetCourant::pour($u->cabinet_id, fn () => self::create(['user_id' => $u->id, 'action' => $action, 'libelle' => $libelle, 'ip' => $ip, 'methode' => 'POST']));
    }

    public static function libelleDe(string $route, string $methode): string
    {
        $parts = explode('.', $route);
        $dernier = end($parts);
        $action = self::ACTIONS[$dernier] ?? ($methode === 'DELETE' ? 'Suppression' : ($methode === 'GET' ? 'Export' : 'Enregistrement'));
        $objet = array_slice($parts, 1, -1);
        if (! isset(self::ACTIONS[$dernier]) && $dernier !== ($parts[0] ?? '')) $objet[] = $dernier;
        $objet = trim(str_replace(['-', '_'], ' ', implode(' · ', $objet)));
        return Droits::libelleModule(Droits::moduleDeRoute($route)).' — '.$action.($objet !== '' ? ' ('.$objet.')' : '');
    }

    /** Dossier concerné : paramètre de route {client}, champ client_id / client, sinon dossier ouvert en session. */
    private static function clientDe(Request $r): ?int
    {
        $id = null;
        $p = $r->route()?->parameter('client');
        if ($p instanceof Client) $id = $p->id;
        else foreach ($r->route()?->parameters() ?? [] as $v) {
            if ($v instanceof Model && ! empty($v->getAttributes()['client_id'])) { $id = (int) $v->client_id; break; }
        }
        $id ??= $r->input('client_id') ?? $r->input('client') ?? session('dossier');
        if (! is_numeric($id)) return null;
        // Le client peut avoir été supprimé par l'opération elle-même
        return Client::whereKey((int) $id)->exists() ? (int) $id : null;
    }
}
