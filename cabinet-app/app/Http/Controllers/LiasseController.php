<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Liasse;
use App\Services\LiasseFiscale;
use App\Models\PassageFiscal;
use App\Services\Compta;
use App\Services\PaieCalc;
use App\Services\TableauPassage;
use App\Models\Setting;
use Illuminate\Http\Request;

class LiasseController extends Controller
{
    public static function fiscal(Client $c, array $P, ?array $L): array
    {
        $L = array_merge(['rei' => 0, 'ded' => 0, 'taux' => 15, 'min' => 0, 'acp' => 0, 'rs' => 0], $L ?? []);
        // Résultat fiscal (II) = résultat comptable après impôt + impôt sur les bénéfices comptabilisé (69 : IS, IRPP, CSS)
        // + réintégrations − déductions, soit le résultat avant impôt + réintégrations − déductions ; puis sections III à VII
        // jusqu'au résultat imposable, base de l'impôt.
        $rf = r3($P['rav'] + $L['rei'] - $L['ded']);
        $calc = TableauPassage::calcul(TableauPassage::annexe($c), $P['res'], $P['is'], $L);
        $base = max(0, $calc['imposable']);
        $th = $c->estPhysique() ? r3(PaieCalc::bareme(\App\Services\DeclarationAnnuelle::arrondiImposable($base), Setting::paie()['bar'])) : r3($base * $L['taux'] / 100);
        $du = r3(max($th, $L['min']));
        return $L + ['res' => $P['res'], 'impots' => $P['is'], 'rf' => $rf, 'calc' => $calc, 'imposable' => $calc['imposable'], 'th' => $th, 'du' => $du, 'sol' => r3($du - $L['acp'] - $L['rs'])];
    }

    private function data(Request $r): array
    {
        $client = $this->dossier($r);
        $annee = (int) $r->input('annee', now()->year);
        $F = Compta::etats($client->id, $annee);
        $Fp = Compta::etats($client->id, $annee - 1);
        $L = PassageFiscal::where('client_id', $client->id)->where('annee', $annee)->first()?->donnees;
        return compact('client', 'annee', 'F', 'Fp') + ['fisc' => static::fiscal($client, $F['P'], $L), 'saved' => (bool) $L,
            'annexe' => TableauPassage::annexe($client), 'passage' => TableauPassage::pourAffichage($L)];
    }

    public function index(Request $r)
    {
        if (! $this->dossier($r)) return view('compta.vide');
        $data = $this->data($r) + ['tab' => $r->input('tab', 'bil'), 'clients' => Client::orderBy('nom')->get(['id', 'nom'])];
        if ($data['tab'] === 'ef') {
            $data['ef'] = \App\Services\EtatsFinanciers::calculer($data['client'], $data['annee']);
            $data['infos'] = self::infosEtats($data['client']);
        }
        if ($data['tab'] === 'lf') {
            $data['doc'] = in_array($r->input('doc'), LiasseFiscale::DOCUMENTS, true) ? $r->input('doc') : 'F6001';
            $data['lf'] = LiasseFiscale::calculer($data['client'], $data['annee']);
            $data['entete'] = LiasseFiscale::entete($data['client'], $data['annee']);
        }
        return view('liasse.index', $data);
    }

    public function passage(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate(['annee' => 'required|integer', 'taux' => 'nullable|numeric', 'min' => 'nullable|numeric', 'acp' => 'nullable|numeric', 'rs' => 'nullable|numeric',
            // Montants positifs : le sens de chaque ligne est fixé par le tableau (lignes « T_NombrePositif15 » du F6005)
            'rei_l' => 'nullable|array', 'rei_l.*' => 'nullable|numeric|min:0', 'ded_l' => 'nullable|array', 'ded_l.*' => 'nullable|numeric|min:0',
            'rei_autres' => 'nullable|array|max:'.TableauPassage::AUTRES_LIGNES, 'rei_autres.*.lib' => 'nullable|max:255', 'rei_autres.*.m' => 'nullable|numeric|min:0',
            'ded_autres' => 'nullable|array|max:'.TableauPassage::AUTRES_LIGNES, 'ded_autres.*.lib' => 'nullable|max:255', 'ded_autres.*.m' => 'nullable|numeric|min:0',
            'suite' => 'nullable|array', 'suite.*' => 'nullable|numeric|min:0'],
            [], ['rei_l.*' => 'montant de réintégration', 'ded_l.*' => 'montant de déduction', 'rei_autres.*.m' => 'montant', 'ded_autres.*.m' => 'montant',
                'suite.*' => 'montant']);
        $donnees = array_map(fn ($v) => (float) $v, array_intersect_key($d, array_flip(['taux', 'min', 'acp', 'rs'])))
            + TableauPassage::donnees(TableauPassage::annexe($client), $d);
        PassageFiscal::updateOrCreate(['client_id' => $client->id, 'annee' => $d['annee']], ['donnees' => $donnees]);
        return back()->with('ok', 'Tableau de passage enregistré.');
    }

    public function print(Request $r) { return view('liasse.print', $this->data($r)); }

    // ---------- Liasse fiscale XML (cahier des charges CIMF, cas général) ----------

    private function liasse(Request $r): Liasse
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        return Liasse::firstOrCreate(['client_id' => $client->id, 'annee' => (int) $r->input('annee', now()->year)]);
    }

    public function lfEntete(Request $r)
    {
        $d = $r->validate(['annee' => 'required|integer|min:2000|max:2099', 'activite' => 'nullable|max:250', 'adresse' => 'nullable|max:250',
            'debut' => ['nullable', 'regex:#^\d{2}/\d{2}/\d{4}$#'], 'cloture' => ['nullable', 'regex:#^\d{2}/\d{2}/\d{4}$#'],
            'acte' => 'required|in:0,1,2', 'nature' => 'required|in:D,P', 'unite' => 'required|in:millimes,dinars',
            'categorie' => 'required|in:M,C,P,N', 'forme' => 'required|in:PP,SP,SC']);
        unset($d['annee']);
        $this->liasse($r)->update(['entete' => $d]);
        return back()->with('ok', 'Entête de la liasse enregistrée.');
    }

    /** Montants saisis d'un document : remplacent ceux de la balance ; vide = montant de la balance. « reinit » efface les saisies du document. */
    public function lfSaisie(Request $r)
    {
        $r->validate(['annee' => 'required|integer', 'doc' => 'required|in:'.implode(',', LiasseFiscale::DOCUMENTS), 'v' => 'nullable|array', 'v.*' => 'array', 'v.*.*' => 'nullable|numeric']);
        $doc = $r->input('doc');
        $liasse = $this->liasse($r);
        $saisies = (array) $liasse->saisies;
        if ($r->boolean('reinit')) {
            unset($saisies[$doc]);
        } else {
            $feuilles = LiasseFiscale::feuilles($doc);
            $saisies[$doc] = [];
            foreach (LiasseFiscale::SAISIE[$doc] as $col) {
                foreach ((array) $r->input("v.$col", []) as $l => $v) {
                    if ($v === null || $v === '' || ! in_array((int) $l, $feuilles, true)) continue;
                    $saisies[$doc][$col][(int) $l] = round((float) $v, 3);
                }
            }
        }
        $liasse->update(['saisies' => $saisies]);
        return back()->with('ok', $r->boolean('reinit') ? 'Montants du document '.$doc.' repris de la balance.' : 'Document '.$doc.' enregistré.');
    }

    public function lfXml(Request $r, string $doc)
    {
        abort_unless(in_array($doc, LiasseFiscale::DOCUMENTS, true), 404);
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $annee = (int) $r->input('annee', now()->year);
        $xml = LiasseFiscale::xml($doc, $client, $annee, LiasseFiscale::calculer($client, $annee));
        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.LiasseFiscale::nomFichier($doc, $client, $annee).'"']);
    }

    /** Valide les fichiers XML de l'exercice (règles du cahier des charges, et schémas XSD officiels s'ils sont déposés). */
    public function lfValider(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $annee = (int) $r->input('annee', now()->year);
        $resultats = LiasseFiscale::valider($client, $annee);
        $nb = array_sum(array_map(fn ($d) => count($d['erreurs']), $resultats));
        return redirect()->route('liasse.index', ['tab' => 'lf', 'doc' => $r->input('doc', 'F6001'), 'annee' => $annee])
            ->with('validation', $resultats)
            ->with($nb ? 'validation_ko' : 'ok', $nb ? "Validation : $nb anomalie(s) à corriger avant le dépôt." : 'Validation réussie : les fichiers de la liasse sont conformes.');
    }

    /** Dépôt des schémas XSD officiels (fichiers .xsd ou archive .zip) ; seuls les fichiers .xsd sont conservés. */
    public function lfXsd(Request $r)
    {
        $r->validate(['fichiers' => 'required|array|max:30', 'fichiers.*' => 'file|max:5120']);
        $dossier = LiasseFiscale::dossierXsd();
        if (! is_dir($dossier)) mkdir($dossier, 0775, true);
        $n = 0;
        $garder = function (string $nom, string $contenu) use ($dossier, &$n) {
            $nom = basename(str_replace('\\', '/', $nom));
            if (! preg_match('/^[\w.\-]+\.xsd$/i', $nom) || ! str_contains($contenu, 'schema')) return;
            file_put_contents($dossier.'/'.$nom, $contenu); $n++;
        };
        foreach ($r->file('fichiers') as $f) {
            if (strtolower($f->getClientOriginalExtension()) === 'zip') {
                $z = new \ZipArchive();
                if ($z->open($f->getRealPath()) !== true) continue;
                for ($i = 0; $i < $z->numFiles; $i++) {
                    $nom = $z->getNameIndex($i);
                    if (str_ends_with(strtolower($nom), '.xsd')) $garder($nom, (string) $z->getFromIndex($i));
                }
                $z->close();
            } else {
                $garder($f->getClientOriginalName(), (string) file_get_contents($f->getRealPath()));
            }
        }
        $manquants = array_values(array_filter(LiasseFiscale::DOCUMENTS, fn ($c) => ! LiasseFiscale::xsd($c)));
        if (! $n) return back()->withErrors(['fichiers' => 'Aucun fichier .xsd trouvé dans l’envoi.']);
        return back()->with('ok', $n
            ? "$n schéma(s) XSD enregistré(s).".($manquants ? ' Toujours manquants : '.implode(', ', $manquants).'.' : '')
            : 'Aucun fichier .xsd trouvé dans l’envoi.');
    }

    public function lfZip(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $annee = (int) $r->input('annee', now()->year);
        $nom = 'liasse-'.substr(LiasseFiscale::nomFichier('X', $client, $annee), 2, -4).'.zip';
        return response()->download(LiasseFiscale::zip($client, $annee), $nom, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    // ---------- États financiers complets (modèle du cabinet) ----------

    /** Informations de présentation absentes de la fiche client (activité, dates, effectif), mémorisées par dossier. */
    public static function infosEtats(Client $c): array
    {
        $effectif = \Illuminate\Support\Facades\DB::table('salaries')->where('client_id', $c->id)->count();
        return array_merge(['activite' => '', 'creation' => '', 'entree' => '', 'effectif' => $effectif ?: ''], (array) Setting::get('ef.'.$c->id, []));
    }

    public function infos(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $d = $r->validate(['activite' => 'nullable|max:255', 'creation' => 'nullable|date', 'entree' => 'nullable|date', 'effectif' => 'nullable|integer|min:0|max:100000']);
        Setting::put('ef.'.$client->id, array_map(fn ($v) => $v ?? '', $d));
        return back()->with('ok', 'Informations de présentation enregistrées.');
    }

    /** États financiers : aperçu imprimable, ou PDF paginé (« Page x of y », en-tête et pied répétés). */
    public function etatsFinanciers(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $annee = (int) $r->input('annee', now()->year);
        $data = ['client' => $client, 'ef' => \App\Services\EtatsFinanciers::calculer($client, $annee), 'infos' => self::infosEtats($client)];
        if ($r->input('format') !== 'pdf') return view('liasse.etats-financiers', $data + ['impression' => true]);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('liasse.etats-financiers', $data + ['impression' => false])
            ->setPaper('a4', 'portrait')->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dom = $pdf->getDomPDF();
        $dom->render();
        $c = $dom->getCanvas();
        $police = $dom->getFontMetrics()->getFont('DejaVu Sans');
        [$l, $h] = [$c->get_width(), $c->get_height()];
        $gris = [0.35, 0.35, 0.35];
        $c->page_text(42, 30, 'ÉTATS FINANCIERS ARRÊTÉS AU 31/12/'.$annee, $police, 7.5, $gris);
        $c->page_text($l - 110, 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $police, 7.5, $gris);
        $c->line(42, 42, $l - 42, 42, $gris, 0.5);
        $c->line(42, $h - 40, $l - 42, $h - 40, $gris, 0.5);
        $c->page_text(42, $h - 32, $client->nom, $police, 7.5, $gris);
        if ($client->matricule_fiscal) $c->page_text($l - 160, $h - 32, $client->matricule_fiscal, $police, 7.5, $gris);
        return response($dom->output(), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.\Illuminate\Support\Str::slug('etats-financiers-'.$annee.'-'.$client->nom).'.pdf"']);
    }
}
