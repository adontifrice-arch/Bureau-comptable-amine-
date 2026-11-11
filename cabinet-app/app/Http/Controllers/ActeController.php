<?php

namespace App\Http\Controllers;

use App\Models\Acte;
use App\Models\Client;
use App\Models\ModeleActe;
use App\Services\Actes\Balises;
use App\Services\Actes\HtmlActe;
use App\Services\Actes\ModeleDocx;
use App\Services\Actes\ModeleTexte;
use App\Services\Actes\OrdreDuJour;
use App\Services\Actes\Statuts;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Rédaction des statuts, PV d'assemblée et contrats de cession de parts. */
class ActeController extends Controller
{
    public function create(Client $client)
    {
        return view('actes.create', ['client' => $client->load('associes'), 'types' => self::types(), 'odd' => OrdreDuJour::tous(),
            'modeles' => ModeleActe::orderBy('nom')->get(['id', 'type', 'nom', 'format'])]);
    }

    /** Ajout d'un point personnalisé à l'ordre du jour d'un type de PV (conservé pour les prochains PV). */
    public function ordreAjouter(Request $r)
    {
        $d = $r->validate(['type' => 'required|in:'.implode(',', OrdreDuJour::TYPES), 'point' => 'required|string|max:500']);
        OrdreDuJour::ajouter($d['type'], $d['point']);
        $this->log('Ordre du jour '.strtoupper($d['type']).' : ajout du point « '.OrdreDuJour::normaliser($d['point']).' »');
        return response()->json(OrdreDuJour::tous());
    }

    public function ordreRetirer(Request $r)
    {
        $d = $r->validate(['type' => 'required|in:'.implode(',', OrdreDuJour::TYPES), 'point' => 'required|string|max:500']);
        OrdreDuJour::retirer($d['type'], $d['point']);
        $this->log('Ordre du jour '.strtoupper($d['type']).' : retrait du point « '.$d['point'].' »');
        return response()->json(OrdreDuJour::tous());
    }

    public static function types(): Collection
    {
        return collect(config('cabinet.TY'))->mapWithKeys(fn ($t) => [$t[0] => $t[1]]);
    }

    public function store(Request $r, Client $client)
    {
        $d = $r->validate(['type' => 'required|in:'.self::types()->keys()->implode(','), 'date' => 'required|date', 'modele_id' => 'nullable|integer|exists:modeles_actes,id',
            'objet' => 'nullable|max:2000', 'duree' => 'nullable|integer|min:1|max:99',
            'ordre' => 'nullable|array', 'ordre.*' => 'string|max:500', 'ordre_libre' => 'nullable|max:2000', 'motif' => 'nullable|max:500', 'date_cessation' => 'nullable|date',
            'cedant' => 'nullable|max:255', 'cessionnaire' => 'nullable|max:255', 'cessionnaire_cin' => 'nullable|max:50', 'cessionnaire_adresse' => 'nullable|max:255',
            'parts' => 'nullable|integer|min:1', 'parts_de' => 'nullable|integer|min:1|required_with:parts_a', 'parts_a' => 'nullable|integer|gte:parts_de|required_with:parts_de',
            'prix' => 'nullable|numeric|min:0']);
        $d += array_fill_keys(['objet', 'duree', 'ordre', 'ordre_libre', 'motif', 'date_cessation', 'cedant', 'cessionnaire', 'cessionnaire_cin', 'cessionnaire_adresse', 'parts', 'parts_de', 'parts_a', 'prix'], null);
        if (empty($d['parts']) && $d['parts_de'] && $d['parts_a']) $d['parts'] = $d['parts_a'] - $d['parts_de'] + 1;
        if (in_array($d['type'], OrdreDuJour::TYPES, true) && $d['ordre']) {
            // L'ordre du PV est celui de la liste, quel que soit l'ordre d'envoi des cases cochées.
            $liste = array_column(OrdreDuJour::points($d['type']), 'texte');
            $d['ordre'] = array_values(array_merge(array_intersect($liste, $d['ordre']), array_diff($d['ordre'], $liste)));
        }
        $modele =! empty($d['modele_id']) ? ModeleActe::find($d['modele_id']) : null;
        unset($d['modele_id']);
        if ($modele && $modele->type !== $d['type']) return back()->withInput()->withErrors(['modele_id' => 'Ce modèle n’est pas prévu pour ce type de document.']);

        $client->load('associes');
        try {
            $contenu = $modele ? $this->depuisModele($modele, $client, $d) : $this->generer($client, $d);
        } catch (RuntimeException $ex) {
            return back()->withInput()->withErrors(['modele_id' => $ex->getMessage()]);
        }
        $acte = Acte::create(['client_id' => $client->id, 'type' => $d['type'], 'modele_id' => $modele?->id, 'date' => $d['date'], 'donnees' => $d,
            'titre' => self::types()[$d['type']].' – '.dfr($d['date']), 'contenu' => HtmlActe::nettoyer($contenu)]);
        $this->log('Rédaction : '.$acte->titre.($modele ? ' (modèle « '.$modele->nom.' »)' : ''), $client->id);
        return redirect()->route('actes.edit', $acte)->with('ok', 'Brouillon généré : relisez-le et modifiez-le avant signature.');
    }

    /** Brouillon HTML à partir d'un modèle importé. */
    private function depuisModele(ModeleActe $m, Client $c, array $d): string
    {
        $chemin = Storage::path($m->fichier);
        if (! is_file($chemin)) throw new RuntimeException('Le fichier du modèle « '.$m->nom.' » est introuvable.');
        $valeurs = Balises::valeurs($c, $d);
        if ($m->format === 'txt') return ModeleTexte::html(Balises::remplacer(ModeleTexte::lire($chemin), $valeurs));

        $tmp = tempnam(sys_get_temp_dir(), 'acte');
        try {
            ModeleDocx::remplir($chemin, $valeurs, $tmp);
            return ModeleDocx::html($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Document Word rempli depuis le modèle d'origine (mise en forme du modèle conservée, données actuelles de la fiche). */
    public function docx(Acte $acte)
    {
        $m = $acte->modele;
        abort_unless($m && $m->format === 'docx' && Storage::exists($m->fichier), 404);
        $d = ($acte->donnees ?? []) + ['type' => $acte->type, 'date' => $acte->date?->format('Y-m-d')];
        $tmp = tempnam(sys_get_temp_dir(), 'acte');
        ModeleDocx::remplir(Storage::path($m->fichier), Balises::valeurs($acte->client->load('associes'), $d), $tmp);
        return response()->download($tmp, self::nomFichier($acte).'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])->deleteFileAfterSend();
    }

    private static function nomFichier(Acte $acte): string
    {
        return Str::slug(str_replace('/', '-', $acte->client->nom.' '.$acte->titre));
    }

    public function edit(Acte $acte) { return view('actes.edit', ['acte' => $acte->load('client', 'modele')]); }

    public function update(Request $r, Acte $acte)
    {
        $d = $r->validate(['titre' => 'required|max:255', 'contenu' => 'required|string|max:2000000']);
        $d['contenu'] = HtmlActe::nettoyer($d['contenu']);
        if (trim(strip_tags($d['contenu'])) === '') return back()->withErrors(['contenu' => 'Le document est vide.']);
        $acte->update($d);
        return back()->with('ok', 'Document enregistré.');
    }

    /** Export PDF A4 paginé « Page i / n ». */
    public function pdf(Acte $acte)
    {
        $pdf = Pdf::loadView('actes.pdf', ['acte' => $acte, 'contenu' => HtmlActe::nettoyer($acte->contenu)])
            ->setPaper('a4', 'portrait')
            ->setOption(['defaultFont' => 'DejaVu Serif', 'isRemoteEnabled' => false, 'isPhpEnabled' => false]);
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $police = $dompdf->getFontMetrics()->getFont('Helvetica');
        $texte = 'Page {PAGE_NUM} / {PAGE_COUNT}';
        $largeur = $dompdf->getFontMetrics()->getTextWidth('Page 00 / 00', $police, 8);
        $canvas->page_text(($canvas->get_width() - $largeur) / 2, $canvas->get_height() - 28, $texte, $police, 8, [0.4, 0.4, 0.4]);

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.self::nomFichier($acte).'.pdf"',
        ]);
    }

    public function destroy(Acte $acte)
    {
        $c = $acte->client_id; $acte->delete();
        return redirect()->route('clients.show', [$c, 'tab' => 'actes'])->with('ok', 'Document supprimé.');
    }

    private function generer(Client $c, array $d): string
    {
        $e = fn ($s) => e((string) $s);
        $P = fn ($s) => '<p>'.$s.'</p>';
        $H = fn ($s) => '<h3>'.$s.'</h3>';
        $cap = $c->capital > 0 ? tnd($c->capital) : '………';
        $vn = $c->valeur_nominale ? tnd($c->valeur_nominale) : '………';
        $lieu = Balises::lieu($c);
        $id = fn ($x) => $e(Balises::identite($x));
        $as = Balises::detenteurs($c);
        $np = $c->nb_parts ?: $as->sum('p');
        $entete = '<p style="text-align:center"><b>'.$e(mb_strtoupper($c->nom)).'</b><br>'.$e($c->forme).' au capital de '.$cap.'<br>Siège social : '.$e($c->adresse ?: '………').($c->matricule_fiscal ? '<br>Matricule fiscal : '.$e($c->matricule_fiscal) : '').'</p>';
        $sig = '<p style="margin-top:30px">'.$as->map(fn ($a) => $e($a['nom']).' : ……………………')->implode('<br>').'</p>';
        $t = $d['type'];

        if (in_array($t, ['sarl', 'suarl'])) return Statuts::html($c, $d, $entete);

        if ($t === 'cession') {
            $ced = $as->first(fn ($a) => mb_strtolower(trim($a['nom'])) === mb_strtolower(trim($d['cedant'] ?? ''))) ?? ['nom' => $d['cedant'] ?: '………'];
            $h = '<h3 style="text-align:center">CONTRAT DE CESSION DE PARTS SOCIALES</h3>'.$entete.$P('<b>ENTRE LES SOUSSIGNÉS :</b>');
            $h .= $P('1) '.$id($ced).', ci-après dénommé(e) « le Cédant »,').$P('ET').$P('2) '.$e($d['cessionnaire'] ?: '………').($d['cessionnaire_cin'] ? ', titulaire de la CIN n° '.$e($d['cessionnaire_cin']) : '').($d['cessionnaire_adresse'] ? ', demeurant à '.$e($d['cessionnaire_adresse']) : '').', ci-après dénommé(e) « le Cessionnaire »,');
            $h .= $P('<b>IL A ÉTÉ CONVENU ET ARRÊTÉ CE QUI SUIT :</b>').$H('Article 1 – Cession').$P('Le Cédant cède, sous les garanties ordinaires de fait et de droit, au Cessionnaire qui accepte, '.($d['parts'] ?: '………').' parts sociales de '.$vn.' chacune'.($d['parts_de'] ? ', numérotées de '.$d['parts_de'].' à '.$d['parts_a'].' inclus' : '').', qu’il détient dans le capital de la société '.$e($c->nom).'.');
            $h .= $H('Article 2 – Prix').$P('La présente cession est consentie et acceptée moyennant le prix global de '.(! empty($d['prix']) ? tnd($d['prix']) : '………').', que le Cédant reconnaît avoir reçu, dont quittance.');
            $h .= $H('Article 3 – Propriété – Jouissance').$P('Le Cessionnaire est propriétaire des parts cédées à compter de ce jour et a droit aux bénéfices à compter de la même date.');
            $h .= $H('Article 4 – Agrément').$P('La présente cession a été agréée conformément aux statuts et au Code des sociétés commerciales.');
            $h .= $H('Article 5 – Formalités').$P('Les parties donnent tous pouvoirs au porteur d’un original pour accomplir les formalités d’enregistrement et de publicité. Les droits d’enregistrement sont à la charge du Cessionnaire.');
            return $h.$P('Fait à '.$e($lieu).', le '.dlong($d['date']).', en quatre exemplaires.').'<p style="margin-top:30px">Le Cédant : ……………………<br>Le Cessionnaire : ……………………</p>';
        }

        // Procès-verbaux
        $items = Balises::ordreDuJour($d);
        $lab = ['ago' => 'ORDINAIRE', 'age' => 'EXTRAORDINAIRE', 'mixte' => 'MIXTE', 'cess' => 'EXTRAORDINAIRE'][$t];
        $h = '<h3 style="text-align:center">'.($t === 'cess' ? 'PROCÈS-VERBAL DE CESSATION DÉFINITIVE D’ACTIVITÉ' : 'PROCÈS-VERBAL DE L’ASSEMBLÉE GÉNÉRALE '.$lab).'</h3>'.$entete;
        $h .= $P('L’an '.\Carbon\Carbon::parse($d['date'])->year.', le '.dlong($d['date']).', à ……… heures, les associés de la société '.$e($c->nom).' se sont réunis au siège social en assemblée générale '.strtolower($lab).', sur convocation de la gérance.');
        $h .= $P('Sont présents ou représentés :').'<ul>'.$as->map(fn ($a) => '<li>'.$e($a['nom']).' : '.($a['p'] ?: '………').' parts</li>')->implode('').'</ul>';
        $h .= $P('Soit au total '.($np ?: '………').' parts sur les '.($np ?: '………').' parts composant le capital social. L’assemblée, régulièrement constituée, peut valablement délibérer. Elle est présidée par '.$e($c->gerant_nom ?: '………').', gérant.');
        $h .= $P('Le président rappelle que l’assemblée est appelée à délibérer sur l’ordre du jour suivant :').'<ol>'.$items->map(fn ($x) => '<li>'.$e($x).'</li>')->implode('').'</ol>';
        foreach ($items as $i => $x) {
            $txt = $e(Balises::resolution($x, $d));
            $h .= $H(($i + 1).($i ? 'e' : 're').' résolution').$P($txt).$P('<i>Cette résolution, mise aux voix, est adoptée à l’unanimité.</i>');
        }
        $h .= $P('L’ordre du jour étant épuisé, la séance est levée. De tout ce qui précède, il a été dressé le présent procès-verbal signé par les associés présents.');
        return $h.$sig;
    }
}
