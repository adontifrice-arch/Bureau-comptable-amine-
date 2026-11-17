<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Declaration;
use App\Models\FactureRecue;
use App\Models\Paie;
use App\Models\Salarie;
use App\Models\User;
use App\Services\Exports\DeclarationEmployeur as Emp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Déclaration d'employeur sur support magnétique (cahier des charges DGI 2025) :
 * DECEMP_AA (51 enregistrements de 38 caractères) et ANXEMP_n_AA_1 (enregistrements de 399 caractères).
 */
class DeclarationEmployeurTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private const MF_FOURN = '1234567RAM000';      // clé correcte : 1234567 → R

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'chef', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha Export', 'matricule_fiscal' => '7654321/C/A/M/000', 'adresse' => '12 rue de Marseille 1000', 'paie_activite' => 'Confection']);
        session(['dossier' => $this->client->id]);
    }

    private function q(array $x = []): array
    {
        return ['type' => 'emp', 'periode' => '2025'] + $x;
    }

    /** Un salarié payé de mars à décembre 2025, une facture d'honoraires (3 %) et un achat (1,5 %). */
    private function donnees(): void
    {
        $e = Salarie::create(['client_id' => $this->client->id, 'nom' => 'Ben Salah', 'prenom' => 'Karim', 'cin' => '01234567', 'poste' => 'Comptable', 'salaire_base' => 1000,
            'situation_familiale' => 'marie', 'enfants' => 2, 'date_embauche' => '2025-03-01', 'adresse' => '5 rue de Rome', 'code_postal' => '1000', 'ville' => 'Tunis']);
        foreach (range(3, 12) as $m) {
            Paie::create(['client_id' => $this->client->id, 'mois' => sprintf('2025-%02d', $m), 'statut' => 'val', 'totaux' => [],
                'resultats' => [['salarie_id' => $e->id, 'brut' => 1000, 'cnssS' => 91.8, 'nature' => 0, 'ir' => 50, 'css' => 5, 'net' => 853.2]]]);
        }
        Paie::create(['client_id' => $this->client->id, 'mois' => '2025-02', 'statut' => 'brouillon', 'totaux' => [], 'resultats' => [['salarie_id' => $e->id, 'brut' => 999, 'cnssS' => 0, 'ir' => 9, 'css' => 0, 'net' => 990]]]);
        FactureRecue::create(['client_id' => $this->client->id, 'date' => '2025-06-10', 'fournisseur' => 'Cabinet Lex', 'matricule_fiscal' => self::MF_FOURN, 'description' => 'Honoraires avocat',
            'compte' => '6226', 'ht' => 1000, 'tva' => 190, 'retenue_taux' => 3, 'statut' => 'val']);
        FactureRecue::create(['client_id' => $this->client->id, 'date' => '2025-07-01', 'fournisseur' => 'Société Béta', 'matricule_fiscal' => self::MF_FOURN, 'description' => 'Matières',
            'compte' => '6011', 'ht' => 2000, 'tva' => 380, 'retenue_taux' => 1.5, 'statut' => 'val']);
    }

    public function test_pre_remplissage_et_fichiers_au_format_du_cahier_des_charges(): void
    {
        $this->donnees();
        $this->post(route('declarations.prefill'), $this->q())->assertSessionHas('ok');
        $this->post(route('declarations.save'), $this->q(['e' => ['acte' => '0', 'activite' => 'Confection', 'ville' => 'Tunis', 'rue' => 'Rue de Marseille', 'num' => '12', 'cp' => '1000']]));
        $d = Declaration::where(['client_id' => $this->client->id, 'type' => 'emp', 'periode' => '2025'])->sole();
        $this->assertCount(1, $d->annexes['a1']);
        $this->assertSame(['2', '2', '2025-03-01', '2025-12-31', 306], [$d->annexes['a1'][0]['sit'], (string) $d->annexes['a1'][0]['enf'], $d->annexes['a1'][0]['deb'], $d->annexes['a1'][0]['fin'], $d->annexes['a1'][0]['jours']]);
        $this->assertEqualsWithDelta(9082, $d->annexes['a1'][0]['imp'], 0.0001);
        $this->assertSame([], Emp::controlesEntete($this->client, $d->champs));
        foreach (['a1', 'a2', 'a5'] as $ax) $this->assertSame([], Emp::controlesLigne($ax, $d->annexes[$ax][0], 2025), $ax);

        $r = $this->get(route('declarations.fichier', $this->q()))->assertOk();
        $z = new \ZipArchive();
        $z->open($r->baseResponse->getFile()->getPathname());
        $noms = array_map(fn ($i) => $z->getNameIndex($i), range(0, $z->numFiles - 1));
        $this->assertSame(['DECEMP_25', 'ANXEMP_1_25_1', 'ANXEMP_2_25_1', 'ANXEMP_5_25_1'], $noms);
        $f = fn ($n) => explode("\r\n", rtrim($z->getFromName($n), "\r\n"));
        $at = fn (string $s, int $p, int $len) => substr($s, $p - 1, $len);

        // Récapitulatif : 51 enregistrements de 38 caractères
        $D = $f('DECEMP_25');
        $this->assertCount(51, $D);
        foreach ($D as $l) $this->assertSame(38, strlen($l));
        $this->assertSame('000'.'7654321CM000'.'2025'.'0011011'.str_repeat(' ', 12), $D[0]);
        $ligne = collect($D)->keyBy(fn ($l) => substr($l, 0, 3));
        $this->assertSame('010'.sprintf('%015d', 9082000).'00000'.sprintf('%015d', 500000), $ligne['010']);
        $this->assertSame('300'.str_repeat(' ', 20).sprintf('%015d', 50000), $ligne['300']);
        $this->assertSame('030'.sprintf('%015d', 1190000).'00300'.sprintf('%015d', 35700), $ligne['030']);
        $this->assertSame('135'.sprintf('%015d', 2380000).'00150'.sprintf('%015d', 35700), $ligne['135']);
        $this->assertSame('170'.str_repeat('0', 15).'02000'.str_repeat('0', 15), $ligne['170']);
        $this->assertSame('999'.str_repeat(' ', 20).sprintf('%015d', 621400), $D[50]);
        $this->assertSame(array_map('strval', array_keys(Emp::RECAP)), array_map(fn ($l) => substr($l, 0, 3), array_slice($D, 1, 49)));

        // Annexe I : entête, bénéficiaire, totaux
        $A = $f('ANXEMP_1_25_1');
        $this->assertCount(3, $A);
        foreach ($A as $l) $this->assertSame(399, strlen($l));
        $this->assertSame('E1'.'7654321CM000'.'2025'.'An1'.'0'.'000001', substr($A[0], 0, 28));
        $this->assertSame('Alpha Export', rtrim($at($A[0], 29, 40)));
        $this->assertSame('Confection', rtrim($at($A[0], 69, 40)));
        $this->assertSame(['Tunis', 'Rue de Marseille', '0012', '1000'], [rtrim($at($A[0], 109, 40)), rtrim($at($A[0], 149, 72)), $at($A[0], 221, 4), $at($A[0], 225, 4)]);
        $b = $A[1];
        $this->assertSame(['L1', '7654321CM000', '2025', '000001', '2', '01234567     '], [$at($b, 1, 2), $at($b, 3, 12), $at($b, 15, 4), $at($b, 19, 6), $at($b, 25, 1), $at($b, 26, 13)]);
        $this->assertSame(['Ben Salah Karim', 'Comptable'], [rtrim($at($b, 39, 40)), rtrim($at($b, 79, 40))]);
        $this->assertSame(['2', '02', '01032025', '31122025', '306'], [$at($b, 239, 1), $at($b, 240, 2), $at($b, 242, 8), $at($b, 250, 8), $at($b, 258, 3)]);
        $this->assertSame([9082000, 0, 9082000, 0, 500000, 0, 50000, 8532000], array_map(fn ($p) => (int) $at($b, $p, 15), [261, 276, 291, 306, 321, 336, 351, 366]));
        $this->assertSame(str_repeat(' ', 19), $at($b, 381, 19));
        $this->assertSame('T1'.'7654321CM000'.'2025'.str_repeat(' ', 242), substr($A[2], 0, 260));
        $this->assertSame([9082000, 500000, 50000, 8532000], array_map(fn ($p) => (int) $at($A[2], $p, 15), [261, 321, 351, 366]));

        // Annexe II : honoraires au régime réel (zone A214), matricule du bénéficiaire sur 13 caractères
        $b = $f('ANXEMP_2_25_1')[1];
        $this->assertSame(['L2', '1', self::MF_FOURN, 'Cabinet Lex', '1'], [$at($b, 1, 2), $at($b, 25, 1), $at($b, 26, 13), rtrim($at($b, 39, 40)), $at($b, 239, 1)]);
        $this->assertSame([0, 1190000, 35700, 1154300], array_map(fn ($p) => (int) $at($b, $p, 15), [240, 255, 360, 375]));
        $t = $f('ANXEMP_2_25_1')[2];
        $this->assertSame([1190000, 35700, 1154300], array_map(fn ($p) => (int) $at($t, $p, 15), [255, 360, 375]));

        // Annexe V : acquisitions auprès des autres entreprises (zone A515)
        $b = $f('ANXEMP_5_25_1')[1];
        $this->assertSame([2380000, 35700, 2344300], array_map(fn ($p) => (int) $at($b, $p, 15), [284, 329, 344]));
        $this->assertSame(str_repeat(' ', 41), $at($b, 359, 41));
        $z->close();
    }

    public function test_saisie_controles_et_recapitulatif_modifiable(): void
    {
        // Annexe IV : un CIN n'est pas un identifiant admis
        $this->post(route('declarations.annexe.store'), $this->q(['ax' => 'a4', 'idk' => '2', 'id' => '01234567', 'nom' => 'X', 'nat' => '1', 'm1' => 100, 'ret' => 15]))->assertSessionHasErrors('idk');

        // Annexe IV : non-résident personne morale, honoraires à 15 %
        $this->post(route('declarations.annexe.store'), $this->q(['ax' => 'a4', 'idk' => '4', 'id' => '1234567', 'nom' => 'Consult Ltd', 'pm' => '1', 'nat' => '1', 't1' => 15, 'm1' => 1000, 'ret' => 150]))->assertSessionHas('ok');
        // Annexe I : situation familiale et période manquantes
        $this->post(route('declarations.annexe.store'), $this->q(['ax' => 'a1', 'idk' => '2', 'id' => '7654321', 'nom' => 'Salarié', 'imp' => 500, 'ret' => 10]))->assertSessionHas('ok');
        $d = Declaration::where(['client_id' => $this->client->id, 'type' => 'emp'])->sole();
        $this->assertSame([], Emp::controlesLigne('a4', $d->annexes['a4'][0], 2025));
        $err = implode(' ', Emp::controlesLigne('a1', $d->annexes['a1'][0], 2025));
        $this->assertStringContainsString('Situation familiale', $err);
        $this->assertStringContainsString('Période de travail', $err);
        $this->get(route('declarations.index', $this->q(['ax' => 'a1'])))->assertOk()->assertSee('Situation familiale à renseigner')->assertSee('1 à corriger');

        // Correction de la ligne (même index)
        $this->post(route('declarations.annexe.store'), $this->q(['ax' => 'a1', 'i' => 0, 'idk' => '2', 'id' => '7654321', 'nom' => 'Salarié', 'sit' => '1', 'deb' => '2025-01-01', 'fin' => '2025-12-31', 'jours' => 365, 'imp' => 500, 'ret' => 10]));
        $d->refresh();
        $this->assertCount(1, $d->annexes['a1']);
        $this->assertSame([], Emp::controlesLigne('a1', $d->annexes['a1'][0], 2025));

        // Récapitulatif calculé : non-résident PM → 024 ; puis saisi, puis repris des annexes
        $R = Emp::lignesRecap($d);
        $this->assertSame(['a' => 1000.0, 'r' => 150.0], $R['024']);
        $this->assertSame(['a' => 500.0, 'r' => 10.0], $R['010']);
        $this->post(route('declarations.save'), $this->q(['r' => ['024' => ['a' => '1000', 'r' => '160'], '010' => ['a' => '500', 'r' => '10']]]));
        $this->assertSame(170.0, Emp::totalRetenues($d->refresh()));
        $this->get(route('declarations.index', $this->q()))->assertOk()->assertSee('Montants saisis')->assertSee('annexes : 1 000,000 / 150,000', false);
        $this->post(route('declarations.save'), $this->q(['reprendre' => 1]));
        $this->assertSame(160.0, Emp::totalRetenues($d->refresh()));

        // Ancienne ligne (montant brut seul) : reprise dans la zone principale
        $this->assertSame(250.0, Emp::normaliser('a2', ['idk' => '1', 'nom' => 'A', 'brut' => 250, 'ret' => 25])['m_res']);
    }

    public function test_ecrans(): void
    {
        $this->get(route('declarations.index', $this->q()))->assertOk()->assertSee('Tableau récapitulatif')->assertSee('DECEMP_25')->assertSee('Fichiers DGI')
            ->assertSee('Adresse du déclarant (ville et rue) à renseigner.');
        foreach (array_keys(Emp::ANNEXES) as $ax) $this->get(route('declarations.index', $this->q(['ax' => $ax])))->assertOk()->assertSee(Emp::nomAnnexe($ax, 2025));
        $this->get(route('declarations.print', $this->q()))->assertOk()->assertSee('Tableau récapitulatif');
        $this->get(route('declarations.csv', $this->q()))->assertOk();
    }
}
