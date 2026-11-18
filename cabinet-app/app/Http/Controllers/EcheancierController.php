<?php

namespace App\Http\Controllers;

use App\Services\Echeancier;
use App\Services\Ecritures;
use Illuminate\Http\Request;

/** Grand livre → Échéancier clients et fournisseurs : délais de paiement du dossier, impression et export. */
class EcheancierController extends Controller
{
    /** Date d'arrêté (aujourd'hui, ou le 31/12 d'un exercice passé), tiers, compte et détail des pièces. */
    public static function parametres(Request $r, int $client, int $annee): array
    {
        $defaut = $annee < (int) now()->year ? $annee.'-12-31' : now()->format('Y-m-d');
        $date = $r->filled('date') && strtotime((string) $r->input('date')) ? date('Y-m-d', strtotime((string) $r->input('date'))) : $defaut;
        $section = $r->filled('section') ? (string) $r->input('section') : null;
        $compte = $r->filled('compte') ? (string) $r->input('compte') : null;
        return ['dateEch' => $date, 'section' => $section, 'compte' => $compte, 'detail' => $r->boolean('detail'),
            'ECH' => Echeancier::calculer($client, $date, $section, $compte)];
    }

    public function delais(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        if (! Ecritures::autorise($r->user())) return back()->with('err', 'Seuls le chef comptable et les collaborateurs autorisés peuvent modifier les délais de paiement.');
        $d = $r->validate(['delais' => 'required|array', 'delais.*' => 'required|integer|min:0|max:365']);
        Echeancier::enregistrerDelais($client->id, $d['delais']);
        $this->log('Échéancier : délais de paiement '.collect(Echeancier::delais($client->id))->map(fn ($v, $k) => $k.' = '.$v.' j')->implode(', '), $client->id);
        return back()->with('ok', 'Délais de paiement enregistrés.');
    }

    public function imprimer(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        return view('compta.echeancier-print', ['client' => $client] + self::parametres($r, $client->id, (int) $r->input('annee', now()->year)));
    }

    public function csv(Request $r)
    {
        $client = $this->dossier($r);
        abort_unless($client, 404);
        $P = self::parametres($r, $client->id, (int) $r->input('annee', now()->year));
        $n = fn ($v) => number_format((float) $v, 3, ',', '');
        $q = fn ($t) => '"'.str_replace('"', '""', (string) $t).'"';
        $row = fn (array $c) => implode(';', $c)."\r\n";
        $cols = array_keys(Echeancier::TRANCHES);
        $out = "\xEF\xBB\xBF".$row(array_merge(['Compte', 'Tiers', 'Pièce', 'Date', 'Échéance', 'Retard (j)'], array_values(Echeancier::TRANCHES), ['Non affecté', 'Total']));
        foreach ($P['ECH']['sections'] as $s) {
            $out .= $row(['', $q($s['titre'].' — délai '.$s['delai'].' j')]);
            foreach ($s['tiers'] as $t) {
                if ($P['detail']) foreach ($t['pieces'] as $p) {
                    $out .= $row(array_merge([$t['compte'], $q($t['libelle']), $q($p['libelle']), dfr($p['date']), $p['echeance'] ? dfr($p['echeance']) : '', $p['retard'] ?: ''],
                        array_map(fn ($k) => $p['tranche'] === $k ? $n($p['montant']) : '', $cols), [$p['tranche'] === 'non_affecte' ? $n($p['montant']) : '', $n($p['montant'])]));
                }
                $out .= $row(array_merge([$t['compte'], $q($t['libelle']), $q('Total'), '', '', ''], array_map(fn ($k) => $n($t[$k]), $cols), [$n($t['non_affecte']), $n($t['total'])]));
            }
            $out .= $row(array_merge(['', $q('Total '.$s['titre']), '', '', '', ''], array_map(fn ($k) => $n($s['T'][$k]), $cols), [$n($s['T']['non_affecte']), $n($s['T']['total'])]));
        }
        return response($out, 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="echeancier-'.$P['dateEch'].'-'.\Illuminate\Support\Str::slug($client->nom).'.csv"']);
    }
}
