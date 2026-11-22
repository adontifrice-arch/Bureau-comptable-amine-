<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Paie;
use App\Models\PaieProfil;
use App\Models\PaieRubrique;
use App\Models\Salarie;
use App\Models\Setting;
use App\Services\PaieCalc;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Paie — menu Fichier : renseignements employeur, sauvegarde et restauration, import des employés, mise à jour du dossier, sécurité, calculatrice. */
class PaieDossierController extends Controller
{
    /** Champs d'un salarié repris par la sauvegarde, la restauration et l'import. */
    private const CHAMPS = ['nom', 'cin', 'cnss', 'poste', 'salaire_base', 'chef_famille', 'enfants', 'date_embauche', 'matricule', 'mode_paiement', 'banque', 'rib', ...Salarie::CARRIERE, ...Salarie::FICHE];

    private const EMPLOYEUR = ['nom', 'forme', 'matricule_fiscal', 'matricule_cnss', 'cnss_bureau', 'paie_activite', 'adresse', 'telephone', 'email', 'gerant_nom', 'paie_banque', 'paie_rib', 'paie_config'];

    /** Statuts juridiques proposés (la valeur enregistrée reste la forme courte utilisée par la fiche client). */
    public const STATUTS = [
        'SARL' => 'Société à responsabilité limitée (S.A.R.L)', 'SUARL' => 'Société unipersonnelle à responsabilité limitée (S.U.A.R.L)',
        'SA' => 'Société anonyme (S.A)', 'SAS' => 'Société par actions simplifiée (S.A.S)', 'SNC' => 'Société en nom collectif (S.N.C)',
        'SCS' => 'Société en commandite simple (S.C.S)', 'GIE' => 'Groupement d’intérêt économique (G.I.E)', 'Personne physique' => 'Personne physique',
        'Association' => 'Association', 'Établissement public' => 'Établissement public',
    ];

    /** Fichier → Renseignements employeur : onglets Renseignements, Informations générales, Informations spécifiques et Logo. */
    public function employeur(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate([
            // Renseignements
            'nom' => 'required|max:255', 'adresse' => 'required|max:255', 'ville' => 'required|max:100', 'code_postal' => 'nullable|max:10',
            'telephone' => 'nullable|max:30', 'fax' => 'nullable|max:30', 'email' => 'nullable|email|max:255',
            'cnss_mat' => 'required|max:20', 'cnss_cle' => 'required|max:4', 'cnss_bureau' => 'nullable|max:10', 'paie_activite' => 'required|max:255', 'site_web' => 'nullable|max:255',
            'nb_employes' => 'nullable|integer|min:0', 'heures_semaine' => 'nullable|numeric|min:0|max:80',
            // Informations générales
            'responsable_nom' => 'required|max:255', 'responsable_qualification' => 'required|max:100', 'forme' => 'required|max:50',
            'rne' => 'nullable|max:30', 'matricule_fiscal' => 'required|max:30', 'paie_banque' => 'nullable|max:100', 'agence' => 'nullable|max:100',
            'paie_rib' => 'nullable|max:30', 'cabinet_paie' => 'nullable|max:255',
            // Informations spécifiques
            'fop' => 'nullable|numeric|min:0|max:10', 'tfp' => 'nullable|in:1,2', 'arrondi' => 'required|integer|min:0|max:3', 'majoration_hs' => 'nullable|numeric|min:0|max:200',
            'regul_irpp' => 'required|boolean', 'regul_primes' => 'required|in:mois,difference,regul',
            'cnssS' => 'required|numeric|min:0|max:30', 'at' => 'required|numeric|min:0|max:10',
            'solde_conge' => 'nullable|in:dernier,moyenne', 'tri' => 'required|in:nom,matricule', 'format_fiche' => 'nullable|in:a4,a5',
            'smig_mensuel' => 'required|numeric|min:0', 'smig_horaire' => 'required|numeric|min:0', 'plafond_rc' => 'nullable|numeric|min:0', 'rc_taux_s' => 'nullable|numeric|min:0|max:30', 'rc_taux_p' => 'nullable|numeric|min:0|max:30',
            // Logo
            'logo' => 'nullable|image|max:2048',
        ], [], ['cnss_mat' => 'n° employeur (matricule)', 'cnss_cle' => 'n° employeur (clé)', 'paie_activite' => 'activité', 'responsable_nom' => 'nom du responsable',
            'responsable_qualification' => 'qualification', 'forme' => 'statut', 'matricule_fiscal' => 'matricule fiscal', 'cnssS' => 'CNSS salariale',
            'at' => 'accident de travail', 'smig_mensuel' => 'SMIG mensuel', 'smig_horaire' => 'SMIG horaire', 'tri' => 'trier par']);

        $k = $client->paie_config ?? [];
        foreach (['ville', 'code_postal', 'fax', 'site_web', 'nb_employes', 'heures_semaine', 'responsable_nom', 'responsable_qualification', 'rne', 'agence', 'cabinet_paie',
            'fop', 'tfp', 'arrondi', 'majoration_hs', 'regul_irpp', 'regul_primes', 'cnssS', 'at', 'solde_conge', 'tri', 'format_fiche', 'smig_mensuel', 'smig_horaire', 'plafond_rc', 'rc_taux_s', 'rc_taux_p'] as $c) {
            $k[$c] = $d[$c] ?? null;
        }
        foreach (['fop_actif', 'tfp_actif', 'notif_demarrage', 'afficher_logo'] as $c) $k[$c] = $r->boolean($c);
        foreach (['arrondi', 'nb_employes', 'tfp'] as $c) if ($k[$c] !== null) $k[$c] = (int) $k[$c];
        foreach (['fop', 'majoration_hs', 'cnssS', 'at', 'heures_semaine', 'smig_mensuel', 'smig_horaire', 'plafond_rc', 'rc_taux_s', 'rc_taux_p'] as $c) if ($k[$c] !== null) $k[$c] = (float) $k[$c];
        $k['regul_irpp'] = (bool) $k['regul_irpp'];

        if ($r->boolean('retirer_logo') && ! empty($k['logo'])) { Storage::disk('public')->delete($k['logo']); $k['logo'] = null; }
        if ($r->hasFile('logo')) {
            if (! empty($k['logo'])) Storage::disk('public')->delete($k['logo']);
            $k['logo'] = $r->file('logo')->store('logos-employeurs/'.$client->id, 'public');
        }

        $client->update([
            'nom' => $d['nom'], 'adresse' => $d['adresse'], 'telephone' => $d['telephone'] ?? null, 'email' => $d['email'] ?? null, 'forme' => $d['forme'],
            'matricule_cnss' => trim($d['cnss_mat']).'-'.trim($d['cnss_cle']), 'cnss_bureau' => isset($d['cnss_bureau']) ? trim($d['cnss_bureau']) : null, 'paie_activite' => $d['paie_activite'], 'matricule_fiscal' => $d['matricule_fiscal'],
            'paie_banque' => $d['paie_banque'] ?? null, 'paie_rib' => $d['paie_rib'] ?? null, 'paie_config' => $k,
        ]);
        $this->log('Paie : renseignements employeur mis à jour', $client->id);
        return back()->with('ok', 'Renseignements employeur enregistrés.');
    }

    /** Sauvegarde du dossier de paie : fichier JSON (employeur, employés, paies). */
    public function sauvegarde(Request $r)
    {
        $client = $this->dossier($r);
        $data = [
            'format' => 'cabinet-paie', 'version' => 1, 'exporte_le' => now()->toIso8601String(),
            'employeur' => $client->only(self::EMPLOYEUR),
            'salaries' => $client->salaries()->orderBy('nom')->get()->map(fn ($e) => ['id' => $e->id]
                + collect($e->only(self::CHAMPS))->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)->all())->all(),
            'profils' => PaieProfil::duDossier($client)->map(fn ($p) => $p->only(['nom', 'cnss_patronale', 'code_exploitant', ...array_keys(PaieProfil::OPTIONS)]))->all(),
            'rubriques' => PaieRubrique::duDossier($client)->map(fn ($x) => collect($x->toArray())->except(['cabinet_id', 'client_id', 'created_at', 'updated_at'])->all())->all(),
            'paies' => Paie::where('client_id', $client->id)->orderBy('mois')->get(['mois', 'type', 'libelle', 'rubrique_id', 'statut', 'primes', 'pointage', 'rubriques', 'resultats', 'totaux', 'jours_ouvrables', 'jours_feries', 'heures_jour', 'heures_ouvrables', 'options'])->toArray(),
        ];
        $this->log('Paie : sauvegarde du dossier', $client->id);
        return response()->streamDownload(fn () => print(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            'sauvegarde-paie-'.Str::slug($client->nom).'-'.now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json']);
    }

    /**
     * Restauration d'une sauvegarde, sans rien écraser : les employés sont rapprochés par CIN (sinon par nom) et mis à jour,
     * les paies d'un mois déjà présent dans le dossier sont ignorées, les renseignements employeur ne complètent que les champs vides.
     * Une paie restaurée revient « transmise » : elle doit être validée en comptabilité dans ce dossier.
     */
    public function restaurer(Request $r)
    {
        $client = $this->dossier($r);
        $r->validate(['fichier' => 'required|file|max:10240']);
        $data = json_decode((string) file_get_contents($r->file('fichier')->getRealPath()), true);
        if (! is_array($data) || ($data['format'] ?? null) !== 'cabinet-paie') return back()->with('err', 'Ce fichier n’est pas une sauvegarde de paie.');

        [$nbSal, $nbPaies] = DB::transaction(function () use ($client, $data) {
            foreach (self::EMPLOYEUR as $k) if (empty($client->$k) && ! empty($data['employeur'][$k])) $client->$k = $data['employeur'][$k];
            $client->save();
            $ids = [];
            foreach ($data['salaries'] ?? [] as $s) {
                if (empty($s['nom'])) continue;
                $ids[$s['id'] ?? 0] = $this->enregistrerSalarie($client, array_intersect_key($s, array_flip(self::CHAMPS)))->id;
            }
            // Profils : ajoutés s'ils n'existent pas encore dans le dossier (rapprochement par nom), sans modifier les profils existants.
            $profilsExistants = PaieProfil::parNom($client);
            foreach ((array) ($data['profils'] ?? []) as $p) {
                $nom = trim((string) ($p['nom'] ?? ''));
                if ($nom === '' || $profilsExistants->has(mb_strtolower($nom))) continue;
                PaieProfil::create(['client_id' => $client->id, 'nom' => $nom] + array_intersect_key($p, array_flip(['cnss_patronale', 'code_exploitant', ...array_keys(PaieProfil::OPTIONS)])));
            }
            $rids = $this->restaurerRubriques($client, (array) ($data['rubriques'] ?? []));
            $existants = Paie::where('client_id', $client->id)->get(['mois', 'type', 'libelle'])->map(fn ($p) => $p->mois.'|'.$p->type.'|'.$p->libelle)->all();
            $n = 0;
            foreach ($data['paies'] ?? [] as $p) {
                if (! preg_match('/^\d{4}-\d{2}$/', $p['mois'] ?? '') || in_array($p['mois'].'|'.($type = in_array($p['type'] ?? 'normale', array_keys(Paie::TYPES), true) ? ($p['type'] ?? 'normale') : 'normale').'|'.(string) ($p['libelle'] ?? ''), $existants, true)) continue;
                $primes = [];
                foreach ((array) ($p['primes'] ?? []) as $id => $v) if (isset($ids[$id])) $primes[$ids[$id]] = (float) $v;
                $pointage = [];
                foreach ((array) ($p['pointage'] ?? []) as $id => $v) if (isset($ids[$id])) $pointage[$ids[$id]] = $v;
                $saisie = [];
                foreach ((array) ($p['rubriques'] ?? []) as $id => $vals) {
                    if (! isset($ids[$id])) continue;
                    foreach ((array) $vals as $rid => $v) if (isset($rids[$rid])) $saisie[$ids[$id]][$rids[$rid]] = $v;
                }
                $res = array_map(fn ($x) => ['salarie_id' => $ids[$x['salarie_id'] ?? 0] ?? null] + $x, (array) ($p['resultats'] ?? []));
                Paie::create(['client_id' => $client->id, 'mois' => $p['mois'], 'primes' => $primes, 'type' => $type, 'libelle' => (string) ($p['libelle'] ?? ''), 'rubrique_id' => $rids[$p['rubrique_id'] ?? 0] ?? null, 'pointage' => $type === 'normale' ? PaieCalc::pointage($pointage) : $pointage, ...array_intersect_key($p, array_flip(['jours_ouvrables', 'jours_feries', 'heures_jour', 'heures_ouvrables', 'options'])), 'rubriques' => PaieCalc::saisieRubriques($saisie) ?: null, 'resultats' => $res ?: null, 'totaux' => $p['totaux'] ?? null,
                    'statut' => ($p['statut'] ?? 'brouillon') === 'brouillon' ? 'brouillon' : 'transmise']);
                $n++;
            }
            return [count($ids), $n];
        });
        $this->log("Paie : restauration d'une sauvegarde ($nbSal employé(s), $nbPaies paie(s))", $client->id);
        return back()->with('ok', "Sauvegarde restaurée : $nbSal employé(s) créé(s) ou mis à jour, $nbPaies paie(s) ajoutée(s).");
    }

    /** Importation → Modèle du personnel : fichier Excel prêt à remplir, prérempli avec les employés du dossier. */
    public function modeleImport(Request $r)
    {
        $client = $this->dossier($r);
        $entetes = ['Matricule', 'Nom et prénom', 'CIN', 'N° CNSS', 'Poste', 'Salaire de base', 'Chef de famille (O/N)', 'Enfants à charge', 'Date d’embauche', 'Mode de paiement', 'Banque', 'RIB',
            'Date de naissance', 'Type de contrat', 'Début du contrat', 'Fin du contrat', 'Catégorie', 'Échelon'];
        $modes = ['virement' => 'Virement', 'cheque' => 'Chèque', 'especes' => 'Espèces'];
        $sal = $client ? PaieCalc::trier($client->salaries()->get(), PaieCalc::parametres($client)['tri']) : collect();
        $lignes = $sal->isEmpty()
            ? [['E01', 'Ben Ali Sami', '01234567', '12345678-01', 'Comptable', 1500, 'O', 2, '01/03/2024', 'Virement', 'BIAT', '08000000000000000000', '15/06/1990', 'CDI', '01/03/2024', null, 'Cadre', '3']]
            : $sal->map(fn ($e) => [$e->matricule, $e->nom, $e->cin, $e->cnss, $e->poste, (float) $e->salaire_base, $e->chef_famille ? 'O' : 'N', $e->enfants,
                optional($e->date_embauche)->format('d/m/Y'), $modes[$e->mode_paiement] ?? 'Virement', $e->banque, $e->rib,
                optional($e->date_naissance)->format('d/m/Y'), $e->type_contrat, optional($e->contrat_debut)->format('d/m/Y'), optional($e->contrat_fin)->format('d/m/Y'), $e->categorie, $e->echelon])->all();
        return $this->classeur('Personnel', $entetes, $lignes, [0, 2, 3, 8, 11, 12, 14, 15, 17], 'modele-personnel'.($client ? '-'.Str::slug($client->nom) : '').'.xlsx');
    }

    /** Importation → Modèle de paie : pointage du mois (jours travaillés, heures supplémentaires, primes) prérempli avec les employés. */
    public function modelePaie(Request $r)
    {
        $client = $this->dossier($r);
        $mois = $r->validate(['mois' => 'nullable|date_format:Y-m'])['mois'] ?? now()->format('Y-m');
        $P = PaieCalc::parametres($client);
        $paie = Paie::where('client_id', $client->id)->where('mois', $mois)->normale()->first();
        $lignes = PaieCalc::trier($client->salaries()->get(), $P['tri'])->map(fn ($e) => [$mois, $e->matricule, $e->cin, $e->nom,
            $paie?->pointage[$e->id]['jours'] ?? $P['jours_mois'], $paie?->pointage[$e->id]['hs'] ?? 0, (float) ($paie?->primes[$e->id] ?? 0)])->all();
        return $this->classeur('Pointage', ['Mois', 'Matricule', 'CIN', 'Nom et prénom', 'Jours travaillés', 'Heures supplémentaires', 'Primes'],
            $lignes, [0, 1, 2], 'modele-paie-'.Str::slug($client->nom).'-'.$mois.'.xlsx');
    }

    /**
     * Importation du pointage : « mensuel » pour le mois choisi, ou « historique » (colonne Mois, plusieurs mois).
     * Les employés sont reconnus par matricule, sinon CIN, sinon nom. Les mois déjà clôturés ne sont pas modifiés.
     * Historique : les mois importés peuvent être clôturés et marqués comptabilisés (paies déjà passées en comptabilité ailleurs).
     */
    public function importPointage(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate(['type' => 'required|in:mensuel,historique', 'mois' => 'required_if:type,mensuel|nullable|date_format:Y-m', 'cloturer' => 'nullable|boolean',
            'fichier' => 'required|file|max:10240|extensions:xlsx,xls,ods,csv'], ['fichier.extensions' => 'Formats acceptés : Excel (.xlsx, .xls), .ods ou .csv.']);
        try {
            $ws = IOFactory::load($r->file('fichier')->getRealPath())->getActiveSheet();
        } catch (\Throwable) {
            return back()->with('err', 'Fichier illisible.');
        }
        $brut = $ws->toArray(null, true, false, false);
        $texte = $ws->toArray(null, true, true, false);
        $cols = [];
        foreach ($brut[0] ?? [] as $i => $entete) if ($k = self::colonnePointage((string) $entete)) $cols[$k] ??= $i;
        if (! array_intersect_key($cols, array_flip(['matricule', 'cin', 'nom']))) return back()->with('err', 'Aucune colonne pour reconnaître les employés (matricule, CIN ou nom). Téléchargez le modèle de paie.');
        if (! array_intersect_key($cols, array_flip(['jours', 'hs', 'prime']))) return back()->with('err', 'Aucune colonne de pointage (jours travaillés, heures supplémentaires, primes).');
        if ($d['type'] === 'historique' && ! isset($cols['mois'])) return back()->with('err', 'Colonne « Mois » introuvable : elle est nécessaire pour l’historique.');

        $sal = $client->salaries()->get();
        $trouver = fn (?string $mat, ?string $cin, ?string $nom) => self::trouverSalarie($sal, $mat, $cin, $nom);
        $num = function ($v) {
            if ($v === null || $v === '') return null;
            if (is_numeric($v)) return (float) $v;
            $x = str_replace([' ', ','], ['', '.'], (string) $v);
            return is_numeric($x) ? (float) $x : null;
        };

        $parMois = []; $inconnus = []; $erreurs = [];
        foreach (array_slice($brut, 1, null, true) as $l => $ligne) {
            $t = fn ($k) => isset($cols[$k]) ? trim((string) $texte[$l][$cols[$k]]) : null;
            if (! array_filter([$t('matricule'), $t('cin'), $t('nom')])) continue;
            $mois = $d['type'] === 'mensuel' ? $d['mois'] : $this->moisDe($ligne[$cols['mois']], $t('mois'));
            if (! $mois) { $erreurs[] = 'ligne '.($l + 1).' : mois illisible'; continue; }
            $e = $trouver($t('matricule'), $t('cin'), $t('nom'));
            if (! $e) { $inconnus[] = $t('nom') ?: $t('matricule') ?: $t('cin'); continue; }
            $parMois[$mois][$e->id] = [
                'jours' => isset($cols['jours']) ? $num($ligne[$cols['jours']]) : null,
                'hs' => isset($cols['hs']) ? $num($ligne[$cols['hs']]) : null,
                'prime' => isset($cols['prime']) ? $num($ligne[$cols['prime']]) : null,
            ];
        }
        ksort($parMois);

        [$faits, $clos] = self::appliquerPointage($client, $parMois, $d['type'] === 'historique' && ! empty($d['cloturer']));

        $this->log('Paie : import du pointage ('.($faits ? implode(', ', $faits) : 'aucun mois').')', $client->id);
        $msg = $faits ? 'Pointage importé pour '.implode(', ', array_map('mois_fr', $faits)).'.' : 'Aucun pointage importé.';
        if ($clos) $msg .= ' Ignoré (paie déjà clôturée) : '.implode(', ', array_map('mois_fr', $clos)).'.';
        if ($inconnus) $msg .= ' Employé(s) non reconnu(s) : '.implode(', ', array_slice(array_unique($inconnus), 0, 5)).'.';
        if ($erreurs) $msg .= ' '.implode(' ; ', array_slice($erreurs, 0, 3)).'.';
        return redirect()->route('paie.index', ['mois' => $d['type'] === 'mensuel' ? $d['mois'] : (end($faits) ?: now()->format('Y-m'))])
            ->with($faits && ! $inconnus && ! $erreurs && ! $clos ? 'ok' : 'err', $msg);
    }

    /** Employé du dossier reconnu par son matricule, puis son CIN, puis son nom. */
    public static function trouverSalarie(\Illuminate\Support\Collection $sal, ?string $mat, ?string $cin, ?string $nom): ?\App\Models\Salarie
    {
        if ($mat && ($e = $sal->first(fn ($e) => (string) $e->matricule !== '' && Str::lower((string) $e->matricule) === Str::lower($mat)))) return $e;
        if ($cin && ($e = $sal->first(fn ($e) => $e->cin === $cin))) return $e;
        if ($nom) return $sal->first(fn ($e) => Str::lower(trim($e->nom)) === Str::lower(trim($nom))
            || Str::lower(trim($e->nomComplet())) === Str::lower(trim($nom)));
        return null;
    }

    /**
     * Pointage lu (par mois : [id salarié => jours, hs, prime]) reporté dans les paies en brouillon ; une paie déjà clôturée n'est pas touchée.
     * Renvoie [mois faits, mois ignorés car clôturés].
     */
    public static function appliquerPointage(Client $client, array $parMois, bool $cloturer = false): array
    {
        $faits = []; $clos = [];
        ksort($parMois);
        DB::transaction(function () use ($client, $parMois, $cloturer, &$faits, &$clos) {
            foreach ($parMois as $mois => $lignes) {
                $paie = Paie::firstOrNew(['client_id' => $client->id, 'mois' => $mois, 'type' => 'normale']);
                if ($paie->exists && $paie->statut !== 'brouillon') { $clos[] = $mois; continue; }
                $pt = (array) $paie->pointage; $pr = (array) $paie->primes;
                foreach ($lignes as $id => $v) {
                    if ($v['jours'] !== null || $v['hs'] !== null) {
                        $pt[$id] = ['jours' => $v['jours'] ?? ($pt[$id]['jours'] ?? null), 'hs' => $v['hs'] ?? ($pt[$id]['hs'] ?? 0)];
                    }
                    if ($v['prime'] !== null) $pr[$id] = $v['prime'];
                }
                $paie->pointage = PaieCalc::pointage($pt);
                $paie->primes = $pr;
                $paie->save();
                if ($cloturer) {
                    $R = PaieCalc::mois($client, $mois, true);
                    $paie->update(['resultats' => $R, 'totaux' => PaieCalc::totaux($R), 'statut' => 'val']);
                }
                $faits[] = $mois;
            }
        });
        return [$faits, $clos];
    }

    /** Classeur Excel d'une feuille : en-têtes en gras, colonnes de texte (identifiants, dates saisies) au format Texte. */
    private function classeur(string $titre, array $entetes, array $lignes, array $colonnesTexte, string $fichier)
    {
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet()->setTitle($titre);
        $ws->fromArray([$entetes]);
        foreach ($lignes as $i => $ligne) {
            foreach (array_values($ligne) as $c => $v) {
                if ($v === null || $v === '') continue;
                if (in_array($c, $colonnesTexte, true)) $ws->setCellValueExplicit([$c + 1, $i + 2], (string) $v, DataType::TYPE_STRING);
                else $ws->setCellValue([$c + 1, $i + 2], $v);
            }
        }
        $der = Coordinate::stringFromColumnIndex(count($entetes));
        $ws->getStyle("A1:{$der}1")->getFont()->setBold(true);
        foreach ($colonnesTexte as $c) {
            $col = Coordinate::stringFromColumnIndex($c + 1);
            $ws->getStyle($col.'2:'.$col.'1000')->getNumberFormat()->setFormatCode('@');
        }
        foreach (range(1, count($entetes)) as $c) $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        $ws->freezePane('A2');
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($ss))->save($tmp);
        return response()->download($tmp, $fichier)->deleteFileAfterSend();
    }

    /** Colonne du fichier de pointage reconnue d'après son en-tête. */
    public static function colonnePointage(string $entete): ?string
    {
        $s = Str::slug($entete);
        return match (true) {
            $s === '' => null,
            $s === 'mois' || str_starts_with($s, 'periode') || str_starts_with($s, 'mois-') => 'mois',
            str_contains($s, 'cnss') => null,
            str_contains($s, 'matricule') => 'matricule',
            $s === 'cin' || str_starts_with($s, 'cin-') || str_starts_with($s, 'n-cin') => 'cin',
            str_contains($s, 'jour') => 'jours',
            str_contains($s, 'heure') || str_contains($s, 'sup') || $s === 'hs' => 'hs',
            str_contains($s, 'prime') => 'prime',
            str_starts_with($s, 'nom') => 'nom',
            default => null,
        };
    }

    /** Mois AAAA-MM d'une cellule : texte 2026-10, 10/2026, 10-2026, date complète ou date Excel. */
    private function moisDe($brut, ?string $texte): ?string
    {
        if (is_numeric($brut) && $brut > 20000) return Carbon::instance(DateExcel::excelToDateTimeObject((float) $brut))->format('Y-m');
        $t = trim((string) $texte);
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})$/', $t, $m)) return sprintf('%04d-%02d', $m[1], $m[2]);
        if (preg_match('/^(\d{1,2})[-\/.](\d{4})$/', $t, $m)) return sprintf('%04d-%02d', $m[2], $m[1]);
        if (preg_match('/^\d{1,2}[-\/.](\d{1,2})[-\/.](\d{4})$/', $t, $m)) return sprintf('%04d-%02d', $m[2], $m[1]);
        if (preg_match('/^(\d{4})-(\d{2})-\d{2}/', $t, $m)) return $m[1].'-'.$m[2];
        return null;
    }

    /** Import des employés depuis Excel / CSV : création ou mise à jour (rapprochement par CIN, sinon par nom). */
    public function importEmployes(Request $r)
    {
        $client = $this->dossier($r);
        $r->validate(['fichier' => 'required|file|max:10240|extensions:xlsx,xls,ods,csv'], ['fichier.extensions' => 'Formats acceptés : Excel (.xlsx, .xls), .ods ou .csv.']);
        try {
            $ws = IOFactory::load($r->file('fichier')->getRealPath())->getActiveSheet();
        } catch (\Throwable) {
            return back()->with('err', 'Fichier illisible.');
        }
        $brut = $ws->toArray(null, true, false, false);
        $texte = $ws->toArray(null, true, true, false);
        $cols = [];
        foreach ($brut[0] ?? [] as $i => $entete) if ($k = $this->colonne((string) $entete)) $cols[$k] ??= $i;
        if (! isset($cols['nom'], $cols['salaire_base'])) return back()->with('err', 'Colonnes « Nom » et « Salaire de base » introuvables sur la première ligne (téléchargez le modèle).');

        $ok = 0; $erreurs = [];
        DB::transaction(function () use ($client, $brut, $texte, $cols, &$ok, &$erreurs) {
            foreach (array_slice($brut, 1, null, true) as $l => $ligne) {
                $v = fn ($k) => isset($cols[$k]) ? $ligne[$cols[$k]] : null;
                $t = fn ($k) => isset($cols[$k]) ? trim((string) $texte[$l][$cols[$k]]) : null;
                if (trim((string) $v('nom')) === '') continue;
                $salaire = is_numeric($v('salaire_base')) ? (float) $v('salaire_base') : (float) str_replace([' ', ','], ['', '.'], (string) $v('salaire_base'));
                if ($salaire < 0) { $erreurs[] = 'ligne '.($l + 1).' : salaire invalide'; continue; }
                $mode = Str::of((string) $t('mode_paiement'))->ascii()->lower();
                $d = [
                    'nom' => trim((string) $v('nom')), 'cin' => $t('cin') ?: null, 'cnss' => $t('cnss') ?: null, 'poste' => $t('poste') ?: null,
                    'salaire_base' => $salaire, 'chef_famille' => in_array(Str::lower((string) $t('chef_famille')), ['o', 'oui', '1', 'x', 'vrai', 'true'], true),
                    'enfants' => min(20, max(0, (int) $v('enfants'))), 'date_embauche' => $this->date($v('date_embauche')),
                    'matricule' => $t('matricule') ?: null, 'banque' => $t('banque') ?: null, 'rib' => preg_replace('/\s+/', '', (string) $t('rib')) ?: null,
                    'mode_paiement' => $mode->contains('esp') ? 'especes' : ($mode->contains('che') ? 'cheque' : 'virement'),
                    'date_naissance' => $this->date($v('date_naissance')), 'contrat_debut' => $this->date($v('contrat_debut')), 'contrat_fin' => $this->date($v('contrat_fin')),
                    'type_contrat' => $this->typeContrat((string) $t('type_contrat')), 'categorie' => $t('categorie') ?: null, 'echelon' => $t('echelon') ?: null,
                ];
                if ($d['rib'] && ! preg_match('/^\d+$/', $d['rib'])) { $erreurs[] = 'ligne '.($l + 1).' : RIB illisible (mettez la colonne au format Texte)'; $d['rib'] = null; }
                // Seules les colonnes présentes dans le fichier modifient un employé existant.
                $this->enregistrerSalarie($client, array_intersect_key($d, $cols + ['nom' => 0]));
                $ok++;
            }
        });
        $this->log("Paie : import de $ok employé(s)", $client->id);
        return redirect()->route('paie.index', ['tab' => 'sal'])->with($erreurs ? 'err' : 'ok', "$ok employé(s) importé(s)".($erreurs ? '. À vérifier : '.implode(' ; ', array_slice($erreurs, 0, 5)) : '.'));
    }

    /**
     * Rubriques d'une sauvegarde : rapprochées par nom (une rubrique existante n'est pas modifiée), les autres sont créées
     * avec leur code, ou le prochain code libre s'il est déjà pris.
     * Renvoie la correspondance ancien id → id du dossier ; les références entre rubriques (base, note) sont recalées.
     */
    private function restaurerRubriques(Client $client, array $liste): array
    {
        $ids = [];
        $creees = [];
        $champs = array_merge(['code', 'categorie', 'nom', 'profils', 'note_id', 'nb_jours_ouv', 'nb_heures_ouv', 'mode', 'montant', 'base', 'taux', 'numerateur', 'denominateur'], array_keys(PaieRubrique::OPTIONS));
        foreach ($liste as $x) {
            if (empty($x['code']) || empty($x['nom']) || ! isset(PaieRubrique::CATEGORIES[$x['categorie'] ?? ''])) continue;
            $exist = PaieRubrique::where('client_id', $client->id)->get()->first(fn ($e) => mb_strtolower(trim($e->nom)) === mb_strtolower(trim($x['nom'])));
            if ($exist) { $ids[$x['id'] ?? 0] = $exist->id; continue; }
            $val = array_intersect_key($x, array_flip($champs));
            if (PaieRubrique::where('client_id', $client->id)->where('code', (int) $x['code'])->exists()) $val['code'] = PaieRubrique::prochainCode($client);
            $rub = PaieRubrique::create(['client_id' => $client->id] + $val);
            $ids[$x['id'] ?? 0] = $rub->id;
            $creees[] = $rub;
        }
        foreach ($creees as $rub) {
            $base = str_starts_with((string) $rub->base, 'r:') ? (isset($ids[(int) substr($rub->base, 2)]) ? 'r:'.$ids[(int) substr($rub->base, 2)] : 'base') : $rub->base;
            $rub->update(['base' => $base, 'note_id' => $rub->note_id ? ($ids[$rub->note_id] ?? null) : null]);
        }
        return $ids;
    }

    /** Mise à jour du dossier : retire des paies en brouillon les primes d'employés supprimés et signale les paies figées sur d'anciens taux. */
    public function mettreAJour(Request $r)
    {
        $client = $this->dossier($r);
        $ids = $client->salaries()->pluck('id')->all();
        $orph = 0;
        foreach (Paie::where('client_id', $client->id)->where('statut', 'brouillon')->get() as $p) {
            $primes = array_filter((array) $p->primes, fn ($v, $id) => in_array((int) $id, $ids, true) && (float) $v != 0, ARRAY_FILTER_USE_BOTH);
            $orph += count((array) $p->primes) - count($primes);
            $p->update(['primes' => $primes]);
        }
        $this->log('Paie : mise à jour du dossier', $client->id);
        return back()->with('ok', 'Dossier mis à jour : les paies en brouillon sont recalculées avec les taux et le barème en vigueur'.($orph ? " ($orph prime(s) d’employés supprimés retirée(s))" : '').'. Les paies clôturées restent figées.');
    }

    /** Sécurité : le chef comptable rouvre une paie clôturée tant qu'elle n'est pas comptabilisée. */
    public function rouvrir(Request $r)
    {
        $client = $this->dossier($r);
        $d = $r->validate(['mois' => 'required|date_format:Y-m', 'type' => 'nullable|in:'.implode(',', array_keys(Paie::TYPES))]);
        $mois = $d['mois'];
        $paie = $r->filled('paie_id') ? Paie::where('client_id', $client->id)->findOrFail((int) $r->input('paie_id'))
            : Paie::where('client_id', $client->id)->where('mois', $mois)->where('type', $d['type'] ?? 'normale')->firstOrFail();
        if ($paie->statut === 'val') return back()->with('err', 'Cette paie est déjà comptabilisée : annulez d’abord l’écriture en comptabilité.');
        $paie->update(['statut' => 'brouillon', 'resultats' => null, 'totaux' => null]);
        $this->log('Paie : réouverture — '.$paie->libelle(), $client->id);
        $retour = $paie->estStc() ? ['tab' => 'stc', 'stc' => $paie->id, 'mois' => $mois] : ($paie->estRappel() ? ['tab' => 'rappel', 'rappel' => $paie->id, 'mois' => $mois] : ($paie->estPrime() ? ['tab' => 'prime', 'prime' => $paie->id, 'mois' => $mois] : ($paie->estSpeciale() ? ['tab' => 'autre', 'type' => $paie->type, 'annee' => substr($mois, 0, 4), 'decl' => $mois, 'mois' => $mois] : ['mois' => $mois])));
        return redirect()->route('paie.index', $retour)->with('ok', $paie->libelle().' rouverte : elle peut de nouveau être modifiée.');
    }

    /** Calculatrice de paie : du brut vers le net, ou du net vers le brut. */
    public function simulation(Request $r)
    {
        $d = $r->validate(['montant' => 'required|numeric|min:0|max:1000000', 'sens' => 'required|in:brut,net', 'chef' => 'nullable|boolean', 'enfants' => 'nullable|integer|min:0|max:20',
            'parents' => 'nullable|integer|min:0|max:4', 'profil' => 'nullable|string|max:255']);
        $client = $this->dossier($r);
        // Simulateur net → brut : le profil choisi (CIVP, gérant…) fixe la CNSS salariale et les charges patronales.
        $profil = ! empty($d['profil']) ? PaieProfil::parNom($client)->get(mb_strtolower(trim($d['profil']))) : null;
        $P = PaieCalc::pourProfil(PaieCalc::parametres($client), $profil);
        $e = new Salarie(['nom' => 'Simulation', 'chef_famille' => (bool) ($d['chef'] ?? false), 'enfants' => (int) ($d['enfants'] ?? 0),
            'parents_charge' => (int) ($d['parents'] ?? 0), 'profil' => $profil?->nom]);
        $calc = function (float $brut) use ($e, $P) { $e->salaire_base = $brut; return PaieCalc::calcul($e, 0, $P); };
        if ($d['sens'] === 'brut') return response()->json($calc((float) $d['montant']));
        [$bas, $haut] = [(float) $d['montant'], (float) $d['montant'] * 3 + 1];
        for ($i = 0; $i < 80 && $haut - $bas > 0.0005; $i++) {
            $mil = ($bas + $haut) / 2;
            $calc($mil)['net'] < $d['montant'] ? $bas = $mil : $haut = $mil;
        }
        return response()->json($calc(round($haut, 3)));
    }

    /** Crée l'employé ou met à jour celui qui a le même CIN (sinon le même nom) avec les seuls champs fournis. */
    private function enregistrerSalarie(Client $client, array $d): Salarie
    {
        if (array_key_exists('mode_paiement', $d) && ! in_array($d['mode_paiement'], ['virement', 'cheque', 'especes'], true)) $d['mode_paiement'] = 'virement';
        if (array_key_exists('enfants', $d)) $d['enfants'] = (int) $d['enfants'];
        if (array_key_exists('chef_famille', $d)) $d['chef_famille'] = (bool) $d['chef_famille'];
        $q = Salarie::where('client_id', $client->id);
        $e = ! empty($d['cin']) ? (clone $q)->where('cin', $d['cin'])->first() : null;
        $e ??= (clone $q)->whereRaw('lower(nom) = ?', [Str::lower($d['nom'])])->first();
        if ($e) { $e->update($d); return $e; }
        return Salarie::create($d + ['client_id' => $client->id, 'salaire_base' => 0]);
    }

    /** Colonne reconnue d'après l'en-tête du fichier importé. */
    private function colonne(string $entete): ?string
    {
        $s = Str::slug($entete);
        return match (true) {
            $s === '' => null,
            str_contains($s, 'cnss') => 'cnss',
            str_contains($s, 'naissance') => 'date_naissance',
            str_contains($s, 'contrat') && str_contains($s, 'fin') => 'contrat_fin',
            str_contains($s, 'contrat') && str_contains($s, 'debut') => 'contrat_debut',
            str_contains($s, 'contrat') => 'type_contrat',
            str_contains($s, 'categorie') => 'categorie',
            str_contains($s, 'echelon') => 'echelon',
            str_contains($s, 'embauche') => 'date_embauche',
            str_contains($s, 'enfant') => 'enfants',
            str_contains($s, 'chef') => 'chef_famille',
            str_contains($s, 'salaire') => 'salaire_base',
            $s === 'cin' || str_starts_with($s, 'cin-') || str_starts_with($s, 'n-cin') => 'cin',
            $s === 'rib' || str_contains($s, 'rib-') => 'rib',
            str_contains($s, 'banque') => 'banque',
            str_contains($s, 'paiement') || $s === 'mode' => 'mode_paiement',
            str_contains($s, 'poste') || str_contains($s, 'fonction') => 'poste',
            str_contains($s, 'matricule') => 'matricule',
            str_starts_with($s, 'nom') => 'nom',
            default => null,
        };
    }

    /** Type de contrat lu dans un fichier importé (CDI, CDD, SIVP, Karama, stage, autre). */
    private function typeContrat(string $v): ?string
    {
        $v = Str::of($v)->ascii()->upper()->trim()->value();
        if ($v === '') return null;
        foreach (['CDI', 'CDD', 'SIVP', 'KARAMA'] as $c) if (str_contains($v, $c)) return $c;
        return str_contains($v, 'STAGE') ? 'stage' : 'autre';
    }

    private function date($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return Carbon::instance(DateExcel::excelToDateTimeObject((float) $v))->format('Y-m-d');
        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y'] as $f) {
            try { return Carbon::createFromFormat('!'.$f, trim((string) $v))->format('Y-m-d'); } catch (\Throwable) {}
        }
        return null;
    }
}
