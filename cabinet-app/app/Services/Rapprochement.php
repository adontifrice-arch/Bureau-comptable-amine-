<?php

namespace App\Services;

use App\Models\EcritureLigne;
use App\Models\ReleveLigne;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * État de rapprochement bancaire d'un compte de banque à une date d'arrêté.
 *
 * Une ligne du compte est « pointée » (vue sur le relevé) si elle provient d'un relevé importé (écriture rb:…)
 * ou si elle a été pointée à une date antérieure ou égale à l'arrêté. Le rapprochement :
 *   solde comptable + encaissements du relevé non comptabilisés − décaissements du relevé non comptabilisés
 * = solde du relevé + débits comptables non pointés (remises non encore créditées) − crédits comptables non pointés (chèques non encaissés).
 * Les montants du compte sont vus de l'entreprise : solde débiteur = avoir en banque.
 */
class Rapprochement
{
    /** Comptes de banque du dossier : comptes 53x et 54x mouvementés, et le compte de banque du paramétrage. */
    public static function comptes(int $client): array
    {
        $c = DB::table('ecriture_lignes')->join('ecritures', 'ecritures.id', '=', 'ecriture_lignes.ecriture_id')
            ->where('ecritures.client_id', $client)->whereNull('ecritures.deleted_at')
            ->where(fn ($q) => $q->where('ecriture_lignes.compte', 'like', '53%')->orWhere('ecriture_lignes.compte', 'like', '54%'))
            ->distinct()->pluck('ecriture_lignes.compte')->map(fn ($x) => (string) $x)->all();
        $defaut = Saisie::normaliser((string) Saisie::banque($client)['compte'], Saisie::longueur($client));
        $c = array_values(array_unique(array_merge([$defaut], $c)));
        sort($c, SORT_STRING);
        return $c;
    }

    /** Solde du relevé saisi pour ce compte à cette date (retenu d'une fois sur l'autre). */
    public static function soldeReleve(int $client, string $compte, string $date): ?float
    {
        $s = Setting::get('rapprochement.'.$client, [])[$compte][$date] ?? null;
        return $s === null ? null : (float) $s;
    }

    public static function enregistrerSoldeReleve(int $client, string $compte, string $date, float $solde): void
    {
        $all = (array) Setting::get('rapprochement.'.$client, []);
        $all[$compte][$date] = round($solde, 3);
        Setting::put('rapprochement.'.$client, $all);
    }

    /** Lignes du compte de banque jusqu'à la date, avec leur état de pointage à cette date. */
    private static function lignes(int $client, string $compte, string $date)
    {
        return EcritureLigne::query()->join('ecritures', 'ecritures.id', '=', 'ecriture_lignes.ecriture_id')
            ->where('ecritures.client_id', $client)->whereNull('ecritures.deleted_at')
            ->where('ecriture_lignes.compte', $compte)->whereDate('ecritures.date', '<=', $date)
            ->orderBy('ecritures.date')->orderBy('ecritures.id')
            ->get(['ecriture_lignes.id', 'ecriture_lignes.debit', 'ecriture_lignes.credit', 'ecriture_lignes.pointe_le',
                'ecritures.id as ecriture_id', 'ecritures.date', 'ecritures.journal', 'ecritures.libelle', 'ecritures.source'])
            ->map(function ($l) use ($date) {
                $releve = str_starts_with((string) $l->source, 'rb:');
                $pointeLe = $l->pointe_le ? substr((string) $l->pointe_le, 0, 10) : null;
                return ['id' => $l->id, 'ecriture_id' => $l->ecriture_id, 'date' => substr((string) $l->date, 0, 10), 'journal' => $l->journal, 'libelle' => $l->libelle,
                    'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'pointe' => $releve || ($pointeLe && $pointeLe <= $date), 'releve' => $releve,
                    'pointe_le' => $pointeLe];
            });
    }

    public static function etat(int $client, string $compte, string $date, ?float $soldeReleve): array
    {
        $L = self::lignes($client, $compte, $date);
        $soldeCompta = r3($L->sum('debit') - $L->sum('credit'));
        $nonPointees = $L->where('pointe', false)->values();
        // Opérations du relevé (de ce compte) à comptabiliser à la date d'arrêté ; les lignes ignorées sont déjà en comptabilité
        $R = ReleveLigne::with('releve')->where('client_id', $client)->where('statut', 'att')->whereDate('date', '<=', $date)->orderBy('date')->get()
            ->filter(fn ($r) => Saisie::normaliser((string) ($r->releve?->compte_banque ?? $compte), Saisie::longueur($client)) === $compte)->values();

        $relEnc = r3($R->sum('credit')); $relDec = r3($R->sum('debit'));          // point de vue de la banque : crédit = encaissement
        $nonPD = r3($nonPointees->sum('debit')); $nonPC = r3($nonPointees->sum('credit'));
        $comptaAjuste = r3($soldeCompta + $relEnc - $relDec);
        $banqueAjuste = $soldeReleve === null ? null : r3($soldeReleve + $nonPD - $nonPC);

        return [
            'compte' => $compte, 'date' => $date, 'soldeCompta' => $soldeCompta, 'soldeReleve' => $soldeReleve,
            'nonPointees' => $nonPointees, 'pointees' => $L->where('pointe', true)->where('releve', false)->values(),
            'releveAtt' => $R, 'relEnc' => $relEnc, 'relDec' => $relDec, 'nonPD' => $nonPD, 'nonPC' => $nonPC,
            'comptaAjuste' => $comptaAjuste, 'banqueAjuste' => $banqueAjuste,
            'ecart' => $banqueAjuste === null ? null : r3($comptaAjuste - $banqueAjuste),
        ];
    }

    /** Pointe (date) ou dépointe (null) des lignes du compte de banque du dossier. */
    public static function pointer(int $client, string $compte, array $ids, ?string $date): int
    {
        $ok = EcritureLigne::query()->join('ecritures', 'ecritures.id', '=', 'ecriture_lignes.ecriture_id')
            ->where('ecritures.client_id', $client)->where('ecriture_lignes.compte', $compte)->whereIn('ecriture_lignes.id', array_map('intval', $ids))
            ->pluck('ecriture_lignes.id');
        return EcritureLigne::whereIn('id', $ok)->update(['pointe_le' => $date]);
    }
}
