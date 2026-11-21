<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\TraitementPieces;
use App\Support\CabinetCourant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Lecture et aiguillage d'un document reçu d'un client, en arrière-plan (la lecture par l'IA peut prendre une minute).
 * Exécutée par le planificateur (queue:work chaque minute) ; en mode synchrone, tout de suite.
 */
class TraiterPieceRecue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $document, public int $cabinet) {}

    public function handle(): void
    {
        CabinetCourant::pour($this->cabinet, function () {
            $doc = Document::find($this->document);
            if ($doc && $doc->traitement === 'en_attente') TraitementPieces::traiter($doc);
        });
    }

    public function failed(\Throwable $e): void
    {
        CabinetCourant::pour($this->cabinet, function () use ($e) {
            $doc = Document::find($this->document);
            if ($doc) TraitementPieces::etat($doc, 'erreur', 'Traitement interrompu : '.$e->getMessage());
        });
    }
}
