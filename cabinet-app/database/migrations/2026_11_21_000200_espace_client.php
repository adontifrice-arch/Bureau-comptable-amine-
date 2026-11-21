<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace client : comptes d'accès des clients (distincts des utilisateurs du cabinet)
 * et échange de documents dans les deux sens, à partir de la GED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password')->nullable();               // null tant que l'invitation n'est pas acceptée
            $t->boolean('actif')->default(true);
            $t->string('jeton')->nullable()->unique();        // empreinte SHA-256 du lien d'invitation / de réinitialisation
            $t->timestamp('jeton_expire_at')->nullable();
            $t->timestamp('derniere_connexion_at')->nullable();
            $t->foreignId('invite_par')->nullable()->constrained('users')->nullOnDelete();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::table('documents', function (Blueprint $t) {
            $t->boolean('partage')->default(false);                 // visible dans l'espace du client
            $t->timestamp('partage_at')->nullable();
            $t->timestamp('consulte_at')->nullable();               // premier téléchargement par le client
            $t->string('origine', 10)->default('cabinet');         // cabinet ou client
            $t->foreignId('depose_par')->nullable()->constrained('client_users')->nullOnDelete(); // envoi du client
            $t->timestamp('traite_at')->nullable();                 // envoi du client pris en charge par le cabinet
            $t->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->dropForeign(['depose_par']);
            $t->dropForeign(['traite_par']);
            $t->dropColumn(['origine', 'partage', 'partage_at', 'consulte_at', 'depose_par', 'traite_at', 'traite_par']);
        });
        Schema::dropIfExists('client_users');
    }
};
