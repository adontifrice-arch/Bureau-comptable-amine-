<?php

use App\Http\Controllers as C;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [C\AuthController::class, 'showLogin'])->name('login');
    Route::post('/connexion', [C\AuthController::class, 'login']);
    Route::get('/inscription', [C\AuthController::class, 'showRegister'])->name('register');
    Route::post('/inscription', [C\AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::get('/invitation/{code}', [C\AuthController::class, 'showRegister'])->name('register.invitation');
    Route::post('/invitation/{code}', [C\AuthController::class, 'register'])->middleware('throttle:10,1');
});
Route::post('/deconnexion', [C\AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'actif'])->group(function () {
    Route::get('/', C\DashboardController::class)->name('dashboard');

    // ---------- Clients ----------
    Route::resource('clients', C\ClientController::class);
    Route::post('clients/{client}/exigences', [C\ClientController::class, 'exigenceStore'])->name('clients.exigences.store');
    Route::put('exigences/{exigence}', [C\ClientController::class, 'exigenceUpdate'])->name('clients.exigences.update');
    Route::delete('exigences/{exigence}', [C\ClientController::class, 'exigenceDestroy'])->name('clients.exigences.destroy');
    Route::post('exigences/{exigence}/echeance', [C\ClientController::class, 'exigenceToEcheance'])->name('clients.exigences.echeance');
    Route::get('clients/{client}/actes/nouveau', [C\ActeController::class, 'create'])->name('actes.create');
    Route::post('clients/{client}/actes', [C\ActeController::class, 'store'])->name('actes.store');
    Route::get('actes/modeles', [C\ModeleActeController::class, 'index'])->name('modeles-actes.index');
    Route::post('actes/modeles', [C\ModeleActeController::class, 'store'])->name('modeles-actes.store');
    Route::get('actes/modeles/{modele}/fichier', [C\ModeleActeController::class, 'download'])->name('modeles-actes.download');
    Route::delete('actes/modeles/{modele}', [C\ModeleActeController::class, 'destroy'])->name('modeles-actes.destroy');
    Route::post('actes/ordre-du-jour', [C\ActeController::class, 'ordreAjouter'])->name('actes.odd.store');
    Route::delete('actes/ordre-du-jour', [C\ActeController::class, 'ordreRetirer'])->name('actes.odd.destroy');
    Route::get('actes/{acte}/pdf', [C\ActeController::class, 'pdf'])->name('actes.pdf');
    Route::get('actes/{acte}/word', [C\ActeController::class, 'docx'])->name('actes.docx');
    Route::get('actes/{acte}', [C\ActeController::class, 'edit'])->name('actes.edit');
    Route::put('actes/{acte}', [C\ActeController::class, 'update'])->name('actes.update');
    Route::delete('actes/{acte}', [C\ActeController::class, 'destroy'])->name('actes.destroy');

    // ---------- Échéances ----------
    Route::get('echeances', [C\EcheanceController::class, 'index'])->name('echeances.index');
    Route::get('echeances/calendrier/{client}', [C\EcheanceController::class, 'calendrier'])->name('echeances.calendrier');
    Route::post('echeances', [C\EcheanceController::class, 'store'])->name('echeances.store');
    Route::post('echeances/serie', [C\EcheanceController::class, 'serie'])->name('echeances.serie');
    Route::post('echeances/serie/supprimer', [C\EcheanceController::class, 'serieDestroy'])->name('echeances.serie.destroy');
    Route::patch('echeances/{echeance}/basculer', [C\EcheanceController::class, 'toggle'])->name('echeances.toggle');
    Route::delete('echeances/{echeance}', [C\EcheanceController::class, 'destroy'])->name('echeances.destroy');

    // ---------- Facturation ----------
    Route::get('facturation/chiffre-affaires', [C\FactureController::class, 'chiffreAffaires'])->name('ca.index');
    Route::get('facturation/chiffre-affaires/export', [C\FactureController::class, 'chiffreAffairesCsv'])->name('ca.csv');
    Route::post('facturation/services', [C\FactureController::class, 'serviceStore'])->name('factures.services.store');
    Route::delete('facturation/services/{service}', [C\FactureController::class, 'serviceDestroy'])->name('factures.services.destroy');
    Route::get('facturation', [C\FactureController::class, 'index'])->name('factures.index');
    Route::get('facturation/nouvelle', [C\FactureController::class, 'create'])->name('factures.create');
    Route::post('facturation', [C\FactureController::class, 'store'])->name('factures.store');
    Route::get('facturation/{facture}', [C\FactureController::class, 'show'])->name('factures.show');
    Route::get('facturation/{facture}/modifier', [C\FactureController::class, 'edit'])->name('factures.edit');
    Route::put('facturation/{facture}', [C\FactureController::class, 'update'])->name('factures.update');
    Route::delete('facturation/{facture}', [C\FactureController::class, 'destroy'])->name('factures.destroy');
    Route::patch('facturation/{facture}/statut', [C\FactureController::class, 'statut'])->name('factures.statut');
    Route::post('facturation/{facture}/convertir', [C\FactureController::class, 'convertir'])->name('factures.convertir');
    Route::get('facturation/{facture}/teif', [C\FactureController::class, 'teif'])->name('factures.teif');

    // ---------- Notes de frais & dépenses ----------
    Route::get('frais', [C\FraisController::class, 'index'])->name('frais.index');
    Route::post('frais', [C\FraisController::class, 'store'])->name('frais.store');
    Route::put('frais/{note}', [C\FraisController::class, 'update'])->name('frais.update');
    Route::patch('frais/{note}/rembourse', [C\FraisController::class, 'toggle'])->name('frais.toggle');
    Route::delete('frais/{note}', [C\FraisController::class, 'destroy'])->name('frais.destroy');
    Route::get('frais/{note}/justificatif', [C\FraisController::class, 'justificatif'])->name('frais.justificatif');
    Route::post('frais/documents', [C\FraisController::class, 'documentStore'])->name('frais.documents.store');
    Route::get('frais/documents/{doc}', [C\FraisController::class, 'documentShow'])->name('frais.documents.show');
    Route::delete('frais/documents/{doc}', [C\FraisController::class, 'documentDestroy'])->name('frais.documents.destroy');
    Route::get('depenses', [C\DepenseController::class, 'index'])->name('depenses.index');
    Route::post('depenses', [C\DepenseController::class, 'store'])->name('depenses.store');
    Route::put('depenses/{depense}', [C\DepenseController::class, 'update'])->name('depenses.update');
    Route::delete('depenses/{depense}', [C\DepenseController::class, 'destroy'])->name('depenses.destroy');
    Route::get('depenses/tej', [C\DepenseController::class, 'tej'])->name('depenses.tej');

    // ---------- Interne ----------
    Route::get('interne', [C\InterneController::class, 'index'])->name('interne.index');
    Route::post('interne/rdv', [C\InterneController::class, 'rdvStore'])->name('interne.rdv.store');
    Route::put('interne/rdv/{rdv}', [C\InterneController::class, 'rdvUpdate'])->name('interne.rdv.update');
    Route::delete('interne/rdv/{rdv}', [C\InterneController::class, 'rdvDestroy'])->name('interne.rdv.destroy');
    Route::post('interne/notes', [C\InterneController::class, 'noteStore'])->name('interne.notes.store');
    Route::put('interne/notes/{note}', [C\InterneController::class, 'noteUpdate'])->name('interne.notes.update');
    Route::patch('interne/notes/{note}/fait', [C\InterneController::class, 'noteToggle'])->name('interne.notes.toggle');
    Route::delete('interne/notes/{note}', [C\InterneController::class, 'noteDestroy'])->name('interne.notes.destroy');

    // ---------- Comptabilité ----------
    Route::get('comptabilite', [C\ComptaController::class, 'index'])->name('compta.index');
    Route::post('comptabilite/factures', [C\ComptaController::class, 'factureStore'])->name('compta.factures.store');
    Route::put('comptabilite/factures/{fr}', [C\ComptaController::class, 'factureUpdate'])->name('compta.factures.update');
    Route::delete('comptabilite/factures/{fr}', [C\ComptaController::class, 'factureDestroy'])->name('compta.factures.destroy');
    Route::get('comptabilite/factures/{fr}/fichier', [C\ComptaController::class, 'factureFichier'])->name('compta.factures.fichier');
    // Écriture : fiche (consultation, y compris supprimée), modification, suppression vers « Pièces supprimées », restauration
    // (droits vérifiés dans le contrôleur : chef comptable ou collaborateur autorisé)
    Route::get('comptabilite/ecritures/{ecriture}', [C\ComptaController::class, 'ecriture'])->withTrashed()->name('compta.ecritures.show');
    Route::put('comptabilite/ecritures/{ecriture}', [C\ComptaController::class, 'ecritureUpdate'])->name('compta.ecritures.update');
    Route::post('comptabilite/ecritures/{ecriture}/extourner', [C\ComptaController::class, 'annuler'])->name('compta.ecritures.annuler');
    Route::post('comptabilite/ecritures/{ecriture}/restaurer', [C\ComptaController::class, 'restaurer'])->withTrashed()->name('compta.ecritures.restaurer');
    Route::post('comptabilite/factures/{fr}/valider', [C\ComptaController::class, 'valider'])->middleware('chef')->name('compta.factures.valider');
    Route::post('comptabilite/factures/{fr}/rejeter', [C\ComptaController::class, 'rejeter'])->middleware('chef')->name('compta.factures.rejeter');
    Route::post('comptabilite/paies/{paie}/valider', [C\ComptaController::class, 'validerPaie'])->middleware('chef')->name('compta.paies.valider');
    Route::post('comptabilite/od', [C\ComptaController::class, 'odStore'])->middleware('chef')->name('compta.od.store');
    Route::post('comptabilite/comptes', [C\ComptaController::class, 'compteStore'])->name('compta.comptes.store');
    Route::get('comptabilite/balance/imprimer', [C\ComptaController::class, 'balancePrint'])->name('compta.balance.print');
    Route::get('comptabilite/balance/export', [C\ComptaController::class, 'balanceCsv'])->name('compta.balance.csv');

    // ---------- Saisie rapide : paramétrage, modèles, factures de vente ----------
    Route::post('comptabilite/parametres', [C\SaisieController::class, 'parametres'])->middleware('chef')->name('compta.parametres');
    Route::post('comptabilite/modeles', [C\SaisieController::class, 'modeleStore'])->name('compta.modeles.store');
    Route::put('comptabilite/modeles/{modele}', [C\SaisieController::class, 'modeleUpdate'])->name('compta.modeles.update');
    Route::delete('comptabilite/modeles/{modele}', [C\SaisieController::class, 'modeleDestroy'])->name('compta.modeles.destroy');
    Route::post('comptabilite/ventes', [C\SaisieController::class, 'venteStore'])->name('compta.ventes.store');
    Route::put('comptabilite/ventes/{fv}', [C\SaisieController::class, 'venteUpdate'])->name('compta.ventes.update');
    Route::delete('comptabilite/ventes/{fv}', [C\SaisieController::class, 'venteDestroy'])->name('compta.ventes.destroy');
    Route::get('comptabilite/ventes/{fv}/fichier', [C\SaisieController::class, 'venteFichier'])->name('compta.ventes.fichier');
    Route::post('comptabilite/ventes/{fv}/valider', [C\SaisieController::class, 'venteValider'])->middleware('chef')->name('compta.ventes.valider');
    Route::post('comptabilite/ventes/{fv}/rejeter', [C\SaisieController::class, 'venteRejeter'])->middleware('chef')->name('compta.ventes.rejeter');

    // ---------- Relevés bancaires ----------
    Route::post('comptabilite/releves', [C\ReleveController::class, 'importer'])->name('compta.releves.importer');
    Route::post('comptabilite/releves/lignes', [C\ReleveController::class, 'lignes'])->name('compta.releves.lignes');
    Route::get('comptabilite/releves/{releve}/fichier', [C\ReleveController::class, 'fichier'])->name('compta.releves.fichier');
    Route::delete('comptabilite/releves/{releve}', [C\ReleveController::class, 'destroy'])->name('compta.releves.destroy');

    // ---------- Immobilisations et amortissements ----------
    Route::get('immobilisations', [C\ImmobilisationController::class, 'index'])->name('immobilisations.index');
    Route::post('immobilisations', [C\ImmobilisationController::class, 'store'])->name('immobilisations.store');
    Route::post('immobilisations/import', [C\ImmobilisationController::class, 'importer'])->name('immobilisations.importer');
    Route::post('immobilisations/dotations', [C\ImmobilisationController::class, 'comptabiliser'])->middleware('chef')->name('immobilisations.comptabiliser');
    Route::post('immobilisations/dotations/annuler', [C\ImmobilisationController::class, 'annuler'])->middleware('chef')->name('immobilisations.annuler');
    Route::get('immobilisations/etats/{etat}/{format}', [C\ImmobilisationController::class, 'etat'])->name('immobilisations.etat');
    Route::put('immobilisations/{immobilisation}', [C\ImmobilisationController::class, 'update'])->name('immobilisations.update');
    Route::delete('immobilisations/{immobilisation}', [C\ImmobilisationController::class, 'destroy'])->name('immobilisations.destroy');
    Route::post('immobilisations/{immobilisation}/acquisition', [C\ImmobilisationController::class, 'acquisition'])->middleware('chef')->name('immobilisations.acquisition');

    // ---------- Paie ----------
    Route::get('paie', [C\PaieController::class, 'index'])->name('paie.index');
    Route::post('paie/salaries', [C\PaieController::class, 'salarieStore'])->name('paie.salaries.store');
    Route::put('paie/salaries/{salarie}', [C\PaieController::class, 'salarieUpdate'])->name('paie.salaries.update');
    Route::delete('paie/salaries/{salarie}', [C\PaieController::class, 'salarieDestroy'])->name('paie.salaries.destroy');
    Route::post('paie/calcul', [C\PaieController::class, 'calcul'])->name('paie.calcul');
    Route::post('paie/cloturer', [C\PaieController::class, 'cloturer'])->name('paie.cloturer');
    Route::get('paie/bulletins', [C\PaieController::class, 'bulletins'])->name('paie.bulletins');
    Route::post('paie/parametres', [C\PaieController::class, 'parametres'])->middleware('chef')->name('paie.parametres');

    // ---------- Liasse ----------
    Route::get('liasse', [C\LiasseController::class, 'index'])->name('liasse.index');
    Route::post('liasse/passage', [C\LiasseController::class, 'passage'])->name('liasse.passage');
    Route::get('liasse/imprimer', [C\LiasseController::class, 'print'])->name('liasse.print');

    // ---------- Déclarations ----------
    Route::get('declarations', [C\DeclarationController::class, 'index'])->name('declarations.index');
    Route::post('declarations/enregistrer', [C\DeclarationController::class, 'save'])->name('declarations.save');
    Route::post('declarations/preremplir', [C\DeclarationController::class, 'prefill'])->name('declarations.prefill');
    Route::post('declarations/statut', [C\DeclarationController::class, 'statut'])->name('declarations.statut');
    Route::post('declarations/annexe', [C\DeclarationController::class, 'annexeStore'])->name('declarations.annexe.store');
    Route::delete('declarations/annexe', [C\DeclarationController::class, 'annexeDestroy'])->name('declarations.annexe.destroy');
    Route::get('declarations/imprimer', [C\DeclarationController::class, 'print'])->name('declarations.print');
    Route::get('declarations/export', [C\DeclarationController::class, 'csv'])->name('declarations.csv');

    // ---------- Audit ----------
    Route::get('audit', [C\AuditController::class, 'index'])->name('audit.index');
    Route::post('audit/missions', [C\AuditController::class, 'missionStore'])->name('audit.missions.store');
    Route::put('audit/missions/{mission}', [C\AuditController::class, 'missionUpdate'])->name('audit.missions.update');
    Route::delete('audit/missions/{mission}', [C\AuditController::class, 'missionDestroy'])->name('audit.missions.destroy');
    Route::post('audit/missions/{mission}/qci', [C\AuditController::class, 'qciSave'])->name('audit.qci');
    Route::post('audit/missions/{mission}/travaux', [C\AuditController::class, 'travauxSave'])->name('audit.travaux');
    Route::post('audit/missions/{mission}/faiblesses', [C\AuditController::class, 'faiblessesToConstats'])->name('audit.faiblesses');
    Route::post('audit/missions/{mission}/rapport', [C\AuditController::class, 'rapport'])->name('audit.rapport');
    Route::get('audit/missions/{mission}/rapport', [C\AuditController::class, 'rapportPrint'])->name('audit.rapport.print');
    Route::post('audit/constats', [C\AuditController::class, 'constatStore'])->name('audit.constats.store');
    Route::put('audit/constats/{constat}', [C\AuditController::class, 'constatUpdate'])->name('audit.constats.update');
    Route::delete('audit/constats/{constat}', [C\AuditController::class, 'constatDestroy'])->name('audit.constats.destroy');

    // ---------- GED ----------
    Route::get('ged', [C\GedController::class, 'index'])->name('ged.index');
    Route::post('ged', [C\GedController::class, 'store'])->name('ged.store');
    Route::put('ged/{document}', [C\GedController::class, 'update'])->name('ged.update');
    Route::get('ged/{document}/telecharger', [C\GedController::class, 'download'])->name('ged.download');
    Route::delete('ged/{document}', [C\GedController::class, 'destroy'])->name('ged.destroy');

    // ---------- Paramètres ----------
    Route::get('parametres', [C\ParametreController::class, 'index'])->name('parametres.index');
    Route::post('parametres/cabinet', [C\ParametreController::class, 'cabinet'])->middleware('chef')->name('parametres.cabinet');
    Route::post('parametres/mot-de-passe', [C\ParametreController::class, 'password'])->name('parametres.password');
    Route::patch('parametres/utilisateurs/{user}', [C\ParametreController::class, 'user'])->middleware('chef')->name('parametres.user');
    Route::delete('parametres/utilisateurs/{user}', [C\ParametreController::class, 'userDestroy'])->middleware('chef')->name('parametres.user.destroy');
    Route::post('parametres/invitation', [C\ParametreController::class, 'invitation'])->middleware('chef')->name('parametres.invitation');
});
