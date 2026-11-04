<?php

namespace App\Http\Controllers;

use App\Models\Depense;
use App\Models\Setting;
use Illuminate\Http\Request;

class DepenseController extends Controller
{
    public static function calc(Depense $e): array
    {
        $ht = (float) $e->ht; $tva = (float) $e->tva; $tim = (float) $e->timbre;
        $ttc = r3($ht + $tva + $tim);
        $rs = 0;
        if ($e->retenue) $rs = $e->retenue_montant !== null ? (float) $e->retenue_montant : r3(($ht + $tva) * $e->retenue_taux / 100);
        return ['ht' => $ht, 'tva' => $tva, 'tim' => $tim, 'ttc' => $ttc, 'rs' => $rs, 'net' => r3($ttc - $rs)];
    }

    public function index(Request $r)
    {
        $items = Depense::orderByDesc('date')->get();
        $T = ['ttc' => 0, 'rs' => 0, 'net' => 0];
        foreach ($items as $e) { $c = static::calc($e); foreach ($T as $k => $_) $T[$k] += $c[$k]; }
        return view('frais.depenses', ['items' => $items, 'T' => $T, 'mois' => $r->input('mois', now()->format('Y-m')),
            'fournisseurs' => $items->unique('fournisseur')->values()]);
    }

    public function store(Request $r) { Depense::create($this->valider($r)); return back()->with('ok', 'Dépense enregistrée.'); }
    public function update(Request $r, Depense $depense) { $depense->update($this->valider($r)); return back()->with('ok', 'Dépense modifiée.'); }
    public function destroy(Depense $depense) { $depense->delete(); return back()->with('ok', 'Dépense supprimée.'); }

    /** Fichier XML des certificats de retenue à la source (plateforme TEJ) pour un mois de paiement. */
    public function tej(Request $r)
    {
        $mois = $r->input('mois', now()->format('Y-m'));
        $L = Depense::where('retenue', true)->get()->filter(fn ($e) => ($e->date_paiement ?? $e->date)->format('Y-m') === $mois)->values();
        $mfN = function ($s) { return preg_match('/(\d{7})\s*[\/\-]?\s*([A-Za-z])/', (string) $s, $m) ? $m[1].strtoupper($m[2]) : ''; };
        $cab = Setting::cabinet(); $dec = $mfN($cab['mf']); $w = [];
        if (! $dec) $w[] = 'matricule fiscal du cabinet (déclarant) manquant ou invalide';
        if ($L->isEmpty()) return back()->with('err', 'Aucune dépense avec retenue payée en '.mois_fr($mois).'.');
        $mil = fn ($x) => (string) round((float) $x * 1000);
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_XML1);
        $x = ['<?xml version="1.0" encoding="UTF-8"?>', '<!-- Brouillon à valider avec le XSD officiel TEJ avant dépôt. -->'];
        foreach ($w as $m) $x[] = "<!-- ATTENTION : $m -->";
        $x[] = '<DeclarationsRS VersionSchema="1.0">';
        $x[] = '<Declarant><TypeIdentifiant>1</TypeIdentifiant><Identifiant>'.$dec.'</Identifiant><CategorieContribuable>PM</CategorieContribuable></Declarant>';
        $x[] = '<ReferenceDeclaration><ActeDepot>0</ActeDepot><AnneeDepot>'.substr($mois, 0, 4).'</AnneeDepot><MoisDepot>'.substr($mois, 5, 2).'</MoisDepot></ReferenceDeclaration>';
        $x[] = '<AjouterCertificats>';
        foreach ($L as $dp) {
            $c = static::calc($dp);
            $id = $dp->type_identifiant === 'MatriculeFiscal' ? $mfN($dp->identifiant) : trim((string) $dp->identifiant);
            $taux = $dp->retenue_taux > 0 ? $dp->retenue_taux : (($c['ht'] + $c['tva']) > 0 ? round($c['rs'] / ($c['ht'] + $c['tva']) * 100, 2) : 0);
            $x[] = '<Certificat><Beneficiaire><IdTaxpayer><'.$dp->type_identifiant.'>'.$e($id).'</'.$dp->type_identifiant.'></IdTaxpayer><Resident>1</Resident><NometprenonOuRaisonsociale>'.$e($dp->fournisseur).'</NometprenonOuRaisonsociale><Adresse>'.$e($dp->adresse).'</Adresse></Beneficiaire>'
                .'<DatePayement>'.($dp->date_paiement ?? $dp->date)->format('d/m/Y').'</DatePayement><Ref_certif_chez_declarant>'.$e($dp->reference ?: 'D'.$dp->id).'</Ref_certif_chez_declarant>'
                .'<ListeOperations><Operation IdTypeOperation="RS7_000001"><AnneeFacturation>'.$dp->date->year.'</AnneeFacturation><CNPC>0</CNPC><P_Charge>0</P_Charge>'
                .'<MontantHT>'.$mil($c['ht']).'</MontantHT><TauxRS>'.$taux.'</TauxRS><TauxTVA>'.(float) $dp->tva_taux.'</TauxTVA><MontantTVA>'.$mil($c['tva']).'</MontantTVA><MontantTTC>'.$mil($c['ttc']).'</MontantTTC><MontantRS>'.$mil($c['rs']).'</MontantRS><MontantNetServi>'.$mil($c['net']).'</MontantNetServi></Operation></ListeOperations>'
                .'<TotalPayement><TotalMontantHT>'.$mil($c['ht']).'</TotalMontantHT><TotalMontantTVA>'.$mil($c['tva']).'</TotalMontantTVA><TotalMontantTTC>'.$mil($c['ttc']).'</TotalMontantTTC><TotalMontantRS>'.$mil($c['rs']).'</TotalMontantRS><TotalMontantNetServi>'.$mil($c['net']).'</TotalMontantNetServi></TotalPayement></Certificat>';
        }
        $x[] = '</AjouterCertificats></DeclarationsRS>';
        return response(implode("\n", $x), 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => "attachment; filename=\"TEJ-RS-$mois.xml\""]);
    }

    private function valider(Request $r): array
    {
        $d = $r->validate(['date' => 'required|date', 'date_paiement' => 'nullable|date', 'fournisseur' => 'required|max:255', 'reference' => 'nullable|max:100',
            'type_identifiant' => 'required|in:MatriculeFiscal,CIN,CarteSejour,NonResident', 'identifiant' => 'nullable|max:50', 'adresse' => 'nullable|max:255',
            'ht' => 'required|numeric|min:0.001', 'tva_taux' => 'nullable|numeric|min:0', 'tva' => 'nullable|numeric|min:0', 'timbre' => 'nullable|numeric|min:0',
            'retenue' => 'nullable|boolean', 'retenue_taux' => 'nullable|numeric|min:0|max:100', 'retenue_montant' => 'nullable|numeric|min:0']);
        $d['tva_taux'] = $d['tva_taux'] ?? 0;
        $d['tva'] = $r->filled('tva') ? $d['tva'] : r3($d['ht'] * $d['tva_taux'] / 100);
        $d['timbre'] = $d['timbre'] ?? 0;
        $d['retenue'] = $r->boolean('retenue');
        $d['retenue_taux'] = $d['retenue_taux'] ?? 0;
        return $d;
    }
}
