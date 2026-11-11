<?php

namespace App\Services\Actes;

use App\Models\Setting;

/**
 * Ordre du jour des PV (spécification §3.2) : points usuels par type d'assemblée et points personnalisés
 * du cabinet, conservés d'un PV à l'autre. L'ordre du PV est celui de la liste ; les points personnalisés
 * se placent avant les points de clôture (pouvoirs, questions diverses).
 */
class OrdreDuJour
{
    public const TYPES = ['ago', 'age', 'mixte', 'cess'];
    private const CLE = 'actes.ordre_du_jour';
    private const CLOTURE = '/^(Pouvoirs|Questions diverses)/u';

    /** Points personnalisés enregistrés, par type. */
    public static function perso(): array
    {
        return array_merge(array_fill_keys(self::TYPES, []), (array) Setting::get(self::CLE, []));
    }

    /** Points usuels d'un type (AG mixte = points de l'AGO puis de l'AGE). */
    private static function usuels(string $type): array
    {
        $odd = config('cabinet.ODD');
        return $type === 'mixte' ? array_values(array_unique(array_merge($odd['ago'], $odd['age']))) : ($odd[$type] ?? []);
    }

    /**
     * Liste ordonnée d'un type : [['texte' => …, 'type' => type d'origine si point personnalisé, sinon null], …]
     */
    public static function points(string $type): array
    {
        $perso = self::perso();
        $ajouts = [];
        foreach ($type === 'mixte' ? ['ago', 'age', 'mixte'] : [$type] as $t) {
            foreach ($perso[$t] as $p) $ajouts[$p] ??= $t;
        }
        $usuels = self::usuels($type);
        $corps = array_filter($usuels, fn ($p) => ! preg_match(self::CLOTURE, $p));
        $cloture = array_filter($usuels, fn ($p) => preg_match(self::CLOTURE, $p));

        $liste = array_map(fn ($p) => ['texte' => $p, 'type' => null], $corps);
        foreach ($ajouts as $p => $t) if (! in_array($p, $usuels, true)) $liste[] = ['texte' => $p, 'type' => $t];
        foreach ($cloture as $p) $liste[] = ['texte' => $p, 'type' => null];
        return array_values($liste);
    }

    /** Listes de tous les types, pour le formulaire de rédaction. */
    public static function tous(): array
    {
        return array_combine(self::TYPES, array_map([self::class, 'points'], self::TYPES));
    }

    public static function ajouter(string $type, string $point): void
    {
        $point = self::normaliser($point);
        if ($point === '' || in_array($point, array_column(self::points($type), 'texte'), true)) return;
        $perso = self::perso();
        $perso[$type][] = $point;
        Setting::put(self::CLE, $perso);
    }

    public static function retirer(string $type, string $point): void
    {
        $perso = self::perso();
        $perso[$type] = array_values(array_filter($perso[$type], fn ($p) => $p !== $point));
        Setting::put(self::CLE, $perso);
    }

    public static function normaliser(string $point): string
    {
        return trim(preg_replace('/\s+/u', ' ', $point));
    }
}
