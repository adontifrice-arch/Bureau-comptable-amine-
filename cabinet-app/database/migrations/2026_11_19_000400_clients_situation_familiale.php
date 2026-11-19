<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Personne physique : situation familiale, chef de famille, enfants à charge (type de chacun) et parents à charge. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->string('situation_familiale')->nullable();   // celibataire | marie | divorce | veuf
            $t->boolean('chef_famille')->nullable();
            $t->json('enfants_charge')->nullable();          // [{prenom, type: moins_20|etudiant|handicape}]
            $t->unsignedTinyInteger('parents_charge')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->dropColumn(['situation_familiale', 'chef_famille', 'enfants_charge', 'parents_charge']);
        });
    }
};
