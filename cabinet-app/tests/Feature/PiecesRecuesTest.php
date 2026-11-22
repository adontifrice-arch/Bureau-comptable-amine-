<?php

namespace Tests\Feature;

use App\Models\Acompte;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Conge;
use App\Models\Document;
use App\Models\Ecriture;
use App\Models\EcritureProposee;
use App\Models\FactureRecue;
use App\Models\FactureVente;
use App\Models\Immobilisation;
use App\Models\Paie;
use App\Models\Pret;
use App\Models\Releve;
use App\Models\Salarie;
use App\Models\SalarieDocument;
use App\Models\User;
use App\Services\TraitementPieces;
use App\Support\CabinetCourant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequeteHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Documents reçus des clients : aiguillage vers la comptabilité, les immobilisations ou la paie, lecture par l'IA, validation et import. */
class PiecesRecuesTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;
    private array $reponseIa = [];
    private Client $client;
    private Salarie $karim;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Mail::fake();
        config(['services.anthropic.key' => 'cle-de-test', 'services.anthropic.model' => 'claude-opus-5-5']);
        // Réponse de l'API Claude simulée : la dernière fixée par ia()
        Http::fake(['api.anthropic.com/*' => fn () => $this->reponseIa === ['erreur' => 401]
            ? Http::response(['error' => ['message' => 'invalid x-api-key']], 401)
            : Http::response(['content' => [['type' => 'text', 'text' => json_encode($this->reponseIa)]], 'stop_reason' => 'end_turn'])]);
        $cabinet = Cabinet::creer('Cabinet test');
        $this->chef = User::factory()->create(['cabinet_id' => $cabinet->id, 'role' => 'chef', 'status' => 'active']);
        [$this->client, $this->karim] = CabinetCourant::pour($cabinet->id, function () {
            $c = Client::factory()->create(['nom' => 'Alpha Conseil']);
            return [$c, Salarie::create(['client_id' => $c->id, 'nom' => 'Haddad', 'prenom' => 'Karim', 'matricule' => 'E01', 'cin' => '01234567', 'salaire_base' => 1300])];
        });
    }

    protected function tearDown(): void
    {
        CabinetCourant::utiliser(null);
        parent::tearDown();
    }

    /** Réponse de l'API Claude contenant l'objet JSON lu. */
    private function ia(array $data): void
    {
        $this->reponseIa = $data;
    }

    private function recu(string $type, string $nom = 'piece.pdf', ?string $periode = null, ?string $contenu = null): Document
    {
        return CabinetCourant::pour($this->chef->cabinet_id, function () use ($type, $nom, $periode, $contenu) {
            $chemin = 'ged/'.$this->chef->cabinet_id.'/'.$this->client->id.'/'.uniqid().'-'.$nom;
            Storage::put($chemin, $contenu ?? '%PDF-1.4 pièce');
            $d = Document::create(['client_id' => $this->client->id, 'categorie' => 'Autres documents', 'nom' => $nom, 'chemin' => $chemin, 'taille' => 10,
                'mime' => 'application/pdf', 'date' => now(), 'origine' => 'client']);
            TraitementPieces::recevoir($d, $type, $periode);
            return $d->fresh();
        });
    }

    private function dans(callable $fn): mixed
    {
        return CabinetCourant::pour($this->chef->cabinet_id, $fn);
    }

    private function facture(array $champs = []): array
    {
        return $champs + ['tiers' => 'STEG', 'matricule_fiscal' => '0001234A', 'reference' => 'F-2026-118', 'date' => '2026-10-12', 'description' => 'Électricité octobre',
            'lignes' => [['compte' => '6061', 'libelle' => 'Électricité', 'ht' => 100, 'taux_tva' => 19, 'tva' => 19]], 'timbre' => 1, 'ttc' => 120, 'retenue_taux' => 0, 'anomalies' => []];
    }

    // ---------- Aiguillage depuis l'espace client ----------

    public function test_envoi_du_client_aiguille_et_lit_la_facture(): void
    {
        $this->ia($this->facture());
        $u = ClientUser::create(['cabinet_id' => $this->chef->cabinet_id, 'client_id' => $this->client->id, 'name' => 'Gérant', 'email' => 'g@alpha.tn', 'password' => 'MotDePasse1', 'actif' => true]);
        $this->actingAs($u, 'client')->post(route('espace.envoyer'), ['type_piece' => 'facture_achat', 'fichiers' => [UploadedFile::fake()->create('steg.pdf', 50, 'application/pdf')]])
            ->assertSessionHas('ok');

        $doc = $this->dans(fn () => Document::sole());
        $this->assertSame(['facture_achat', 'compta', 'Factures d’achat', 'propose'], [$doc->type_piece, $doc->destination, $doc->categorie, $doc->traitement]);
        $fr = $this->dans(fn () => FactureRecue::sole());
        $this->assertSame(['STEG', 'F-2026-118', 100.0, 19.0, 'prop', $doc->id, '2026-10-12'], [$fr->fournisseur, $fr->reference, (float) $fr->ht, (float) $fr->tva, $fr->statut, $fr->document_id, $fr->date->format('Y-m-d')]);
        $this->assertNotSame($doc->chemin, $fr->fichier);
        Storage::assertExists($fr->fichier);

        // La pièce est envoyée à l'API avec un schéma imposé (sorties structurées) et le repli en cas de refus
        Http::assertSent(fn (RequeteHttp $r) => $r->url() === 'https://api.anthropic.com/v1/messages' && $r->hasHeader('x-api-key', 'cle-de-test')
            && $r['model'] === 'claude-opus-5-5' && $r['output_config']['format']['type'] === 'json_schema' && $r['fallbacks'] === 'default'
            && $r['messages'][0]['content'][0]['type'] === 'document' && ! isset($r['thinking']));

        // Validation au journal des achats → document intégré
        $this->actingAs($this->chef)->post(route('compta.factures.valider', $fr))->assertSessionHas('ok');
        $this->assertSame('importe', $doc->fresh()->traitement);
        $this->assertSame(1, $this->dans(fn () => Ecriture::where('journal', 'ACH')->count()));
    }

    public function test_envoi_de_plusieurs_types_de_documents_a_la_fois(): void
    {
        $this->ia($this->facture());
        $u = ClientUser::create(['cabinet_id' => $this->chef->cabinet_id, 'client_id' => $this->client->id, 'name' => 'Gérant', 'email' => 'g@alpha.tn', 'password' => 'MotDePasse1', 'actif' => true]);
        $this->actingAs($u, 'client')->post(route('espace.envoyer'), ['message' => 'Pièces d’octobre', 'lots' => [
            ['type_piece' => 'facture_achat', 'fichiers' => [UploadedFile::fake()->create('steg.pdf', 20, 'application/pdf'), UploadedFile::fake()->create('sonede.pdf', 20, 'application/pdf')]],
            ['type_piece' => 'courrier', 'fichiers' => [UploadedFile::fake()->create('lettre.pdf', 20, 'application/pdf')]],
            ['type_piece' => 'pointage', 'periode' => '2026-10', 'fichiers' => [UploadedFile::fake()->create('pointage.pdf', 20, 'application/pdf')]],
        ]])->assertSessionHasNoErrors()->assertSessionHas('ok', '4 documents envoyés au cabinet.');

        $docs = $this->dans(fn () => Document::orderBy('id')->get());
        $this->assertSame(['facture_achat', 'facture_achat', 'courrier', 'pointage'], $docs->pluck('type_piece')->all());
        $this->assertSame(['compta', 'compta', 'ged', 'paie'], $docs->pluck('destination')->all());
        $this->assertSame([null, null, null, '2026-10'], $docs->pluck('periode')->all());
        $this->assertSame(2, $this->dans(fn () => FactureRecue::count()));
        Mail::assertSent(\App\Mail\EspaceClientMail::class, fn ($m) => $m->modele === 'envoi' && $m->donnees['docs']->count() === 4);

        // Un groupe sans type ou sans fichier est refusé, et rien n'est enregistré
        $this->actingAs($u, 'client')->post(route('espace.envoyer'), ['lots' => [
            ['type_piece' => 'facture_vente', 'fichiers' => [UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')]], ['type_piece' => '', 'fichiers' => []],
        ]])->assertSessionHasErrors(['lots.1.type_piece', 'lots.1.fichiers']);
        $this->assertSame(4, $this->dans(fn () => Document::count()));
    }

    public function test_ajout_en_ged_de_plusieurs_categories_a_la_fois(): void
    {
        Http::fake();
        $this->actingAs($this->chef)->post(route('ged.store'), ['client_id' => $this->client->id, 'date' => '2026-11-01', 'lots' => [
            ['categorie' => 'Statuts', 'description' => 'Statuts mis à jour', 'fichiers' => [UploadedFile::fake()->create('statuts.pdf', 20, 'application/pdf')]],
            ['categorie' => 'Patente', 'fichiers' => [UploadedFile::fake()->create('patente-recto.jpg', 20, 'image/jpeg'), UploadedFile::fake()->create('patente-verso.jpg', 20, 'image/jpeg')]],
        ]])->assertSessionHasNoErrors()->assertSessionHas('ok', '3 document(s) classé(s).');
        $docs = $this->dans(fn () => Document::orderBy('id')->get());
        $this->assertSame(['Statuts', 'Patente', 'Patente'], $docs->pluck('categorie')->all());
        $this->assertSame(['Statuts mis à jour', null, null], $docs->pluck('description')->all());
        $this->assertSame([null, null, null], $docs->pluck('type_piece')->all());              // ajout du cabinet : pas de traitement
        Http::assertNothingSent();
    }

    public function test_sans_cle_le_document_attend_dans_le_module(): void
    {
        config(['services.anthropic.key' => null]);
        Http::fake();
        $doc = $this->recu('facture_vente');
        $this->assertSame(['compta', 'a_saisir'], [$doc->destination, $doc->traitement]);
        $this->assertStringContainsString('Factures de vente', $doc->traitement_message);
        Http::assertNothingSent();
        $this->actingAs($this->chef)->get(route('compta.recues', ['client' => $this->client->id]))->assertOk()
            ->assertSee('piece.pdf')->assertSee('À saisir')->assertSee('ANTHROPIC_API_KEY');
        // Accès depuis les modules : onglet avec le nombre de documents à traiter
        session(['dossier' => $this->client->id]);
        $this->actingAs($this->chef)->get(route('compta.index'))->assertOk()->assertSee('Pièces reçues (1)');
        $this->actingAs($this->chef)->get(route('immobilisations.index'))->assertOk()->assertSee('Documents reçus');
        $this->actingAs($this->chef)->get(route('paie.index'))->assertOk()->assertSee('Documents reçus du client');
        $this->actingAs($this->chef)->post(route('pieces-recues.saisi', $doc))->assertSessionHas('ok');
        $this->assertSame('importe', $doc->fresh()->traitement);
    }

    public function test_un_document_ged_n_est_pas_traite(): void
    {
        Http::fake();
        $doc = $this->recu('juridique');
        $this->assertSame(['ged', null], [$doc->destination, $doc->traitement]);
        Http::assertNothingSent();
    }

    // ---------- Comptabilité ----------

    public function test_facture_de_vente_et_tableau_des_achats(): void
    {
        $this->ia($this->facture(['tiers' => 'Société Beta', 'lignes' => [['compte' => '705', 'libelle' => 'Honoraires', 'ht' => 1000, 'taux_tva' => 19, 'tva' => 190]]]));
        $this->recu('facture_vente');
        $fv = $this->dans(fn () => FactureVente::sole());
        $this->assertSame(['Société Beta', 1000.0, 'prop'], [$fv->tiers, (float) $fv->ht, $fv->statut]);

        $this->ia(['factures' => [$this->facture(['reference' => 'A1']), $this->facture(['reference' => 'A2', 'tiers' => 'SONEDE']), $this->facture(['reference' => 'X', 'lignes' => []])], 'anomalies' => ['ligne 3 illisible']]);
        $doc = $this->recu('tableau_achats', 'achats.xlsx', null, $this->classeur([['Fournisseur', 'Réf', 'HT'], ['STEG', 'A1', 100]]));
        $this->assertSame(['A1', 'A2'], $this->dans(fn () => FactureRecue::orderBy('id')->pluck('reference')->all()));
        $this->assertSame('propose', $doc->traitement);
        $this->assertSame(['ligne 3 illisible'], $doc->resultat['anomalies']);
        // Le tableur est envoyé à l'IA sous forme de texte
        Http::assertSent(fn (RequeteHttp $r) => $r['messages'][0]['content'][0]['type'] === 'text' && str_contains($r['messages'][0]['content'][0]['text'], 'STEG;A1;100'));
    }

    public function test_etat_de_caisse_propose_valide_et_corrige(): void
    {
        $this->ia(['ecritures' => [
            ['date' => '2026-10-03', 'libelle' => 'Achat fournitures', 'lignes' => [['compte' => '6064', 'libelle' => 'Fournitures', 'debit' => 45, 'credit' => 0], ['compte' => '541', 'libelle' => 'Caisse', 'debit' => 0, 'credit' => 45]]],
            ['date' => '2026-10-05', 'libelle' => 'Recette', 'lignes' => [['compte' => '541', 'libelle' => '', 'debit' => 200, 'credit' => 0], ['compte' => '411', 'libelle' => '', 'debit' => 0, 'credit' => 150]]],
        ], 'anomalies' => []]);
        $doc = $this->recu('etat_caisse', 'caisse.jpg', '2026-10');
        [$ok, $faux] = $this->dans(fn () => EcritureProposee::orderBy('id')->get()->all());
        $this->assertSame(['CAI', true, false], [$ok->journal, $ok->equilibree(), $faux->equilibree()]);

        $this->actingAs($this->chef)->get(route('compta.recues', ['client' => $this->client->id]))->assertOk()->assertSee('Écritures proposées à valider')->assertSee('Achat fournitures');
        $this->actingAs($this->chef)->post(route('compta.proposees.valider', $faux))->assertSessionHas('err', fn ($m) => str_contains($m, 'déséquilibrée'));
        $this->actingAs($this->chef)->put(route('compta.proposees.update', $faux), ['date' => '2026-10-05', 'journal' => 'CAI', 'libelle' => 'Recette client',
            'lignes' => [['compte' => '541', 'debit' => 150, 'credit' => 0], ['compte' => '411', 'debit' => 0, 'credit' => 150]]])->assertSessionHas('ok');
        $this->actingAs($this->chef)->post(route('compta.proposees.valider', $ok))->assertSessionHas('ok');
        $this->actingAs($this->chef)->post(route('compta.proposees.valider', $faux->fresh()))->assertSessionHas('ok');

        $e = $this->dans(fn () => Ecriture::with('lignes')->where('journal', 'CAI')->orderBy('id')->get());
        $this->assertCount(2, $e);
        $this->assertSame('Recette client', $e[1]->libelle);
        $this->assertSame(150.0, (float) $e[1]->lignes->sum('debit'));
        $this->assertSame('importe', $doc->fresh()->traitement);
    }

    public function test_un_collaborateur_sans_droit_ne_valide_pas_une_ecriture_proposee(): void
    {
        $this->ia(['ecritures' => [['date' => '2026-10-03', 'libelle' => 'Chèque 12', 'lignes' => [['compte' => '401', 'libelle' => '', 'debit' => 10, 'credit' => 0], ['compte' => '532', 'libelle' => '', 'debit' => 0, 'credit' => 10]]]], 'anomalies' => []]);
        $this->recu('traites_cheques');
        $ep = $this->dans(fn () => EcritureProposee::sole());
        $collab = User::factory()->create(['cabinet_id' => $this->chef->cabinet_id, 'role' => 'collaborateur', 'status' => 'active']);
        $collab->forceFill(['droit_validation' => false])->save();
        $this->actingAs($collab)->post(route('compta.proposees.valider', $ep))->assertSessionHas('err');
        $this->assertSame('prop', $ep->fresh()->statut);

        $chefB = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        $this->actingAs($chefB)->post(route('compta.proposees.valider', $ep))->assertNotFound();
    }

    public function test_releve_bancaire_csv_importe_sans_ia(): void
    {
        config(['services.anthropic.key' => null]);
        $csv = "Date;Libellé;Débit;Crédit\n02/10/2026;VIR RECU BETA;;1 191,000\n05/10/2026;Frais tenue de compte;12,500;\n";
        $doc = $this->recu('releve_bancaire', 'releve.csv', '2026-10', $csv);
        $this->assertSame('propose', $doc->traitement);
        [$releve, $nbLignes] = $this->dans(fn () => [Releve::sole(), Releve::sole()->lignes()->count()]);
        $this->assertSame([$releve->id, 2], [$doc->resultat['releve'], $nbLignes]);
    }

    // ---------- Immobilisations ----------

    public function test_facture_d_immobilisation_entre_au_registre_a_sa_validation(): void
    {
        $this->ia($this->facture(['tiers' => 'Tunisie Info', 'description' => 'Serveur Dell', 'lignes' => [['compte' => '2282', 'libelle' => 'Serveur', 'ht' => 3000, 'taux_tva' => 19, 'tva' => 570]]]));
        $doc = $this->recu('facture_immobilisation');
        $this->assertSame('immobilisations', $doc->destination);
        $fr = $this->dans(fn () => FactureRecue::sole());
        // Seuls les comptes d'immobilisations (classe 2) sont proposés à l'IA
        Http::assertSent(fn (RequeteHttp $r) => str_contains($t = collect($r['messages'][0]['content'])->last()['text'], "\n2282 ") && ! str_contains($t, "\n6061 "));

        $this->actingAs($this->chef)->post(route('compta.factures.valider', $fr))->assertSessionHas('ok', fn ($m) => str_contains($m, 'registre des immobilisations'));
        $i = $this->dans(fn () => Immobilisation::sole());
        $this->assertSame([$fr->id, 3000.0, 'Serveur Dell'], [$i->facture_recue_id, (float) $i->valeur_origine, $i->designation]);
        $this->assertSame('importe', $doc->fresh()->traitement);
        $this->actingAs($this->chef)->get(route('immobilisations.recus', ['client' => $this->client->id, 'vue' => 'tous']))->assertOk()->assertSee('Registre des immobilisations');
    }

    public function test_contrat_de_leasing_inscrit_au_registre(): void
    {
        $this->ia(['bailleur' => 'Tunisie Leasing', 'reference_contrat' => 'CB-2026-77', 'designation' => 'Camion Isuzu', 'nature' => 'transport', 'date_debut' => '2026-09-01',
            'valeur_bien_ht' => 85000, 'tva' => 16150, 'duree_mois' => 48, 'loyer_mensuel_ht' => 2100, 'valeur_residuelle' => 850, 'anomalies' => []]);
        $doc = $this->recu('contrat_leasing');
        $i = $this->dans(fn () => Immobilisation::sole());
        $this->assertSame(['Crédit-bail : Camion Isuzu', 'transport', 85000.0, 'Tunisie Leasing', 'CB-2026-77'], [$i->designation, $i->nature, (float) $i->valeur_origine, $i->fournisseur, $i->reference]);
        $this->assertSame('importe', $doc->traitement);
    }

    // ---------- Paie ----------

    public function test_pointage_excel_reporte_sans_ia(): void
    {
        config(['services.anthropic.key' => null]);
        $xlsx = $this->classeur([['Matricule', 'Nom', 'Jours travaillés', 'Heures sup', 'Prime'], ['E01', 'Haddad', 24, 3, 50], ['E99', 'Inconnu', 26, 0, 0]]);
        $doc = $this->recu('pointage', 'pointage.xlsx', '2026-10', $xlsx);
        $paie = $this->dans(fn () => Paie::where('mois', '2026-10')->sole());
        $this->assertEquals(24, $paie->pointage[$this->karim->id]['jours']);
        $this->assertEquals(50, $paie->primes[$this->karim->id]);
        $this->assertSame('propose', $doc->traitement);                       // un salarié non reconnu reste à saisir
        $this->assertSame(['Inconnu'], $doc->resultat['inconnus']);
    }

    public function test_etat_des_conges_lu_puis_importe(): void
    {
        $this->ia(['lignes' => [
            ['matricule' => 'E01', 'cin' => '', 'nom' => 'Karim Haddad', 'type' => 'paye', 'du' => '2026-11-02', 'au' => '2026-11-06', 'jours' => 5, 'observation' => ''],
            ['matricule' => '', 'cin' => '', 'nom' => 'Personne', 'type' => 'maladie', 'du' => '2026-11-10', 'au' => '2026-11-11', 'jours' => 0, 'observation' => ''],
        ], 'anomalies' => []]);
        $doc = $this->recu('etat_conges', 'conges.png');
        $this->assertSame(['paie', 'propose'], [$doc->destination, $doc->traitement]);
        $this->actingAs($this->chef)->get(route('paie.recus', ['client' => $this->client->id]))->assertOk()->assertSee('Importer les lignes cochées');

        $this->actingAs($this->chef)->post(route('paie.recus.importer', $doc), ['importer' => [0, 1], 'salarie' => [0 => $this->karim->id, 1 => '']])
            ->assertSessionHas('err', fn ($m) => str_contains($m, '1 ligne(s) importée(s)') && str_contains($m, 'salarié à choisir'));
        $c = $this->dans(fn () => Conge::sole());
        $this->assertSame([$this->karim->id, 'paye', 5.0], [$c->salarie_id, $c->type, (float) $c->jours]);
        $this->assertSame('propose', $doc->fresh()->traitement);
        $this->actingAs($this->chef)->post(route('paie.recus.importer', $doc), ['importer' => [0, 1], 'salarie' => [1 => $this->karim->id]])->assertSessionHas('ok');
        $this->assertSame(2, $this->dans(fn () => Conge::count()));               // la ligne déjà importée ne l'est pas deux fois
        $this->assertSame('importe', $doc->fresh()->traitement);
    }

    public function test_etat_des_acomptes_et_prets_importe(): void
    {
        $this->ia(['lignes' => [
            ['matricule' => 'E01', 'cin' => '', 'nom' => '', 'nature' => 'acompte', 'date' => '2026-11-05', 'montant' => 200, 'mois_retenue' => '2026-11', 'nb_echeances' => 0, 'echeance' => 0, 'reference' => ''],
            ['matricule' => 'E01', 'cin' => '', 'nom' => '', 'nature' => 'pret_societe', 'date' => '2026-11-01', 'montant' => 1200, 'mois_retenue' => '2026-12', 'nb_echeances' => 6, 'echeance' => 0, 'reference' => 'P1'],
        ], 'anomalies' => []]);
        $doc = $this->recu('etat_acomptes_prets');
        $this->actingAs($this->chef)->post(route('paie.recus.importer', $doc), ['importer' => [0, 1], 'salarie' => [0 => $this->karim->id, 1 => $this->karim->id]])->assertSessionHas('ok');
        $this->assertSame(200.0, (float) $this->dans(fn () => Acompte::sole())->montant);
        $p = $this->dans(fn () => Pret::sole());
        $this->assertSame(['societe', 6, 200.0, '2026-12'], [$p->type, (int) $p->nb_echeances, (float) $p->echeance, $p->premier_mois]);
    }

    public function test_carte_d_identite_classee_dans_la_fiche_du_salarie(): void
    {
        $this->ia(['nom' => 'Haddad', 'prenom' => 'Karim', 'cin' => '01234567', 'nature' => 'cin', 'face' => 'verso', 'anomalies' => []]);
        $doc = $this->recu('piece_identite', 'cin.jpg');
        $sd = $this->dans(fn () => SalarieDocument::sole());
        $this->assertSame([$this->karim->id, 'cin2'], [$sd->salarie_id, $sd->type]);
        $this->assertSame('importe', $doc->traitement);

        // Salarié non reconnu : à classer à la main
        $this->ia(['nom' => 'Inconnu', 'prenom' => 'X', 'cin' => '99999999', 'nature' => 'contrat', 'face' => 'sans_objet', 'anomalies' => []]);
        $doc2 = $this->recu('contrat_travail', 'contrat.pdf');
        $this->assertSame('a_saisir', $doc2->traitement);
        $this->actingAs($this->chef)->post(route('paie.recus.classer', $doc2), ['salarie_id' => $this->karim->id, 'type' => 'contrat'])->assertSessionHas('ok');
        $this->assertSame(2, $this->dans(fn () => SalarieDocument::where('salarie_id', $this->karim->id)->count()));
        $this->assertSame('importe', $doc2->fresh()->traitement);
    }

    // ---------- Reclassement, erreurs, cloisonnement ----------

    public function test_reclasser_un_document_le_traite_a_nouveau(): void
    {
        $doc = $this->recu('courrier');
        $this->ia($this->facture());
        $this->actingAs($this->chef)->post(route('pieces-recues.reclasser', $doc), ['type_piece' => 'facture_achat'])->assertSessionHas('ok');
        $this->assertSame(['facture_achat', 'compta', 'propose'], [$doc->fresh()->type_piece, $doc->fresh()->destination, $doc->fresh()->traitement]);
        $this->assertSame(1, $this->dans(fn () => FactureRecue::count()));
    }

    public function test_erreur_de_l_api_signalee(): void
    {
        $this->ia(['erreur' => 401]);
        $doc = $this->recu('facture_achat');
        $this->assertSame('erreur', $doc->traitement);
        $this->assertStringContainsString('401', $doc->traitement_message);
        $this->assertSame(0, $this->dans(fn () => FactureRecue::count()));
    }

    public function test_un_autre_cabinet_ne_voit_pas_les_documents_recus(): void
    {
        $this->ia(['lignes' => [['matricule' => 'E01', 'cin' => '', 'nom' => '', 'type' => 'paye', 'du' => '2026-11-02', 'au' => '2026-11-03', 'jours' => 2, 'observation' => '']], 'anomalies' => []]);
        $doc = $this->recu('etat_conges');
        $chefB = User::factory()->create(['role' => 'chef', 'status' => 'active']);
        $this->actingAs($chefB)->post(route('paie.recus.importer', $doc), ['importer' => [0], 'salarie' => [0 => $this->karim->id]])->assertNotFound();
        $this->actingAs($chefB)->post(route('pieces-recues.reclasser', $doc), ['type_piece' => 'autre'])->assertNotFound();
        $this->assertSame(0, $this->dans(fn () => Conge::count()));
    }

    private function classeur(array $lignes): string
    {
        $ss = new Spreadsheet();
        $ss->getActiveSheet()->fromArray($lignes);
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($ss))->save($tmp);
        $contenu = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $contenu;
    }
}
