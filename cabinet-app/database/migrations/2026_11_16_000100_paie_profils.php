<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paie, menu Profil : profils de paie du dossier (Ordinaire, CIVP, Dignité, Exo 2 ans, Gérant…). Le profil fixe le taux de CNSS patronale,
 * le code exploitant, l'assujettissement à la TFP et au FOPROLOS et la présence dans les déclarations CNSS et employeur.
 * Les rubriques du profil sont portées par les rubriques elles-mêmes (paie_rubriques.profils).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paie_profils', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->nullable()->constrained('cabinets')->cascadeOnDelete();
            $t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $t->string('nom', 60);
            $t->decimal('cnss_patronale', 6, 3)->nullable();          // vide : taux du dossier
            $t->string('code_exploitant', 10)->nullable();
            foreach (['soumis_tfp_fop', 'decl_cnss', 'decl_employeur', 'base_calcul', 'contrat_dignite', 'contrat_exo2ans', 'regul_karama'] as $c) {
                $t->boolean($c)->default(false);
            }
            $t->timestamps();
            $t->unique(['client_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paie_profils');
    }
};
