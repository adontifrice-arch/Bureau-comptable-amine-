<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Factures d'achat et de vente ventilées sur plusieurs comptes, chacun avec son montant HT et son taux de TVA.
 * « lignes » : [{compte, ht, tva_taux, tva}] ; les colonnes ht, tva, compte et tva_taux gardent les totaux et le premier compte.
 * Une facture sans « lignes » (saisie antérieure, lecture par l'IA) est une facture à une seule ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['factures_recues', 'factures_ventes'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->json('lignes')->nullable()->after('compte'));
        }
    }

    public function down(): void
    {
        foreach (['factures_recues', 'factures_ventes'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('lignes'));
        }
    }
};
