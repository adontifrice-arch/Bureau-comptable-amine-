<?php

use App\Models\Compte;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan comptable tunisien officiel (classes 1 à 7, config('cabinet.PCN')) :
 * - renumérote les écritures et les factures reçues passées sur l'ancien plan simplifié de l'application,
 *   dont plusieurs numéros contredisaient le plan officiel (TVA 4366/4367 inversées, CNSS sur 431, salaires sur 661…) ;
 * - remplace, dans chaque cabinet, les comptes non personnalisés par le plan officiel (les comptes personnalisés sont conservés).
 */
return new class extends Migration
{
    /** Ancien compte => compte du plan officiel. Appliqué en une seule passe (les permutations comme 4366 ↔ 4367 sont sûres). */
    public const CORRESPONDANCE = [
        '161' => '1681',     // Emprunts → Autres emprunts
        '211' => '218',      // Immobilisations incorporelles → Autres immobilisations incorporelles
        '220' => '221',      // Terrains
        '221' => '222',      // Constructions
        '225' => '2282',     // Mobilier et matériel de bureau → Équipement de bureau
        '226' => '2282',     // Matériel informatique → Équipement de bureau
        '227' => '2281',     // Agencements et installations → Installations générales, agencements et aménagements divers
        '228' => '28',       // Amortissements des immobilisations
        '2281' => '2818', '2282' => '2822', '2283' => '2823', '2284' => '2824', '2285' => '28282', '2286' => '28282', '2287' => '28281',
        '371' => '37',       // Stocks de marchandises
        '421' => '425',      // Personnel, rémunérations dues
        '431' => '45311',    // CNSS
        '4365' => '432',     // Retenues à la source
        '4366' => '4367',    // TVA collectée
        '4367' => '4366',    // TVA déductible
        '4369' => '437',     // TFP et FOPROLOS à payer
        '661' => '640',      // Salaires
        '664' => '647',      // Charges sociales légales
        '681' => '6811',     // Dotations aux amortissements
        '706' => '705',      // Prestations de services
        '771' => '75',       // Produits financiers
    ];

    /** 671 « Impôts et taxes (timbre, TFP, FOPROLOS) » : 661 TFP/FOPROLOS dans les paies, 6654 droit de timbre ailleurs. */
    private const TAXES_PAIE = '661';
    private const TAXES_AUTRES = '6654';

    public function up(): void
    {
        DB::transaction(function () {
            $this->renumeroter(self::CORRESPONDANCE);
            $paie = DB::table('ecritures')->where('journal', 'PAI')->pluck('id');
            DB::table('ecriture_lignes')->where('compte', '671')->whereIn('ecriture_id', $paie)->update(['compte' => self::TAXES_PAIE]);
            DB::table('ecriture_lignes')->where('compte', '671')->update(['compte' => self::TAXES_AUTRES]);
            DB::table('factures_recues')->where('compte', '671')->update(['compte' => self::TAXES_AUTRES]);

            foreach (DB::table('cabinets')->pluck('id') as $cab) {
                DB::table('comptes')->where('cabinet_id', $cab)->where('personnalise', false)->delete();
                Compte::installerPlan((int) $cab);
            }
        });
    }

    public function down(): void
    {
        // Renumérotation inverse des écritures uniquement : le plan des cabinets reste le plan officiel.
        DB::transaction(function () {
            $paie = DB::table('ecritures')->where('journal', 'PAI')->pluck('id');
            DB::table('ecriture_lignes')->where('compte', self::TAXES_PAIE)->whereIn('ecriture_id', $paie)->update(['compte' => '671']);
            DB::table('ecriture_lignes')->where('compte', self::TAXES_AUTRES)->update(['compte' => '671']);
            DB::table('factures_recues')->where('compte', self::TAXES_AUTRES)->update(['compte' => '671']);
            // Retour approximatif : pour les regroupements (225 et 226 → 2282), le premier ancien compte est retenu.
            $this->renumeroter(collect(self::CORRESPONDANCE)->unique()->flip()->all());
        });
    }

    /** Renumérote les écritures et les factures reçues en une seule requête par table. */
    private function renumeroter(array $map): void
    {
        $cas = 'CASE compte '.implode(' ', array_fill(0, count($map), 'WHEN ? THEN ?')).' ELSE compte END';
        $liaisons = collect($map)->flatMap(fn ($n, $a) => [(string) $a, (string) $n])->all();
        foreach (['ecriture_lignes', 'factures_recues'] as $table) {
            DB::table($table)->whereIn('compte', array_map('strval', array_keys($map)))->update(['compte' => DB::raw($this->lier($cas, $liaisons))]);
        }
    }

    /** Injecte les numéros (chiffres uniquement) dans l'expression CASE. */
    private function lier(string $sql, array $valeurs): string
    {
        foreach ($valeurs as $v) {
            if (! ctype_digit($v)) throw new InvalidArgumentException("Numéro de compte invalide : $v");
            $sql = preg_replace('/\?/', "'$v'", $sql, 1);
        }
        return $sql;
    }
};
