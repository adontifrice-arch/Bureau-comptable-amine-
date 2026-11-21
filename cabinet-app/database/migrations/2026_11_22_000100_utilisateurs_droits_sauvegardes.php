<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètres réservés au chef comptable :
 * - droits des utilisateurs : fonction, modules visibles, dossiers autorisés, accès hors du réseau du cabinet, blocage ;
 * - journal des opérations (suivi de chaque action et connexion d'un utilisateur) ;
 * - historique des sauvegardes (manuelles et automatiques).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('fonction')->nullable();
            $t->json('modules')->nullable();                      // null : tous les modules
            $t->boolean('tous_dossiers')->default(true);          // sinon : dossiers de user_dossiers
            $t->boolean('acces_distant')->default(true);          // false : seulement depuis le réseau du cabinet
            $t->string('motif_blocage')->nullable();
            $t->timestamp('bloque_le')->nullable();
            $t->timestamp('derniere_connexion_at')->nullable();
            $t->string('derniere_ip', 45)->nullable();
        });

        Schema::create('user_dossiers', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->primary(['user_id', 'client_id']);
        });

        Schema::create('journal_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->string('module', 30)->nullable();
            $t->string('action', 100);                             // nom de la route, ou auth.connexion…
            $t->string('libelle');
            $t->string('methode', 10)->nullable();
            $t->string('url', 500)->nullable();
            $t->string('ip', 45)->nullable();
            $t->unsignedSmallInteger('statut')->nullable();        // code HTTP de la réponse
            $t->timestamp('created_at')->useCurrent();
            $t->index(['cabinet_id', 'user_id', 'created_at']);
        });

        Schema::create('sauvegardes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $t->string('fichier');
            $t->unsignedBigInteger('taille')->default(0);
            $t->json('elements');
            $t->string('declencheur', 10);                         // auto | manuel
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('statut', 10)->default('ok');               // ok | erreur
            $t->text('message')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sauvegardes');
        Schema::dropIfExists('journal_operations');
        Schema::dropIfExists('user_dossiers');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['fonction', 'modules', 'tous_dossiers', 'acces_distant', 'motif_blocage', 'bloque_le', 'derniere_connexion_at', 'derniere_ip']);
        });
    }
};
