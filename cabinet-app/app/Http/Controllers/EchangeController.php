<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Document;
use App\Services\EspaceClientAvis;
use App\Support\CabinetCourant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Échanges cabinet ↔ client, côté cabinet : envois reçus des clients, documents partagés,
 * comptes d'accès à l'espace client (invitation, réinitialisation, désactivation).
 */
class EchangeController extends Controller
{
    public function index(Request $r)
    {
        $filtre = $r->input('vue', 'a-traiter');
        $client = $r->filled('client') ? Client::find($r->input('client')) : null;
        $recus = Document::with(['client', 'deposant', 'traitePar'])->where('origine', 'client')
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->when($filtre === 'a-traiter', fn ($q) => $q->whereNull('traite_at'))
            ->latest()->paginate(25, ['*'], 'recus')->withQueryString();
        $partages = Document::with(['client', 'user'])->where('partage', true)->where('origine', 'cabinet')
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->latest('partage_at')->limit(30)->get();
        return view('echanges.index', [
            'recus' => $recus, 'partages' => $partages, 'filtre' => $filtre, 'client' => $client,
            'clients' => Client::orderBy('nom')->get(['id', 'nom']),
            'nbATraiter' => Document::aTraiter()->count(),
            'nbAcces' => ClientUser::duCabinet()->where('actif', true)->whereNotNull('password')->count(),
            'nbNonConsultes' => Document::where('partage', true)->where('origine', 'cabinet')->whereNull('consulte_at')->count(),
        ]);
    }

    // ---------- Documents ----------

    /** Dépose des documents dans la GED du client et les met à sa disposition dans son espace. */
    public function partager(Request $r, Client $client)
    {
        $d = $r->validate(['categorie' => ['required', Rule::in(config('cabinet.GC'))], 'description' => 'nullable|max:1000', 'date' => 'nullable|date',
            'fichiers' => 'required|array|max:20', 'fichiers.*' => Document::REGLE_FICHIER]);
        $docs = collect($r->file('fichiers'))->map(fn ($f) => Document::create([
            'client_id' => $client->id, 'categorie' => $d['categorie'], 'description' => $d['description'] ?? null, 'date' => $d['date'] ?? now(),
            'nom' => $f->getClientOriginalName(), 'chemin' => $f->store('ged/'.CabinetCourant::exiger().'/'.$client->id),
            'taille' => $f->getSize(), 'mime' => $f->getMimeType(), 'user_id' => auth()->id(), 'partage' => true, 'partage_at' => now(),
        ]));
        $this->log($docs->count().' document(s) partagé(s) avec le client', $client->id);
        return back()->with('ok', $this->messagePartage($docs->count(), EspaceClientAvis::documentsPartages($client, $docs), $client));
    }

    /** Partage ou retire du partage un document de la GED (dossier client uniquement, pas les envois du client). */
    public function basculerPartage(Document $document)
    {
        abort_if($document->client_id === null, 422, 'Un document interne au cabinet ne peut pas être partagé.');
        abort_if($document->envoiClient(), 422, 'Ce document vient déjà du client.');
        if ($document->partage) {
            $document->update(['partage' => false]);
            return back()->with('ok', 'Document retiré de l’espace client.');
        }
        $document->update(['partage' => true, 'partage_at' => now(), 'consulte_at' => null]);
        $this->log('Document « '.$document->nom.' » partagé avec le client', $document->client_id);
        return back()->with('ok', $this->messagePartage(1, EspaceClientAvis::documentsPartages($document->client, collect([$document])), $document->client));
    }

    /** Marque un envoi du client comme pris en charge (ou le remet à traiter). */
    public function traiter(Document $document)
    {
        abort_unless($document->envoiClient(), 404);
        $document->update($document->traite_at ? ['traite_at' => null, 'traite_par' => null] : ['traite_at' => now(), 'traite_par' => auth()->id()]);
        return back()->with('ok', $document->traite_at ? 'Envoi marqué comme traité.' : 'Envoi remis à traiter.');
    }

    // ---------- Accès à l'espace client (chef comptable) ----------

    public function inviter(Request $r, Client $client)
    {
        $r->merge(['email' => ClientUser::normaliserEmail($r->input('email'))]);
        $d = $r->validate(['name' => 'required|min:2|max:100', 'email' => 'required|email|max:255|unique:client_users,email'],
            ['email.unique' => 'Cette adresse a déjà un accès à un espace client.'], ['name' => 'nom']);
        $u = ClientUser::create($d + ['cabinet_id' => $client->cabinet_id, 'client_id' => $client->id, 'actif' => true, 'invite_par' => auth()->id()]);
        $envoye = EspaceClientAvis::invitation($u, $u->nouveauJeton());
        $this->log('Accès à l’espace client créé pour '.$u->name, $client->id);
        return back()->with($envoye ? 'ok' : 'err', $envoye
            ? 'Invitation envoyée à '.$u->email.'.'
            : 'Accès créé, mais l’e-mail d’invitation n’a pas pu partir : vérifiez la configuration e-mail puis utilisez « Renvoyer le lien ».');
    }

    /** Nouveau lien d'activation (compte jamais activé) ou de réinitialisation du mot de passe ; l'ancien lien cesse de fonctionner. */
    public function renvoyer(ClientUser $acces)
    {
        abort_unless($acces->actif, 422, 'Réactivez d’abord cet accès.');
        $envoye = EspaceClientAvis::invitation($acces, $acces->nouveauJeton(), $acces->estActive());
        return back()->with($envoye ? 'ok' : 'err', $envoye ? 'Lien envoyé à '.$acces->email.'.' : 'L’e-mail n’a pas pu partir : vérifiez la configuration e-mail.');
    }

    public function basculer(ClientUser $acces)
    {
        $acces->forceFill(['actif' => ! $acces->actif, 'jeton' => null, 'jeton_expire_at' => null])->save();
        $this->log('Accès à l’espace client de '.$acces->name.($acces->actif ? ' réactivé' : ' désactivé'), $acces->client_id);
        return back()->with('ok', $acces->actif ? 'Accès réactivé : envoyez un nouveau lien si besoin.' : 'Accès désactivé : '.$acces->name.' ne peut plus se connecter.');
    }

    public function supprimer(ClientUser $acces)
    {
        $this->log('Accès à l’espace client de '.$acces->name.' supprimé', $acces->client_id);
        $acces->delete();
        return back()->with('ok', 'Accès supprimé. Les documents déjà envoyés restent dans la GED.');
    }

    private function messagePartage(int $nbDocs, int $nbEmails, Client $client): string
    {
        $docs = $nbDocs > 1 ? $nbDocs.' documents partagés' : 'Document partagé';
        if (! $client->accesEspace()->where('actif', true)->whereNotNull('password')->exists()) {
            return $docs.'. '.$client->nom.' n’a pas encore d’accès actif à l’espace client : invitez-le depuis sa fiche (onglet Espace client).';
        }
        return $docs.($nbEmails ? ' et client prévenu par e-mail.' : ' (l’e-mail d’avis n’a pas pu partir).');
    }
}
