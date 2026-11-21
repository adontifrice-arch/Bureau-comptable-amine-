<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Prénom du père du salarié : identité de l'assuré (prénom, prénom du père, nom) dans la télédéclaration CNSS. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salaries', fn (Blueprint $t) => $t->string('prenom_pere', 100)->nullable()->after('prenom'));
    }

    public function down(): void
    {
        Schema::table('salaries', fn (Blueprint $t) => $t->dropColumn('prenom_pere'));
    }
};
