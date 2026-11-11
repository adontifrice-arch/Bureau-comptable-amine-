<?php

namespace App\Http\Controllers;

use App\Models\Acte;
use App\Models\Client;
use App\Models\ModeleActe;
use App\Services\Actes\Balises;
use App\Services\Actes\ModeleDocx;
use App\Services\Actes\ModeleTexte;
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
        return view('actes.create', ['client' => $client->load('associes'), 'types' => self::types(),
            'modeles' => ModeleActe::orderBy('nom')->get(['id', 'type', 'nom', 'format'])]);
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
        $modele = ! empty($d['modele_id']) ? ModeleActe::find($d['modele_id']) : null;
        unset($d['modele_id']);
        if ($modele && $modele->type !== $d['type']) return back()->withInput()->withErrors(['modele_id' => 'Ce modèle n’est pas prévu pour ce type de document.']);

        $client->load('associes');
        try {
            $contenu = $modele ? $this->depuisModele($modele, $client, $d) : $this->generer($client, $d);
        } catch (RuntimeException $ex) {
            return back()->withInput()->withErrors(['modele_id' => $ex->getMessage()]);
        }
        $acte = Acte::create(['client_id' => $client->id, 'type' => $d['type'], 'modele_id' => $modele?->id, 'date' => $d['date'], 'donnees' => $d,
            'titre' => self::types()[$d['type']].' – '.dfr($d['date']), 'contenu' => $contenu]);
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
        return response()->download($tmp, Str::slug($acte->client->nom.' '.$acte->titre).'.docx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])->deleteFileAfterSend();
    }

    public function edit(Acte $acte) { return view('actes.edit', ['acte' => $acte->load('client')]); }

    public function update(Request $r, Acte $acte)
    {
        $acte->update($r->validate(['titre' => 'required|max:255', 'contenu' => 'required']));
        return back()->with('ok', 'Document enregistré.');
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
        $ger = Balises::gerant($c);
        $np = $c->nb_parts ?: $as->sum('p');
        $entete = '<p style="text-align:center"><b>'.$e(mb_strtoupper($c->nom)).'</b><br>'.$e($c->forme).' au capital de '.$cap.'<br>Siège social : '.$e($c->adresse ?: '………').($c->matricule_fiscal ? '<br>Matricule fiscal : '.$e($c->matricule_fiscal) : '').'</p>';
        $sig = '<p style="margin-top:30px">'.$as->map(fn ($a) => $e($a['nom']).' : ……………………')->implode('<br>').'</p>';
        $t = $d['type'];

        if (in_array($t, ['sarl', 'suarl'])) {
            $uni = $t === 'suarl';
            $h = '<h3 style="text-align:center">STATUTS<br>'.($uni ? 'SOCIÉTÉ UNIPERSONNELLE À RESPONSABILITÉ LIMITÉE' : 'SOCIÉTÉ À RESPONSABILITÉ LIMITÉE').'</h3>'.$entete;
            $h .= $P($uni ? 'Le soussigné :' : 'Les soussignés :').'<ul>'.$as->map(fn ($a) => '<li>'.$id($a).'</li>')->implode('').'</ul>';
            $h .= $P(($uni ? 'a établi' : 'ont établi').' ainsi qu’il suit les statuts d’une société à responsabilité limitée'.($uni ? ' unipersonnelle' : '').' régie par le Code des sociétés commerciales et par les présents statuts.');
            $h .= $H('Article 1 – Forme').$P('Il est formé une société à responsabilité limitée'.($uni ? ' unipersonnelle' : '').' régie par les lois en vigueur et par les présents statuts.');
            $h .= $H('Article 2 – Dénomination').$P('La société prend la dénomination : <b>'.$e($c->nom).'</b>'.($c->denomination_commerciale ? ', nom commercial « '.$e($c->denomination_commerciale).' »' : '').'.'.($c->residence ? ' La société est '.($c->residence === 'residente' ? 'résidente' : 'non résidente').(['non' => ' et non exportatrice', 'totale' => ' et totalement exportatrice', 'partielle' => ' et partiellement exportatrice'][$c->regime_export] ?? '').'.' : ''));
            $h .= $H('Article 3 – Objet').$P($e($d['objet'] ?: '[Objet social à préciser]').'. Et plus généralement, toutes opérations commerciales, financières, mobilières ou immobilières se rattachant directement ou indirectement à l’objet social.');
            $h .= $H('Article 4 – Siège social').$P('Le siège social est fixé à '.$e($c->adresse ?: '………').'. Il pourra être transféré en tout autre lieu par décision '.($uni ? 'de l’associé unique' : 'de l’assemblée générale extraordinaire').'.');
            $h .= $H('Article 5 – Durée').$P('La durée de la société est fixée à '.($d['duree'] ?: 99).' années à compter de son immatriculation au registre national des entreprises, sauf dissolution anticipée ou prorogation.');
            $h .= $H('Article 6 – Apports').$P('Il est fait apport à la société des sommes suivantes :').'<ul>'.$as->map(fn ($a) => '<li>'.$e($a['nom']).' : apport en '.(($a['ap'] ?? 'numeraire') === 'nature' ? 'nature' : 'numéraire').' de '.($c->valeur_nominale && $a['p'] ? tnd($a['p'] * $c->valeur_nominale) : '………').'</li>')->implode('').'</ul>'.$P('Soit au total la somme de '.$cap.', intégralement libérée et déposée au compte de la société.');
            $h .= $H('Article 7 – Capital social').$P('Le capital social est fixé à '.$cap.', divisé en '.($np ?: '………').' parts sociales de '.$vn.' chacune, entièrement souscrites et libérées, attribuées comme suit :').'<ul>'.$as->map(fn ($a) => '<li>'.$e($a['nom']).' : '.($a['p'] ?: '………').' parts'.($a['pd'] ? ', numérotées de '.$a['pd'].' à '.$a['pf'] : '').'</li>')->implode('').'</ul>';
            $h .= $H('Article 8 – Cession et transmission des parts').$P($uni ? 'Les parts sont librement cessibles par l’associé unique. Toute cession est constatée par écrit et rendue opposable à la société dans les formes légales.' : 'Les parts sociales ne peuvent être cédées à des tiers étrangers à la société qu’avec le consentement de la majorité des associés représentant au moins les trois quarts du capital social, conformément au Code des sociétés commerciales.');
            $h .= $H('Article 9 – Gérance').$P('La société est gérée par une ou plusieurs personnes physiques, associées ou non. Est nommé(e) premier gérant pour une durée '.$e($c->gerant_nomination_duree ?: 'illimitée').' : '.$id($ger).'. Le gérant dispose des pouvoirs les plus étendus pour agir au nom de la société.');
            $h .= $H('Article 10 – Décisions collectives').$P($uni ? 'L’associé unique exerce les pouvoirs dévolus à l’assemblée des associés. Ses décisions sont consignées dans un registre.' : 'Les décisions ordinaires sont adoptées par un ou plusieurs associés représentant plus de la moitié du capital ; les décisions modifiant les statuts sont adoptées par les associés représentant au moins les trois quarts du capital.');
            $h .= $H('Article 11 – Exercice social').$P('L’exercice social commence le 1er janvier et se termine le 31 décembre. Par exception, le premier exercice commence à l’immatriculation de la société.');
            $h .= $H('Article 12 – Affectation des résultats').$P('Sur le bénéfice net, il est prélevé 5 % pour constituer la réserve légale jusqu’à ce que celle-ci atteigne le dixième du capital. Le solde est affecté sur décision '.($uni ? 'de l’associé unique' : 'des associés').'.');
            $h .= $H('Article 13 – Dissolution – Liquidation').$P('À l’expiration de la société ou en cas de dissolution anticipée, la liquidation est effectuée conformément à la loi.');
            $h .= $H('Article 14 – Publicité – Pouvoirs').$P('Tous pouvoirs sont donnés au porteur d’une copie des présentes pour accomplir les formalités de dépôt et de publicité prévues par la loi.');
            return $h.$P('Fait à '.$e($lieu).', le '.dlong($d['date']).', en quatre exemplaires originaux.').$sig;
        }

        if ($t === 'cession') {
            $ced = $as->first(fn ($a) => mb_strtolower(trim($a['nom'])) === mb_strtolower(trim($d['cedant'] ?? ''))) ?? ['nom' => $d['cedant'] ?: '………'];
            $h = '<h3 style="text-align:center">CONTRAT DE CESSION DE PARTS SOCIALES</h3>'.$entete.$P('<b>ENTRE LES SOUSSIGNÉS :</b>');
            $h .= $P('1) '.$id($ced).', ci-après dénommé(e) « le Cédant »,').$P('ET').$P('2) '.$e($d['cessionnaire'] ?: '………').($d['cessionnaire_cin'] ? ', titulaire de la CIN n° '.$e($d['cessionnaire_cin']) : '').($d['cessionnaire_adresse'] ? ', demeurant à '.$e($d['cessionnaire_adresse']) : '').', ci-après dénommé(e) « le Cessionnaire »,');
            $h .= $P('<b>IL A ÉTÉ CONVENU ET ARRÊTÉ CE QUI SUIT :</b>').$H('Article 1 – Cession').$P('Le Cédant cède, sous les garanties ordinaires de fait et de droit, au Cessionnaire qui accepte, '.($d['parts'] ?: '………').' parts sociales de '.$vn.' chacune'.($d['parts_de'] ? ', numérotées de '.$d['parts_de'].' à '.$d['parts_a'] : '').', qu’il détient dans le capital de la société '.$e($c->nom).'.');
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
