# Cabinet Amine Boudaya — application de gestion

Application Laravel 12 de gestion d’un cabinet d’expertise comptable (Tunisie), construite à partir du prototype HTML « Cabinet – Gestion ».

## Modules

| Module | Contenu |
|---|---|
| **Tableau de bord** | Échéances prioritaires, honoraires à encaisser, CA des 12 derniers mois, factures à valider, agenda |
| **Clients** | Fiche complète (identification, gérant, associés, capital), exigences, échéances, factures, documents, actes |
| **Échéances** | Liste filtrable, calendrier annuel par client, séries (mensuelle, trimestrielle…), acomptes provisionnels créés automatiquement |
| **Facturation** | Factures et devis à lignes, catalogue de services, TVA, droit de timbre, statuts, impression PDF, XML TEIF (El Fatoora), récapitulatif du CA + export Excel |
| **Notes de frais** | Frais du cabinet avec justificatif, refacturation aux clients ; dépenses avec retenue à la source et fichier XML TEJ |
| **Interne** | Rendez-vous clients (compte rendu, actions), notes et rappels, autres ressources |
| **Comptabilité** | Factures reçues (saisie, lecture IA optionnelle, validation par le chef comptable → journal des achats), paie à comptabiliser, écritures manuelles, journal, balance générale, grand livre, plan comptable |
| **Paie** | Salariés, calcul mensuel (CNSS, IRPP au barème, CSS, TFP, FOPROLOS — taux paramétrables), clôture, bulletins |
| **Liasse fiscale** | Bilan, état de résultat (N et N-1), tableau de passage au résultat fiscal, impression des états financiers |
| **RNE** | Dépôt des états financiers : bilan, état de résultat, flux, notes et liste des associés en PDF (classés en GED), PV d’AGO et rapport du CAC importés après approbation, dossier ZIP, délai et suivi du dépôt ; check-lists des 95 formalités du RNE (documents demandés, redevance, délais) cochées par client |
| **Déclarations** | Mensuelle (TVA, retenues, CSS, TFP…), annuelle (IS / IRPP), employeur (annexes 1 à 7) — pré-remplissage depuis la comptabilité et la paie, validation, export |
| **Audit & CAC** | Missions, questionnaire de contrôle interne (OECT), programme de travail, contrôles automatiques, constats, honoraires au barème, projet de rapport éditable, piste d’audit |
| **GED** | Classement des documents par client et catégorie, complétude des 9 pièces de base, recherche |
| **Échanges clients** | Espace client séparé (`/espace-client`) : le client envoie ses pièces au cabinet et télécharge les documents partagés ; côté cabinet, envois à traiter, partage depuis la GED ou la fiche client, accès des clients (invitation par e-mail), avis par e-mail |
| **Rédaction juridique** | Statuts SARL / SUARL, PV d’AGO / AGE / mixte, PV de cessation, contrat de cession de parts — brouillons éditables et imprimables |

Rôles : **chef comptable** (tous les droits, gère les utilisateurs et les paramètres) et **collaborateur** (saisit **et valide** les écritures : OD, factures reçues et de vente, paies, relevés bancaires, immobilisations ; le chef comptable peut lui retirer ce droit, et lui accorder celui de modifier ou supprimer des écritures déjà passées). Le premier compte créé devient chef comptable ; les suivants sont validés par un chef comptable.

## Installation sous Windows (Laragon recommandé)

Prérequis : PHP 8.2 ou plus avec les extensions `pdo_sqlite`, `sqlite3`, `mbstring`, `fileinfo`, `openssl`, `zip`. [Laragon](https://laragon.org) ou XAMPP les fournissent.

Le dossier livré contient déjà les dépendances (`vendor/`) et les fichiers CSS/JS compilés (`public/build/`) : **ni Composer ni Node.js ne sont nécessaires** pour le faire tourner.

```bat
cd "C:\Users\Admin\Desktop\cabinet expert comptable Amine Boudaya\cabinet-app"
php artisan storage:link
php artisan serve
```

Puis ouvrir http://127.0.0.1:8000

La base SQLite (`database/database.sqlite`) est livrée avec un jeu de démonstration.

**Comptes de démonstration** (mot de passe `Cabinet2026!`) :
- `admin@cabinet.tn` — chef comptable
- `collaborateur@cabinet.tn` — collaborateur
- `client@cabinet.tn` — espace client de démonstration (Boulangerie Dupain), sur http://127.0.0.1:8000/espace-client

Pensez à changer ces mots de passe (Paramètres → Mon compte).

### Utilisateurs, droits et sauvegardes (Paramètres, chef comptable)

Le menu **Paramètres** n’apparaît qu’au chef comptable (un collaborateur garde « Mon compte » dans le menu de son avatar).

- **Utilisateurs** : « Ajouter un utilisateur » crée un compte avec sa fonction, son rôle, les **modules** qu’il voit et les **dossiers** qu’il peut saisir. Ces droits sont vérifiés sur chaque page : un module ou un dossier non autorisé est refusé même par son adresse. Chaque utilisateur a une page « Droits & suivi » : blocage (avec motif) et déblocage, nouveau mot de passe, et **suivi des opérations** (ajouts, modifications, suppressions, validations, exports, connexions, avec le dossier et l’adresse IP).
- **Accès à distance** : déclarez les adresses IP publiques du cabinet (Paramètres → Utilisateurs → Réseau du cabinet) ; un utilisateur sans « accès à distance » ne peut alors se connecter que depuis ces adresses. Derrière un proxy ou un hébergeur, configurez les proxys de confiance de Laravel pour que l’adresse réelle soit lue.
- **Sauvegardes** : programmation quotidienne, hebdomadaire, mensuelle ou annuelle à l’heure choisie, choix des éléments (base complète, clients, dossiers comptables, paie, liasse, immobilisations, déclarations, audit, facturation, notes de frais), sauvegarde immédiate et historique. Une archive ZIP par sauvegarde dans `storage/app/sauvegardes` (ou le dossier indiqué par `SAUVEGARDE_DOSSIER` dans `.env`, par exemple un disque externe ou un dossier OneDrive).

Pour que les sauvegardes partent **à l’heure prévue**, le planificateur de Laravel doit tourner chaque minute. Sous Windows (invite de commandes en administrateur) :
```bat
schtasks /Create /SC MINUTE /MO 1 /TN "Cabinet - planificateur" /TR "\"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe\" C:\laragon\www\cabinet\cabinet-app\artisan schedule:run"
```
Sans planificateur, une sauvegarde en retard est faite dès qu’un utilisateur ouvre l’application.

### Documents reçus des clients : traitement automatique

Dans son espace, le client choisit le **type** de documents qu’il envoie ; chaque fichier part au module concerné et y est traité en arrière-plan (tâche « Cabinet - planificateur », chaque minute) :

| Type | Module | Traitement |
|---|---|---|
| Factures d’achat / de vente, tableaux de factures | Comptabilité | Lecture (OCR par l’IA) → factures proposées aux journaux ACH / VTE |
| Relevé bancaire | Comptabilité | Import (Excel, CSV, PDF ; image avec l’IA) → contreparties proposées, journal BQ |
| Traites, chèques, état de caisse, pièce diverse | Comptabilité | Lecture → écritures proposées (BQ, CAI, OD), modifiables |
| Facture d’immobilisation | Immobilisations | Lecture → facture proposée au journal ACH ; le bien entre au registre à sa validation |
| Contrat de leasing | Immobilisations | Lecture → bien en crédit-bail inscrit au registre |
| Pointage | Paie | Import dans la paie du mois (Excel sans IA ; PDF ou photo avec l’IA) |
| États des congés, des acomptes et prêts | Paie | Lecture → lignes vérifiées puis importées |
| Contrat de travail, CIVP, CIN, documents des salariés | Paie | Classés dans la fiche du salarié reconnu |

Rien n’est comptabilisé sans validation : chaque proposition se valide dans son journal par un utilisateur autorisé. Chaque module a sa boîte « Documents reçus » (Comptabilité → Pièces reçues, Immobilisations → Documents reçus, Paie → Documents reçus du client), où l’on peut aussi reclasser un document mal typé, le relire ou le marquer comme saisi à la main.

La lecture automatique utilise l’API Claude : renseignez `ANTHROPIC_API_KEY` dans `.env` (modèle : `ANTHROPIC_MODEL`). Sans clé, les documents arrivent dans leur module « à saisir » ; les relevés Excel / CSV et les pointages Excel sont importés quand même.

### Formalités du RNE

Les formalités du Registre National des Entreprises et leurs check-lists (documents demandés, redevance, délais, observations) sont reprises du site officiel (https://home.registre-entreprises.tn/formalites/) et de la fiche PDF de chaque formalité. Elles sont conservées dans `database/data/rne_formalites.json` ; pour les mettre à jour quand le RNE change ses fiches :
```bat
php artisan rne:formalites
```

### Espace client et e-mails

Le chef comptable invite un client depuis sa fiche (onglet **Espace client**) : la personne reçoit un e-mail pour choisir son mot de passe, puis se connecte sur `/espace-client`. Elle ne voit que ses propres envois et les documents de son dossier que le cabinet partage avec elle.

Les avis (invitation, document partagé, envoi d’un client) partent avec la configuration mail de Laravel. Par défaut (`MAIL_MAILER=log`), ils sont seulement écrits dans `storage/logs/laravel.log`. Pour les voir en local avec Mailpit (fourni par Laragon), mettre dans `.env` :
```
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```
puis ouvrir http://localhost:8025. En production, indiquer le serveur SMTP du cabinet et mettre dans `APP_URL` l’adresse publique de l’application : les liens des e-mails en dépendent. Les envois des clients sont signalés à l’adresse e-mail du cabinet (Paramètres) ou, à défaut, aux chefs comptables.

### Repartir d’une base vide

```bat
php artisan migrate:fresh --seed --seeder=BaseSeeder
```
(crée le plan comptable, le catalogue de services et le compte `admin@cabinet.tn`). Pour recharger la démonstration : `php artisan migrate:fresh --seed`.

### Passer sur MySQL

Dans `.env` :
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cabinet
DB_USERNAME=root
DB_PASSWORD=
```
puis `php artisan migrate --seed`.

### Lecture des factures par l’IA (facultatif)

Renseigner `ANTHROPIC_API_KEY` dans `.env` : le bouton « Lire une facture avec l’IA » apparaît dans Comptabilité → Factures reçues. Les propositions restent en attente de validation par le chef comptable.

## Développement

```bat
composer install
npm install
npm run dev      # rechargement à chaud du CSS (Tailwind 4) et du JS (Alpine.js)
npm run build    # compilation pour la production
```

Structure :
- `app/Http/Controllers` — un contrôleur par module
- `app/Services/Compta.php` — balance, états financiers, passation des écritures
- `app/Services/PaieCalc.php` — calcul de paie tunisien
- `config/cabinet_data.json` — données de référence reprises du prototype (plan comptable, questionnaires, barèmes, ordres du jour…)
- `resources/views` — vues Blade (composants dans `components/`)

## Avertissement

Les taux, barèmes, fichiers XML (TEIF, TEJ) et modèles d’actes sont des points de départ à vérifier selon la législation en vigueur et les schémas officiels avant tout dépôt ou signature.
