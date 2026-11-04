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
| **Déclarations** | Mensuelle (TVA, retenues, CSS, TFP…), annuelle (IS / IRPP), employeur (annexes 1 à 7) — pré-remplissage depuis la comptabilité et la paie, validation, export |
| **Audit & CAC** | Missions, questionnaire de contrôle interne (OECT), programme de travail, contrôles automatiques, constats, honoraires au barème, projet de rapport éditable, piste d’audit |
| **GED** | Classement des documents par client et catégorie, complétude des 9 pièces de base, recherche |
| **Rédaction juridique** | Statuts SARL / SUARL, PV d’AGO / AGE / mixte, PV de cessation, contrat de cession de parts — brouillons éditables et imprimables |

Rôles : **chef comptable** (valide les pièces, les paies et les écritures, gère les utilisateurs et les paramètres) et **collaborateur** (saisit). Le premier compte créé devient chef comptable ; les suivants sont validés par un chef comptable.

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

Pensez à changer ces mots de passe (Paramètres → Mon compte).

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
