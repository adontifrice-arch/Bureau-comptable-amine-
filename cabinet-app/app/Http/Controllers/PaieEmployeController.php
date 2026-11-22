<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Salarie;
use App\Models\SalarieDocument;
use App\Models\Setting;
use App\Services\Exports\EtatsEmployes;
use App\Services\PaieCalc;
use App\Support\CabinetCourant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Paie — menu Employé : fiche employé en six onglets, pièces jointes, états du personnel et documents RH. */
class PaieEmployeController extends Controller
{
    public const DOCUMENTS = [
        'attestation-travail' => 'Attestation de travail',
        'attestation-salaire' => 'Attestation de salaire mensuelle',
        'attestation-salaire-annuelle' => 'Attestation de salaire annuelle',
        'certificat-travail' => 'Certificat de travail',
        'certificat-retenue' => 'Certificat de retenue',
        'attestation-cnss' => 'Attestation des salaires déclarés à la CNSS',
        'recu-vetements' => 'Reçu de vêtements de travail',
        'contrat' => 'Contrat de travail',
        'demission' => 'Démission',
    ];

    public function create(Request $r)
    {
        $client = $this->dossier($r);
        $e = new Salarie(['matricule' => $this->prochainMatricule($client), 'regime' => 'mensuel', 'etat_presence' => 'actif', 'nb_bulletin' => 1,
            'cnss_assujetti' => true, 'mode_paiement' => 'virement', 'nationalite' => 'Tunisienne', 'type_contrat' => 'CDI']);
        return $this->fiche($r, $client, $e);
    }

    public function edit(Request $r, Salarie $salarie)
    {
        $client = $this->dossier($r);
        abort_unless($salarie->client_id === $client->id, 404);
        return $this->fiche($r, $client, $salarie->load(['documents', 'historique.user']));
    }

    private function fiche(Request $r, Client $client, Salarie $e)
    {
        $mois = $r->input('mois', now()->format('Y-m'));
        return view('paie.employe', PaieController::contexte($r, $client, 'sal', $mois) + [
            'e' => $e,
            'departements' => Salarie::where('client_id', $client->id)->whereNotNull('departement')->distinct()->orderBy('departement')->pluck('departement'),
            'profils' => collect(\App\Models\PaieRubrique::profilsDisponibles($client))->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'categories' => Salarie::where('client_id', $client->id)->whereNotNull('categorie')->distinct()->orderBy('categorie')->pluck('categorie'),
            'echelons' => Salarie::where('client_id', $client->id)->whereNotNull('echelon')->distinct()->orderBy('echelon')->pluck('echelon'),
        ]);
    }

    public function store(Request $r)
    {
        $client = $this->dossier($r);
        $d = $this->valider($r, $client);
        $e = DB::transaction(function () use ($r, $client, $d) {
            $e = Salarie::create($d + ['client_id' => $client->id]);
            $this->pieces($r, $e);
            return $e;
        });
        $this->log('Paie : nouvel employé '.$e->nomComplet(), $client->id);
        return redirect()->route('paie.employes.edit', $e)->with('ok', 'Employé enregistré.');
    }

    public function update(Request $r, Salarie $salarie)
    {
        $client = $this->dossier($r);
        abort_unless($salarie->client_id === $client->id, 404);
        $d = $this->valider($r, $client, $salarie);
        DB::transaction(function () use ($r, $salarie, $d) {
            $salarie->update($d);
            $this->pieces($r, $salarie);
        });
        return redirect()->route('paie.employes.edit', $salarie)->with('ok', 'Fiche employé enregistrée.');
    }

    public function destroy(Request $r, Salarie $salarie)
    {
        $client = $this->dossier($r);
        abort_unless($salarie->client_id === $client->id, 404);
        foreach ($salarie->documents as $doc) Storage::delete($doc->chemin);
        $nom = $salarie->nomComplet();
        $salarie->delete();
        $this->log('Paie : suppression de l’employé '.$nom, $client->id);
        return redirect()->route('paie.index', ['tab' => 'sal'])->with('ok', 'Employé supprimé.');
    }

    public function document(SalarieDocument $document)
    {
        abort_unless($document->salarie, 404);
        return Storage::response($document->chemin, $document->nom);
    }

    public function documentDestroy(SalarieDocument $document)
    {
        Storage::delete($document->chemin);
        $document->delete();
        return back()->with('ok', 'Pièce jointe supprimée.');
    }

    /** Fiche employé imprimable. */
    public function imprimer(Request $r, Salarie $salarie)
    {
        $client = $this->dossier($r);
        abort_unless($salarie->client_id === $client->id, 404);
        return view('paie.documents.fiche', ['client' => $client, 'e' => $salarie->load('documents', 'historique'), 'pdf' => false]);
    }

    /** États du personnel (Liste des employés, historiques, contrats, profils, catégories et échelons). */
    public function etat(Request $r, string $etat, string $format)
    {
        $client = $this->dossier($r);
        abort_unless($client && isset(EtatsEmployes::ETATS[$etat]) && in_array($format, ['imprimer', 'pdf', 'xlsx', 'docx'], true), 404);
        $d = $r->validate(['statut' => 'nullable|in:tous,actifs,inactifs', 'mois' => 'nullable|date_format:Y-m']);
        return EtatsEmployes::etat($etat, $client, $d['statut'] ?? 'tous', $d['mois'] ?? now()->format('Y-m'))->reponse($format);
    }

    /** Documents RH : attestations, certificats, contrat de travail, lettre de démission, reçu de vêtements de travail. */
    public function rh(Request $r, Salarie $salarie, string $type)
    {
        $client = $this->dossier($r);
        abort_unless($salarie->client_id === $client->id && isset(self::DOCUMENTS[$type]), 404);
        $d = $r->validate(['annee' => 'nullable|integer|min:2000|max:2100', 'mois' => 'nullable|date_format:Y-m', 'pdf' => 'nullable|boolean']);
        $annee = (int) ($d['annee'] ?? now()->year);
        $page = ['e' => $salarie];
        switch ($type) {
            case 'attestation-salaire':
                $mois = $d['mois'] ?? now()->format('Y-m');
                $page += ['mois' => $mois, 'paie' => collect(PaieCalc::mois($client, $mois, true))->firstWhere('salarie_id', $salarie->id)];
                break;
            case 'attestation-salaire-annuelle':
                $page += $this->dernierSalaire($client, $salarie, $d['mois'] ?? now()->format('Y-m'));
                break;
            case 'certificat-retenue':
                $page += ['annee' => $annee, 'cumul' => $this->cumulsAnnuels($client, $annee)[$salarie->id] ?? self::cumulVide()];
                break;
            case 'attestation-cnss':
                $page += ['annee' => $annee, 'trimestres' => $this->salairesDeclaresCnss($client, $annee)[$salarie->id] ?? $this->trimestresVides($annee)];
                break;
            case 'recu-vetements':
                $page += ['annee' => $annee];
                break;
        }
        $nom = self::DOCUMENTS[$type].' '.$salarie->nomComplet().' '.(in_array($type, ['certificat-retenue', 'attestation-cnss'], true) ? $annee : '');
        return $this->rendreRh($client, $type, [$page], $r->boolean('pdf'), $nom);
    }

    /**
     * Certificat de retenue de tous les employés payés dans l'année : un certificat par page, à imprimer ou en un seul PDF.
     */
    public function rhTous(Request $r, string $type)
    {
        $client = $this->dossier($r);
        abort_unless($client && $type === 'certificat-retenue', 404);
        $annee = (int) ($r->validate(['annee' => 'nullable|integer|min:2000|max:2100', 'pdf' => 'nullable|boolean'])['annee'] ?? now()->year);
        $cumuls = $this->cumulsAnnuels($client, $annee);
        $salaries = PaieCalc::trier(Salarie::where('client_id', $client->id)->whereIn('id', array_keys($cumuls))->get(), 'nom');
        if ($salaries->isEmpty()) return back()->with('err', 'Aucun salaire versé en '.$annee.' : pas de certificat de retenue à établir.');
        $pages = $salaries->map(fn ($e) => ['e' => $e, 'annee' => $annee, 'cumul' => $cumuls[$e->id]])->values()->all();
        return $this->rendreRh($client, $type, $pages, $r->boolean('pdf'), 'Certificats de retenue '.$annee.' tous les employes');
    }

    private function rendreRh(Client $client, string $type, array $pages, bool $pdf, string $nomFichier)
    {
        $data = ['client' => $client, 'type' => $type, 'titre' => self::DOCUMENTS[$type], 'pdf' => $pdf, 'pages' => $pages,
            'k' => $client->paie_config ?? [], 'cab' => Setting::cabinet()];
        if ($pdf) {
            return Pdf::loadView('paie.documents.rh', $data)->setPaper('a4')->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
                ->download(Str::slug(trim($nomFichier)).'.pdf');
        }
        return view('paie.documents.rh', $data);
    }

    private static function cumulVide(): array
    {
        return array_fill_keys(['brut', 'cnssS', 'ir', 'css', 'net'], 0.0);
    }

    /** Cumuls de l'année par salarié (brut, CNSS, IRPP, CSS, net) : salariés ayant perçu un salaire dans l'année. */
    private function cumulsAnnuels(Client $client, int $annee): array
    {
        $par = [];
        foreach (range(1, 12) as $m) {
            foreach (PaieCalc::mois($client, sprintf('%d-%02d', $annee, $m)) as $x) {
                $par[$x['salarie_id']] ??= self::cumulVide();
                foreach ($par[$x['salarie_id']] as $c => $v) $par[$x['salarie_id']][$c] = r3($v + $x[$c]);
            }
        }
        return array_filter($par, fn ($c) => abs($c['brut']) > 0.0005);
    }

    /**
     * Attestation de salaire annuelle : dernier salaire connu au plus tard le mois choisi (jusqu'à douze mois en arrière),
     * multiplié par 12.
     */
    private function dernierSalaire(Client $client, Salarie $e, string $mois): array
    {
        $m = \Carbon\Carbon::parse($mois.'-01');
        for ($i = 0; $i < 12; $i++, $m->subMonth()) {
            $x = collect(PaieCalc::mois($client, $m->format('Y-m'), $i === 0))->firstWhere('salarie_id', $e->id);
            if ($x && $x['brut'] > 0) return ['mois' => $m->format('Y-m'), 'paie' => $x];
        }
        return ['mois' => $mois, 'paie' => null];
    }

    /** Trimestres de l'année, chacun avec ses trois mois à zéro. */
    private function trimestresVides(int $annee): array
    {
        $t = [];
        foreach ([1, 2, 3, 4] as $q) {
            $t[$q] = ['mois' => [], 'total' => 0.0];
            foreach (range(3 * $q - 2, 3 * $q) as $mm) $t[$q]['mois'][sprintf('%d-%02d', $annee, $mm)] = 0.0;
        }
        return $t;
    }

    /**
     * Salaires déclarés à la CNSS (assiette des déclarations trimestrielles : paie du mois et paies spéciales rattachées,
     * employés inclus dans la déclaration CNSS), par salarié, trimestre et mois.
     */
    private function salairesDeclaresCnss(Client $client, int $annee): array
    {
        $par = [];
        foreach (range(1, 12) as $mm) {
            $m = sprintf('%d-%02d', $annee, $mm);
            $q = intdiv($mm - 1, 3) + 1;
            foreach (PaieCalc::moisDeclares($client, $m) as $x) {
                if (! ($x['decl_cnss'] ?? true)) continue;
                $par[$x['salarie_id']] ??= $this->trimestresVides($annee);
                $v = (float) ($x['base_cnss'] ?? $x['brut']);
                $par[$x['salarie_id']][$q]['mois'][$m] = r3($par[$x['salarie_id']][$q]['mois'][$m] + $v);
                $par[$x['salarie_id']][$q]['total'] = r3($par[$x['salarie_id']][$q]['total'] + $v);
            }
        }
        return $par;
    }

    private function prochainMatricule(Client $client): string
    {
        $max = Salarie::where('client_id', $client->id)->pluck('matricule')->filter(fn ($m) => ctype_digit((string) $m))->map(fn ($m) => (int) $m)->max() ?? 0;
        return str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Validation de la fiche : les mentions obligatoires sont celles du logiciel de paie (*). */
    private function valider(Request $r, Client $client, ?Salarie $e = null): array
    {
        $unique = 'unique:salaries,matricule,'.($e?->id ?? 'NULL').',id,client_id,'.$client->id;
        $d = $r->validate([
            // En-tête
            'matricule' => ['required', 'max:20', $unique], 'titre' => 'nullable|in:'.implode(',', array_keys(Salarie::TITRES)), 'matricule_pointeuse' => 'nullable|max:30',
            'nom' => 'required|max:255', 'prenom' => 'required|max:255', 'prenom_pere' => 'nullable|max:100',
            // État civil
            'adresse' => 'required|max:255', 'ville' => 'required|max:100', 'code_postal' => 'nullable|max:10', 'tel_fixe' => 'nullable|max:30', 'tel_mobile' => 'nullable|max:30',
            'cin' => 'required|max:20', 'cin_date' => 'nullable|date', 'carte_sejour' => 'nullable|boolean', 'date_naissance' => 'nullable|date|before:today',
            'nationalite' => 'nullable|max:50', 'lieu_naissance' => 'nullable|max:100',
            'situation_familiale' => 'required|in:'.implode(',', array_keys(Salarie::SITUATIONS)), 'chef_famille' => 'nullable|boolean',
            'enfants_detail' => 'nullable|array|max:4', 'enfants_detail.*.a_charge' => 'nullable|boolean', 'enfants_detail.*.etudiant' => 'nullable|boolean', 'enfants_detail.*.infirme' => 'nullable|boolean',
            'parents_charge' => 'nullable|integer|min:0|max:4',
            // Informations spécifiques
            'poste' => 'required|max:100', 'regime' => 'required|in:mensuel,horaire', 'conges_acquis' => 'required|numeric|min:0|max:200', 'conges_report' => 'nullable|numeric|min:-366|max:1000', 'conges_report_annee' => 'nullable|required_with:conges_report|integer|min:1990|max:2100',
            'categorie' => 'nullable|max:50', 'categorie_debut' => 'nullable|date', 'categorie_fin' => 'nullable|date|after_or_equal:categorie_debut',
            'echelon' => 'nullable|max:50', 'echelon_debut' => 'nullable|date', 'echelon_fin' => 'nullable|date|after_or_equal:echelon_debut',
            'salaire_base' => 'required|numeric|min:0', 'profil' => 'nullable|max:255', 'profil_debut' => 'nullable|date', 'profil_fin' => 'nullable|date|after_or_equal:profil_debut',
            'cnss_assujetti' => 'nullable|boolean', 'cnss' => 'nullable|max:30', 'nb_bulletin' => 'required|integer|min:1|max:5',
            'assurance_vie' => 'nullable|numeric|min:0', 'cea' => 'nullable|numeric|min:0', 'interets_bancaires' => 'nullable|numeric|min:0',
            'retenue_etranger' => 'nullable|boolean', 'cnss_rc' => 'nullable|boolean', 'stc_solde_total' => 'nullable|boolean', 'regul_irpp' => 'nullable|boolean', 'regul_primes' => 'nullable|boolean',
            // Entrée / sortie
            'type_contrat' => 'nullable|in:'.implode(',', array_keys(Salarie::CONTRATS)), 'contrat_debut' => 'required|date', 'contrat_fin' => 'nullable|date|after_or_equal:contrat_debut',
            'departement' => 'nullable|max:100', 'etat_presence' => 'required|in:'.implode(',', array_keys(Salarie::ETATS)),
            'date_sortie' => 'nullable|required_if:etat_presence,sorti|date', 'motif_sortie' => 'nullable|in:'.implode(',', array_keys(Salarie::MOTIFS_SORTIE)),
            'date_embauche' => 'nullable|date', 'date_declaration_cnss' => 'nullable|date',
            // Règlement
            'mode_paiement' => 'required|in:'.implode(',', array_keys(Salarie::MODES)), 'banque' => 'nullable|max:100', 'agence' => 'nullable|max:100', 'rib' => 'nullable|max:30',
            // Pièces jointes
            'photo' => 'nullable|image|max:4096', 'cin1' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:4096', 'cin2' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:4096',
            'contrat_fichier' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240', 'contrat_nom' => 'nullable|max:100',
            'diplome_fichier' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240', 'diplome_nom' => 'nullable|required_with:diplome_fichier|max:100',
        ], [], ['matricule' => 'matricule', 'prenom' => 'prénom', 'prenom_pere' => 'prénom du père', 'cin' => 'n° CIN / carte de séjour', 'situation_familiale' => 'situation familiale',
            'poste' => 'qualification', 'regime' => 'régime salarial', 'conges_acquis' => 'acquis / mois', 'conges_report' => 'report de congés', 'conges_report_annee' => 'année du report', 'categorie' => 'catégorie', 'echelon' => 'échelon',
            'salaire_base' => 'salaire de base / taux horaire', 'nb_bulletin' => 'nombre de bulletins', 'contrat_debut' => 'date de début', 'etat_presence' => 'état de présence',
            'date_sortie' => 'date de sortie', 'diplome_nom' => 'nom du diplôme']);

        foreach (['carte_sejour', 'chef_famille', 'cnss_assujetti', 'retenue_etranger', 'cnss_rc', 'stc_solde_total', 'regul_irpp', 'regul_primes'] as $c) $d[$c] = $r->boolean($c);
        $enfants = [];
        foreach (array_slice(array_values((array) $r->input('enfants_detail', [])), 0, 4) as $x) {
            $x = ['a_charge' => ! empty($x['a_charge']), 'etudiant' => ! empty($x['etudiant']), 'infirme' => ! empty($x['infirme'])];
            if ($x['a_charge'] || $x['etudiant'] || $x['infirme']) $enfants[] = $x;
        }
        $d['enfants_detail'] = $enfants ?: null;
        $d['enfants'] = count($enfants);
        $d['parents_charge'] = (int) ($d['parents_charge'] ?? 0);
        if ($d['etat_presence'] !== 'sorti') { $d['date_sortie'] = null; $d['motif_sortie'] = null; }
        $d['date_embauche'] ??= $d['contrat_debut'];
        foreach (['photo', 'cin1', 'cin2', 'contrat_fichier', 'contrat_nom', 'diplome_fichier', 'diplome_nom'] as $c) unset($d[$c]);
        return $d;
    }

    /** Pièces jointes envoyées avec la fiche : photo et CIN remplacent l'ancien fichier, contrats et diplômes s'ajoutent à la liste. */
    private function pieces(Request $r, Salarie $e): void
    {
        $dossier = 'employes/'.CabinetCourant::exiger().'/'.$e->id;
        foreach (['photo', 'cin1', 'cin2'] as $type) {
            if (! $r->hasFile($type)) continue;
            foreach ($e->documents()->where('type', $type)->get() as $ancien) { Storage::delete($ancien->chemin); $ancien->delete(); }
            $f = $r->file($type);
            $e->documents()->create(['type' => $type, 'nom' => SalarieDocument::TYPES[$type], 'chemin' => $f->store($dossier), 'mime' => $f->getMimeType()]);
        }
        foreach (['contrat' => 'contrat_fichier', 'diplome' => 'diplome_fichier'] as $type => $champ) {
            if (! $r->hasFile($champ)) continue;
            $f = $r->file($champ);
            $nom = $r->input($type.'_nom') ?: ($type === 'contrat' ? 'Contrat '.(Salarie::CONTRATS[$e->type_contrat] ?? '') : $f->getClientOriginalName());
            $e->documents()->create(['type' => $type, 'nom' => trim($nom), 'chemin' => $f->store($dossier), 'mime' => $f->getMimeType()]);
        }
    }
}
