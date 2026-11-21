<?php

namespace App\Support;

use RuntimeException;

/**
 * Cabinet courant (multi-cabinet, spécification §4) : celui de l'utilisateur connecté.
 * En ligne de commande (seeders, imports), le cabinet est choisi explicitement avec utiliser().
 */
class CabinetCourant
{
    private static ?int $force = null;

    public static function id(): ?int
    {
        return self::$force ?? auth()->user()?->cabinet_id;
    }

    /** Vrai quand le cabinet est fixé explicitement (commande, tâche, espace client) plutôt que lu sur l'utilisateur connecté. */
    public static function estFixe(): bool
    {
        return self::$force !== null;
    }

    /** Exige un cabinet : à utiliser là où agir sans cabinet serait une fuite de données. */
    public static function exiger(): int
    {
        return self::id() ?? throw new RuntimeException('Aucun cabinet courant : opération refusée.');
    }

    /** Fixe le cabinet courant (commandes artisan, seeders, tâches en file). null pour revenir à l'utilisateur connecté. */
    public static function utiliser(?int $cabinet): void
    {
        self::$force = $cabinet;
    }

    /** Exécute $fn dans le contexte d'un cabinet, puis rétablit le contexte précédent. */
    public static function pour(int $cabinet, callable $fn): mixed
    {
        $avant = self::$force;
        self::$force = $cabinet;
        try {
            return $fn();
        } finally {
            self::$force = $avant;
        }
    }

    /**
     * Sans cabinet courant, les requêtes ne sont filtrées qu'en ligne de commande (maintenance, migrations).
     * Pendant une requête HTTP, l'absence de cabinet ne doit jamais donner accès aux données de tous.
     */
    public static function sansFiltreAutorise(): bool
    {
        return app()->runningInConsole() && ! app()->runningUnitTests();
    }
}
