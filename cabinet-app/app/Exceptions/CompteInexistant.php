<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Pièce refusée : un ou plusieurs comptes sont absents du plan comptable du cabinet.
 * Aucune écriture n'est passée (ni modifiée) tant que ces comptes ne sont pas créés.
 */
class CompteInexistant extends RuntimeException
{
    public function __construct(public readonly array $comptes)
    {
        parent::__construct((count($comptes) > 1 ? 'Comptes absents' : 'Compte absent').' du plan comptable : '.implode(', ', $comptes)
            .'. Créez '.(count($comptes) > 1 ? 'ces comptes' : 'ce compte').' (en saisissant le numéro dans un champ de compte, ou dans l’onglet Plan comptable) : aucune pièce n’est validée avec un compte inexistant.');
    }

    /** Retour au formulaire avec le message (JSON 422 pour un appel en arrière-plan). */
    public function render($request)
    {
        if ($request->expectsJson()) return response()->json(['message' => $this->getMessage(), 'errors' => ['compte' => [$this->getMessage()]]], 422);
        return back()->withInput()->with('err', $this->getMessage());
    }
}
