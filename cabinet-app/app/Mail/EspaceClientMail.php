<?php

namespace App\Mail;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-mails de l'espace client : invitation, documents mis à disposition, envoi d'un client au cabinet.
 * Construits dans le cabinet courant (nom et coordonnées du cabinet en signature).
 */
class EspaceClientMail extends Mailable
{
    use Queueable;

    public array $cab;

    /** @param array<string, mixed> $donnees variables de la vue mail.espace.{$modele} */
    public function __construct(public string $modele, public string $sujet, public array $donnees = [])
    {
        $this->cab = Setting::cabinet();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->cab['nom'] ?: config('mail.from.name')),
            replyTo: filter_var($this->cab['email'] ?? '', FILTER_VALIDATE_EMAIL) ? [new Address($this->cab['email'], $this->cab['nom'])] : [],
            subject: $this->sujet,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.espace.'.$this->modele, with: $this->donnees + ['cab' => $this->cab]);
    }
}
