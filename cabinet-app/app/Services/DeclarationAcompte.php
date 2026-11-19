<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Declaration;

/**
 * Déclaration de l'acompte provisionnel (التصريح بالقسط الاحتياطي, modèle DGI).
 *
 * Période « AAAA-An » (n = 1, 2 ou 3). Acompte (I) = 30 % de l'impôt théorique calculé sur la base de l'impôt de l'année précédente
 * (résultat fiscal + moins-values − plus-values, plus-values exclues car non récurrentes) ; déductions (II) : excédents, retenues, avances, excédent de l'acompte précédent ;
 * reste III = I − II (dû ou excédent), plus les pénalités.
 */
class DeclarationAcompte
{
    public const ACTES = ['0' => 'Spontanée', '1' => 'Régularisation', '2' => 'Rectificative', '3' => 'Taxation d’office'];

    /** Rubriques : code => [libellé, libellé arabe, nature (m montant, r résultat signé, c calculé)]. */
    public const LIGNES = [
        'base' => ['Base de l’impôt sur les revenus ou bénéfices de l’année précédente', 'قاعدة احتساب الضريبة على الدخل أو الضريبة على الشركات بعنوان مداخيل أو أرباح السنة الفارطة', 'r'],
        'pv' => ['Plus-values (cession d’immobilisations et de titres)', 'القيمة الزائدة', 'm'],
        'mv' => ['Moins-values (cession d’immobilisations et de titres)', 'القيمة الناقصة', 'm'],
        'base_th' => ['Base de l’impôt théorique de calcul des acomptes', 'قاعدة احتساب الضريبة النظرية قاعدة احتساب الأقساط الاحتياطية', 'c'],
        'impot_th' => ['Impôt théorique, base de calcul des acomptes (au moins le minimum d’impôt)', 'الضريبة النظرية قاعدة احتساب الأقساط الاحتياطية', 'm'],
        'I' => ['30 % de l’impôt théorique (I)', '30% من الضريبة النظرية (I)', 'c'],
        'exc_annuel' => ['Excédent d’impôt de la déclaration des revenus ou bénéfices de l’année précédente', 'فائض الضريبة الناتج عن التصريح بعنوان مداخيل أو أرباح السنة الفارطة', 'm'],
        'rs' => ['Retenues à la source', 'المبالغ المخصومة من المورد', 'm'],
        'av_imp' => ['Avance de 10 % sur les produits importés', 'التسبقة بنسبة 10% على المنتجات الموردة', 'm'],
        'av_1pc' => ['Avance de 1 % sur les ventes des industriels et grossistes (BNC au forfait)', 'التسبقة بنسبة 1% الموظفة على بيوعات المؤسسات الصناعية ومؤسسات تجارة الجملة إلى الأشخاص الطبيعيين الخاضعين للضريبة على الدخل على أساس قاعدة تقديرية في صنف أرباح المهن غير التجارية', 'm'],
        'exc_prec' => ['Excédent de l’acompte provisionnel précédent', 'فائض القسط الاحتياطي السابق', 'm'],
        'paye' => ['Montant de l’acompte payé (déclarations rectificatives)', 'مبلغ القسط المدفوع', 'm'],
        'penalites' => ['Pénalités de retard', 'الخطايا', 'm'],
    ];

    /** Rubriques de la déduction (II). */
    public const DEDUCTIONS = ['exc_annuel', 'rs', 'av_imp', 'av_1pc', 'exc_prec', 'paye'];

    /** Année et numéro de l'acompte d'une période « AAAA-An ». */
    public static function periode(string $p): array
    {
        return preg_match('/^(\d{4})-A([1-3])$/', $p, $m) ? [(int) $m[1], (int) $m[2]] : [(int) now()->year, 1];
    }

    public static function libelle(string $p): string
    {
        [$an, $n] = self::periode($p);
        return ['1' => '1er', '2' => '2e', '3' => '3e'][$n].' acompte provisionnel '.$an;
    }

    /** Acompte à déclarer par défaut : le prochain à échéance (juin, septembre, décembre). */
    public static function periodeParDefaut(): string
    {
        $m = now()->month;
        return now()->year.'-A'.($m <= 6 ? 1 : ($m <= 9 ? 2 : 3));
    }

    public static function n(array $f, string $k): float
    {
        return (float) ($f[$k] ?? 0);
    }

    public static function calcul(array $f): array
    {
        $n = fn ($k) => self::n($f, $k);
        $o['base_th'] = r3($n('base') + $n('mv') - $n('pv'));          // note (4) du modèle : base + moins-values − plus-values
        // Impôt théorique : taux de l'IS ou barème de l'IR appliqué à la base théorique, sans descendre sous le minimum d'impôt (note 5) ;
        // un montant saisi l'emporte.
        $b = max(0, $o['base_th']);
        $o['impot_calc'] = match ($f['regime'] ?? null) {
            'is' => r3(max($b * (float) ($f['taux'] ?? 15) / 100, $n('min'))),
            'ir' => r3(max(DeclarationAnnuelle::bareme(DeclarationAnnuelle::arrondiImposable($b)), $n('min'))),
            default => 0.0,
        };
        $o['impot_th'] = isset($f['impot_th']) && $f['impot_th'] !== '' ? r3($n('impot_th')) : $o['impot_calc'];
        $o['I'] = r3($o['impot_th'] * 0.30);
        $o['II'] = r3(array_sum(array_map($n, self::DEDUCTIONS)));
        $o['III'] = r3($o['I'] - $o['II']);
        $o['penalites'] = r3($n('penalites'));
        $o['tot'] = r3(max(0, $o['III']) + $o['penalites']);
        $o['excedent'] = r3(max(0, -$o['III']));
        return $o;
    }

    /**
     * Pré-remplissage depuis la déclaration annuelle de l'année précédente (base, impôt théorique avec le minimum, excédent)
     * et, pour les 2e et 3e acomptes, depuis l'acompte précédent (excédent reporté).
     */
    public static function preremplir(Client $c, Declaration $d): string
    {
        [$an, $num] = self::periode($d->periode);
        $f = (array) ($d->champs ?? []);
        $msg = [];
        $ann = Declaration::where(['client_id' => $c->id, 'type' => 'ann', 'periode' => (string) ($an - 1)])->first();
        if ($ann) {
            $g = DeclarationAnnuelle::champs($ann->champs ?? [], $c, $an - 1);
            if (DeclarationAnnuelle::modele($c) === 'is') {
                $C = DeclarationAnnuelle::calculIs($g);
                $f = array_merge($f, ['regime' => 'is', 'taux' => $C['taux'], 'min' => $C['min'], 'base' => (float) ($g['benef_net'] ?? $g['res_fiscal'] ?? 0), 'exc_annuel' => $C['excedent']]);
            } else {
                $C = DeclarationAnnuelle::calculIr($g);
                $f = array_merge($f, ['regime' => 'ir', 'min' => $C['min'], 'base' => $C['rni'], 'exc_annuel' => r3(max(0, -$C['solde']))]);
            }
            unset($f['impot_th']);     // recalculé sur la base théorique
            $msg[] = 'déclaration annuelle '.($an - 1);
        }
        if ($num > 1) {
            $prev = Declaration::where(['client_id' => $c->id, 'type' => 'acp', 'periode' => $an.'-A'.($num - 1)])->first();
            if ($prev) {
                $f['exc_prec'] = self::calcul($prev->champs ?? [])['excedent'];
                $f['exc_annuel'] = 0;      // déjà imputé sur l'acompte précédent
                $msg[] = 'acompte n° '.($num - 1);
            }
        }
        $d->champs = $f;
        return $msg ? 'Pré-remplissage depuis : '.implode(', ', $msg).'. Vérifiez les retenues à la source et les avances de la période.'
            : 'Aucune déclaration annuelle '.($an - 1).' enregistrée : saisissez la base et l’impôt théorique.';
    }
}
