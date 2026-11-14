<?php

namespace Tests\Unit;

use App\Services\Amortissement;
use PHPUnit\Framework\TestCase;

/** Valeurs de recette de la spécification §5.5. */
class AmortissementTest extends TestCase
{
    private function dotations(array $plan): array
    {
        return array_map(fn ($l) => $l['dotation'], $plan);
    }

    public function test_recette_informatique_lineaire_prorata_au_mois(): void
    {
        // Matériel informatique 3 000 TND acquis le 15/03, linéaire 33,33 %, prorata au mois → 833,250
        $p = Amortissement::plan(['valeur_origine' => 3000, 'methode' => 'lineaire', 'taux' => 33.33, 'prorata' => 'mois', 'date_mise_service' => '2026-03-15'], 2030);

        $this->assertSame(833.25, $p[2026]['dotation']);
        $this->assertSame(999.9, $p[2027]['dotation']);
        $this->assertSame(999.9, $p[2028]['dotation']);
        $this->assertSame(166.95, $p[2029]['dotation']);          // solde : cumul plafonné à la base
        $this->assertSame(0.0, $p[2030]['dotation']);
        $this->assertSame(3000.0, $p[2029]['cumul']);
        $this->assertSame(0.0, $p[2029]['vnc']);
    }

    public function test_recette_machine_degressif_avec_passage_au_lineaire(): void
    {
        // Machine 10 000 TND, durée 5 ans (taux linéaire 20 %, coefficient 2) → 4 000, 2 400, 1 440, 1 080, 1 080
        $p = Amortissement::plan(['valeur_origine' => 10000, 'methode' => 'degressif', 'taux' => 20, 'prorata' => 'mois', 'date_mise_service' => '2026-01-01'], 2031);

        $this->assertSame(2.0, Amortissement::coefficient(20));
        $this->assertSame([2026 => 4000.0, 2027 => 2400.0, 2028 => 1440.0, 2029 => 1080.0, 2030 => 1080.0, 2031 => 0.0], $this->dotations($p));
        $this->assertSame(0.0, $p[2030]['vnc']);
    }

    public function test_recette_camionnette_cedee_en_cours_d_annee(): void
    {
        // Camionnette 6 000 TND, 10 %, cédée le 30/06 → 300 l'année de la cession, puis plus rien
        $p = Amortissement::plan(['valeur_origine' => 6000, 'methode' => 'lineaire', 'taux' => 10, 'prorata' => 'mois',
            'date_mise_service' => '2020-01-01', 'date_cession' => '2026-06-30'], 2027);

        $this->assertSame(600.0, $p[2025]['dotation']);
        $this->assertSame(300.0, $p[2026]['dotation']);
        $this->assertSame(0.0, $p[2027]['dotation']);
    }

    public function test_coefficients_du_degressif(): void
    {
        $this->assertSame(1.5, Amortissement::coefficient(33.33));   // 3 ans
        $this->assertSame(1.5, Amortissement::coefficient(25));      // 4 ans
        $this->assertSame(2.0, Amortissement::coefficient(20));      // 5 ans
        $this->assertSame(2.0, Amortissement::coefficient(16.67));   // 6 ans
        $this->assertSame(2.5, Amortissement::coefficient(10));      // 10 ans
    }

    public function test_prorata_au_jour_et_sans_prorata(): void
    {
        $b = ['valeur_origine' => 3650, 'methode' => 'lineaire', 'taux' => 10, 'date_mise_service' => '2027-07-01'];

        // 2027 : 184 jours sur 365
        $this->assertSame(184.0, Amortissement::plan($b + ['prorata' => 'jour'], 2027)[2027]['dotation']);
        $this->assertSame(365.0, Amortissement::plan($b + ['prorata' => 'aucun'], 2027)[2027]['dotation']);
        $this->assertSame(182.5, Amortissement::plan($b + ['prorata' => 'mois'], 2027)[2027]['dotation']);
    }

    public function test_valeur_residuelle_et_bien_non_amortissable(): void
    {
        $p = Amortissement::plan(['valeur_origine' => 10000, 'valeur_residuelle' => 2000, 'methode' => 'lineaire', 'taux' => 25, 'prorata' => 'aucun',
            'date_mise_service' => '2026-01-01'], 2031);
        $this->assertSame([2026 => 2000.0, 2027 => 2000.0, 2028 => 2000.0, 2029 => 2000.0, 2030 => 0.0, 2031 => 0.0], $this->dotations($p));
        $this->assertSame(2000.0, $p[2031]['vnc']);

        $terrain = Amortissement::plan(['valeur_origine' => 50000, 'methode' => 'lineaire', 'taux' => 0, 'date_mise_service' => '2026-01-01', 'amortissable' => false], 2027);
        $this->assertSame([2026 => 0.0, 2027 => 0.0], $this->dotations($terrain));
    }

    public function test_degressif_avec_prorata_la_premiere_annee(): void
    {
        // 10 000 TND au 01/07, 5 ans, coefficient 2 : 1re année 10 000 × 40 % × 6/12
        $p = Amortissement::plan(['valeur_origine' => 10000, 'methode' => 'degressif', 'taux' => 20, 'prorata' => 'mois', 'date_mise_service' => '2026-07-01'], 2032);
        $this->assertSame(2000.0, $p[2026]['dotation']);
        $this->assertSame(3200.0, $p[2027]['dotation']);
        $this->assertSame(10000.0, $p[2031]['cumul']);                 // totalement amorti au terme des 5 ans
        $this->assertSame(0.0, $p[2032]['dotation']);
    }

    public function test_nature_deduite_du_compte(): void
    {
        $this->assertSame('informatique', Amortissement::natureDuCompte('2261'));
        $this->assertSame('transport', Amortissement::natureDuCompte('224'));
        $this->assertSame('terrain', Amortissement::natureDuCompte('220'));
        $this->assertSame('incorporelle', Amortissement::natureDuCompte('2131'));
    }
}
