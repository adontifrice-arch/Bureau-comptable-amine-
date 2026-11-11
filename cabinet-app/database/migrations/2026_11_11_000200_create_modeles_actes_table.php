<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Modèles d'actes importables (.docx / .txt) avec balises {{…}} — spécification §3.2. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modeles_actes', function (Blueprint $t) {
            $t->id();
            $t->string('type');                       // suarl | sarl | ago | age | mixte | cess | cession
            $t->string('nom');
            $t->string('format', 4);                  // txt | docx
            $t->string('fichier');                    // fichier d'origine (disque local privé)
            $t->string('fichier_nom');
            $t->json('balises')->nullable();          // balises trouvées dans le modèle
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index('type');
        });

        Schema::table('actes', function (Blueprint $t) {
            $t->foreignId('modele_id')->nullable()->after('type')->constrained('modeles_actes')->nullOnDelete();
            $t->json('donnees')->nullable()->after('contenu');   // saisie du formulaire, pour régénérer le .docx
        });
    }

    public function down(): void
    {
        Schema::table('actes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('modele_id');
            $t->dropColumn('donnees');
        });
        Schema::dropIfExists('modeles_actes');
    }
};
