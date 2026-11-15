<?php

namespace App\Services\Releves;

use App\Models\Client;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\ModeleSaisie;
use App\Models\Releve;
use App\Models\ReleveLigne;
use App\Services\Saisie;
use App\Support\CabinetCourant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import d'un relevé bancaire dans un dossier : lecture du fichier, lignes déjà importées écartées,
 * contrepartie proposée pour chaque ligne (règles du paramétrage, tiers connus, sinon compte d'attente).
 */
class ImportReleve
{
    /** @return array{0: Releve, 1: int, 2: ?array} le relevé créé, le nombre de lignes déjà importées écartées, le contrôle des totaux (PDF) */
    public static function importer(Client $client, UploadedFile $fichier, ?int $user): array
    {
        $ext = strtolower($fichier->getClientOriginalExtension());
        if (! in_array($ext, LecteurReleve::EXTENSIONS, true)) {
            throw new RuntimeException('Format non pris en charge : '.$ext.'. Formats acceptés : Excel, CSV, Word (.docx), PDF ou image.');
        }
        $chemin = $fichier->getRealPath();
        $lu = LecteurReleve::lire($chemin, $ext);
        ['methode' => $methode, 'lignes' => $lignes] = $lu;
        $controle = $lu['controle'] ?? null;

        // Rien de lisible en local (PDF scanné, image) ou sens des opérations indéterminable : lecture par l'IA si elle est configurée
        $incertain = $lignes && collect($lignes)->every(fn ($l) => $l['sens_incertain']);
        if ((! $lignes || $incertain) && config('services.anthropic.key')) {
            $texte = $ext === 'docx' ? null : ($ext === 'pdf' ? LecteurReleve::pdfTexte($chemin) : null);
            $lignesIa = LecteurReleve::ia($chemin, $ext, $texte ?: null);
            if ($lignesIa) [$methode, $lignes, $controle] = ['ia', $lignesIa, null];
        }
        if (! $lignes) {
            throw new RuntimeException(in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) && ! config('services.anthropic.key')
                ? 'Aucune opération lisible dans ce fichier (document scanné ?). Configurez la lecture par l’IA (ANTHROPIC_API_KEY) ou importez le relevé au format Excel ou CSV.'
                : 'Aucune opération reconnue dans ce fichier : vérifiez qu’il contient des colonnes Date, Libellé et Débit / Crédit (ou Montant).');
        }

        $banque = Saisie::banque($client->id);
        $proposer = self::proposeur($client->id, $banque);
        $cle = fn ($date, $libelle, $debit, $credit) => $date.'|'.$libelle.'|'.number_format((float) $debit, 3, '.', '').'|'.number_format((float) $credit, 3, '.', '');
        return DB::transaction(function () use ($client, $fichier, $user, $methode, $lignes, $banque, $proposer, $cle, $controle) {
            // Opérations déjà importées (relevés précédents), comptées : deux opérations identiques d'un même relevé restent deux lignes
            $dejaLa = [];
            $dates = array_column($lignes, 'date');
            ReleveLigne::where('client_id', $client->id)->whereDate('date', '>=', min($dates))->whereDate('date', '<=', max($dates))->get(['date', 'libelle', 'debit', 'credit'])
                ->each(function ($l) use (&$dejaLa, $cle) { $k = $cle($l->date->format('Y-m-d'), $l->libelle, $l->debit, $l->credit); $dejaLa[$k] = ($dejaLa[$k] ?? 0) + 1; });

            $releve = Releve::create(['client_id' => $client->id, 'nom_fichier' => Str::limit($fichier->getClientOriginalName(), 250, ''),
                'fichier' => $fichier->store('releves/'.CabinetCourant::exiger()), 'compte_banque' => $banque['compte'] ?: '532',
                'methode' => $methode, 'importe_par' => $user]);
            $doublons = 0; $maintenant = now();
            $nouvelles = [];
            foreach ($lignes as $l) {
                $k = $cle($l['date'], $l['libelle'], $l['debit'], $l['credit']);
                if (($dejaLa[$k] ?? 0) > 0) { $dejaLa[$k]--; $doublons++; continue; }
                $nouvelles[] = ['cabinet_id' => CabinetCourant::exiger(), 'client_id' => $client->id, 'releve_id' => $releve->id, 'date' => $l['date'],
                    'libelle' => $l['libelle'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'sens_incertain' => $l['sens_incertain'],
                    'compte' => $proposer($l['libelle']), 'statut' => 'att', 'created_at' => $maintenant, 'updated_at' => $maintenant];
            }
            foreach (array_chunk($nouvelles, 200) as $lot) ReleveLigne::insert($lot);      // relevés de plus de mille opérations
            return [$releve, $doublons, $controle];
        });
    }

    /** Contrepartie proposée : règle du paramétrage, puis fournisseur / client connu, sinon compte d'attente. */
    public static function proposeur(int $client, array $banque): \Closure
    {
        $norm = fn ($s) => Str::upper(Str::ascii((string) $s));
        $regles = collect($banque['regles'] ?? [])->filter(fn ($r) => trim($r['mot_cle'] ?? '') !== '' && trim($r['compte'] ?? '') !== '')
            ->map(fn ($r) => [$norm(trim($r['mot_cle'])), trim($r['compte'])]);
        $fournisseur = Saisie::achat($client)['compte_tiers'] ?: '401';
        $clientCpt = Saisie::vente($client)['compte_tiers'] ?: '411';
        $tiers = collect()
            ->merge(ModeleSaisie::where('client_id', $client)->get()->map(fn ($m) => [$m->tiers, $m->type === 'achat' ? $fournisseur : $clientCpt]))
            ->merge(FactureRecue::where('client_id', $client)->where('statut', 'val')->distinct()->pluck('fournisseur')->map(fn ($n) => [$n, $fournisseur]))
            ->merge(FactureVente::where('client_id', $client)->where('statut', 'val')->distinct()->pluck('tiers')->map(fn ($n) => [$n, $clientCpt]))
            ->filter(fn ($t) => mb_strlen(trim((string) $t[0])) >= 3)->map(fn ($t) => [$norm(trim($t[0])), $t[1]])->unique(0);
        $attente = $banque['attente'] ?: '461';

        return function (string $libelle) use ($norm, $regles, $tiers, $attente) {
            $t = $norm($libelle);
            foreach ($regles as [$mot, $compte]) if (str_contains($t, $mot)) return $compte;
            foreach ($tiers as [$nom, $compte]) if (str_contains($t, $nom)) return $compte;
            return $attente;
        };
    }
}
