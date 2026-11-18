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
