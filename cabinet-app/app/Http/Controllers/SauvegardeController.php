<?php

namespace App\Http\Controllers;

use App\Models\Sauvegarde;
use App\Models\Setting;
use App\Services\Sauvegardes;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sauvegardes du cabinet (chef comptable) : programmation automatique, sauvegarde immédiate, historique. */
class SauvegardeController extends Controller
{
    public function reglages(Request $r)
    {
        $d = $r->validate([
            'actif' => 'nullable|boolean',
            'frequence' => ['required', Rule::in(array_keys(Sauvegardes::FREQUENCES))],
            'jour_semaine' => 'required_if:frequence,semaine|nullable|integer|between:1,7',
            'jour_mois' => 'required_if:frequence,mois|nullable|integer|between:1,31',
            'date_annee' => ['required_if:frequence,annee', 'nullable', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
            'heure' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'elements' => 'required|array|min:1', 'elements.*' => Rule::in(array_keys(Sauvegardes::ELEMENTS)),
            'conserver' => 'required|integer|between:1,365',
        ], ['elements.required' => 'Cochez au moins un élément à sauvegarder.', 'date_annee.regex' => 'Date annuelle au format MM-JJ (ex. 12-31).'],
            ['frequence' => 'fréquence', 'heure' => 'heure', 'conserver' => 'nombre de sauvegardes conservées']);
        $avant = Sauvegardes::reglages();
        $cfg = ['actif' => $r->boolean('actif'), 'frequence' => $d['frequence'], 'jour_semaine' => (int) ($d['jour_semaine'] ?? $avant['jour_semaine']),
            'jour_mois' => (int) ($d['jour_mois'] ?? $avant['jour_mois']), 'date_annee' => $d['date_annee'] ?? $avant['date_annee'], 'heure' => $d['heure'],
            'elements' => array_values($d['elements']), 'conserver' => (int) $d['conserver'],
            // Point de départ de la programmation : pas de rattrapage des dates passées avant l'activation ou la modification
            'depuis' => now()->toIso8601String()];
        Setting::put('sauvegarde', $cfg);
        $this->log('Sauvegarde automatique '.($cfg['actif'] ? 'programmée : '.mb_strtolower(Sauvegardes::FREQUENCES[$cfg['frequence']]).' à '.$cfg['heure'] : 'désactivée'));
        return redirect()->route('parametres.index', ['tab' => 'sauvegardes'])->with('ok', $cfg['actif']
            ? 'Sauvegarde automatique programmée. Prochaine : '.Sauvegardes::prochaine($cfg, now())->translatedFormat('l j F Y à H:i').'.'
            : 'Sauvegarde automatique désactivée.');
    }

    public function maintenant(Request $r)
    {
        $d = $r->validate(['elements' => 'required|array|min:1', 'elements.*' => Rule::in(array_keys(Sauvegardes::ELEMENTS))],
            ['elements.required' => 'Cochez au moins un élément à sauvegarder.']);
        $s = Sauvegardes::creer($r->user()->cabinet_id, $d['elements'], 'manuel', $r->user()->id);
        $this->log('Sauvegarde manuelle '.$s->fichier.($s->statut === 'ok' ? '' : ' (échec)'));
        return redirect()->route('parametres.index', ['tab' => 'sauvegardes'])->with($s->statut === 'ok' ? 'ok' : 'err', $s->statut === 'ok'
            ? 'Sauvegarde faite : '.$s->fichier.($s->message ? ' — '.$s->message : '')
            : 'La sauvegarde a échoué : '.$s->message);
    }

    public function telecharger(Sauvegarde $sauvegarde)
    {
        $f = Sauvegardes::chemin($sauvegarde);
        abort_unless($sauvegarde->statut === 'ok' && is_file($f), 404);
        return response()->download($f, $sauvegarde->fichier, ['Content-Type' => 'application/zip']);
    }

    public function supprimer(Request $r, Sauvegarde $sauvegarde)
    {
        Sauvegardes::supprimer($sauvegarde, $r->user()->cabinet_id);
        return back()->with('ok', 'Sauvegarde supprimée.');
    }
}
