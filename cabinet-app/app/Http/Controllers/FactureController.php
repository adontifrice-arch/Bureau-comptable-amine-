<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Facture;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FactureController extends Controller
{
    public function index(Request $r)
    {
        $type = $r->input('type', 'facture');
        $items = Facture::with(['client', 'lignes'])->where('type', $type)
            ->when($r->input('client'), fn ($q, $c) => $q->where('client_id', $c))
            ->when($r->filled('statut'), fn ($q) => $q->where('statut', $r->input('statut')))
            ->orderByDesc('date')->orderByDesc('id')->get();
        $all = Facture::with('lignes')->where('type', 'facture')->get();
        $stats = [
            'emis' => $all->sum(fn ($f) => $f->totaux()['ttc']),
            'du' => $all->where('statut', '<', 2)->sum(fn ($f) => $f->totaux()['ttc']),
            'retard' => $all->filter->enRetard()->sum(fn ($f) => $f->totaux()['ttc']),
            'paye' => $all->where('statut', 2)->sum(fn ($f) => $f->totaux()['ttc']),
        ];
        return view('factures.index', ['items' => $items, 'type' => $type, 'stats' => $stats,
            'clients' => Client::orderBy('nom')->get(['id', 'nom']), 'services' => Service::orderBy('nom')->get()]);
    }

    public function create(Request $r)
    {
        $type = $r->input('type', 'facture') === 'devis' ? 'devis' : 'facture';
        $f = new Facture(['type' => $type, 'numero' => Facture::prochainNumero($type), 'date' => now(), 'echeance' => now()->addDays(30),
            'tva_taux' => 19, 'timbre' => $type === 'devis' ? 0 : 1, 'client_id' => $r->input('client')]);
        $lignes = [];
        if ($r->input('client') && $r->boolean('honoraires')) {
            $c = Client::find($r->input('client'));
            if ($c?->honoraires_mensuels > 0) $lignes[] = ['designation' => 'Honoraires de tenue comptable – '.now()->locale('fr')->translatedFormat('F Y'), 'quantite' => 1, 'prix_unitaire' => (float) $c->honoraires_mensuels];
        }
        return $this->form($f, $lignes);
    }

    public function store(Request $r)
    {
        $f = DB::transaction(function () use ($r) {
            $f = Facture::create($this->valider($r));
            $this->syncLignes($f, $r);
            return $f;
        });
        return redirect()->route('factures.show', $f)->with('ok', ($f->estDevis() ? 'Devis' : 'Facture').' '.$f->numero.' enregistré'.($f->estDevis() ? '' : 'e').'.');
    }

    public function show(Facture $facture)
    {
        $facture->load(['client', 'lignes']);
        return view('factures.show', ['f' => $facture, 't' => $facture->totaux()]);
    }

    public function edit(Facture $facture)
    {
        return $this->form($facture, $facture->lignes->map(fn ($l) => ['designation' => $l->designation, 'quantite' => (float) $l->quantite, 'prix_unitaire' => (float) $l->prix_unitaire])->all());
    }

    public function update(Request $r, Facture $facture)
    {
        DB::transaction(function () use ($r, $facture) {
            $facture->update($this->valider($r, $facture));
            $this->syncLignes($facture, $r);
        });
        return redirect()->route('factures.show', $facture)->with('ok', 'Document enregistré.');
    }

    public function destroy(Facture $facture)
    {
        $type = $facture->type;
        $facture->delete();
        return redirect()->route('factures.index', ['type' => $type])->with('ok', 'Document supprimé.');
    }

    public function statut(Request $r, Facture $facture)
    {
        $max = $facture->estDevis() ? 4 : 3;
        $facture->update(['statut' => $r->filled('statut') ? (int) $r->input('statut') % $max : ($facture->statut + 1) % $max]);
        return back()->with('ok', 'Statut : '.$facture->statutLibelle()[0].'.');
    }

    public function convertir(Facture $facture)
    {
        abort_unless($facture->estDevis(), 404);
        $f = DB::transaction(function () use ($facture) {
            $f = Facture::create(['type' => 'facture', 'numero' => Facture::prochainNumero('facture'), 'client_id' => $facture->client_id,
                'date' => now(), 'echeance' => now()->addDays(30), 'tva_taux' => $facture->tva_taux, 'timbre' => 1, 'devis_id' => $facture->id]);
            foreach ($facture->lignes as $l) $f->lignes()->create($l->only(['designation', 'quantite', 'prix_unitaire', 'ordre']));
            $facture->update(['statut' => 2]);
            return $f;
        });
        return redirect()->route('factures.edit', $f)->with('ok', 'Facture '.$f->numero.' créée à partir du devis '.$facture->numero.'.');
    }

    /** Brouillon XML TEIF (facture électronique TTN / El Fatoora) — à valider avec le XSD officiel. */
    public function teif(Facture $facture)
    {
        $facture->load(['client', 'lignes']);
        $c = $facture->client; $cab = Setting::cabinet(); $t = $facture->totaux();
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_XML1);
        $a3 = fn ($n) => number_format((float) $n, 3, '.', '');
        $d6 = fn ($d) => $d ? $d->format('dmy') : '';
        $moa = fn ($code, $n) => '<Moa amountTypeCode="'.$code.'" currencyCodeList="ISO_4217"><Amount currencyIdentifier="TND">'.$a3($n).'</Amount></Moa>';
        $part = fn ($fn, $mf, $nom, $adr) => '<PartnerDetails functionCode="'.$fn.'"><Nad><PartnerIdentifier type="I-01">'.$e($mf).'</PartnerIdentifier><PartnerName nameType="Qualification">'.$e($nom).'</PartnerName><PartnerAdresses lang="fr"><AdressDescription>'.$e($adr).'</AdressDescription><Country codeList="ISO_3166-1">TN</Country></PartnerAdresses></Nad></PartnerDetails>';
        $w = [];
        if (! $cab['mf']) $w[] = 'matricule fiscal du cabinet manquant';
        if (! $c->matricule_fiscal) $w[] = 'matricule fiscal du client manquant';
        $x = ['<?xml version="1.0" encoding="UTF-8"?>', '<!-- Brouillon TEIF non signé : à valider avec le XSD officiel de TTN avant dépôt sur El Fatoora. -->'];
        foreach ($w as $m) $x[] = "<!-- ATTENTION : $m -->";
        $x[] = '<TEIF controlingAgency="TTN" version="1.8.8">';
        $x[] = '<InvoiceHeader><MessageSenderIdentifier type="I-01">'.$e($cab['mf']).'</MessageSenderIdentifier><MessageRecieverIdentifier type="I-01">'.$e($c->matricule_fiscal).'</MessageRecieverIdentifier></InvoiceHeader>';
        $x[] = '<InvoiceBody><Bgm><DocumentIdentifier>'.$e($facture->numero).'</DocumentIdentifier><DocumentType code="I-11">Facture</DocumentType></Bgm>';
        $x[] = '<Dtm><DateText format="ddMMyy" functionCode="I-31">'.$d6($facture->date).'</DateText>'.($facture->echeance ? '<DateText format="ddMMyy" functionCode="I-32">'.$d6($facture->echeance).'</DateText>' : '').'</Dtm>';
        $x[] = '<PartnerSection>'.$part('I-62', $cab['mf'], $cab['nom'], $cab['adresse']).$part('I-64', $c->matricule_fiscal, $c->nom, $c->adresse).'</PartnerSection>';
        $x[] = '<LinSection>';
        foreach ($facture->lignes as $i => $l) {
            $x[] = '<Lin><ItemIdentifier>'.($i + 1).'</ItemIdentifier><LinImd lang="fr"><ItemCode>'.($i + 1).'</ItemCode><ItemDescription>'.$e($l->designation).'</ItemDescription></LinImd><LinQty><Quantity measurementUnit="UNIT">'.$a3($l->quantite).'</Quantity></LinQty><LinTax><TaxTypeName code="I-1602">TVA</TaxTypeName><TaxDetails><TaxRate>'.$a3($facture->tva_taux).'</TaxRate></TaxDetails></LinTax><LinMoa><MoaDetails>'.$moa('I-183', $l->prix_unitaire).'</MoaDetails><MoaDetails>'.$moa('I-171', $l->quantite * $l->prix_unitaire).'</MoaDetails></LinMoa></Lin>';
        }
        $x[] = '</LinSection><InvoiceMoa><AmountDetails>'.$moa('I-176', $t['ht']).'</AmountDetails><AmountDetails>'.$moa('I-181', $t['tva']).'</AmountDetails><AmountDetails>'.$moa('I-180', $t['ttc']).'</AmountDetails></InvoiceMoa>';
        $x[] = '<InvoiceTax><InvoiceTaxDetails><Tax><TaxTypeName code="I-1602">TVA</TaxTypeName><TaxDetails><TaxRate>'.$a3($facture->tva_taux).'</TaxRate></TaxDetails></Tax><AmountDetails>'.$moa('I-177', $t['ht']).'</AmountDetails><AmountDetails>'.$moa('I-178', $t['tva']).'</AmountDetails></InvoiceTaxDetails>';
        if ($t['timbre'] > 0) $x[] = '<InvoiceTaxDetails><Tax><TaxTypeName code="I-1601">droit de timbre</TaxTypeName><TaxDetails><TaxRate>0</TaxRate></TaxDetails></Tax><AmountDetails>'.$moa('I-178', $t['timbre']).'</AmountDetails></InvoiceTaxDetails>';
        $x[] = '</InvoiceTax></InvoiceBody></TEIF>';
        return response(implode("\n", $x), 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => 'attachment; filename="TEIF-'.$facture->numero.'.xml"']);
    }

    // ---------- Chiffre d'affaires ----------
    private function caRows(Request $r)
    {
        $from = $r->input('du', now()->startOfYear()->format('Y-m-d'));
        $to = $r->input('au', now()->endOfYear()->format('Y-m-d'));
        $rows = Facture::with(['client', 'lignes'])->where('type', 'facture')->whereBetween('date', [$from, $to])
            ->when($r->input('client'), fn ($q, $c) => $q->where('client_id', $c))->orderBy('date')->orderBy('numero')->get()
            ->map(fn ($f) => ['f' => $f] + $f->totaux());
        return [$rows, $from, $to];
    }

    public function chiffreAffaires(Request $r)
    {
        [$rows, $from, $to] = $this->caRows($r);
        $s = ['ht' => $rows->sum('ht'), 'tva' => $rows->sum('tva'), 'timbre' => $rows->sum('timbre'), 'ttc' => $rows->sum('ttc')];
        return view('factures.ca', ['rows' => $rows, 'from' => $from, 'to' => $to, 's' => $s, 'clients' => Client::orderBy('nom')->get(['id', 'nom'])]);
    }

    public function chiffreAffairesCsv(Request $r)
    {
        [$rows, $from, $to] = $this->caRows($r);
        $n = fn ($v) => number_format((float) $v, 3, ',', '');
        $out = "\xEF\xBB\xBF".implode(';', ['Date', 'Facture n°', 'Client', 'Matricule fiscal', 'Compte comptable', 'Total HT', 'TVA', 'Droit de timbre', 'TTC', 'Statut', 'Retenue à la source'])."\r\n";
        foreach ($rows as $x) {
            $f = $x['f'];
            $out .= implode(';', [dfr($f->date), $f->numero, '"'.str_replace('"', '""', $f->client->nom).'"', $f->client->matricule_fiscal, $f->client->compte_comptable,
                $n($x['ht']), $n($x['tva']), $n($x['timbre']), $n($x['ttc']), $f->statutLibelle()[0], $f->retenue_source ? 'Oui' : 'Non'])."\r\n";
        }
        return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"chiffre-affaires_{$from}_{$to}.csv\""]);
    }

    // ---------- Services du cabinet ----------
    public function serviceStore(Request $r)
    {
        Service::create($r->validate(['nom' => 'required|max:255', 'prix' => 'nullable|numeric|min:0']) + ['prix' => 0]);
        return back()->with('ok', 'Service ajouté au catalogue.');
    }

    public function serviceDestroy(Service $service)
    {
        $service->delete();
        return back()->with('ok', 'Service retiré du catalogue.');
    }

    private function form(Facture $f, array $lignes)
    {
        return view('factures.form', ['f' => $f, 'lignes' => $lignes, 'clients' => Client::orderBy('nom')->get(['id', 'nom', 'honoraires_mensuels']),
            'services' => Service::orderBy('nom')->get(['id', 'nom', 'prix'])]);
    }

    private function valider(Request $r, ?Facture $f = null): array
    {
        $d = $r->validate([
            'type' => 'required|in:facture,devis', 'numero' => 'required|max:50', 'client_id' => 'required|'.existe_cab('clients'),
            'date' => 'required|date', 'echeance' => 'nullable|date', 'tva_taux' => 'required|numeric|min:0|max:100',
            'timbre' => 'nullable|numeric|min:0', 'retenue_source' => 'nullable|boolean', 'notes' => 'nullable|max:2000',
            'lignes' => 'required|array|min:1', 'lignes.*.designation' => 'nullable|max:500',
            'lignes.*.quantite' => 'nullable|numeric', 'lignes.*.prix_unitaire' => 'nullable|numeric',
        ]);
        $dup = Facture::where('type', $d['type'])->where('numero', $d['numero'])->when($f, fn ($q) => $q->where('id', '!=', $f->id))->exists();
        if ($dup) abort(back()->withInput()->with('err', 'Ce numéro est déjà utilisé.'));
        if (! collect($d['lignes'])->contains(fn ($l) => trim($l['designation'] ?? '') !== '' || (float) ($l['prix_unitaire'] ?? 0))) {
            abort(back()->withInput()->with('err', 'Ajoutez au moins une ligne.'));
        }
        unset($d['lignes']);
        $d['timbre'] = $d['type'] === 'devis' ? 0 : ($d['timbre'] ?? 0);
        $d['retenue_source'] = $r->boolean('retenue_source');
        return $d;
    }

    private function syncLignes(Facture $f, Request $r): void
    {
        $f->lignes()->delete();
        foreach (array_values((array) $r->input('lignes')) as $i => $l) {
            if (trim($l['designation'] ?? '') === '' && ! (float) ($l['prix_unitaire'] ?? 0)) continue;
            $f->lignes()->create(['designation' => $l['designation'] ?: '—', 'quantite' => (float) ($l['quantite'] ?? 1), 'prix_unitaire' => (float) ($l['prix_unitaire'] ?? 0), 'ordre' => $i]);
        }
    }
}
