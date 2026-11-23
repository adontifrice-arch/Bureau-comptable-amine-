<?php

namespace App\Models\Concerns;

use App\Models\ModeleFacture;
use Illuminate\Validation\ValidationException;

/**
 * Facture d'achat ou de vente saisie selon un modèle de facture (ModeleFacture) : écriture construite avec la copie
 * du paramétrage gardée dans « parametres » et les lignes « objet · assiette · TVA » saisies.
 *
 * Chaque montant va sur son compte, dans le sens du paramétrage (case « débiteur ») :
 * assiette → compte assiette (ou compte assiette majorée si la ligne a un taux majoré), TVA → compte TVA,
 * TVA majorée → compte TVA majorée ; timbre, FODEC et avance → leurs comptes.
 * Le TTC (compte du tiers saisi, sinon compte TTC du modèle) équilibre l'écriture : c'est la somme des montants
 * de sens opposé, moins ceux du même sens (une avance du même sens que le TTC vient donc en déduction).
 * Sans compte TVA, la TVA s'ajoute au compte de l'assiette (TVA non récupérable).
 */
trait SaisieSelonModele
{
    public function modeleFacture() { return $this->belongsTo(ModeleFacture::class); }

    public function selonModele(): bool
    {
        return ! empty($this->parametres);
    }

    /** Journal de l'écriture : celui du modèle, sinon le journal habituel. */
    public function journalEcriture(string $defaut): string
    {
        return (string) ($this->parametres['journal'] ?? '') ?: $defaut;
    }

    /**
     * Lignes de l'écriture (avant regroupement) et montant TTC, à partir du paramétrage et des montants.
     * $p : parametres ; $lignes : lignes saisies ; $m : timbre, fodec, avance ; $tiers : compte du tiers saisi.
     */
    public static function calculerSelonModele(array $p, array $lignes, array $m, string $tiers = ''): array
    {
        $sens = fn (string $compte, float $montant, bool $debit, ?string $libelle = null) => array_filter(
            ['compte' => $compte, 'debit' => $debit ? $montant : 0, 'credit' => $debit ? 0 : $montant, 'libelle' => $libelle], fn ($v) => $v !== null);
        $l = [];
        foreach ($lignes as $x) {
            $debit = (bool) ($x['debit'] ?? false);
            $ht = round((float) ($x['ht'] ?? 0), 3);
            $maj = round((float) ($x['tva_majoree'] ?? 0), 3);
            $tva = round((float) ($x['tva'] ?? 0) - $maj, 3);
            $assiette = ((float) ($x['taux_majore'] ?? 0) > 0 && ($x['compte_majore'] ?? '') !== '') ? $x['compte_majore'] : ($x['compte'] ?? '');
            if ($ht > 0) $l[] = $sens((string) $assiette, $ht, $debit, $x['objet'] ?? null);
            if ($tva > 0) $l[] = $sens((string) (($x['compte_tva'] ?? '') ?: $assiette), $tva, $debit, $x['objet'] ?? null);
            if ($maj > 0) $l[] = $sens((string) (($x['compte_tva_majore'] ?? '') ?: ($x['compte_tva'] ?? '') ?: $assiette), $maj, $debit, $x['objet'] ?? null);
        }
        foreach (['timbre', 'fodec', 'avance'] as $k) {
            $v = round((float) ($m[$k] ?? 0), 3);
            if ($v > 0) $l[] = $sens((string) ($p[$k]['compte'] ?? ''), $v, (bool) ($p[$k]['debit'] ?? false));
        }
        $d = array_sum(array_column($l, 'debit')); $c = array_sum(array_column($l, 'credit'));
        $ttcDebit = (bool) ($p['ttc']['debit'] ?? false);
        $ttc = round($ttcDebit ? $c - $d : $d - $c, 3);
        if ($ttc != 0) array_unshift($l, $sens($tiers ?: (string) ($p['ttc']['compte'] ?? ''), abs($ttc), $ttc > 0 ? $ttcDebit : ! $ttcDebit));
        return ['lignes' => $l, 'ttc' => $ttc];
    }

    /** Comptes manquants pour les montants saisis (message par compte), vérifiés à l'enregistrement. */
    public static function comptesManquants(array $p, array $lignes, array $m, string $tiers): array
    {
        $err = [];
        foreach ($lignes as $x) {
            if ((float) ($x['ht'] ?? 0) > 0 && ($x['compte'] ?? '') === '') $err[] = 'La ligne « '.($x['objet'] ?? '').' » n’a pas de compte assiette dans le modèle.';
        }
        foreach (['timbre' => 'timbre', 'fodec' => 'FODEC', 'avance' => 'avance'] as $k => $n) {
            if ((float) ($m[$k] ?? 0) > 0 && ($p[$k]['compte'] ?? '') === '') $err[] = 'Montant '.$n.' saisi : renseignez le compte '.$n.' dans le paramétrage du modèle.';
        }
        if ($tiers === '' && ($p['ttc']['compte'] ?? '') === '') $err[] = 'Choisissez le compte du tiers (ou un compte TTC dans le modèle).';
        return $err;
    }

    /** Libellé de la pièce et de chacune de ses lignes : N° de facture originale et nom du fournisseur ou du client. */
    public function libelleEcriture(): string
    {
        return trim(trim((string) $this->reference).' '.trim((string) ($this->fournisseur ?? $this->tiers))) ?: 'Facture';
    }

    /** Écriture de la facture : lignes regroupées par compte et sens, toutes au libellé de la pièce. */
    public function lignesSelonModele(): array
    {
        $x = self::calculerSelonModele((array) $this->parametres, (array) $this->lignes,
            ['timbre' => $this->timbre, 'fodec' => $this->fodec, 'avance' => $this->avance], (string) $this->compte_tiers);
        $g = [];
        foreach ($x['lignes'] as $l) {
            $l['libelle'] = $this->libelleEcriture();
            $k = $l['compte'].'|'.($l['debit'] > 0 ? 'D' : 'C').'|'.($l['libelle'] ?? '');
            if (isset($g[$k])) { $g[$k]['debit'] = round($g[$k]['debit'] + $l['debit'], 3); $g[$k]['credit'] = round($g[$k]['credit'] + $l['credit'], 3); }
            else $g[$k] = $l;
        }
        return array_values($g);
    }

    /** Facture saisie selon un modèle : écriture équilibrée et comptes renseignés, sinon message d'erreur. */
    public function erreurSelonModele(): ?string
    {
        $m = self::comptesManquants((array) $this->parametres, (array) $this->lignes, ['timbre' => $this->timbre, 'fodec' => $this->fodec, 'avance' => $this->avance], (string) $this->compte_tiers);
        if ($m) return $m[0];
        foreach ($this->lignesSelonModele() as $l) if ($l['compte'] === '') return 'Un montant n’a pas de compte : complétez le modèle de facture.';
        return null;
    }

    /** Montants « objet · assiette · TVA » saisis → lignes à enregistrer (copie des comptes du modèle, TVA majorée calculée). */
    public static function lignesDepuisModele(ModeleFacture $mo, array $saisies): array
    {
        $out = [];
        foreach ($mo->lignesModele() as $i => $l) {
            $ht = round((float) ($saisies[$i]['ht'] ?? 0), 3);
            $tva = round((float) ($saisies[$i]['tva'] ?? 0), 3);
            if ($ht <= 0 && $tva <= 0) continue;
            $maj = $l['taux_majore'] > 0 ? round($ht * $l['taux_majore'] / 100, 3) : 0;
            // Taux retenu pour les déclarations : celui du modèle, sinon celui que donnent les montants (7, 13, 19…)
            $taux = $l['tva_taux'] > 0 ? $l['tva_taux'] : ($ht > 0 && $tva > 0 ? self::tauxProche($tva / $ht * 100) : 0);
            $out[] = array_merge($l, ['ligne' => $i, 'ht' => $ht, 'tva' => round($tva + $maj, 3), 'tva_majoree' => $maj, 'tva_taux' => $taux]);
        }
        return $out;
    }

    private static function tauxProche(float $t): float
    {
        foreach (\App\Services\Saisie::TAUX_TVA as $legal) if (abs($t - $legal) < 0.6) return (float) $legal;
        return round($t, 2);
    }

    /** Erreur de validation si la facture n'a aucun montant. */
    public static function exigerMontants(array $lignes): void
    {
        if (! $lignes) throw ValidationException::withMessages(['lignes' => 'Saisissez au moins une assiette ou un montant de TVA.']);
    }
}
