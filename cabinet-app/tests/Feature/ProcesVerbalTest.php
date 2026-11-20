<?php

namespace Tests\Feature;

use App\Models\Acte;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PV d'AGO et d'AGE selon les modèles du cabinet (« PV AGO 2025 », « PV AGE »). */
class ProcesVerbalTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'collaborateur', 'status' => 'active']));
        $this->client = Client::factory()->create([
            'nom' => 'QUEUES DE JOIE', 'forme' => 'SUARL', 'capital' => 1000, 'nb_parts' => 100, 'valeur_nominale' => 10,
            'adresse' => 'Résidence Mimosa, rue Ibn Rochd Sidi Daoued La Marsa Tunis', 'matricule_fiscal' => '1837891DNM000',
            'gerant_nom' => 'Yosr Hend Lakhoua', 'gerant_nationalite' => 'tunisienne', 'gerant_cin' => '04705460', 'gerant_adresse' => '10 rue Ali Bouchoucha Montfleury Tunis',
            'gerant_associe' => true, 'gerant_parts' => 100,
        ]);
    }

    private function pv(array $d): string
    {
        $this->post(route('actes.store', $this->client), $d)->assertSessionHasNoErrors();
        return Acte::latest('id')->first()->contenu;
    }

    public function test_pv_ago_associe_unique(): void
    {
        $h = $this->pv(['type' => 'ago', 'date' => '2026-07-24', 'heure' => '12', 'exercice' => 2025, 'total_bilan' => 205092.063, 'resultat' => 15104.127,
            'ordre' => ['Approbation des états financiers de l’exercice clos le 31 décembre', 'Affectation du résultat', 'Quitus au gérant']]);

        $this->assertStringContainsString('<b>SOCIÉTÉ QUEUES DE JOIE</b><br>SUARL au capital de 1 000 DT<br>Résidence Mimosa', $h);
        $this->assertStringContainsString('<br>Matricule fiscal : 1837891DNM000', $h);
        $this->assertStringContainsString('PV DE L’ASSEMBLÉE GÉNÉRALE ORDINAIRE<br>DU 24 JUILLET 2026', $h);
        $this->assertStringContainsString('L’an deux mille vingt-six, le 24 juillet à 12 heures, l’associé(e) unique de la société QUEUES DE JOIE SUARL s’est réuni(e) au siège de la société à l’effet de délibérer sur l’ordre du jour suivant :', $h);
        $this->assertStringContainsString('<li>Approbation des états financiers de l’exercice clos le 31 décembre 2025 ;</li><li>Affectation du résultat ;</li><li>Quitus au gérant pour sa gestion de l’exercice 2025.</li>', $h);
        $this->assertStringContainsString('Était présent(e) : Yosr Hend Lakhoua, de nationalité tunisienne, titulaire de la CIN n° 04705460, demeurant à 10 rue Ali Bouchoucha Montfleury Tunis, l’associé(e) unique de la société.', $h);

        $this->assertStringContainsString('<h3>Article premier :</h3><p style="text-align:justify">Après examen des états financiers arrêtés au 31 décembre 2025, l’Assemblée Générale Ordinaire décide de les approuver dans leur intégralité.', $h);
        $this->assertStringContainsString('Ces états font ressortir un total bilan de 205 092,063 dinars et un résultat bénéficiaire net de 15 104,127 dinars.', $h);
        $this->assertStringContainsString('<h3>Article deux :</h3><p style="text-align:justify">Le résultat de l’exercice 2025 présente un bénéfice net de 15 104,127 DT, l’Assemblée Générale Ordinaire décide de l’affecter en report à nouveau.', $h);
        $this->assertStringContainsString('<h3>Article trois :</h3><p style="text-align:justify">Un quitus entier et définitif est donné au gérant pour sa gestion de l’exercice 2025.', $h);
        $this->assertSame(3, substr_count($h, 'Cette résolution a été adoptée à l’unanimité.'));
        $this->assertStringContainsString('Plus rien n’étant à l’ordre du jour, la séance est levée.', $h);
        $this->assertStringContainsString('<b>L’associé(e) unique</b><br><br>Yosr Hend Lakhoua', $h);
    }

    public function test_pv_age_cession_augmentation_et_transformation(): void
    {
        $h = $this->pv(['type' => 'age', 'date' => '2026-09-22', 'heure' => '8',
            'ordre' => ['Cession de parts sociales et agrément du cessionnaire', 'Augmentation du capital', 'Transformation de la forme juridique'],
            'cedant' => 'Yosr Hend Lakhoua', 'parts' => 49, 'cessionnaire' => 'Slim Gammoudi', 'cessionnaire_cin' => '07039838', 'cessionnaire_adresse' => '10 rue Ali Bouchoucha Montfleury Tunis',
            'augmentation' => 11000]);

        $this->assertStringContainsString('PV DE L’ASSEMBLÉE GÉNÉRALE EXTRAORDINAIRE<br>DU 22 SEPTEMBRE 2026', $h);
        $this->assertStringContainsString('le 22 septembre à 8 heures, l’assemblée générale de la société QUEUES DE JOIE s’est réunie', $h);
        $this->assertStringContainsString('Était présent(e) aussi : Slim Gammoudi, titulaire de la CIN n° 07039838, demeurant à 10 rue Ali Bouchoucha Montfleury Tunis.', $h);
        $this->assertStringContainsString('Après discussions et échanges de vues, les résolutions suivantes ont été adoptées et prises à l’unanimité.', $h);

        $this->assertStringContainsString('<h3>Première résolution :</h3><p style="text-align:justify">L’associé(e) unique a décidé d’autoriser et d’approuver la cession de 49 parts sociales lui appartenant à Slim Gammoudi', $h);
        $this->assertStringContainsString('<h3>Deuxième résolution :</h3><p style="text-align:justify">Le capital social est augmenté de 11 000 DT en numéraire et passe ainsi de 1 000 DT à 12 000 DT.', $h);
        $this->assertStringContainsString('Cette augmentation est réalisée par l’émission de 1100 parts sociales nouvelles de 10 DT chacune.', $h);
        // Souscription au prorata des parts détenues après la cession (51 / 49)
        $this->assertMatchesRegularExpression('#Yosr Hend Lakhoua</td>\s*<td[^>]*>561</td>\s*<td[^>]*>5 610</td>#', $h);
        $this->assertMatchesRegularExpression('#Slim Gammoudi</td>\s*<td[^>]*>539</td>\s*<td[^>]*>5 390</td>#', $h);
        // Répartition avant et après augmentation
        $this->assertStringContainsString('colspan="4"', $h);
        $this->assertMatchesRegularExpression('#Yosr Hend Lakhoua</td>\s*<td[^>]*>51</td>\s*<td[^>]*>10</td>\s*<td[^>]*>510</td>\s*<td[^>]*>51 %</td>\s*<td[^>]*>612</td>\s*<td[^>]*>10</td>\s*<td[^>]*>6 120</td>\s*<td[^>]*>51 %</td>\s*<td[^>]*>5 610</td>#', $h);
        $this->assertMatchesRegularExpression('#<b>Total</b></td>\s*<td[^>]*>100</td>\s*<td[^>]*></td>\s*<td[^>]*>1 000</td>\s*<td[^>]*>100 %</td>\s*<td[^>]*>1200</td>\s*<td[^>]*></td>\s*<td[^>]*>12 000</td>#', $h);

        $this->assertStringContainsString('<h3>Troisième résolution :</h3><p style="text-align:justify">L’assemblée générale décide la modification de la forme juridique de la société de SUARL en SARL.</p><p style="text-align:justify">L’assemblée générale décide par conséquent la mise à jour des statuts.', $h);
        $this->assertStringContainsString('<b>LES ASSOCIÉS</b>', $h);
        $this->assertMatchesRegularExpression('#Yosr Hend Lakhoua</td>\s*<td[^>]*>Slim Gammoudi</td></tr></table>$#', $h);
    }

    public function test_informations_manquantes(): void
    {
        $h = $this->pv(['type' => 'ago', 'date' => '2026-06-30', 'ordre' => ['Approbation des états financiers de l’exercice clos le 31 décembre', 'Questions diverses']]);
        $this->assertStringContainsString('à ……… heures', $h);
        $this->assertStringContainsString('total bilan de ……… dinars et un résultat net de ……… dinars', $h);
        $this->assertStringContainsString('<li>Questions diverses.</li>', $h);
        $this->assertSame(1, substr_count($h, '<h3>Article'));   // pas de résolution pour les questions diverses
    }
}
