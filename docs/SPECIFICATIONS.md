# Spécifications fonctionnelles et techniques — Gestion du cabinet

## 1. Contexte

- Utilisateur : un cabinet d'expertise comptable tunisien (« Cabinet Amine Boudaya » dans le prototype). Le cabinet gère **plusieurs clients** (sociétés SARL/SUARL/SA…, personnes physiques) et leur fournit : tenue comptable, paie, déclarations fiscales/sociales, juridique (statuts, PV), audit et commissariat aux comptes, GED.
- Langue : français. Devise : **dinar tunisien (TND), 3 décimales** (millimes). Fuseau Africa/Tunis. Séparateur décimal « , », milliers = espace fine.
- Plateformes : web responsive (mobile d'abord), mode clair/sombre, impression papier A4 (portrait et paysage) et exports PDF / Excel / Word.

## 2. Rôles et accès

| Rôle | Droits |
|---|---|
| Chef comptable (administrateur) | Tout, dont validation des écritures, comptabilisation des dotations, approbation des comptes, gestion des rôles |
| Collaborateur | Saisie ; ne peut pas valider ni comptabiliser |

- Inscription libre « Inscription utilisateur » ; le **premier inscrit devient chef comptable** ; les suivants sont « en attente d'approbation » jusqu'à approbation par un chef.
- Les modules **Comptabilité** et **Immobilisations** exigent une connexion (nom d'utilisateur + mot de passe, 8 caractères minimum). Blocage 30 s après 5 échecs. Changement de mot de passe par l'utilisateur ; le chef gère rôles et suppressions (jamais le dernier chef, jamais soi-même).
- Cible Laravel : authentification complète pour **tous** les modules, 2FA, politique de mots de passe, journal d'audit, RBAC par permission (spatie/laravel-permission), approbation des comptes.

## 3. Modules (un onglet = un module)

### 3.1 Tableau de bord
Compteurs : échéances en retard / ouvertes, honoraires à encaisser et encaissés ; 5 échéances prioritaires.

### 3.2 Clients
- **Fiche client** : nom, forme juridique, nature (personne morale/physique, déduite de la forme si absente), mission, honoraires mensuels, matricule fiscal, **compte comptable client (411xxx), n° CNSS, matricule indépendant, adresse**, **dénomination commerciale**, **résidente / non résidente**, **non exportatrice / totalement / partiellement exportatrice**, capital, nombre de parts, valeur nominale (capital = parts × valeur nominale).
- **Gérant** : nom, nationalité, adresse, date et lieu de naissance, **pièces d'identité séparées** : CIN (n°, date et lieu de délivrance), carte de séjour (résident étranger), passeport (n°, date de délivrance, date de validité), **date et durée de nomination**, **associé ou non, nombre de parts**, **numéros de parts (de … à … inclus)**, apport en numéraire ou en nature.
- **Associés** (liste) : mêmes pièces d'identité, parts, numéros de parts, apport numéraire/nature.
- **Rédaction juridique** : types SUARL (statuts), SARL (statuts), PV AGO, PV AGE, PV mixte, PV de cessation définitive d'activité, contrat de cession de parts sociales. Textes générés depuis la fiche (articles 1 forme/résidence/export, 3 dénomination commerciale, 6 capital et apports, 8 gérance avec date et durée de nomination, etc.). **Modèles importables** (.docx/.txt) avec balises {{DENOMINATION}}, {{FORME}}, {{CAPITAL}}, {{NB_PARTS}}, {{VALEUR_NOMINALE}}, {{SIEGE}}, {{MATRICULE_FISCAL}}, {{OBJET}}, {{DUREE}}, {{DATE}}, {{LIEU}}, {{GERANT}}, {{GERANT_CIN}}, {{GERANT_NATIONALITE}}, {{GERANT_ADRESSE}}, {{ASSOCIES}}, {{REPARTITION_PARTS}}, {{SIGNATURES}}, {{DENOMINATION_COMMERCIALE}}, {{RESIDENCE}}, {{REGIME_EXPORT}}, {{DATE_NOMINATION_GERANT}}, {{DUREE_GERANT}}, {{APPORTS}}, et pour les PV {{TYPE_AG}}, {{PRESENTS}}, {{ORDRE_DU_JOUR}}, {{RESOLUTIONS}}, {{DATE_CESSATION}}, {{MOTIF}}, pour la cession {{CEDANT}}, {{CESSIONNAIRE}}, {{NB_PARTS_CEDEES}}, {{NUMEROS_PARTS}}, {{PRIX}}.
- **Éditeur riche** du brouillon (gras, italique, souligné, alignements, listes, titres, taille, annuler/rétablir), aperçu « page A4 », estimation du nombre de pages, **export PDF paginé « Page i / n »**, copie.
- **Ordre du jour des PV** : liste à cocher par type (AGO, AGE, mixte, cessation), ajout de points personnalisés persistants, ordre = ordre de la liste.
- **Documents du client** (liste de contrôle par catégories, voir GED) ; **calendrier annuel** (voir 3.3).

### 3.3 Échéances et calendrier annuel
- Échéance : client, type/catégorie (fiscale, déclaration mensuelle, sociale CNSS, RNE, autre), intitulé, date, accomplie (date d'accomplissement).
- **Calendrier annuel par client** : 12 mois avec jours colorés (vert accomplie, orange à faire, rouge en retard), cases à cocher, ajout d'une échéance ou **d'une série** (mensuelle/trimestrielle/semestrielle/annuelle, jour du mois, **sur 1 à 10 ans**), suppression d'une série (toutes années / à partir de / une année).
- **Acomptes provisionnels automatiques** : pour chaque client, 3 échéances fiscales par an (juin, septembre, décembre) : **le 28 pour les personnes morales, le 25 pour les personnes physiques** ; année en cours + 4 suivantes ; la suppression d'une échéance générée est définitive ; changement de type du client = régénération des non cochées.

### 3.4 Facturation
- **Factures** (FAC-AAAA-NNN) et **devis** (DEV-AAAA-NNN, date de validité, statuts brouillon/envoyé/accepté/refusé, conversion en facture) : lignes (désignation avec **liste de services du cabinet** modifiable, quantité, PU HT), **TVA** (19 % par défaut, modifiable), **droit de timbre 1 TND** (modifiable), **retenue à la source effectuée oui/non**, échéance, statut (à envoyer/envoyée/payée), aperçu, PDF, impression, **espaces de signature client et cachet cabinet**, pied de page « _Une information de qualité, une décision adéquate._ », logo, coordonnées bancaires du cabinet (RIB, IBAN, BIC).
- **Récapitulatif du chiffre d'affaires** par période/client : date, n° facture, client, matricule fiscal, compte comptable, total HT, TVA, timbre, TTC, retenue à la source (oui/non) ; PDF paysage + Excel ; devis exclus.
- **Facture électronique El Fatoora** par facture : XML TEIF contrôlé, signature XAdES, dépôt et suivi (référence TTN + QR code imprimés) : voir §8.
- **Dépenses et frais** (factures fournisseurs du cabinet) : fournisseur, matricule fiscal (ou CIN/passeport/carte de séjour), adresse, date facture et de paiement, HT, taux TVA, timbre, retenue à la source (taux ou montant), code d'opération TEJ, net à payer ; **génération XML TEJ** par mois de paiement (voir §8).

### 3.5 Notes de frais
Notes de frais (date, description, catégorie, client, montant TTC, statut en attente/remboursée, justificatif) ; **note de frais à adresser au client** (NF-AAAA-NNN) : document sans TVA ni timbre, créé à partir de notes cochées d'un même client ou vierge ; aperçu, PDF, impression, signatures, pied de page, coordonnées bancaires.

### 3.6 Interne
Rendez-vous clients avec **rapport de réunion** et actions ; notes et rappels (date de rappel, en retard/aujourd'hui) ; autres éléments catégorisés.

### 3.7 Comptabilité (accès protégé)
- **Factures reçues des clients** : photo/scan (image) ou texte collé → **IA** (voir §7) → enregistrement « à valider » avec proposition d'écriture ; saisie manuelle possible ; **validation / rejet / annulation de validation réservés au chef comptable**.
- **Paie à comptabiliser** : paies transmises par le module Paie ; validation par le chef → écriture journal PAI.
- **Écritures** : journal ; écritures manuelles OD équilibrées (4 lignes) ou **à-nouveau (AN)** ; chef comptable seulement.
- **Balance générale par exercice** : colonnes **Report à nouveau (débit/crédit)**, **Mouvements de la période**, **Soldes** ; ligne des totaux ; **résultat de l'exercice** (produits classe 7 − charges classe 6) ; contrôle d'équilibre ; **impression papier (paysage) et PDF**. Le report reprend les soldes de clôture des exercices précédents (le résultat antérieur est porté au compte 121) et les écritures AN.
- **Déclaration mensuelle** et **déclaration annuelle** : voir 3.12.
- **Utilisateurs** (mon compte, gestion des comptes par le chef). **Plan comptable** indicatif modifiable (ajout de comptes).

### 3.8 Immobilisations et amortissements (accès protégé)
- **Registre** des achats par **nature** : incorporelles (211/2281), terrains (220, non amortissable), constructions (221/2282), matériel et outillage (223/2283), matériel de transport (224/2284), mobilier et matériel de bureau (225/2285), matériel informatique (226/2286), agencements et installations (227/2287) ; désignation, date de mise en service, valeur d'origine HT, TVA déductible, fournisseur, méthode **linéaire ou dégressif**, taux, valeur résiduelle, date de cession. Import depuis les **factures reçues validées** (comptes de classe 2).
- **Calcul des dotations de l'exercice** : prorata temporis au mois (défaut), au jour ou sans prorata ; linéaire = base × taux × fraction ; dégressif = VNC × taux × coefficient (1,5 jusqu'à 4 ans, 2 pour 5–6 ans, 2,5 au-delà) avec passage au linéaire sur la durée restante ; plafonné à la base amortissable ; arrêt à la cession.
- **Comptabilisation en un clic par le chef comptable** : D 681 / C 228x par nature, journal AMO, date 31/12 ; idempotent (suivi par immobilisation et par exercice, compléments possibles) ; annulation en deux temps ; **écriture d'acquisition** (D compte 22x [+ D 4367] / C 401) pour les biens saisis à la main ; blocage de suppression si des dotations sont comptabilisées.
- **États imprimables** : tableau des immobilisations et amortissements, plan d'amortissement prévisionnel sur 5 ans ; **impression, PDF, Excel, Word**.

### 3.9 Paie (législation tunisienne)
- Salariés par client : nom, CIN, n° CNSS, poste, salaire de base, chef de famille, enfants à charge.
- **Calcul mensuel** (paramétrable) : brut = base + primes ; CNSS salariale 9,68 % ; frais professionnels 10 % du net de CNSS annualisé plafonnés à 2 000 TND ; déductions chef de famille 300 TND et 100 TND/enfant (4 max) ; base imposable annuelle ; **barème IRPP** (0–5 000 : 0 % ; 5 000–10 000 : 15 % ; 10 000–20 000 : 25 % ; 20 000–30 000 : 30 % ; 30 000–40 000 : 33 % ; 40 000–50 000 : 36 % ; 50 000–70 000 : 38 % ; > 70 000 : 40 %) ; CSS 0,5 % du revenu imposable ; net = brut − CNSS − (IRPP + CSS)/12 ; charges patronales : CNSS 17,07 %, TFP 2 %, FOPROLOS 1 %.
- Bulletins de paie PDF (une page par salarié). **Clôture et transmission à la comptabilité** : écriture D 661 brut, D 664 CNSS patronale, D 671 TFP+FOPROLOS / C 421 net, C 431 CNSS salariale+patronale, C 4365 IRPP+CSS, C 4369 TFP+FOPROLOS ; la paie conserve ir, css, tfp, fop pour la déclaration mensuelle.

### 3.10 Liasse fiscale et états financiers
Bilan (actif/passif), état de résultat, tableau de passage résultat comptable → résultat fiscal (réintégrations, déductions, taux IS, minimum d'impôt, acomptes, retenues subies) ; PDF. Les soldes de bilan = **report + mouvements** ; le résultat = mouvements de l'exercice.

### 3.11 Déclarations (employeur)
**Déclaration d'employeur annuelle** : récapitulatif (lignes DECEMP01 à DECEMP49 + total DECEMP50, avec taux par défaut) et **annexes 1 à 7** (1 salaires ; 2 honoraires, commissions, courtages, loyers (résidents) ; 3 capitaux mobiliers/intérêts ; 4 non-résidents ; 5 acquisitions ≥ 1 000 TND ; 6 ristournes/ventes/jeux ; 7 montants payés pour autrui). **Pré-remplissage** : annexe 1 depuis la paie, annexes 2, 3, 5, 6 depuis la comptabilité (factures reçues avec retenue, compte 709), annexes 4 et 7 manuelles ; lignes automatiques repérées « auto » (régénérables sans écraser les lignes modifiées) ; saisie manuelle de toute ligne ; statuts **brouillon → validée → déposée** (n° de dépôt) ; verrouillage ; **export PDF et Excel (une feuille par annexe)**.

### 3.12 Déclarations mensuelle et annuelle (sous Comptabilité)
- **Mensuelle** : TVA (CA par taux, collectée, déductible biens/immobilisations, crédit reporté, TVA à payer), retenues à la source (salaires, honoraires/commissions/loyers, acquisitions), CSS, TFP, FOPROLOS, timbre ; **pré-remplissage depuis la comptabilité et la paie** ou saisie manuelle ; échéance indicative le 28 du mois suivant ; statuts brouillon/validée/déposée ; PDF/Excel.
- **Annuelle** : résultat comptable, réintégrations, déductions, résultat fiscal, impôt (IS au taux saisi, ou barème IRPP pour une personne physique), minimum d'impôt, acomptes, retenues subies, crédit reporté, solde ; mêmes statuts et exports.

### 3.13 Audit et commissariat aux comptes
1. **Missions multitype** (11 types : CAC audit légal, audit contractuel, comptes consolidés, examen limité, procédures convenues, compilation/assistance, due diligence, audit interne, fiscal, social, conformité) : désignation, durée du mandat, signataire, équipe, budget d'heures, honoraires, statut (planifiée/en cours/rapport émis/clôturée), progression.
2. **Questionnaire de contrôle interne** : ~60 questions en 9 cycles (environnement, ventes, achats, trésorerie, paie, stocks, immobilisations, fiscalité, systèmes) ; Oui/Non/Sans objet + commentaire ; indice par cycle et global (≥ 80 % fiable, 60–79 % à renforcer, < 60 % faible) ; faiblesses → constats ; questions supplémentaires.
3. **Travaux d'audit** : programme de 20 étapes (statut, responsable, référence W/P, notes) ; **seuil de signification** (base CA 0,5 % / résultat 5 % / actif 1 % / capitaux propres 2 %, planification 75 %, insignifiant 5 %) ; **revue analytique N / N−1** avec explications ; **échantillonnage** aléatoire reproductible (graine).
4. **Revue sociale et fiscale CNSS** : rapprochements (paie vs CNSS déclarée par trimestre, IRPP/CSS, TFP/FOPROLOS, TVA, retenues fournisseurs, annexe 1) avec tolérance 1 TND + liste de revue de 16 questions ; écarts → constats.
5. **Honoraires du CAC au barème des auditeurs des comptes** : honoraires = A (total brut du bilan) + B (total des produits) + C (effectif) selon tranches (voir §5.4) × coefficient d'actualisation, co-commissariat +50 %, suppléments, frais, TVA 19 %, échéancier 20/30/30/20 ; seuils de désignation d'un CAC.
6. **Cohérence et rapport** : 12 tests automatiques (équilibre journal/bilan, factures en attente, matricules fournisseurs, doublons, soldes anormaux, retenues à reverser, paies non comptabilisées, fiches salariés, déclarations mensuelles manquantes, échéances en retard, pièces de base), synthèse avec avis indicatif ; **rapport du commissaire aux comptes** (opinion sans réserve / avec réserve / impossibilité d'exprimer une opinion / défavorable, observations, vérifications spécifiques), rapport spécial sur les conventions, rapports adaptés aux autres types de mission ; éditeur riche + PDF.
7. **Constats** (intitulé, gravité faible/moyen/élevé, statut ouvert/résolu) et **piste d'audit** (écritures validées, factures rejetées, déclarations validées/déposées) ; rapport d'audit PDF.

### 3.14 GED
Documents par client ou « Cabinet (interne) » : catégories (statuts, patente, déclaration d'existence, extrait RNE, affiliation CNSS, bénéficiaire effectif, API, BCT, code en douane, CIN gérant/associés, passeport, Kbis/RNE sociétés associées, liste des associés, procuration, réservation de raison sociale, contrats de travail, décisions d'avantages, lettre de mission, contrat client, courrier, PV AGO/AGE, demandes, autres), dépôt multiple (PDF, images, TXT, CSV ; 20 Mo), recherche instantanée sans accents, filtres dossier/catégorie/année, modification des métadonnées, suppression confirmée, pièces liées (justificatifs de notes de frais, factures reçues), vue « Dossiers clients » avec complétude (9 pièces de base).

## 4. Modèle de données cible (tables principales)

Toutes les tables métier portent cabinet_id (multi-tenant) sauf mention contraire ; clés id bigint, horodatages, deleted_at (soft delete) pour les entités comptables.

- cabinets (nom, matricule fiscal, adresse, logo, banque, titulaire, rib, iban, bic, paramètres JSON)
- users (cabinet_id, name, email/username, password, role, status, 2fa…), permissions/roles (spatie)
- clients (cabinet_id, nom, forme, nature, mission, honoraires_mensuels, matricule_fiscal, compte_comptable, cnss, matricule_independant, adresse, denomination_commerciale, residence, regime_export, capital, nombre_parts, valeur_nominale, gerant_\* [nom, nationalite, adresse, naissance, cin, cin_date, cin_lieu, carte_sejour, passeport, passeport_date, passeport_validite, nomination_date, nomination_duree, est_associe, parts, parts_de, parts_a, apport])
- associes (client_id, mêmes pièces d'identité, parts, parts_de, parts_a, apport)
- echeances (client_id, categorie, intitule, date, accomplie, accomplie_le, source [manuelle|serie|acompte_provisionnel])
- invoices (client_id, kind [facture|devis], numero, date, echeance, tva_taux, timbre, retenue_source, statut, converted_from_id) + invoice_lines
- services (cabinet_id, nom, prix_unitaire)
- expense_notes (client_id?, date, description, categorie, montant, statut, justificatif) et client_expense_notes (+ lignes) ; supplier_expenses (dépenses TEJ)
- meetings, reminders, misc_items (module Interne)
- accounts (plan comptable par client ou par cabinet), journal_entries (client_id, date, journal [ACH|PAI|OD|AN|AMO], libelle, source_type, source_id, validated_by, validated_at) + journal_lines (account_code, debit, credit) — **montants en DECIMAL(15,3)**
- received_invoices (client_id, fournisseur, mf, date, ref, ht, taux_tva, tva, timbre, compte, tva_deductible, retenue_taux, statut [propose|valide|rejete], ai_json, motif_rejet, media)
- fixed_assets (client_id, nature, designation, date, cout, tva, fournisseur, ref, methode, taux, residuelle, date_cession, acquisition_comptabilisee, source_received_invoice_id) + depreciation_postings (asset_id, exercice, montant, journal_entry_id)
- employees, payroll_params, payroll_runs (client_id, mois, statut, totaux, entry_id) + payroll_lines (employee_id, brut, cnss, ir, css, net, patronal…)
- tax_returns (client_id, type [mensuelle|annuelle|employeur], periode, statut, valide_le, depose_le, reference, data JSON) + employer_annex_lines (return_id, annexe, données)
- audit_missions (client_id, exercice, type, …) + audit_answers, audit_tasks, audit_findings, audit_fee_calcs, audit_reports
- documents (client_id?, categorie, nom, chemin S3, taille, mime, description, date), activity_log (spatie)

## 5. Règles de calcul de référence

### 5.1 Facturation
TTC = HT + TVA (HT × taux) + timbre. Arrondi au millime (3 décimales), bcmath/brick/money côté PHP.

### 5.2 Écritures types
- **Facture reçue validée** : D compte de charge (HT) ; D 4367 TVA déductible (si déductible, sinon TVA ajoutée à la charge) ; D 671 timbre ; C 401 fournisseur (TTC − retenue) ; C 4365 retenue à la source. Retenue = (HT + TVA) × taux.
- **Paie** : voir 3.9. **Amortissements** : D 681 / C 228x. **Acquisition** : D 22x [+ D 4367] / C 401.
- Journaux : ACH, PAI, OD, AN, AMO. Une écriture doit être **équilibrée** (contrôle à l'enregistrement).

### 5.3 États
- Balance : par compte, report (soldes de clôture N−1 des classes 1–5, résultat antérieur → 121 ; écritures AN), mouvements de l'exercice, soldes = report + mouvements.
- Bilan : actif (immobilisations nettes classe 2 y compris 228x, stocks 3, clients 41 débiteurs, autres actifs 4 débiteurs, liquidités 5 débiteurs) ; passif (capital 10x, réserves/reports 11-12 + résultat, emprunts 16, fournisseurs 40, autres passifs 4 créditeurs, concours bancaires 5 créditeurs). Résultat = Σ classe 7 − Σ classe 6.

### 5.4 Barème des honoraires CAC (valeurs de base, arrêtés des 28/02/2003 et 24/09/2003)
Honoraires = A + B + C, où pour chaque critère : forfait de la première tranche + Σ (fraction de chaque tranche × taux). Unités : bilan et produits en **milliers de dinars**, effectif en nombre.
- **A. Total brut du bilan** : forfait 450 jusqu'à 300 ; puis taux par millier : 300–1 000 : 1 ; 1 000–3 000 : 0,5 ; 3 000–7 000 : 0,25 ; 7 000–15 000 : 0,1 ; 15 000–35 000 : 0,075 ; 35 000–80 000 : 0,05 ; 80 000–200 000 : 0,025 ; 200 000–500 000 : 0,01 ; 500 000–1 000 000 : 0,0075 ; au-delà : 0,005.
- **B. Total des produits** : forfait 300 jusqu'à 100 ; 100–300 : 2,25 ; 300–700 : 1,5 ; 700–1 500 : 1 ; 1 500–3 000 : 0,5 ; 3 000–7 500 : 0,25 ; 7 500–20 000 : 0,125 ; 20 000–50 000 : 0,1 ; 50 000–120 000 : 0,05 ; 120 000–350 000 : 0,025 ; au-delà : 0,0125.
- **C. Effectif** : forfait 500 jusqu'à 50 ; 50–150 : 8,5 ; 150–500 : 5 ; 500–1 200 : 2,5 ; 1 200–3 000 : 1,25 ; 3 000–7 000 : 1 ; au-delà : 0,75.
- Contrôles : bilan 1 000 → 1 150 ; bilan 1 000 000 → 17 450 ; produits 3 000 → 2 900 ; produits 350 000 → 17 837,5 ; effectif 150 → 1 350 ; effectif 7 000 → 11 100.
- × coefficient d'actualisation annuel de l'OECT (paramètre), +50 % en co-commissariat, + suppléments, + frais, TVA 19 %.

### 5.5 Amortissements
Voir 3.8. Exemples de recette : informatique 3 000 TND acquis le 15/03, linéaire 33,33 % au mois → 833,250 ; machine 10 000 TND dégressif durée 5 ans (taux linéaire 20 %, coefficient 2) → 4 000, 2 400, 1 440, 1 080, 1 080 ; camionnette 6 000 TND, 10 %, cédée le 30/06 → 300 l'année de la cession.

## 6. Formats d'échange (brouillons à valider)

- **TEIF** (facture électronique, plateforme TTN / El Fatoora), d'après le Guide d'implémentation TEIF v2.0 et les XSD officiels (`resources/elfatoora/`) : racine TEIF (controlingAgency="TTN", version="1.8.8"), InvoiceHeader (émetteur I-01 = MF du cabinet ; destinataire I-01 MF, I-02 CIN, I-03 carte de séjour ou I-04 MF étranger), Bgm (I-11 facture), Dtm ddMMyy (I-31 émission, I-32 limite de paiement), PartnerSection (I-62 fournisseur, I-64 client), PytSection (I-114 virement + RIB), LinSection (TVA I-1602, taux sur 5 caractères au plus, I-183 PU HT, I-171 total HT ligne), InvoiceMoa (I-180 TTC avec montant en lettres, I-176 HT, I-182 base taxe, I-181 total taxe), InvoiceTax (timbre I-1601, TVA I-1602 avec I-177 base et I-178 montant). **Contrôles** avant génération : matricule fiscal selon l'assertion du XSD 1.8.9 (7 chiffres + clé [+ code TVA + catégorie + 3 chiffres]), XSD 1.8.8 (sans les constructions XSD 1.1), numéro sans « / ».
- **Signature** (Spécifications techniques signature fournisseur v3.0, 29/05/2026) : XAdES-B enveloppée, Id « SigFrs », RSA-SHA256, C14N exclusive, transformations XPath `not(ancestor-or-self::ds:Signature)` et `not(ancestor-or-self::RefTtnVal)`, SigningCertificateV2 (SHA-1 + IssuerSerialV2), politique urn:2.16.788.1.2.1.3 (hash SHA-256 du PDF de politique TTN), ClaimedRole « Fournisseur » (paramétrable), chaîne de certificats dans KeyInfo. Implémentation vérifiée sur l'exemple signé officiel (empreintes, IssuerSerialV2 et signatures SigFrs/SigTTN retrouvées). Certificat .p12/.pfx importé dans Paramètres › Facture électronique (stocké chiffré). Les certificats sur token ou à distance (DigiGo, Mobile ID) ne s'exportent pas : télécharger alors le XML TEIF et le signer avec l'outil de l'ANCE/TTN.
- **Dépôt** (Spécifications web services El Fatoora) : SOAP `saveEfact` (login, mot de passe, matricule, documentEfact en base 64) puis `consultEfact` par numéro de facture → acquittements négatifs (rejet, motif affiché) ou facture validée : XML signé par TTN conservé, référence unique I-88 et cachet ReferenceCEV (QR code) imprimés sur la facture. États : signée → déposée → validée / rejetée ; facture déposée ou validée verrouillée (ni modification ni suppression) ; modification d'une facture signée ou rejetée = signature annulée. Adresse du service paramétrable (production `https://elfatoora.tn/ElfatouraServices/EfactService` ou environnement de test TTN) ; **non testé contre la plateforme réelle** (identifiants TTN requis).
- **TEJ** (certificats de retenue à la source) : racine DeclarationsRS, Declarant, ReferenceDeclaration (ActeDepot, AnneeDepot, MoisDepot), AjouterCertificats/Certificat (Beneficiaire/IdTaxpayer, DatePayement JJMMAAAA, Ref_certif_chez_declarant, ListeOperations/Operation : IdTypeOperation, MontantHT, TauxRS, MontantTVA, MontantTTC, MontantRS, MontantNetServi). Montants en **millimes entiers** ; net = TTC − retenue ; matricule fiscal = 7 chiffres + 1 lettre ; référence unique. Structure issue de guides publics : **valider avec le cahier des charges CCT-RS-V2 et le XSD**.
- **Déclaration d'employeur** : structure des annexes tirée du cahier des charges officiel 2025 (récapitulatif DECEMP01–49, annexes 1–7) ; la génération des **fichiers à longueur fixe (DECEMP / ANXEMP, 399 caractères)** n'est pas implémentée dans le prototype.
- Le prototype ne peut pas télécharger de fichier .xml (contrainte de l'environnement) : en Laravel, exporter directement le .xml.

## 7. Intelligence artificielle

- **Lecture de facture** (image ou texte) → JSON : fournisseur, matricule_fiscal, date, numero, ht, taux_tva, tva, timbre, ttc, nature, compte (dans la liste des comptes 2xx/6xx du plan), tva_deductible, retenue_taux, retenue_motif, anomalies[], confiance. Règles : montants TND, TVA non déductible pour restauration/véhicules de tourisme/cadeaux, retenue à 0 si incertain, anomalies listées (matricule absent, total incohérent, timbre manquant).
- **Principe impératif** : l'IA **propose**, un humain (chef comptable) **valide** ; l'écriture est calculée de façon déterministe à partir des champs validés ; toute sortie IA est affichée avec sa confiance et ses anomalies ; journaliser le prompt, le modèle, la réponse et l'utilisateur.
- Ajouts souhaités : saisie d'écritures en langage naturel, commentaire de gestion, détection d'anomalies sur les écritures, aide à la rédaction des rapports d'audit (toujours révisés par le professionnel).
- Architecture : interface AiProvider (Anthropic par défaut, remplaçable), appels en file d'attente, quotas, masquage des données sensibles (CIN, RIB) quand c'est possible, clé API en secret.

## 8. Exports et impressions

Chaque état imprimable doit offrir : **impression papier** (feuille de style @media print, A4 portrait ou paysage, texte sombre sur fond blanc même en thème sombre), **PDF** (pagination « Page i / n », en-tête cabinet + logo, client, période, date d'édition), **Excel** (.xlsx, nombres numériques au format #,##0.000, dates réelles, volets figés) et **Word** (.docx, tableau avec en-tête répété, orientation adaptée). États concernés : balance, journal, grand livre, bilan, état de résultat, tableau des immobilisations, plan d'amortissement, récapitulatif du CA, déclarations, rapport d'audit, factures/devis/notes de frais (PDF + impression).

## 9. Exigences non fonctionnelles

- Sécurité : HTTPS, mots de passe hachés (Argon2id/bcrypt), 2FA, verrouillage après échecs, RBAC, **cloisonnement par cabinet**, journal d'audit immuable des opérations comptables, chiffrement des champs sensibles (CIN, RIB) et des fichiers, sauvegardes chiffrées, durées de conservation.
- Conformité : données personnelles (loi tunisienne sur la protection des données personnelles, INPDP) et choix de l'hébergement/région en conséquence ; à faire valider juridiquement.
- Qualité : tests automatisés (calculs financiers, écritures, exports), accessibilité WCAG AA, i18n (fichiers de langue FR), performances (listes paginées, index), observabilité.

## 10. Hypothèses à valider (non vérifiées auprès des textes officiels)

Taux et barème de paie (CNSS 9,68/17,07, CSS 0,5 %, barème IRPP à 8 tranches : sources publiques non officielles) ; plan comptable (comptes 4365, 4366, 4367, 4369, 671, 681, 228x, 220, 226, 227 : indicatifs) ; taux d'amortissement (informatique 33,33 %, transport 20 %, constructions 5 %, mobilier 10 %, matériel 10 %, agencements 10 %, incorporelles 33,33 %) et coefficients du dégressif ; échéances des acomptes (28/25 juin, septembre, décembre) ; échéance mensuelle (28 du mois suivant) ; structures TEIF et TEJ ; règle d'imputation de la retenue (base HT + TVA, hors timbre) ; trames des déclarations mensuelle et annuelle (simplifiées) ; seuils de désignation d'un CAC (100 000 / 300 000 / 10 ; 1,5 M / 2 M / 30) ; barème CAC 2003 à actualiser ; modèles de rapports du CAC et textes de statuts/PV/cession (à faire relire par un juriste/expert-comptable) ; questionnaire de contrôle interne (modèle usuel, pas le texte officiel de l'OECT).

## 11. Migration des données du prototype

Le prototype stocke tout dans localStorage["cabinet-v2"] (objet S). Fournir une commande php artisan app:import-legacy-json {fichier} : S.clients→clients/associes, S.tasks→echeances, S.inv→invoices(+lines), S.cab→cabinets, S.svc→services, S.dep→supplier_expenses, S.nf/S.nfd→expense_notes, S.docs→documents (fichiers à rapatrier), S.ka→received_invoices, S.ecr→journal_entries(+lines), S.ptr→payroll_runs, S.pe→employees, S.pp→payroll_params, S.lf/S.dcl→tax_returns, S.imm→fixed_assets, S.am/S.aud→audit_*, S.usr→users (mots de passe non réutilisables : réinitialisation), S.rdv/S.nr/S.au→module Interne, S.pcx→accounts. Export manuel depuis la console du navigateur : copy(localStorage.getItem('cabinet-v2')).

## 12. Critères d'acceptation

Les 14+ scripts Playwright du dossier tests/ décrivent des scénarios de recette exécutables sur le prototype (leur périmètre est dans tests/_index.json) ; ils doivent être **réécrits en tests Pest/Dusk** sur l'application Laravel avec les mêmes valeurs attendues (ex. dotation informatique 833,250 ; barème CAC 10 748,675 TTC pour bilan 2 000 000, produits 3 000 000, effectif 40, coefficient 1,1, co-commissariat, supplément 500, frais 200 ; TVA à payer 1 330,000 et total mensuel 1 883,700 sur le jeu d'essai).
