<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Client;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\Releve;
use App\Models\ReleveLigne;
use App\Models\User;
use App\Services\Compta;
use App\Services\Recherche;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Recherche multicritère : période, montant (de … à …), partie du libellé, compte — seuls ou cumulés. */
class RechercheComptaTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $cabinet = Cabinet::creer('Cabinet test');
        $this->actingAs(User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']));
        $this->client = Client::factory()->create();
        session(['dossier' => $this->client->id]);
    }

    /** Valeurs d'une colonne affichées dans l'onglet pour une recherche. */
    private function chercher(string $tab, array $criteres, string $variable, string $colonne): array
    {
        return $this->get(route('compta.index', ['tab' => $tab] + $criteres))->assertOk()->viewData($variable)->pluck($colonne)
            ->map(fn ($v) => is_numeric($v) && ! in_array($colonne, ['compte'], true) ? (float) $v : $v)->sort()->values()->all();
    }

    public function test_criteres_lus_et_normalises(): void
    {
        $r = Recherche::depuis(Request::create('/', 'GET', ['du' => '2026-03-31', 'au' => '2026-01-01', 'min' => '1 250,500', 'max' => '100', 'texte' => '  steg ', 'cpt' => '60 61']));

        $this->assertSame(['2026-01-01', '2026-03-31', 100.0, 1250.5, 'steg', '6061'], [$r->du, $r->au, $r->min, $r->max, $r->texte, $r->compte]);   // bornes inversées remises dans l'ordre
        $this->assertSame(4, $r->nombre());
        $this->assertFalse(Recherche::depuis(Request::create('/', 'GET', ['min' => 'abc', 'du' => 'pas une date']))->active());
    }

    public function test_factures_d_achat(): void
    {
        $f = fn ($d, $fo, $ht, $cpt, $desc = null) => FactureRecue::create(['client_id' => $this->client->id, 'date' => $d, 'fournisseur' => $fo, 'description' => $desc,
            'ht' => $ht, 'tva' => round($ht * .19, 3), 'timbre' => 1, 'compte' => $cpt, 'statut' => 'prop']);
        $f('2026-01-10', 'STEG', 100, '6062', 'Électricité janvier');
        $f('2026-02-10', 'STEG', 250, '6062', 'Électricité février');
        $f('2026-02-15', 'Tunisie Telecom', 80, '626');
        $f('2026-03-01', 'Cabinet Ksouri', 1200, '622', 'Honoraires avocat');

        $this->assertSame(['STEG', 'STEG'], $this->chercher('ach', ['texte' => 'steg'], 'factures', 'fournisseur'));                       // une partie du libellé
        $this->assertSame(['Cabinet Ksouri'], $this->chercher('ach', ['texte' => 'honoraires'], 'factures', 'fournisseur'));            // description
        $this->assertSame(['STEG', 'Tunisie Telecom'], $this->chercher('ach', ['du' => '2026-02-01', 'au' => '2026-02-28'], 'factures', 'fournisseur'));
        $this->assertSame([250.0], $this->chercher('ach', ['min' => '200', 'max' => '300'], 'factures', 'ht'));                           // HT dans la fourchette
        $this->assertSame([100.0], $this->chercher('ach', ['min' => '120,000', 'max' => '120,000'], 'factures', 'ht'));                   // TTC exact 100 + 19 + 1
        $this->assertSame([250.0], $this->chercher('ach', ['texte' => 'STEG', 'du' => '2026-02-01', 'min' => '200'], 'factures', 'ht'));   // trois critères cumulés
        $this->assertSame(['626'], $this->chercher('ach', ['cpt' => '62', 'max' => '100'], 'factures', 'compte'));
        $this->assertSame([], $this->chercher('ach', ['texte' => 'STEG', 'cpt' => '622'], 'factures', 'compte'));

        $this->get(route('compta.index', ['tab' => 'ach', 'texte' => 'steg', 'min' => '50']))
            ->assertSee('2</b> résultat(s)', false)->assertSee('libellé contenant « steg »', false)->assertSee('Effacer la recherche');
    }

    public function test_factures_de_vente(): void
    {
        foreach ([['2026-01-05', 'Société Alpha', 1000], ['2026-01-20', 'Société Beta', 500], ['2026-02-02', 'Alpha Sport', 75]] as [$d, $t, $ht]) {
            FactureVente::create(['client_id' => $this->client->id, 'date' => $d, 'tiers' => $t, 'ht' => $ht, 'tva' => $ht * .19, 'compte' => '705', 'statut' => 'prop']);
        }
        $this->assertSame(['Alpha Sport', 'Société Alpha'], $this->chercher('vte', ['texte' => 'alpha'], 'ventes', 'tiers'));
        $this->assertSame(['Société Alpha'], $this->chercher('vte', ['texte' => 'alpha', 'min' => '900'], 'ventes', 'tiers'));
        $this->assertSame(['Société Beta'], $this->chercher('vte', ['texte' => 'société', 'au' => '2026-01-31', 'max' => '600'], 'ventes', 'tiers'));
    }

    public function test_lignes_de_banque_toutes_statuts_et_liens_conserves(): void
    {
        $rel = Releve::create(['client_id' => $this->client->id, 'nom_fichier' => 'janvier.pdf', 'methode' => 'pdf']);
        foreach ([['2026-01-02', 'REM TPE 311225', 0, 73, 'att'], ['2026-01-02', 'COMM REM TPE', 0.78, 0, 'cpt'], ['2026-01-19', 'REGLEMENT PRELEVEMENT CNSS', 3929.345, 0, 'att'],
            ['2026-01-27', 'VIR EMIS STE LEFT FACTURE 000199', 1083, 0, 'ign']] as [$d, $l, $db, $cr, $st]) {
            ReleveLigne::create(['client_id' => $this->client->id, 'releve_id' => $rel->id, 'date' => $d, 'libelle' => $l, 'debit' => $db, 'credit' => $cr, 'statut' => $st, 'compte' => '461']);
        }

        // Sans statut choisi, la recherche porte sur toutes les lignes (comptabilisées et ignorées comprises)
        $this->assertSame(['COMM REM TPE', 'REM TPE 311225'], $this->chercher('bq', ['texte' => 'rem tpe'], 'lignes', 'libelle'));
        $this->assertSame(['VIR EMIS STE LEFT FACTURE 000199'], $this->chercher('bq', ['min' => '1 000', 'max' => '2 000'], 'lignes', 'libelle'));
        $this->assertSame(['REGLEMENT PRELEVEMENT CNSS'], $this->chercher('bq', ['min' => '3929,345', 'max' => '3929,345'], 'lignes', 'libelle'));      // montant exact
        $this->assertSame(['REM TPE 311225'], $this->chercher('bq', ['texte' => 'tpe', 'statut' => 'att'], 'lignes', 'libelle'));                        // statut choisi conservé
        $this->assertSame(['COMM REM TPE', 'REM TPE 311225'], $this->chercher('bq', ['du' => '2026-01-01', 'au' => '2026-01-05'], 'lignes', 'libelle'));

        $this->get(route('compta.index', ['tab' => 'bq', 'texte' => 'cnss']))->assertSee('texte=cnss', false)->assertSee('débits 3 929,345 TND', false);
    }

    public function test_ecritures_du_journal(): void
    {
        Compta::passer($this->client->id, '2026-01-15', 'ACH', 'Facture STEG', [['compte' => '6062', 'debit' => 100], ['compte' => '401', 'credit' => 100]]);
        Compta::passer($this->client->id, '2026-02-15', 'BQ', 'Règlement STEG', [['compte' => '401', 'debit' => 100], ['compte' => '532', 'credit' => 100]]);
        Compta::passer($this->client->id, '2025-12-20', 'OD', 'Loyer décembre', [['compte' => '613', 'debit' => 900], ['compte' => '401', 'credit' => 900]]);

        $this->assertSame(['Facture STEG', 'Règlement STEG'], $this->chercher('jnl', ['annee' => 2026, 'texte' => 'steg'], 'ecritures', 'libelle'));
        $this->assertSame(['Facture STEG'], $this->chercher('jnl', ['annee' => 2026, 'cpt' => '606'], 'ecritures', 'libelle'));                  // compte d'une ligne
        $this->assertSame(['Loyer décembre'], $this->chercher('jnl', ['annee' => 2026, 'du' => '2025-12-01', 'au' => '2025-12-31'], 'ecritures', 'libelle'));   // la période remplace l'exercice
        $this->assertSame(['Loyer décembre'], $this->chercher('jnl', ['annee' => 2026, 'du' => '2025-01-01', 'min' => '500'], 'ecritures', 'libelle'));
        $this->assertSame(['Règlement STEG'], $this->chercher('jnl', ['annee' => 2026, 'journal' => 'BQ', 'texte' => 'steg'], 'ecritures', 'libelle'));
    }
}
