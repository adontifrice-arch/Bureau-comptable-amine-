<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Multi-cabinet (spécification §4 et §9 « cloisonnement par cabinet ») :
 * table cabinets, colonne cabinet_id sur les utilisateurs et toutes les tables métier,
 * paramètres et plan comptable propres à chaque cabinet.
 * Les données existantes sont rattachées à un premier cabinet créé d'après les paramètres actuels.
 */
return new class extends Migration
{
    /** Tables métier rattachées à un cabinet. */
    public const TABLES = ['users', 'clients', 'associes', 'echeances', 'exigences', 'services', 'factures', 'facture_lignes',
        'notes_frais_documents', 'notes_frais', 'depenses', 'rendez_vous', 'notes_internes', 'comptes', 'factures_recues',
        'ecritures', 'ecriture_lignes', 'salaries', 'paies', 'passages_fiscaux', 'declarations', 'missions_audit',
        'constats_audit', 'documents', 'actes', 'journal_activite', 'modeles_actes'];

    public function up(): void
    {
        Schema::create('cabinets', function (Blueprint $t) {
            $t->id();
            $t->string('nom');
            $t->string('code_invitation', 64)->unique();
            $t->timestamps();
        });

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('cabinet_id')->nullable()->after('id')->constrained('cabinets')->cascadeOnDelete();
            });
        }

        // Paramètres : la clé n'est plus unique que dans un cabinet.
        $anciens = DB::table('settings')->get();
        Schema::drop('settings');
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cabinet_id')->constrained('cabinets')->cascadeOnDelete();
            $t->string('key');
            $t->json('value')->nullable();
            $t->timestamps();
            $t->unique(['cabinet_id', 'key']);
        });

        // Plan comptable : un numéro de compte n'est unique que dans un cabinet.
        Schema::table('comptes', function (Blueprint $t) {
            $t->dropUnique(['numero']);
            $t->unique(['cabinet_id', 'numero']);
        });
        // Numéros de facture / devis : uniques par cabinet.
        Schema::table('factures', function (Blueprint $t) {
            $t->index(['cabinet_id', 'numero']);
        });

        // Reprise des données existantes dans un premier cabinet.
        $existant = $anciens->isNotEmpty() || collect(self::TABLES)->contains(fn ($tb) => DB::table($tb)->exists());
        if ($existant) {
            $param = json_decode((string) optional($anciens->firstWhere('key', 'cabinet'))->value, true) ?: [];
            $id = DB::table('cabinets')->insertGetId(['nom' => $param['nom'] ?? config('app.name'), 'code_invitation' => Str::random(40),
                'created_at' => now(), 'updated_at' => now()]);
            foreach (self::TABLES as $table) DB::table($table)->update(['cabinet_id' => $id]);
            foreach ($anciens as $s) {
                DB::table('settings')->insert(['cabinet_id' => $id, 'key' => $s->key, 'value' => $s->value, 'created_at' => $s->created_at, 'updated_at' => $s->updated_at]);
            }
        }
    }

    public function down(): void
    {
        $anciens = DB::table('settings')->orderBy('cabinet_id')->get()->unique('key');
        Schema::drop('settings');
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });
        foreach ($anciens as $s) DB::table('settings')->insert(['key' => $s->key, 'value' => $s->value, 'created_at' => $s->created_at, 'updated_at' => $s->updated_at]);

        Schema::table('factures', fn (Blueprint $t) => $t->dropIndex(['cabinet_id', 'numero']));
        Schema::table('comptes', function (Blueprint $t) {
            $t->dropUnique(['cabinet_id', 'numero']);
            $t->unique(['numero']);
        });
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('cabinet_id'));
        }
        Schema::dropIfExists('cabinets');
    }
};
