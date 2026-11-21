<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Droits des utilisateurs du cabinet, fixés par le chef comptable (qui a toujours tous les droits) :
 * - modules visibles (menu et pages : une page d'un module non autorisé est refusée) ;
 * - dossiers autorisés (toutes les données rattachées à un client sont filtrées, voir AppartientAuCabinet) ;
 * - accès hors du réseau du cabinet (adresses IP déclarées dans Paramètres → Utilisateurs).
 */
class Droits
{
    /** Modules de l'application : clé => [libellé, motifs des noms de routes]. */
    public const MODULES = [
        'clients' => ['Clients', ['clients.*', 'actes.*', 'modeles-actes.*']],
        'echanges' => ['Échanges clients', ['echanges.*']],
        'echeances' => ['Échéances', ['echeances.*']],
        'facturation' => ['Facturation', ['factures.*', 'ca.*']],
        'frais' => ['Notes de frais', ['frais.*', 'depenses.*']],
        'interne' => ['Interne', ['interne.*']],
        'compta' => ['Comptabilité', ['compta.*']],
        'immobilisations' => ['Immobilisations', ['immobilisations.*']],
        'paie' => ['Paie', ['paie.*']],
        'liasse' => ['Liasse fiscale', ['liasse.*']],
        'declarations' => ['Déclarations', ['declarations.*']],
        'audit' => ['Audit & CAC', ['audit.*']],
        'ged' => ['GED', ['ged.*']],
    ];

    private static array $dossiers = [];
    private static array $colonneClient = [];

    /** Module auquel appartient une route (null : page commune, comme le tableau de bord ou « Mon compte »). */
    public static function moduleDeRoute(?string $route): ?string
    {
        if (! $route) return null;
        foreach (self::MODULES as $cle => [, $motifs]) {
            foreach ($motifs as $m) if (\Illuminate\Support\Str::is($m, $route)) return $cle;
        }
        return null;
    }

    public static function libelleModule(?string $cle): string
    {
        return self::MODULES[$cle][0] ?? 'Général';
    }

    /** Utilisateur du cabinet dont les droits s'appliquent : aucun hors requête web ou quand le cabinet est fixé explicitement. */
    public static function utilisateur(): ?User
    {
        if (CabinetCourant::estFixe()) return null;
        $u = Auth::guard('web')->user();
        return $u instanceof User ? $u : null;
    }

    /**
     * Dossiers (clients) autorisés de l'utilisateur courant ; null quand il n'y a pas de restriction
     * (chef comptable, « tous les dossiers », ligne de commande, espace client).
     */
    public static function dossiers(): ?array
    {
        $u = self::utilisateur();
        if (! $u || $u->estChef() || ($u->tous_dossiers ?? true)) return null;
        return self::$dossiers[$u->id] ??= DB::table('user_dossiers')->where('user_id', $u->id)->pluck('client_id')->map(fn ($id) => (int) $id)->all();
    }

    /** À appeler après une modification des dossiers autorisés. */
    public static function oublier(): void
    {
        self::$dossiers = [];
    }

    public static function dossierAutorise(?int $client): bool
    {
        $ids = self::dossiers();
        return $ids === null || $client === null || in_array($client, $ids, true);
    }

    public static function aColonneClient(string $table): bool
    {
        return self::$colonneClient[$table] ??= Schema::hasColumn($table, 'client_id');
    }

    // ---------- Réseau du cabinet ----------

    /** Adresses IP ou plages (CIDR) du réseau du cabinet, une par ligne. */
    public static function adressesReseau(): array
    {
        $txt = (string) (Setting::get('reseau', [])['adresses'] ?? '');
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $txt) ?: [])));
    }

    /** Vrai si l'utilisateur peut travailler depuis cette adresse. */
    public static function reseauAutorise(User $u, ?string $ip): bool
    {
        if ($u->estChef() || ($u->acces_distant ?? true)) return true;
        $adresses = CabinetCourant::pour($u->cabinet_id, fn () => self::adressesReseau());
        if (! $adresses) return true;                                   // réseau non déclaré : pas de contrôle possible
        return $ip !== null && IpUtils::checkIp($ip, $adresses);
    }

    public static function adresseValide(string $a): bool
    {
        [$ip, $masque] = array_pad(explode('/', $a, 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) return false;
        if ($masque === null) return true;
        $max = str_contains($ip, ':') ? 128 : 32;
        return ctype_digit($masque) && (int) $masque <= $max;
    }
}
