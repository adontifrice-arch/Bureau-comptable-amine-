<?php

namespace Database\Seeders;

use App\Http\Controllers\EcheanceController;
use App\Models\Client;
use App\Models\Echeance;
use App\Models\Facture;
use App\Models\FactureRecue;
use App\Models\NoteFrais;
use App\Models\NoteInterne;
use App\Models\RendezVous;
use App\Models\Salarie;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Compta;
use Illuminate\Database\Seeder;

/** Jeu de démonstration repris du prototype (4 clients fictifs). */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Client::count()) return;
        $d = fn (int $n) => now()->addDays($n)->format('Y-m-d');
        Setting::put('cabinet', ['nom' => 'Cabinet Amine Boudaya', 'adresse' => 'Rue du Lac Léman, Les Berges du Lac, 1053 Tunis', 'mf' => '1234567/A/P/000',
            'telephone' => '+216 71 000 000', 'email' => 'contact@cabinet-boudaya.tn', 'banque' => 'BIAT', 'titulaire' => 'Cabinet Amine Boudaya', 'rib' => '08 000 0000000000000 00']);
        User::firstOrCreate(['email' => 'collaborateur@cabinet.tn'], ['name' => 'Sana Collaboratrice', 'password' => 'Cabinet2026!', 'role' => 'collaborateur', 'status' => 'active']);

        $clients = [
            ['nom' => 'Boulangerie Dupain', 'forme' => 'SARL', 'type_personne' => 'morale', 'mission' => 'Tenue + bilan', 'honoraires_mensuels' => 450, 'matricule_fiscal' => '1234567/A/M/000', 'matricule_cnss' => '12345678-90', 'capital' => 20000, 'nb_parts' => 2000, 'valeur_nominale' => 10, 'adresse' => '12 rue de la République, Tunis', 'compte_comptable' => '411001', 'gerant_nom' => 'Ben Salah Karim', 'gerant_cin' => '07654321', 'gerant_nationalite' => 'Tunisienne', 'gerant_adresse' => '12 rue de la République, Tunis', 'gerant_naissance_date' => '1978-03-14', 'gerant_naissance_lieu' => 'Tunis', 'gerant_associe' => true, 'gerant_parts' => 1200],
            ['nom' => 'Studio Mirage', 'forme' => 'SARL', 'type_personne' => 'morale', 'mission' => 'Bilan + paie', 'honoraires_mensuels' => 780, 'matricule_fiscal' => '2345678/B/M/000', 'matricule_cnss' => '23456789-01', 'capital' => 50000, 'adresse' => '5 avenue Habib Bourguiba, Sfax', 'compte_comptable' => '411002', 'gerant_nom' => 'Trabelsi Sonia', 'gerant_cin' => '09876543', 'gerant_nationalite' => 'Tunisienne', 'gerant_adresse' => '21 rue de Palestine, Sfax', 'gerant_naissance_date' => '1985-11-02', 'gerant_naissance_lieu' => 'Sfax'],
            ['nom' => 'Dr Hélène Roux', 'forme' => 'Libéral (BNC)', 'type_personne' => 'physique', 'mission' => 'Déclarations fiscales', 'honoraires_mensuels' => 300, 'matricule_fiscal' => '3456789/C/N/000', 'matricule_independant' => 'IND-45678', 'adresse' => '8 rue Ibn Khaldoun, Sousse', 'compte_comptable' => '411003', 'gerant_nom' => 'Roux Hélène', 'gerant_passeport' => '19AB12345', 'gerant_nationalite' => 'Française'],
            ['nom' => 'Garage Bertin', 'forme' => 'SUARL', 'type_personne' => 'morale', 'mission' => 'Tenue + TVA', 'honoraires_mensuels' => 520, 'matricule_fiscal' => '4567890/D/M/000', 'matricule_cnss' => '34567890-12', 'capital' => 10000, 'adresse' => 'Zone industrielle, Ariana', 'compte_comptable' => '411004', 'gerant_nom' => 'Bertin Marc', 'gerant_cin' => '05432109', 'gerant_nationalite' => 'Tunisienne', 'gerant_adresse' => '3 rue des Jasmins, Ariana'],
        ];
        $C = collect($clients)->map(fn ($c) => Client::create($c));
        $C[0]->associes()->create(['nom' => 'Ben Salah Karim', 'cin' => '07654321', 'parts' => 1200, 'parts_de' => 1, 'parts_a' => 1200]);
        $C[0]->associes()->create(['nom' => 'Ben Salah Leila', 'cin' => '08123456', 'parts' => 800, 'parts_de' => 1201, 'parts_a' => 2000]);
        foreach ($C as $c) EcheanceController::acomptesProvisionnels($c);

        foreach ([[3, 'Échéance fiscale', 'TVA mensuelle', -2], [0, 'Échéance fiscale', 'Liasse fiscale', 3], [1, 'Échéance sociale (CNSS)', 'Déclaration CNSS du trimestre', 5], [2, 'Échéance fiscale', 'Déclaration annuelle IRPP', 21], [1, 'Autre échéance', 'Approbation des comptes', -10]] as [$i, $cat, $t, $j]) {
            Echeance::create(['client_id' => $C[$i]->id, 'categorie' => $cat, 'intitule' => $t, 'date_echeance' => $d($j), 'accomplie_le' => $j === -10 ? $d(-12) : null]);
        }

        $tenue = Service::where('nom', 'Tenue de comptabilité')->first();
        $tenue?->update(['prix' => 450]);
        foreach ([[0, 450, -40, 2], [1, 780, -25, 1], [3, 520, -4, 1], [2, 300, 0, 0], [0, 450, -70, 2], [1, 780, -95, 2], [3, 520, -130, 2]] as $k => [$i, $m, $j, $st]) {
            $f = Facture::create(['type' => 'facture', 'numero' => Facture::prochainNumero(), 'client_id' => $C[$i]->id, 'date' => $d($j), 'echeance' => $d($j + 30), 'tva_taux' => 19, 'timbre' => 1, 'statut' => $st]);
            $f->lignes()->create(['designation' => 'Honoraires de tenue comptable – '.now()->addDays($j)->locale('fr')->translatedFormat('F Y'), 'quantite' => 1, 'prix_unitaire' => $m]);
        }
        $dv = Facture::create(['type' => 'devis', 'numero' => Facture::prochainNumero('devis'), 'client_id' => $C[1]->id, 'date' => $d(-3), 'echeance' => $d(27), 'tva_taux' => 19, 'timbre' => 0, 'statut' => 1]);
        $dv->lignes()->create(['designation' => 'Mission d’audit contractuel des comptes', 'quantite' => 1, 'prix_unitaire' => 3500]);

        // Comptabilité de démonstration (Boulangerie Dupain)
        $chef = User::where('role', 'chef')->first();
        $c0 = $C[0]->id; $y = now()->year;
        Compta::passer($c0, "$y-01-02", 'OD', 'Apport en capital', [['compte' => '532', 'debit' => 20000], ['compte' => '101', 'credit' => 20000]], null, $chef->id);
        foreach (range(1, max(1, now()->month - 1)) as $m) {
            $dt = sprintf('%d-%02d-25', $y, $m);
            Compta::passer($c0, $dt, 'VTE', 'Ventes du mois', [['compte' => '411', 'debit' => 17850], ['compte' => '707', 'credit' => 15000], ['compte' => '4366', 'credit' => 2850]], null, $chef->id);
            Compta::passer($c0, $dt, 'BQ', 'Encaissements clients', [['compte' => '532', 'debit' => 17000], ['compte' => '411', 'credit' => 17000]], null, $chef->id);
        }
        foreach ([['Moulins de Tunis', '1112223/A/M/000', 'Farine T55', 6200, '601', 0], ['STEG', '0000111/X/A/000', 'Électricité', 820, '606', 0], ['Cabinet Juridique Ksouri', '5556667/B/P/000', 'Honoraires avocat', 1200, '622', 3]] as $k => [$fo, $mf, $ds, $ht, $cpt, $rs]) {
            $fr = FactureRecue::create(['client_id' => $c0, 'date' => now()->subDays(10 + $k * 7), 'fournisseur' => $fo, 'matricule_fiscal' => $mf, 'reference' => 'F-'.(100 + $k), 'description' => $ds,
                'ht' => $ht, 'tva_taux' => 19, 'tva' => r3($ht * .19), 'timbre' => 1, 'compte' => $cpt, 'tva_deductible' => true, 'retenue_taux' => $rs, 'statut' => $k < 2 ? 'val' : 'prop', 'valide_par' => $k < 2 ? $chef->id : null]);
            if ($fr->statut === 'val') Compta::passer($c0, $fr->date->format('Y-m-d'), 'ACH', 'Facture '.$fr->reference.' – '.$fr->fournisseur, $fr->lignesEcriture(), 'fr:'.$fr->id, $chef->id);
        }
        foreach ([['Ben Salah Karim', 'Gérant', 2500, true, 2], ['Haddad Amel', 'Vendeuse', 950, false, 0], ['Jlassi Mehdi', 'Boulanger', 1300, true, 1]] as [$n, $p, $b, $cf, $e]) {
            Salarie::create(['client_id' => $C[1]->id, 'nom' => $n, 'poste' => $p, 'salaire_base' => $b, 'chef_famille' => $cf, 'enfants' => $e, 'cin' => '0'.rand(1000000, 9999999), 'cnss' => rand(10000000, 99999999).'-'.rand(10, 99)]);
        }

        NoteFrais::create(['date' => $d(-6), 'description' => 'Déplacement chez le client (Sfax)', 'categorie' => 'Déplacement', 'client_id' => $C[1]->id, 'montant' => 85.5]);
        NoteFrais::create(['date' => $d(-2), 'description' => 'Timbres fiscaux RNE', 'categorie' => 'Frais administratifs', 'client_id' => $C[0]->id, 'montant' => 30]);
        RendezVous::create(['client_id' => $C[0]->id, 'objet' => 'Revue de la clôture annuelle', 'date' => $d(2), 'heure' => '10:00', 'lieu' => 'Au cabinet']);
        RendezVous::create(['client_id' => $C[1]->id, 'objet' => 'Point paie et CNSS', 'date' => $d(6), 'heure' => '15:30', 'lieu' => 'Visio']);
        NoteInterne::create(['type' => 'note', 'titre' => 'Relancer la banque pour les relevés de septembre', 'rappel_le' => $d(1)]);
    }
}
