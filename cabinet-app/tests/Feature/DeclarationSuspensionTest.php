<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Declaration;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\User;
use App\Services\Exports\BonsCommandeSuspension as Bcd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Déclarations : ventes en suspension de TVA (relevé mensuel) et liste trimestrielle des bons de commandes
 * et factures d'achats en suspension (cahier des charges DGI, fichier BCD_Tn_AA, bordereau de transmission).
 */
class DeclarationSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    // Matricules dont la clé est correcte : 7654321 → C, 1234567 → R.
    private const MF_DOSSIER = '7654321/C/A/M/000';
    private const MF_FOURN = '1234567RAM000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'chef', 'status' => 'active']));
        $this->client = Client::create(['nom' => 'Alpha Export', 'matricule_fiscal' => self::MF_DOSSIER, 'adresse' => '12 rue de Marseille 1000 Tunis', 'paie_activite' => 'Confection']);
        session(['dossier' => $this->client->id]);
    }

    public function test_onglets_presents(): void
    {
        $this->get(route('declarations.index'))->assertOk()
            ->assertSee('Achats en suspension de TVA')->assertSee('Ventes en suspension de TVA');
        $this->get(route('declarations.index', ['type' => 'sach', 'periode' => '2026-T3']))->assertOk()->assertSee('3e trimestre 2026')->assertSee('BCD_T3_26');
    }

    public function test_matricule_fiscal_et_cle(): void
    {
        $this->assertNull(Bcd::erreurMatricule(self::MF_FOURN));
        $this->assertNull(Bcd::erreurMatricule('1234567/R/A/M/000'));
        $this->assertNull(Bcd::erreurMatricule('1234567RM000'));                 // sans code TVA
        $this->assertSame('Clé du matricule fiscal incorrecte.', Bcd::erreurMatricule('1234567AAM000'));
        $this->assertNotNull(Bcd::erreurMatricule('123456'));
        $this->assertSame('7654321CM000', Bcd::identifiantDeclarant($this->client));
    }

    private function venteOk(array $x = []): array
    {
        return $x + ['fac' => 'FV-1', 'fac_date' => '2026-10-12', 'idk' => '1', 'id' => self::MF_FOURN, 'nom' => 'Client Nord', 'adr' => 'Zone industrielle 4000 Sousse',
            'att' => 'A-2026-77', 'att_date' => '2026-09-15', 'ht' => 1000];
    }

    public function test_ventes_saisie_controles_totaux_et_verrouillage(): void
    {
        $q = ['type' => 'sven', 'periode' => '2026-T4'];
        // Contrôles du cahier des charges FACDET
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['fac' => str_repeat('9', 21)]))->assertSessionHasErrors('fac');
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['idk' => '']))->assertSessionHasErrors('idk');
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['id' => '1234567AAM000']))->assertSessionHasErrors('id');
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['idk' => '2', 'id' => '123456789']))->assertSessionHasErrors('id');
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['att' => '']))->assertSessionHasErrors('att');
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['att_date' => '']))->assertSessionHasErrors('att_date');
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['tva' => 990, 'fodec' => 20]))->assertSessionHasErrors('tva');
        $this->assertSame(0, Declaration::where('type', 'sven')->count());

        // FODEC 1 % sur le HT, TVA 19 % sur HT + FODEC
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['fodec_taux' => 1]))->assertSessionHas('ok');
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['fac' => 'FV-0', 'fac_date' => '2026-10-05', 'idk' => '2', 'id' => '01234567', 'nom' => 'Particulier', 'ht' => 500, 'taux' => 7]))->assertSessionHas('ok');
        $d = Declaration::where(['type' => 'sven', 'periode' => '2026-T4'])->firstOrFail();
        [$a, $b] = $d->annexes['l'];
        $this->assertSame('FV-0', $a['fac']);                              // triées par date de facture
        $this->assertEqualsWithDelta(35, $a['tva'], 0.001);
        $this->assertEqualsWithDelta(10, $b['fodec'], 0.001);
        $this->assertEqualsWithDelta(191.9, $b['tva'], 0.001);

        $this->get(route('declarations.index', $q))->assertOk()->assertSee('Client Nord')->assertSee('A-2026-77')->assertSee('226,900')->assertSee('FAC_T4_26')->assertSee('Conforme');
        $this->get(route('declarations.print', $q))->assertOk()->assertSee('Client Nord')->assertSee('4e trimestre 2026');
        $csv = $this->get(route('declarations.csv', $q))->assertOk()->getContent();
        $this->assertStringContainsString('2;"FV-1";"12102026";1;"'.self::MF_FOURN.'";"Client Nord";"Zone industrielle 4000 Sousse";"A-2026-77";"15092026";1000000;1;10000;0;0;19;191900', $csv);

        $this->post(route('declarations.prefill'), $q)->assertSessionHas('ok');   // aucune facture : les lignes saisies restent
        $this->assertCount(2, $d->fresh()->annexes['l']);
        $this->delete(route('declarations.suspension.destroy'), $q + ['i' => 0])->assertSessionHas('ok');
        $this->assertCount(1, $d->fresh()->annexes['l']);

        $this->post(route('declarations.statut'), $q + ['action' => 'valider']);
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['fac' => 'FV-3']))->assertSessionHas('err');
        $this->get(route('declarations.fichier', ['type' => 'men', 'periode' => '2026-10']))->assertNotFound();
        $this->get(route('declarations.liste', ['type' => 'sach', 'periode' => '2026-T4']))->assertNotFound();
    }

    public function test_ventes_fichier_fac_conforme_au_cahier_des_charges(): void
    {
        $q = ['type' => 'sven', 'periode' => '2026-T4'];
        $this->post(route('declarations.save'), $q + ['f' => ['activite' => 'Confection', 'adresse' => '12 rue de Marseille 1000 Tunis']])->assertSessionHas('ok');
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['nom' => 'Société Électrique', 'fodec_taux' => 1, 'dc_taux' => 2.5]))->assertSessionHas('ok');
        $this->post(route('declarations.suspension.store'), $q + $this->venteOk(['fac' => 'FV-2', 'fac_date' => '2026-11-03', 'idk' => '4', 'id' => '777', 'nom' => 'Buyer Ltd', 'adr' => '', 'att' => '55', 'ht' => 200, 'taux' => 7]))->assertSessionHas('ok');

        $res = $this->get(route('declarations.fichier', $q))->assertOk();
        $this->assertStringContainsString('filename="FAC_T4_26"', $res->headers->get('Content-Disposition'));
        $txt = $res->getContent();
        $this->assertTrue(mb_check_encoding($txt, 'ASCII'));
        $R = explode("\r\n", rtrim($txt, "\r\n"));
        $this->assertCount(4, $R);
        foreach ($R as $rec) $this->assertSame(331, strlen($rec));             // longueur fixe de tous les enregistrements
        [$ef, $d1, $d2, $tf] = $R;

        $this->assertSame('EF7654321CM0002026T4', substr($ef, 0, 20));
        $this->assertSame(str_pad('Alpha Export', 40), substr($ef, 20, 40));
        $this->assertSame(str_pad('12 rue de Marseille 1000 Tunis', 120), substr($ef, 100, 120));
        $this->assertSame(str_repeat(' ', 111), substr($ef, 220));

        // FACDET : positions du cahier des charges (position − 1 pour substr).
        $this->assertSame('DF7654321CM0002026T4000001', substr($d1, 0, 26));
        $this->assertSame(str_pad('FV-1', 20), substr($d1, 26, 20));
        $this->assertSame('12102026', substr($d1, 46, 8));
        $this->assertSame('1', substr($d1, 54, 1));
        $this->assertSame(self::MF_FOURN, substr($d1, 55, 13));
        $this->assertSame(str_pad('Societe Electrique', 40), substr($d1, 68, 40));
        $this->assertSame(str_pad('Zone industrielle 4000 Sousse', 120), substr($d1, 108, 120));
        $this->assertSame(str_pad('A-2026-77', 20, '0', STR_PAD_LEFT), substr($d1, 228, 20));   // cadré par des zéros, comme l'outil officiel
        $this->assertSame('15092026', substr($d1, 248, 8));
        $this->assertSame('000000001000000', substr($d1, 256, 15));
        $this->assertSame('00100', substr($d1, 271, 5));                   // FODEC 1 %
        $this->assertSame('000000000010000', substr($d1, 276, 15));
        $this->assertSame('00250', substr($d1, 291, 5));                   // droit de consommation 2,5 %
        $this->assertSame('000000000025000', substr($d1, 296, 15));
        $this->assertSame('01900', substr($d1, 311, 5));
        $this->assertSame('000000000196650', substr($d1, 316, 15));       // 19 % × (1000 + 10 + 25)
        $this->assertSame('4', substr($d2, 54, 1));
        $this->assertSame('00000777     ', substr($d2, 55, 13));          // 8 chiffres cadrés par des zéros + 5 espaces
        $this->assertSame(str_repeat(' ', 120), substr($d2, 108, 120));

        $this->assertSame('TF7654321CM0002026T4000002', substr($tf, 0, 26));
        $this->assertSame(str_repeat(' ', 230), substr($tf, 26, 230));
        $this->assertSame('000000001200000'.'00000'.'000000000010000'.'00000'.'000000000025000'.'00000'.'000000000210650', substr($tf, 256));

        $this->get(route('declarations.bordereau', $q))->assertOk()->assertSee('قائمة مفصلة في الفواتير التي تم اصدارها')
            ->assertSee('1 200,000')->assertSee('210,650')->assertSee('25,000')->assertDontSee('عدد أذون التزود');
        $this->get(route('declarations.liste', $q))->assertOk()->assertSee('وصل إيداع')->assertSee('Société Électrique')->assertSee('A-2026-77')->assertSee('196,650');
    }

    public function test_achats_controles_de_saisie(): void
    {
        $q = ['type' => 'sach', 'periode' => '2026-T4'];
        $ok = ['att' => '15', 'att_date' => '2026-10-01', 'nom' => 'Fournisseur Sud', 'id' => self::MF_FOURN, 'fac' => 'FA-1', 'fac_date' => '2026-10-10', 'ht' => 1000];
        $this->post(route('declarations.suspension.store'), $q + ['att' => 'BC-15'] + $ok)->assertSessionHasErrors('att');
        $this->post(route('declarations.suspension.store'), $q + ['id' => '1234567AAM000'] + $ok)->assertSessionHasErrors('id');
        $this->post(route('declarations.suspension.store'), $q + ['nom' => str_repeat('x', 41)] + $ok)->assertSessionHasErrors('nom');
        $this->post(route('declarations.suspension.store'), $q + ['objet' => 'Tissu <coton>'] + $ok)->assertSessionHasErrors('objet');
        $this->post(route('declarations.suspension.store'), $q + ['aut' => 'COURT'] + $ok)->assertSessionHasErrors('aut');
        $this->post(route('declarations.suspension.store'), $q + ['tva' => 2000] + $ok)->assertSessionHasErrors('tva');
        $this->assertSame(0, Declaration::where('type', 'sach')->count());
        $this->post(route('declarations.suspension.store'), $q + ['id' => '1234567/r/a/m/000'] + $ok)->assertSessionHas('ok');
        $this->assertSame(self::MF_FOURN, Declaration::firstWhere('type', 'sach')->annexes['l'][0]['id']);
    }

    public function test_achats_fichier_bcd_conforme_au_cahier_des_charges(): void
    {
        $q = ['type' => 'sach', 'periode' => '2026-T4'];
        $this->post(route('declarations.save'), $q + ['f' => ['aut' => 'AUT123456789', 'activite' => 'Confection', 'adresse' => '12 rue de Marseille 1000 Tunis']])->assertSessionHas('ok');
        $this->post(route('declarations.suspension.store'), $q + ['att' => '15', 'att_date' => '2026-10-01', 'nom' => 'Société Générale d’Électricité', 'id' => self::MF_FOURN,
            'fac' => 'FA-1', 'fac_date' => '2026-10-10', 'ht' => 1000.5, 'objet' => 'Câbles électriques'])->assertSessionHas('ok');
        $this->post(route('declarations.suspension.store'), $q + ['att' => '16', 'att_date' => '2026-11-02', 'nom' => 'Fournisseur Nord', 'id' => self::MF_FOURN,
            'fac' => 'FA-2', 'fac_date' => '2026-11-05', 'ht' => 200, 'taux' => 7, 'aut' => 'AUT999999999'])->assertSessionHas('ok');

        $res = $this->get(route('declarations.fichier', $q))->assertOk();
        $this->assertStringContainsString('filename="BCD_T4_26"', $res->headers->get('Content-Disposition'));
        $txt = $res->getContent();
        $this->assertTrue(mb_check_encoding($txt, 'ASCII'));
        $this->assertStringEndsWith("\r\n", $txt);
        $R = explode("\r\n", rtrim($txt, "\r\n"));
        $this->assertCount(4, $R);
        [$ef, $df1, $df2, $tf] = $R;

        // Entête EF : 220 caractères.
        $this->assertSame(220, strlen($ef));
        $this->assertSame('EF7654321CM0002026T4', substr($ef, 0, 20));
        $this->assertSame(str_pad('Alpha Export', 40), substr($ef, 20, 40));
        $this->assertSame(str_pad('Confection', 40), substr($ef, 60, 40));
        $this->assertSame(str_pad('12 rue de Marseille 1000 Tunis', 120), substr($ef, 100, 120));

        // Détail DF : zones aux positions du cahier des charges, objet entre « < » et « /> ».
        $this->assertSame('DF7654321CM0002026T4', substr($df1, 0, 20));
        $this->assertSame('000001', substr($df1, 20, 6));
        $this->assertSame(str_pad('AUT123456789', 30), substr($df1, 26, 30));
        $this->assertSame('0000000000015', substr($df1, 56, 13));
        $this->assertSame('01102026', substr($df1, 69, 8));
        $this->assertSame(self::MF_FOURN, substr($df1, 77, 13));
        $this->assertSame(str_pad('Societe Generale d\'Electricite', 40), substr($df1, 90, 40));
        $this->assertSame(str_pad('FA-1', 30), substr($df1, 130, 30));
        $this->assertSame('10102026', substr($df1, 160, 8));
        $this->assertSame('000000001000500', substr($df1, 168, 15));     // 1 000,500 TND en millimes
        $this->assertSame('000000000190095', substr($df1, 183, 15));     // TVA 19 %
        $this->assertSame('<Cables electriques/>', substr($df1, 198));
        $this->assertSame(str_pad('AUT999999999', 30), substr($df2, 26, 30));   // autorisation propre à la ligne
        $this->assertSame('000002', substr($df2, 20, 6));
        $this->assertSame(201, strlen($df2));                             // objet vide : taille minimale
        $this->assertSame('000000000014000', substr($df2, 183, 15));

        // Fin TF : 198 caractères, nombre de lignes et totaux.
        $this->assertSame(198, strlen($tf));
        $this->assertSame('TF7654321CM0002026T4000002', substr($tf, 0, 26));
        $this->assertSame(str_repeat(' ', 142), substr($tf, 26, 142));
        $this->assertSame('000000001200500', substr($tf, 168, 15));
        $this->assertSame('000000000204095', substr($tf, 183, 15));

        // Export Excel au format de la feuille « BCD » de l'outil officiel, bordereau et impression.
        $csv = $this->get(route('declarations.csv', $q))->getContent();
        $this->assertStringContainsString('1;"AUT123456789";"15";"01102026";"'.self::MF_FOURN.'"', $csv);
        $this->assertStringContainsString(';1000500;190095;"Câbles électriques"', $csv);
        $this->get(route('declarations.bordereau', $q + ['date' => '2027-01-20']))->assertOk()
            ->assertSee('جدول إحالة على حامل ممغنط')->assertSee('1 200,500')->assertSee('204,095')->assertSee('Alpha Export');
        $this->get(route('declarations.print', $q))->assertOk()->assertSee('4e trimestre 2026')->assertSee('AUT999999999');
    }

    public function test_factures_en_suspension_comptabilisees_sans_tva_et_reprises_dans_les_declarations(): void
    {
        $susp = ['tva_suspension' => '1', 'suspension_ref' => '2026015', 'suspension_date' => '2026-10-01'];
        // Achats en suspension (oct. 2 lignes, déc.) + achat ordinaire ; vente en suspension + vente ordinaire.
        $this->post(route('compta.factures.store'), ['date' => '2026-10-10', 'fournisseur' => 'Fournisseur Sud', 'matricule_fiscal' => '1234567/R/A/M/000', 'reference' => 'FA-1', 'tva_deductible' => '1',
            'description' => 'Tissu <coton/>', 'lignes' => [['compte' => '6022', 'ht' => 1000, 'tva_taux' => 19], ['compte' => '6022', 'ht' => 100, 'tva_taux' => 7]]] + $susp)->assertSessionHas('ok');
        $this->post(route('compta.factures.store'), ['date' => '2026-12-03', 'fournisseur' => 'Fournisseur Sud', 'matricule_fiscal' => self::MF_FOURN, 'reference' => 'FA-3', 'ht' => 300, 'tva_taux' => 19, 'compte' => '6022'] + $susp)->assertSessionHas('ok');
        $this->post(route('compta.factures.store'), ['date' => '2026-10-11', 'fournisseur' => 'Fournisseur Local', 'reference' => 'FA-2', 'ht' => 200, 'tva_taux' => 19, 'compte' => '6022'])->assertSessionHas('ok');
        $this->post(route('compta.factures.store'), ['date' => '2026-10-11', 'fournisseur' => 'X', 'ht' => 200, 'compte' => '6022', 'tva_suspension' => '1', 'suspension_ref' => 'BC-1'])->assertSessionHasErrors('suspension_ref');
        $this->post(route('compta.ventes.store'), ['date' => '2026-10-12', 'tiers' => 'Client Export', 'matricule_fiscal' => '7654321/C/A/M/000', 'reference' => 'FV-1', 'ht' => 2000, 'tva_taux' => 19, 'compte' => '7011',
            'tva_suspension' => '1', 'suspension_ref' => 'ATT-9', 'suspension_date' => '2026-10-01'])->assertSessionHas('ok');
        $this->post(route('compta.ventes.store'), ['date' => '2026-10-13', 'tiers' => 'Client Local', 'reference' => 'FV-2', 'ht' => 500, 'tva_taux' => 19, 'compte' => '7011'])->assertSessionHas('ok');

        $fa = FactureRecue::firstWhere('reference', 'FA-1');
        $this->assertTrue($fa->tva_suspension);
        $this->assertEqualsWithDelta(0, $fa->tva, 0.001);
        $this->assertEqualsWithDelta(197, $fa->tva_suspendue, 0.001);
        $this->assertEqualsWithDelta(1100, $fa->ttc(), 0.001);
        $this->assertSame([0.0, 0.0], array_column($fa->lignesSaisie(), 'tva'));
        $this->assertNotContains('4366', array_column($fa->lignesEcriture(), 'compte'));
        $fv = FactureVente::firstWhere('reference', 'FV-1');
        $this->assertEqualsWithDelta(380, $fv->tva_suspendue, 0.001);
        $this->assertNotContains('4367', array_column($fv->lignesEcriture(), 'compte'));
        $this->assertFalse(FactureVente::firstWhere('reference', 'FV-2')->tva_suspension);

        // Factures non validées : rien n'est repris.
        $q = ['type' => 'sach', 'periode' => '2026-T4'];
        $this->post(route('declarations.prefill'), $q)->assertSessionHas('ok');
        $this->assertSame([], Declaration::firstWhere('type', 'sach')->annexes['l'] ?? []);

        foreach (FactureRecue::all() as $f) $this->post(route('compta.factures.valider', $f))->assertSessionHas('ok');
        foreach (FactureVente::all() as $f) $this->post(route('compta.ventes.valider', $f))->assertSessionHas('ok');

        // Ligne saisie à la main, conservée au pré-remplissage ; pré-remplissage répété sans doublon ; tout le trimestre.
        $this->post(route('declarations.suspension.store'), $q + ['att' => '99', 'att_date' => '2026-11-01', 'nom' => 'Manuel', 'id' => self::MF_FOURN, 'fac' => 'M-1', 'fac_date' => '2026-11-20', 'ht' => 10])->assertSessionHas('ok');
        foreach ([1, 2] as $_) $this->post(route('declarations.prefill'), $q)->assertSessionHas('ok');
        $L = Declaration::firstWhere('type', 'sach')->annexes['l'];
        $this->assertSame(['FA-1', 'M-1', 'FA-3'], array_column($L, 'fac'));
        $this->assertSame(['Fournisseur Sud', '2026015', '2026-10-01', self::MF_FOURN, 'Tissu coton'], [$L[0]['nom'], $L[0]['att'], $L[0]['att_date'], $L[0]['id'], $L[0]['objet']]);
        $this->assertEqualsWithDelta(197, $L[0]['tva'], 0.001);
        $this->assertSame(2, Bcd::totaux(Declaration::firstWhere('type', 'sach'))['bons']);   // bons 2026015 et 99

        $this->post(route('declarations.prefill'), ['type' => 'sven', 'periode' => '2026-T4'])->assertSessionHas('ok');
        $L = Declaration::firstWhere('type', 'sven')->annexes['l'];
        $this->assertSame(['Client Export'], array_column($L, 'nom'));
        $this->assertSame(['ATT-9', '1', '7654321CAM000'], [$L[0]['att'], $L[0]['idk'], $L[0]['id']]);
        $this->assertEqualsWithDelta(380, $L[0]['tva'], 0.001);

        // Déclaration mensuelle : la vente en suspension est déclarée pour information (ventes en suspension de TVA).
        $this->post(route('declarations.prefill'), ['type' => 'men', 'periode' => '2026-10'])->assertSessionHas('ok');
        $f = Declaration::firstWhere('type', 'men')->champs;
        $this->assertEqualsWithDelta(500, $f['tva19']['b'], 0.001);
        $this->assertEqualsWithDelta(2000, $f['inf_vsusp']['b'], 0.001);
        $this->assertEqualsWithDelta(95, \App\Services\DeclarationMensuelle::calcul($f)['tva_I'], 0.001);
        $this->assertEqualsWithDelta(38, $f['tvd_al']['m'], 0.001);

        // Décocher la case rétablit la TVA facturée.
        $fv2 = FactureVente::create(['client_id' => $this->client->id, 'date' => '2026-10-20', 'tiers' => 'X', 'ht' => 1, 'compte' => '7011', 'statut' => 'prop']);
        $this->put(route('compta.ventes.update', $fv2), ['date' => '2026-10-20', 'tiers' => 'X', 'ht' => 100, 'tva_taux' => 19, 'compte' => '7011', 'tva_suspension' => '0', 'suspension_ref' => 'Z'])->assertSessionHas('ok');
        $fv2->refresh();
        $this->assertEqualsWithDelta(19, $fv2->tva, 0.001);
        $this->assertNull($fv2->suspension_ref);
    }

    public function test_entete_repris_du_trimestre_precedent_et_formulaires_de_factures(): void
    {
        $this->post(route('declarations.save'), ['type' => 'sach', 'periode' => '2026-T3', 'f' => ['aut' => 'AUT123456789', 'activite' => 'Négoce', 'adresse' => 'Sfax']]);
        $this->get(route('declarations.index', ['type' => 'sach', 'periode' => '2026-T4']))->assertOk()->assertSee('AUT123456789')->assertSee('Négoce');
        $this->get(route('declarations.index', ['type' => 'sach', 'periode' => '2026-T2']))->assertOk()->assertDontSee('AUT123456789')->assertSee('Confection');

        $this->get(route('compta.index', ['tab' => 'ach']))->assertOk()->assertSee('Achat en suspension de TVA');
        $this->get(route('compta.index', ['tab' => 'vte']))->assertOk()->assertSee('Vente en suspension de TVA');
    }
}
