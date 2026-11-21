<?php

namespace App\Http\Controllers;

use App\Models\Acompte;
use App\Models\Conge;
use App\Models\Document;
use App\Models\EcritureProposee;
use App\Models\Ecriture;
use App\Models\Paie;
use App\Models\Pret;
use App\Models\Salarie;
use App\Models\SalarieDocument;
use App\Services\LecturePieces;
use App\Services\PaieCalc;
use App\Services\TraitementPieces;
use App\Support\TypesPieces;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Documents reçus des clients, vus de chaque module : comptabilité (écritures proposées à valider), immobilisations,
 * paie (import des congés, acomptes et prêts lus ; classement des documents des salariés) ; reclassement et relecture.
 */
class PiecesRecuesController extends Controller
{
    // ---------- Boîtes de réception des modules ----------

    public function compta(Request $r)
    {
        return $this->boite($r, 'compta', 'compta.recues');
    }

    public function immobilisations(Request $r)
    {
        return $this->boite($r, 'immobilisations', 'immobilisations.recus');
    }

    public function paie(Request $r)
    {
        return $this->boite($r, 'paie', 'paie.recus');
    }

    private function boite(Request $r, string $destination, string $route)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $docs = Document::with('deposant')->where('client_id', $client->id)->where('destination', $destination)
            ->when($r->input('vue', 'en_cours') === 'en_cours', fn ($q) => $q->where(fn ($w) => $w->whereIn('traitement', ['en_attente', 'propose', 'a_saisir', 'erreur'])))
            ->latest()->paginate(30)->withQueryString();
        return view('pieces-recues.index', [
            'client' => $client, 'clients' => \App\Models\Client::orderBy('nom')->get(['id', 'nom']), 'destination' => $destination, 'route' => $route,
            'docs' => $docs, 'vue' => $r->input('vue', 'en_cours'), 'ia' => LecturePieces::disponible(),
            'proposees' => $destination === 'compta' ? EcritureProposee::with('document')->where('client_id', $client->id)->where('statut', 'prop')->orderBy('date')->get() : collect(),
            'journaux' => Ecriture::journauxSaisie($client->id),
            'salaries' => $destination === 'paie' ? $client->salaries()->orderBy('nom')->get() : collect(),
        ]);
    }

    // ---------- Écritures proposées (comptabilité) ----------

    public function ecritureUpdate(Request $r, EcritureProposee $ep)
    {
        abort_unless($ep->statut === 'prop', 422, 'Cette écriture a déjà été traitée.');
        $d = $r->validate(['date' => 'required|date', 'journal' => ['required', Rule::in(array_keys(Ecriture::journauxSaisie($ep->client_id)))], 'libelle' => 'required|max:255',
            'lignes' => 'required|array|min:2', 'lignes.*.compte' => 'nullable|regex:/^\d{1,10}$/', 'lignes.*.libelle' => 'nullable|max:120',
            'lignes.*.debit' => 'nullable|numeric|min:0', 'lignes.*.credit' => 'nullable|numeric|min:0']);
        $lignes = collect($d['lignes'])->filter(fn ($l) => ! empty($l['compte']) && ((float) ($l['debit'] ?? 0) || (float) ($l['credit'] ?? 0)))
            ->map(fn ($l) => ['compte' => $l['compte'], 'libelle' => $l['libelle'] ?? '', 'debit' => r3($l['debit'] ?? 0), 'credit' => r3($l['credit'] ?? 0)])->values()->all();
        $ep->update(['date' => $d['date'], 'journal' => $d['journal'], 'libelle' => $d['libelle'], 'lignes' => $lignes]);
        return back()->with('ok', 'Écriture proposée modifiée'.($ep->equilibree() ? '.' : ' : elle n’est pas encore équilibrée.'));
    }

    public function ecritureValider(EcritureProposee $ep)
    {
        try {
            TraitementPieces::validerEcriture($ep, auth()->id());
        } catch (\RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }
        $this->log('Validation de l’écriture proposée « '.$ep->libelle.' » ('.$ep->journal.')', $ep->client_id);
        TraitementPieces::apresValidation($ep->document_id);
        return back()->with('ok', 'Écriture validée et passée au journal '.(Ecriture::journaux($ep->client_id)[$ep->journal] ?? $ep->journal).'.');
    }

    public function ecritureRejeter(Request $r, EcritureProposee $ep)
    {
        abort_unless($ep->statut === 'prop', 422, 'Cette écriture a déjà été traitée.');
        $ep->update(['statut' => 'rej', 'motif_rejet' => $r->input('motif') ?: 'Rejetée']);
        TraitementPieces::apresValidation($ep->document_id);
        return back()->with('ok', 'Écriture proposée rejetée.');
    }

    // ---------- Paie : import des congés, acomptes et prêts lus ; classement des documents des salariés ----------

    public function paieImporter(Request $r, Document $document)
    {
        abort_unless($document->destination === 'paie' && in_array($document->type_piece, ['etat_conges', 'etat_acomptes_prets'], true) && $document->traitement === 'propose', 404);
        $client = $document->client;
        $d = $r->validate(['importer' => 'required|array|min:1', 'importer.*' => 'integer', 'salarie' => 'array', 'salarie.*' => 'nullable|integer'],
            ['importer.required' => 'Cochez au moins une ligne à importer.']);
        $lignes = (array) ($document->resultat['lignes'] ?? []);
        $faits = []; $erreurs = [];
        DB::transaction(function () use ($d, $lignes, $client, $document, &$faits, &$erreurs) {
            foreach ($d['importer'] as $i) {
                $l = $lignes[$i] ?? null;
                if (! $l || ! empty($l['importe'])) continue;
                $s = Salarie::where('client_id', $client->id)->find((int) ($d['salarie'][$i] ?? 0));
                if (! $s) { $erreurs[] = 'ligne '.($i + 1).' : salarié à choisir'; continue; }
                try {
                    $document->type_piece === 'etat_conges' ? $this->creerConge($client, $s, $l) : $this->creerAcompteOuPret($client, $s, $l);
                    $faits[] = $i;
                } catch (\RuntimeException $e) {
                    $erreurs[] = 'ligne '.($i + 1).' ('.$s->nomComplet().') : '.$e->getMessage();
                }
            }
        });
        $res = (array) $document->resultat;
        foreach ($faits as $i) $res['lignes'][$i]['importe'] = true;
        $reste = collect($res['lignes'] ?? [])->filter(fn ($l) => empty($l['importe']))->count();
        TraitementPieces::etat($document, $reste ? 'propose' : 'importe', $reste
            ? count($faits).' ligne(s) importée(s) ; '.$reste.' restante(s) à vérifier.'
            : 'Toutes les lignes ont été importées dans la paie.', $res);
        $this->log('Paie : import de '.count($faits).' ligne(s) de « '.TypesPieces::libelle($document->type_piece).' » reçu du client', $client->id);
        return back()->with($erreurs ? 'err' : 'ok', count($faits).' ligne(s) importée(s).'.($erreurs ? ' Non importé : '.implode(' ; ', array_slice($erreurs, 0, 5)).'.' : ''));
    }

    private function creerConge($client, Salarie $s, array $l): void
    {
        $du = $this->date($l['du'] ?? null); $au = $this->date($l['au'] ?? null);
        if (! $du || ! $au || $au->lt($du)) throw new \RuntimeException('dates du congé illisibles');
        $type = isset(Conge::TYPES[$l['type'] ?? '']) ? $l['type'] : 'paye';
        $cinq = (float) PaieCalc::parametres($client)['jours_mois'] <= 22;
        $jours = (float) ($l['jours'] ?? 0) ?: Conge::joursOuvres($du, $au, $cinq);
        Conge::create(['client_id' => $client->id, 'salarie_id' => $s->id, 'type' => $type, 'du' => $du, 'au' => $au, 'jours' => $jours,
            'observation' => mb_substr((string) ($l['observation'] ?? ''), 0, 255) ?: 'Reçu du client']);
    }

    private function creerAcompteOuPret($client, Salarie $s, array $l): void
    {
        $montant = r3($l['montant'] ?? 0);
        $date = $this->date($l['date'] ?? null) ?? now();
        $mois = preg_match('/^\d{4}-\d{2}$/', (string) ($l['mois_retenue'] ?? '')) ? $l['mois_retenue'] : $date->format('Y-m');
        if ($montant <= 0) throw new \RuntimeException('montant illisible');
        $p = Paie::where('client_id', $client->id)->where('mois', $mois)->normale()->first();
        if ($p && $p->statut !== 'brouillon') throw new \RuntimeException('la paie de '.mois_fr($mois).' est clôturée');
        if (($l['nature'] ?? 'acompte') === 'acompte') {
            Acompte::create(['client_id' => $client->id, 'salarie_id' => $s->id, 'date' => $date, 'montant' => $montant, 'mode' => 'especes', 'mois' => $mois,
                'observation' => 'Reçu du client']);
            return;
        }
        $nb = (int) ($l['nb_echeances'] ?? 0); $ech = (float) ($l['echeance'] ?? 0);
        if ($nb <= 0 && $ech <= 0) throw new \RuntimeException('nombre ou montant des échéances du prêt illisible');
        $nb = $nb > 0 ? $nb : (int) ceil($montant / $ech - 1e-9);
        $ech = $ech > 0 ? $ech : r3($montant / $nb);
        $pret = Pret::create(['client_id' => $client->id, 'salarie_id' => $s->id, 'type' => $l['nature'] === 'pret_cnss' ? 'cnss' : 'societe', 'date' => $date, 'montant' => $montant,
            'mode' => 'virement', 'echeance' => $ech, 'nb_echeances' => max(1, $nb), 'premier_mois' => $mois, 'reference' => mb_substr((string) ($l['reference'] ?? ''), 0, 60) ?: null,
            'observation' => 'Reçu du client']);
        if ($pret->type === 'societe') PaiePretController::comptabiliser($pret);
    }

    public function paieClasser(Request $r, Document $document)
    {
        abort_unless($document->destination === 'paie' && in_array($document->type_piece, ['contrat_travail', 'piece_identite', 'document_salarie'], true), 404);
        $d = $r->validate(['salarie_id' => 'required|integer', 'type' => ['required', Rule::in(array_keys(SalarieDocument::TYPES))]], ['salarie_id.required' => 'Choisissez le salarié.']);
        $s = Salarie::where('client_id', $document->client_id)->findOrFail((int) $d['salarie_id']);
        $sd = TraitementPieces::classerDansFiche($document, $s, $d['type']);
        TraitementPieces::etat($document, 'importe', 'Classé dans la fiche de '.$s->nomComplet().' ('.SalarieDocument::TYPES[$d['type']].').', ['salarie_documents' => [$sd->id], 'salarie' => $s->id]);
        return back()->with('ok', 'Document classé dans la fiche de '.$s->nomComplet().'.');
    }

    // ---------- Reclassement, relecture, saisie manuelle ----------

    /** Change le type d'un document reçu (le client s'est trompé) et le traite à nouveau. */
    public function reclasser(Request $r, Document $document)
    {
        abort_unless($document->envoiClient() || $document->type_piece, 404);
        $d = $r->validate(['type_piece' => ['required', Rule::in(array_keys(TypesPieces::TYPES))], 'periode' => 'nullable|date_format:Y-m']);
        TraitementPieces::recevoir($document, $d['type_piece'], $d['periode'] ?? $document->periode);
        return back()->with('ok', 'Document classé en « '.TypesPieces::libelle($d['type_piece']).' »'.(TypesPieces::destination($d['type_piece']) === 'ged' ? '.' : ' et envoyé au module '.TypesPieces::DESTINATIONS[TypesPieces::destination($d['type_piece'])].'.'));
    }

    public function relancer(Document $document)
    {
        abort_unless($document->type_piece && TypesPieces::destination($document->type_piece) !== 'ged', 404);
        abort_if(in_array($document->traitement, ['propose', 'importe'], true) && ! empty($document->resultat), 422, 'Ce document a déjà été traité : rejetez d’abord ses propositions.');
        TraitementPieces::recevoir($document, $document->type_piece, $document->periode);
        return back()->with('ok', 'Nouvelle lecture demandée : le résultat apparaît dans une minute environ.');
    }

    /** Document saisi à la main dans son module : il sort de la liste « à traiter ». */
    public function saisi(Document $document)
    {
        abort_unless($document->type_piece, 404);
        TraitementPieces::etat($document, 'importe', 'Saisi manuellement par '.auth()->user()->name.'.', (array) $document->resultat);
        return back()->with('ok', 'Document marqué comme saisi.');
    }

    private function date(?string $v): ?Carbon
    {
        if (! $v || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
        try { return Carbon::createFromFormat('Y-m-d', $v)->startOfDay(); } catch (\Throwable) { return null; }
    }
}
