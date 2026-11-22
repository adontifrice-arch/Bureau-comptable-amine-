<?php

namespace App\Support;

use App\Models\Client;

/** Mentions communes des documents de paie remis au salarié : signataire de l'employeur, ville, logo. */
final class DocPaie
{
    /** @return array{k: array, resp: string, respQ: string, ville: string, logo: ?string} */
    public static function employeur(Client $client, bool $pdf = false): array
    {
        $k = $client->paie_config ?? [];
        $logo = null;
        if (! empty($k['afficher_logo']) && ! empty($k['logo']) && is_file($f = storage_path('app/public/'.$k['logo']))) {
            $logo = $pdf ? 'data:image/'.pathinfo($f, PATHINFO_EXTENSION).';base64,'.base64_encode(file_get_contents($f)) : asset('storage/'.$k['logo']);
        }
        return ['k' => $k, 'resp' => $k['responsable_nom'] ?? $client->gerant_nom ?? '……………………', 'respQ' => $k['responsable_qualification'] ?? 'Gérant',
            'ville' => $k['ville'] ?? '……………', 'logo' => $logo];
    }
}
