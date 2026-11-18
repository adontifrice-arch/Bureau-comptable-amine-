<?php

namespace App\Http\Controllers;

use App\Models\Ecriture;
use App\Services\Ecritures;
use App\Services\Lettrage;
use Illuminate\Http\Request;

/** Lettrage des comptes (grand livre), lettrages invalides et transfert de compte à compte. */
class LettrageController extends Controller
{
    private const REFUS = 'Seuls le chef comptable et les collaborateurs autorisés à modifier les écritures peuvent le faire.';

    public function lettrer(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        if (! Ecritures::autorise($r->user())) return back()->with('err', self::REFUS);
        $d = $r->validate(['lignes' => 'required|array|min:2', 'lignes.*' => 'integer'], ['lignes.required' => 'Cochez les lignes à lettrer.', 'lignes.min' => 'Cochez au moins deux lignes.']);
        try {
            $lettre = Lettrage::lettrer($client->id, $d['lignes']);
        } catch (\RuntimeException $e) {
            return back()->with('err', $e->getMessage());
        }
        $this->log('Lettrage '.$lettre.' ('.count($d['lignes']).' lignes)', $client->id);
        return back()->with('ok', count($d['lignes']).' lignes lettrées « '.$lettre.' ».');
    }

    public function delettrer(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        if (! Ecritures::autorise($r->user())) return back()->with('err', self::REFUS);
        $d = $r->validate(['compte' => 'required|regex:/^\d{1,10}$/', 'lettre' => 'required|regex:/^[A-Z]{1,6}$/']);
        $n = Lettrage::delettrer($client->id, $d['compte'], $d['lettre']);
        $this->log('Délettrage '.$d['compte'].' '.$d['lettre'], $client->id);
        return back()->with('ok', 'Lettre « '.$d['lettre'].' » du compte '.$d['compte'].' retirée ('.$n.' ligne(s)).');
    }

    /** État des comptes non lettrés : date d'arrêté (31/12 de l'exercice par défaut), tiers et compte facultatifs. */
    public static function parametresNonLettres(Request $r, int $client, int $annee): array
    {
        $date = $r->filled('date') && strtotime((string) $r->input('date')) ? date('Y-m-d', strtotime((string) $r->input('date'))) : $annee.'-12-31';
        $section = $r->filled('section') ? (string) $r->input('section') : null;
        $compte = $r->filled('compte') ? (string) $r->input('compte') : null;
        return ['dateNL' => $date, 'section' => $section, 'compte' => $compte, 'NL' => Lettrage::nonLettres($client, $date, $section, $compte)];
    }

    public function nonLettresPrint(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        return view('compta.non-lettres-print', ['client' => $client] + self::parametresNonLettres($r, $client->id, (int) $r->input('annee', now()->year)));
    }

    public function nonLettresCsv(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $P = self::parametresNonLettres($r, $client->id, (int) $r->input('annee', now()->year));
        $n = fn ($v) => number_format((float) $v, 3, ',', '');
        $q = fn ($t) => '"'.str_replace('"', '""', (string) $t).'"';
        $row = fn (array $c) => implode(';', $c)."\r\n";
        $out = "\xEF\xBB\xBF".$row(['Compte', 'Date', 'Journal', 'Libellé', 'Débit', 'Crédit', 'Solde non lettré']);
        foreach ($P['NL']['sections'] as $s) {
            $out .= $row(['', '', '', $q($s['titre']), '', '', '']);
            foreach ($s['comptes'] as $c) {
                foreach ($c['lignes'] as $l) $out .= $row([$c['compte'], dfr($l['date']), $l['journal'], $q($l['libelle']), $n($l['debit']), $n($l['credit']), '']);
                $out .= $row([$c['compte'], '', '', $q('Total '.$c['compte'].' '.$c['libelle']), $n($c['debit']), $n($c['credit']), $n($c['solde'])]);
            }
            $out .= $row(['', '', '', $q('Total '.$s['titre']), $n($s['T']['debit']), $n($s['T']['credit']), $n($s['T']['solde'])]);
        }
        $out .= $row(['', '', '', $q('Total général'), $n($P['NL']['T']['debit']), $n($P['NL']['T']['credit']), $n($P['NL']['T']['solde'])]);
        return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="comptes-non-lettres-'.$P['dateNL'].'-'.\Illuminate\Support\Str::slug($client->nom).'.csv"']);
    }

    public function transfert(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        if (! Ecritures::autorise($r->user())) return back()->with('err', self::REFUS);
        $d = $r->validate(['source' => 'required|regex:/^\d{1,10}$/', 'destination' => 'required|regex:/^\d{1,10}$/', 'du' => 'required|date', 'au' => 'required|date|after_or_equal:du',
            'journal' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(Ecriture::journaux($client->id)))]],
            ['regex' => 'Un compte ne comporte que des chiffres (10 au plus).', 'au.after_or_equal' => 'La fin de période précède le début.']);
        try {
            $t = Lettrage::transferer($client->id, $d['source'], $d['destination'], $d['du'], $d['au'], $d['journal'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('err', $e->getMessage());
        }
        $this->log('Transfert du compte '.$d['source'].' au compte '.$t['destination'].' du '.dfr($d['du']).' au '.dfr($d['au']).($d['journal'] ?? null ? ' (journal '.$d['journal'].')' : '')
            .' : '.$t['lignes'].' ligne(s), débit '.tnd($t['debit']).', crédit '.tnd($t['credit']), $client->id);
        return redirect()->route('compta.index', ['tab' => 'gl', 'annee' => (int) substr($d['au'], 0, 4), 'compte' => $t['destination'], 'exact' => 1])
            ->with('ok', $t['lignes'].' ligne(s) transférée(s) du compte '.$d['source'].' au compte '.$t['destination'].' (débit '.tnd($t['debit']).', crédit '.tnd($t['credit']).').');
    }
}
