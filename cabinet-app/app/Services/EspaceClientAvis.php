<?php

namespace App\Services;

use App\Mail\EspaceClientMail;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Setting;
use App\Models\User;
use App\Support\CabinetCourant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Avis par e-mail de l'espace client. Un e-mail qui ne part pas (serveur absent, adresse refusée)
 * est journalisé sans bloquer l'action : le document reste partagé ou reçu.
 */
class EspaceClientAvis
{
    /** Invitation ou nouveau lien de mot de passe pour un compte client. Renvoie false si l'e-mail n'est pas parti. */
    public static function invitation(ClientUser $u, string $jeton, bool $reinitialisation = false): bool
    {
        $client = $u->client;
        return self::envoyer($u->email, new EspaceClientMail(
            $reinitialisation ? 'reinitialisation' : 'invitation',
            $reinitialisation ? 'Votre lien de connexion à l’espace client' : 'Votre espace client '.Setting::cabinet()['nom'],
            ['u' => $u, 'client' => $client, 'lien' => route('espace.activer', $jeton), 'jours' => ClientUser::VALIDITE_JETON_JOURS],
        ));
    }

    /** Documents mis à disposition d'un client : un e-mail à chacun de ses comptes actifs. */
    public static function documentsPartages(Client $client, Collection $docs): int
    {
        if ($docs->isEmpty()) return 0;
        $n = 0;
        foreach ($client->accesEspace()->where('actif', true)->whereNotNull('password')->get() as $u) {
            $n += (int) self::envoyer($u->email, new EspaceClientMail('documents', $docs->count() > 1 ? $docs->count().' nouveaux documents dans votre espace client' : 'Nouveau document dans votre espace client',
                ['u' => $u, 'client' => $client, 'docs' => $docs, 'lien' => route('espace.index')]));
        }
        return $n;
    }

    /** Envoi d'un client : avis à l'adresse du cabinet (Paramètres) ou, à défaut, aux chefs comptables. */
    public static function envoiClient(ClientUser $u, Client $client, Collection $docs, ?string $message): int
    {
        $n = 0;
        foreach (self::destinatairesCabinet() as $email) {
            $n += (int) self::envoyer($email, new EspaceClientMail('envoi', $client->nom.' : '.$docs->count().' document(s) reçu(s)',
                ['u' => $u, 'client' => $client, 'docs' => $docs, 'commentaire' => $message, 'lien' => route('echanges.index', ['client' => $client->id])]));
        }
        return $n;
    }

    /** @return array<int, string> */
    public static function destinatairesCabinet(): array
    {
        $email = trim((string) (Setting::cabinet()['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) return [$email];
        return User::where('cabinet_id', CabinetCourant::exiger())->where('role', 'chef')->where('status', 'active')->pluck('email')->all();
    }

    private static function envoyer(string $email, EspaceClientMail $mail): bool
    {
        try {
            Mail::to($email)->send($mail);
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }
}
