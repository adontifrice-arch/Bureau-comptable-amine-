<?php

namespace App\Services;

use App\Models\Client;

/**
 * Déclaration annuelle (modèles DGI) : impôt sur les sociétés (personnes morales, التصريح بالضريبة على الشركات)
 * ou impôt sur le revenu (personnes physiques, التصريح بالضريبة على دخل الأشخاص الطبيعيين).
 *
 * Valeurs dans Declaration::champs[code] ; tableaux (cadres juridiques, réinvestissements, acomptes…) dans champs[tableau][i][colonne].
 * Résultats signés (bénéfice positif, perte négative). Les rubriques calculées ne sont pas saisies.
 */
class DeclarationAnnuelle
{
    public const ACTES = ['0' => 'Spontanée', '1' => 'Régularisation', '2' => 'Rectificative', '3' => 'Taxation d’office'];

    public const TAUX_IS = [40, 35, 20, 15, 10];

    /** Modèle applicable au client : IS pour une personne morale, IR pour une personne physique. */
    public static function modele(Client $c): string
    {
        return $c->estPhysique() ? 'ir' : 'is';
    }

    // ================= Impôt sur les sociétés =================

    /** Cases « impôt ou taxe payé sur la base de cette déclaration ». */
    public const IMPOTS_IS = [
        'is' => ['Impôt sur les sociétés', 'الضريبة على الشركات'],
        'visite' => ['Droit de visite', 'معلوم الزيارة'],
        'fonds' => ['Taxe au profit du fonds d’encouragement des salariés', 'المعلوم على صندوق ترغيب الأجراء'],
        'etab' => ['Impôt sur les bénéfices des établissements stables et bénéfices distribués à l’étranger', 'الضريبة على أرباح المنشآت الدائمة التونسية والضريبة على الأرباح الموزعة بالخارج من قبل الشركات غير المقيمة التي لها منشآت دائمة بتونس'],
    ];

    /** Rubriques saisies : section => [code => [libellé, libellé arabe, nature]] ; nature : m montant, r résultat signé, t texte, d date, x oui/non. */
    public const IS = [
        'I' => ['I- Données pour la détermination de l’impôt', 'معطيات لتوظيف الضريبة', [
            'stk_deb' => ['Stock au début de l’exercice', 'قيمة المخزون أوّل السنة المالية', 'm'],
            'stk_fin' => ['Stock à la fin de l’exercice', 'قيمة المخزون آخر السنة المالية', 'm'],
            'achats' => ['Achats de l’exercice', 'قيمة الشراءات خلال السنة المالية', 'm'],
            'ca_loc' => ['Chiffre d’affaires local (hors taxes)', 'رقم المعاملات المحلي (خال من الأداءات)', 'm'],
            'ca_exp' => ['Chiffre d’affaires à l’exportation', 'رقم المعاملات عند التصدير', 'm'],
            'ca_ttc' => ['Chiffre d’affaires global toutes taxes comprises', 'رقم المعاملات الجملي بما في ذلك الأداءات', 'm'],
            'ca_srv' => ['Chiffre d’affaires HT des services et activités non commerciales', 'رقم المعاملات الجملي خال من الأداءات المتأتي من أنشطة الخدمات ومن الأنشطة غير التجارية', 'm'],
            'ca_conso' => ['Chiffre d’affaires HT de la consommation sur place', 'رقم المعاملات الجملي خال من الأداءات المتأتي من أنشطة الاستهلاك على عين المكان', 'm'],
            'subv' => ['Montant des subventions', 'مبلغ المنح', 'm'],
            'res_compta' => ['Résultat comptable (bénéfice ou perte)', 'النتيجة المحاسبية', 'r'],
            'res_fiscal' => ['Résultat fiscal (bénéfice ou perte)', 'النتيجة الجبائية', 'r'],
        ]],
        'IV' => ['IV- Bénéfices et revenus non imposables', 'الأرباح والمحاصيل غير الخاضعة للضريبة', [
            'ni_div' => ['Bénéfices distribués par des sociétés soumises à l’IS en Tunisie, revenus des parts de fonds communs', 'الأرباح الموزعة من قبل الشركات الخاضعة للضريبة على الشركات بتونس وكذلك مداخيل حصص الصناديق المشتركة للتوظيف في الأوراق المالية ومداخيل صناديق المساعدة على الانطلاق والصناديق المشتركة للتوظيف في رأس مال تنمية', 'm'],
            'ni_etr' => ['Bénéfices réalisés par des établissements situés à l’étranger', 'الأرباح المحققة في إطار منشآت موجودة بالخارج', 'm'],
            'ni_nouv' => ['Bénéfices des nouvelles entreprises (déclaration d’investissement 2018 à 2025)', 'الأرباح المحققة من قبل المؤسسات المحدثة والمتحصلة على شهادة إيداع تصريح بالاستثمار خلال سنوات 2018 و2019 و2020 و2024 و2025', 'm'],
            'ni_startup' => ['Bénéfices des startups', 'الأرباح المحققة من قبل المؤسسات الناشئة', 'm'],
            'ni_ahl' => ['Bénéfices des sociétés citoyennes (أهلية)', 'الأرباح المحققة من قبل الشركات الأهلية', 'm'],
            'ni_zones' => ['Zones industrielles agréées avant le 1er avril 2017', 'مؤسسات تقوم بإنجاز مناطق صناعية متحصلة على موافقة اللجنة العليا للاستثمار قبل غرة أفريل 2017 (الفقرة 2 من الفصل 32 من القانون عدد 71 لسنة 2016 المؤرخ في 30 سبتمبر 2016 المتعلق بقانون الاستثمار.)', 'm'],
            'ni_inv' => ['Investissements d’importance nationale ou zones frontalières agréés avant le 1er avril 2017', 'مؤسسات تقوم باستثمارات ذات أهمية خاصة بالنسبة إلى الاقتصاد الوطني أو بالنسبة إلى المناطق الحدودية متحصلة على موافقة اللجنة العليا للاستثمار قبل غرة أفريل 2017 (الفقرة 2 من الفصل 32 من القانون عدد 71 لسنة 2016 المؤرخ في 30 سبتمبر 2016 المتعلق بقانون الاستثمار).', 'm'],
            'ni_aut' => ['Autres bénéfices non imposables', 'الأرباح الأخرى غير الخاضعة للضريبة', 'm'],
        ]],
        'V' => ['V- Calcul de l’impôt dû', 'احتساب الضريبة المستوجبة', [
            'taux' => ['Taux de l’impôt sur les sociétés (%)', 'النسبة', 't'],
            'benef_av' => ['Bénéfice fiscal avant déduction des avantages fiscaux', 'الربح الجبائي قبل طرح الامتيازات الجبائية', 'm'],
            'benef_net' => ['Bénéfice net imposable', 'الربح الصافي الخاضع للضريبة', 'm'],
            'ca_min_ttc' => ['Chiffre d’affaires local toutes taxes comprises', 'رقم المعاملات المحلي بما في ذلك الأداءات', 'm'],
            'ca_min' => ['Chiffre d’affaires local soumis au minimum d’impôt (TTC)', 'رقم المعاملات المحلي الخاضع للضريبة الدنيا بما في ذلك الأداءات', 'm'],
            'ca_homol' => ['… dont produits homologués à marge ≤ 6 % (TTC)', 'رقم المعاملات المحلي الخاضع للضريبة الدنيا بما في ذلك الأداءات والمتأتي من ترويج منتجات أو خدمات خاضعة للمصادقة الادارية للأسعار والتي لا يفوق هامش ربحها الخام 6%', 'm'],
            'ca_min_exp' => ['Chiffre d’affaires à l’exportation', 'رقم المعاملات المتأتي من التصدير', 'm'],
            'min_avant' => ['Minimum d’impôt compte tenu des avantages fiscaux', 'الضريبة الدنيا باعتبار الامتيازات الجبائية', 'm'],
            'exo_min' => ['Exonéré du minimum d’impôt (création, exonération totale)', '', 'x'],
        ]],
        'VII' => ['VII- Liquidation de l’impôt', 'التســوية', [
            'ded_rs' => ['Retenues à la source', 'الخصم من المـــورد', 'm'],
            'ded_acp' => ['Acomptes provisionnels payés (report du tableau VI)', 'الأقساط الاحتــياطية المدفوعة', 'c'],
            'ded_exc' => ['Excédents antérieurs', 'الفوائض السابقة', 'm'],
            'ded_imp' => ['Avance sur les produits importés', 'التسبقة على المنتجات الموردة', 'm'],
            'ded_alc' => ['Avance sur les ventes de boissons alcoolisées', 'التسبقة على بيوعات المشروبات الكحولية', 'm'],
            'ded_qp' => ['Quote-part de la société dans l’avance due par les sociétés de personnes', 'حصة الشركة في التسبقة المستوجبة على الشركات التي تخضع لنظام شركات الأشخاص وما شابهها', 'm'],
            'ded_aut' => ['Autres montants déductibles', 'مبالغ أخرى قابلة للطرح', 'm'],
            'restit' => ['Montant de l’impôt restitué (déclarations rectificatives)', 'مبلغ الضريبة على الشركات المسترجع', 'm'],
        ]],
        'VIII' => ['VIII- Contributions au profit du compte de diversification des sources de la sécurité sociale', 'المساهمات لفائدة حساب تنويع مصادر الضمان الاجتماعي', [
            'cc_soumis' => ['Banques, assurances, opérateurs télécoms, concessionnaires automobiles : contribution de 4 % due', '', 'x'],
            'cc_exc' => ['Contribution conjoncturelle : excédent de la déclaration de l’année précédente', 'طرح فائض المساهمة الصادر عن تصريح السنة الفارطة (د)', 'm'],
            'css_exc' => ['Contribution sociale de solidarité : excédent de la déclaration de l’année précédente', 'طرح فائض المساهمة الاجتماعية التضامنية الصادر عن تصريح السنة الفارطة (د)', 'm'],
        ]],
        'IX' => ['IX- Droit de visite', 'معلوم الزيارة', [
            'visite' => ['Montant du droit de visite', 'المبلـــغ (د)', 'm'],
        ]],
        'X' => ['X- Taxe au profit du fonds d’encouragement des salariés (bénéfices non distribués)', 'المعلوم على صندوق ترغيب الأجـراء الذي لم يتم توزيعه', [
            'fonds_base' => ['Montant', 'المبلـــغ (د)', 'm'],
        ]],
        'XI' => ['XI- Impôt sur les bénéfices des établissements stables', 'الضريبة على أرباح المنشآت الدائمة التونسية والضريبة على الأرباح الموزعة بالخارج من قبل الشركات غير المقيمة التي لها منشآت دائمة بتونس', [
            'etab_nom' => ['Raison sociale de la société non résidente', 'الاسم الاجتماعي للشركة غير المقيمة', 't'],
            'etab_pays' => ['Pays de résidence', 'بــلد الإقامة', 't'],
            'etab_paradis' => ['Société résidente d’un paradis fiscal', 'شركة مقيمة ببلد أو إقليم ذي نظام جبائي تفاضلي', 'x'],
            'etab_benef' => ['Bénéfices réalisés par l’établissement stable tunisien', 'مبلـــغ الأرباح المحققة من قبل المنشآت الدائمة التونسية (د)', 'm'],
            'etab_distr' => ['Bénéfices distribués à l’étranger', 'مبلـــغ الأرباح الموزعة بالخارج (د)', 'm'],
        ]],
        'XII' => ['XII- Synthèse : déductions (rectificatives) et pénalités', 'الخلاصة', [
            'pen_is' => ['Pénalités de retard – impôt sur les sociétés', '', 'm'],
            'pen_exo' => ['Pénalités dues sur les bénéfices exonérés non déclarés', 'الخطايا المستوجبة على الأرباح المعفاة والأرباح التي خضعت لخصم من المورد تحرري غير المصرّح بها', 'm'],
            'pen_aut' => ['Pénalités de retard – autres impôts et contributions', '', 'm'],
            'paye' => ['Impôt déjà payé (déclarations rectificatives)', 'الطرح (الضريبة المدفوعة)', 'm'],
        ]],
        'XIII' => ['XIII- Renseignements complémentaires', 'إرشادات تكميلية', [
            'compta_nom' => ['Comptable ou expert-comptable', 'اسم ولقب المحاسب أو الخبير المحاسب أو الاسم الاجتماعي', 't'],
            'compta_salarie' => ['Le comptable est salarié de la société', 'أجيرا', 'x'],
            'compta_adr' => ['Adresse du comptable', 'العنوان', 't'],
            'compta_mf' => ['Matricule fiscal du comptable', 'المعرف الجبائي', 't'],
            'conseil_nom' => ['Conseiller fiscal', 'اسم ولقب المستشار الجبائي أو الاسم الاجتماعي', 't'],
            'conseil_adr' => ['Adresse du conseiller fiscal', 'العنوان', 't'],
            'conseil_mf' => ['Matricule fiscal du conseiller fiscal', 'المعرف الجبائي', 't'],
            'cac_nom' => ['Commissaire aux comptes', 'اسم ولقب مراقب الحسابات أو الاسم الاجتماعي', 't'],
            'cac_adr' => ['Adresse du commissaire aux comptes', 'العنوان', 't'],
            'cac_mf' => ['Matricule fiscal du commissaire aux comptes', 'المعرف الجبائي', 't'],
            'capital' => ['Capital social (D)', 'مبلـغ رأس المـــال الاجتماعي بالدينـــار', 'm'],
            'nominal' => ['Valeur nominale de l’action ou de la part (D)', 'قيمـــة الســـهم أو المنـــاب الاجتماعي بالـدينـــار', 'm'],
            'nb_parts' => ['Nombre d’actions ou de parts sociales', 'عـدد الأســـهم أو المنـــابات الاجتماعية', 't'],
        ]],
    ];

    /** Tableaux à lignes multiples : clé => [titre, titre arabe, colonnes [clé => [libellé, libellé arabe, nature]], nombre de lignes]. */
    public const TABLEAUX_IS = [
        'cadres' => ['Cadre juridique et déclaration d’investissement', 'الإطار القانوني', [
            'code' => ['Code', 'الرمز', 't'], 'cadre' => ['Cadre juridique', 'الإطار', 't'], 'struct_code' => ['Code de la structure', 'الرمز', 't'],
            'struct' => ['Structure de dépôt de la déclaration', 'الإطار', 't'], 'num' => ['N° de la déclaration', 'العدد', 't'], 'date' => ['Date', 'التاريخ', 'd'], 'entree' => ['Entrée en activité effective', 'تاريخ الدخول في طور النشاط الفعلي', 'd']], 3],
        'reinv' => ['II- Bénéfices déduits au titre de l’exploitation ou du réinvestissement', 'الأربـــاح التي تــــمّ طـــرحـــها بعنوان الاستغلال أو إعادة الاستثمار', [
            'code' => ['Code', 'الرمز', 't'], 'cadre' => ['Cadre légal de la déduction', 'الإطار القانوني للطرح', 't'], 'investi' => ['Montant investi', 'المبالغ المستثمرة (د)', 'm'],
            'mf' => ['Matricule fiscal de l’entreprise bénéficiaire', 'المعرف الجبائي', 't'], 'nom' => ['Raison sociale', 'الاسم الاجتماعي', 't'], 'deduit' => ['Montant déduit', 'المبالغ المطروحة (د)', 'm']], 3],
        'exoneres' => ['III- Entreprises exonérées de l’impôt sur les sociétés', 'بيانات تتعلق بالمؤسسات المعفاة من الضريبة على الشركات', [
            'code' => ['Code', 'الرمز', 't'], 'cadre' => ['Cadre légal', 'الإطار القانوني', 't'], 'ca' => ['Chiffre d’affaires brut', 'رقم المعاملات الخام (د)', 'm'],
            'res_compta' => ['Résultat comptable', 'النتيجة المحاسبية (د)', 'r'], 'res_fiscal' => ['Résultat fiscal', 'النتيجة الجبائية (د)', 'r'], 'rcm' => ['Revenus de capitaux mobiliers (retenue libératoire)', 'مداخيل رؤوس الأموال المنقولة التي خضعت لخصم من المورد نهائي وغير قابل للإرجاع (د)', 'm']], 1],
        'acomptes' => ['VI- Acomptes provisionnels payés', 'مراجع دفع الأقساط الاحتياطية', [
            'recette' => ['Recette des finances', 'القباضة المالية', 't'], 'date' => ['Date de la quittance', 'التاريخ', 'd'], 'num' => ['N° de quittance', 'العدد', 't'], 'montant' => ['Montant', 'المبلغ (د)', 'm']], 3],
        'banques' => ['Comptes bancaires ou postaux', 'أرقام الحسابات البنكية أو البريدية', [
            'banque' => ['Banque ou Office national des postes', 'المؤسسة البنكية أو الديوان الوطني للبريد', 't'], 'rib' => ['N° de compte', 'رقم الحساب البنكي أو البريدي', 't'], 'ouverture' => ['Date d’ouverture', 'تاريخ فتح الحساب', 'd']], 3],
        'associes' => ['Répartition du capital entre les associés', 'بيانات حول توزيع رأس المال بين الشركاء', [
            'nom' => ['Nom ou raison sociale', 'الاسم واللقب أو الاسم الاجتماعي', 't'], 'adr' => ['Adresse', 'العنوان', 't'], 'code' => ['Code identifiant', 'الرمز', 't'],
            'id' => ['CIN ou matricule fiscal', 'المعرف', 't'], 'parts' => ['Nombre d’actions ou parts', 'عدد الأسهم أو المنابات الاجتماعية', 't'], 'dividendes' => ['Bénéfices distribués l’an passé (net)', 'المبلغ الصافي للأرباح الموزعة خلال السنة الماضية على كل شريك', 'm']], 4],
    ];

    /** Champs d'entête enregistrés (e[...]) par modèle. */
    public const ENTETE = [
        'is' => ['acte', 'nature', 'ouv', 'clo', 'activite', 'forme', 'nationalite', 'privilege'],
        'ir' => ['acte', 'ouv', 'clo', 'activite', 'privilege', 'nom', 'prenom', 'naissance', 'nationalite', 'pays_naissance', 'telephone', 'situation', 'enf_handicap', 'enf1', 'enf2', 'enf3', 'enf4', 'id_code', 'id_num', 'id_date'],
    ];

    /** [rubriques, tableaux, cases des impôts, champs d'entête] d'un modèle. */
    public static function definitions(string $mod): array
    {
        return $mod === 'is' ? [self::IS, self::TABLEAUX_IS, self::IMPOTS_IS, self::ENTETE['is']] : [self::IR, self::TABLEAUX_IR, self::IMPOTS_IR, self::ENTETE['ir']];
    }

    /** Section de l'écran sous laquelle chaque tableau est affiché. */
    public const PLACE = [
        'is' => ['cadres' => 'I', 'reinv' => 'IV', 'exoneres' => 'IV', 'acomptes' => 'VII', 'banques' => 'XIII', 'associes' => 'XIII'],
        'ir' => ['cadres' => 'bic', 'branches' => 'bic', 'pertes' => 'ded', 'ded_act' => 'ded', 'reinv' => 'ded', 'enfants' => 'ded', 'acomptes' => 'liq', 'banques' => 'contrib'],
    ];

    public const LIB_SYNTHESE = [
        'is' => 'Impôt sur les sociétés', 'exo' => 'Pénalités sur bénéfices exonérés non déclarés', 'visite' => 'Droit de visite',
        'fonds' => 'Fonds d’encouragement des salariés', 'etab' => 'Impôt des établissements stables', 'cc' => 'Contribution conjoncturelle (banques, assurances…)',
        'css' => 'Contribution sociale de solidarité',
        'ir' => 'Impôt sur le revenu',
    ];

    /**
     * Champs de la déclaration : anciens champs du formulaire simplifié convertis, valeurs par défaut de l'entête
     * (exercice civil, activité, forme juridique, capital et associés de la fiche client).
     */
    public static function champs(array $f, Client $c, int $annee): array
    {
        if (array_intersect(array_keys($f), ['rc', 'rei', 'ded', 'acp', 'rsub', 'crep']) && ! isset($f['res_compta'])) {
            $rf = r3((float) ($f['rc'] ?? 0) + (float) ($f['rei'] ?? 0) - (float) ($f['ded'] ?? 0));
            $f += ['res_compta' => $f['rc'] ?? null, 'res_fiscal' => $rf, 'benef_net' => max(0, $rf), 'benef_av' => max(0, $rf), 'ca_loc' => $f['ca'] ?? null,
                'ded_rs' => $f['rsub'] ?? null, 'ded_exc' => $f['crep'] ?? null, 'min_avant' => $f['min'] ?? null];
            if (! empty($f['acp']) && empty($f['acomptes'])) $f['acomptes'] = [['montant' => $f['acp']]];
        }
        $f += ['acte' => '0', 'nature' => 'd', 'ouv' => "$annee-01-01", 'clo' => "$annee-12-31", 'activite' => $c->paie_activite, 'forme' => $c->forme,
            'nationalite' => 'tunisienne', 'taux' => 15, 'capital' => $c->capital ?: null, 'nominal' => $c->valeur_nominale ?: null, 'nb_parts' => $c->nb_parts ?: null];
        if ($c->estPhysique()) $f += ['nom' => $c->nom, 'telephone' => $c->telephone, 'id_code' => '2'] + self::famille($c);
        if (empty($f['associes']) && ! $c->estPhysique()) {
            $f['associes'] = $c->associes()->get()->map(fn ($a) => ['nom' => $a->nom, 'adr' => $a->adresse, 'code' => $a->cin ? '1' : '', 'id' => $a->cin, 'parts' => $a->parts, 'dividendes' => ''])->all();
        }
        return $f;
    }

    /** Valeur numérique d'une rubrique. */
    public static function n(array $f, string $k): float
    {
        return (float) ($f[$k] ?? 0);
    }

    /** Somme d'une colonne d'un tableau. */
    public static function somme(array $f, string $tab, string $col): float
    {
        return r3(array_sum(array_map(fn ($l) => (float) ($l[$col] ?? 0), (array) ($f[$tab] ?? []))));
    }

    /** Calcul de la déclaration d'impôt sur les sociétés. */
    public static function calculIs(array $f): array
    {
        $n = fn ($k) => self::n($f, $k);
        $taux = (float) ($f['taux'] ?? 15);
        $o = ['taux' => $taux];
        $o['ni_tot'] = r3(array_sum(array_map($n, array_keys(self::IS['IV'][2]))));
        $o['reinv_tot'] = self::somme($f, 'reinv', 'deduit');
        $o['impot_calc'] = r3(max(0, $n('benef_net')) * $taux / 100);

        // Minimum d'impôt : 0,2 % du chiffre d'affaires local TTC (0,1 % pour l'IS à 10 % ou les produits homologués à marge ≤ 6 %),
        // avec un minimum de 500 D (300 D pour les sociétés à 10 % et les produits homologués) ; exonération possible (création, exonération totale).
        $ca = max(0, $n('ca_min')); $hom = min($ca, max(0, $n('ca_homol')));
        $min = $taux == 10 ? $ca * 0.001 : ($ca - $hom) * 0.002 + $hom * 0.001;
        $plancher = ($taux == 10 || ($hom > 0 && $hom >= $ca)) ? 300 : 500;
        $o['min_ca'] = ! empty($f['exo_min']) ? 0.0 : r3(max($min, $plancher));
        $o['min'] = $n('min_avant') > 0 ? r3(max($o['min_ca'], $n('min_avant'))) : $o['min_ca'];
        $o['is_du'] = r3(max($o['impot_calc'], $o['min']));

        // Liquidation : IV = I − II + III
        $o['acomptes'] = self::somme($f, 'acomptes', 'montant');
        $o['II'] = r3($n('ded_rs') + $o['acomptes'] + $n('ded_exc') + $n('ded_imp') + $n('ded_alc') + $n('ded_qp') + $n('ded_aut'));
        $o['IV'] = r3($o['is_du'] - $o['II'] + $n('restit'));

        // Contribution conjoncturelle (4 %, minimum 10 000 D) et contribution sociale de solidarité
        $b = max(0, $n('benef_net'));
        $o['cc_du'] = ! empty($f['cc_soumis']) ? r3(max($b * 0.04, 10000)) : 0.0;
        $o['cc_net'] = r3($o['cc_du'] - $n('cc_exc'));
        [$tx, $mn] = in_array((int) $taux, [40, 35], true) ? [4, 500] : ((int) $taux === 10 ? [3, 200] : [3, 400]);
        $exo = $b <= 0 && ($o['ni_tot'] > 0 || ! empty($f['exo_min']));
        $o['css_taux'] = $tx;
        $o['css_du'] = $exo ? 400.0 : r3(max($b * $tx / 100, $mn));
        $o['css_net'] = r3($o['css_du'] - $n('css_exc'));

        $o['fonds'] = r3($n('fonds_base') * 0.07);
        $o['etab_taux'] = ! empty($f['etab_paradis']) ? 25 : 10;
        $o['etab'] = r3($n('etab_distr') * $o['etab_taux'] / 100);

        // Synthèse : I (dû ou excédent), II (déjà payé, rectificatives), III = I − II, pénalités, total
        $L = ['is' => [$o['IV'], $n('paye'), $n('pen_is')], 'exo' => [0.0, 0.0, $n('pen_exo')], 'visite' => [$n('visite'), 0.0, 0.0], 'fonds' => [$o['fonds'], 0.0, 0.0],
            'etab' => [$o['etab'], 0.0, 0.0], 'cc' => [$o['cc_net'], 0.0, 0.0], 'css' => [$o['css_net'], 0.0, $n('pen_aut')]];
        $o['synthese'] = array_map(fn ($x) => ['I' => r3($x[0]), 'II' => r3($x[1]), 'III' => r3($x[0] - $x[1]), 'pen' => r3($x[2]), 'tot' => r3(max(0, $x[0] - $x[1]) + $x[2])], $L);
        $o['tot'] = r3(array_sum(array_column($o['synthese'], 'tot')));
        $o['excedent'] = r3(max(0, -$o['IV']));
        return $o;
    }

    /** Cases cochées de l'entête IS : enregistrées, sinon déduites des montants. */
    public static function impotsIs(array $f): array
    {
        if (isset($f['imp'])) return array_values(array_intersect(array_keys(self::IMPOTS_IS), (array) $f['imp']));
        $c = self::calculIs($f);
        return array_values(array_filter(['is', 'visite', 'fonds', 'etab'], fn ($k) => match ($k) { 'is' => true, 'visite' => self::n($f, 'visite') > 0, 'fonds' => $c['fonds'] > 0, 'etab' => $c['etab'] > 0 }));
    }

    // ================= Impôt sur le revenu des personnes physiques =================

    public const IMPOTS_IR = [
        'ir' => ['Impôt sur le revenu', 'الضريبة على الدخل'],
        'visite' => ['Droit de visite', 'معلوم الزيارة'],
        'fonds' => ['Taxe au profit du fonds d’encouragement des salariés (bénéfices non distribués)', 'المعلوم على صندوق ترغيب الأجراء الذي لم يقع توزيعه'],
    ];

    public const SITUATIONS = ['1' => ['Célibataire', 'أعزب (عزباء)'], '2' => ['Marié(e)', 'متزوج (ة)'], '3' => ['Divorcé(e)', 'مطلّق (ة)'], '4' => ['Veuf / veuve', 'أرمل (ة)']];

    /** Barème de l'impôt sur le revenu (revenus réalisés à partir du 1er janvier 2025) : [plafond de la tranche, taux %]. */
    public const BAREME = [[5000, 0], [10000, 15], [20000, 25], [30000, 30], [40000, 33], [50000, 36], [70000, 38], [INF, 40]];

    public const IR = [
        'bic' => ['Bénéfices industriels et commerciaux', 'الأرباح الصناعية والتجارية', [
            'bic_stk_deb' => ['a) Régime réel – stock au début de l’année', 'قيمة المخزون أوّل السنة', 'm'],
            'bic_stk_fin' => ['Stock à la fin de l’année', 'قيمة المخزون آخر السنة', 'm'],
            'bic_achats' => ['Achats de l’année', 'قيمة الشراءات خلال السنة', 'm'],
            'bic_ca_loc' => ['Chiffre d’affaires local hors taxes', 'رقم المعاملات المحلي خال من الأداءات', 'm'],
            'bic_ca_exp' => ['Chiffre d’affaires à l’exportation', 'رقم المعاملات عند التصدير', 'm'],
            'bic_ca_ttc' => ['Chiffre d’affaires global toutes taxes comprises', 'رقم المعاملات الجملي بما في ذلك الأداءات', 'm'],
            'bic_ca_srv' => ['Chiffre d’affaires HT des services', 'رقم المعاملات الجملي خال من الأداءات المتأتي من أنشطة الخدمات', 'm'],
            'bic_ca_conso' => ['Chiffre d’affaires HT de la consommation sur place', 'رقم المعاملات الجملي خال من الأداءات المتأتي من أنشطة الاستهلاك على عين المكان', 'm'],
            'bic_subv' => ['Subventions', 'مبلغ المنح', 'm'],
            'bic_res_compta' => ['Résultat comptable', 'النتيجة المحاسبية', 'r'],
            'bic_res_fiscal' => ['Résultat fiscal', 'النتيجة الجبائية', 'r'],
            'bic_qp' => ['b) Quote-part dans les sociétés de personnes (bénéfice ou perte) (I)', 'المناب', 'r'],
            'bic_pv' => ['Plus-value de cession des droits dans ces sociétés (II)', 'القيمة الزائدة', 'm'],
        ]],
        'bnc' => ['Bénéfices des professions non commerciales', 'أرباح المهن غير التجارية', [
            'bnc_ca_loc' => ['a) Régime réel – chiffre d’affaires local hors taxes', 'رقم المعاملات المحلي خال من الأداءات', 'm'],
            'bnc_ca_exp' => ['Chiffre d’affaires à l’exportation', 'رقم المعاملات عند التصدير', 'm'],
            'bnc_ca_ttc' => ['Chiffre d’affaires global toutes taxes comprises', 'رقم المعاملات الجملي بما في ذلك الأداءات', 'm'],
            'bnc_subv' => ['Subventions', 'مبلغ المنح', 'm'],
            'bnc_res_compta' => ['Résultat comptable', 'النتيجة المحاسبية', 'r'],
            'bnc_res_fiscal' => ['Résultat fiscal', 'النتيجة الجبائية', 'r'],
            'bnc_rec_loc' => ['b) Base forfaitaire – recettes des services locaux', 'المقابيض بعنوان الخدمات المحلية', 'm'],
            'bnc_rec_exp' => ['Recettes des services exportés', 'المقابيض بعنوان الخدمات المصدرة', 'm'],
            'bnc_rec_ttc' => ['Total des recettes brutes toutes taxes comprises', 'مجموع المقابيض الخام بما في ذلك الأداءات', 'm'],
            'bnc_forf_subv' => ['Subventions (base forfaitaire)', 'مبلغ المنح', 'm'],
            'bnc_qp' => ['c) Quote-part dans les sociétés de personnes (I)', 'المناب', 'r'],
            'bnc_pv' => ['Plus-value de cession des droits dans ces sociétés (II)', 'القيمة الزائدة', 'm'],
        ]],
        'agr' => ['Bénéfices des activités agricoles ou de pêche', 'أرباح الأنشطة الفلاحية أو الصيد البحري', [
            'agr_ca_loc' => ['a) Régime réel – chiffre d’affaires local', 'رقـم المـعـاملات المــحلي', 'm'],
            'agr_ca_exp' => ['Chiffre d’affaires à l’exportation', 'رقـم المــعاملات عـند التـصـدير', 'm'],
            'agr_ca_tot' => ['Chiffre d’affaires global', 'رقـم المــعاملات الجــملي', 'm'],
            'agr_subv' => ['Subventions', 'مبلغ المنح', 'm'],
            'agr_res_compta' => ['Résultat comptable', 'النتــيجة المــحاسبية', 'r'],
            'agr_res_fiscal' => ['Résultat fiscal', 'النتــيجة الجبائية', 'r'],
            'agr_rec' => ['b) Recettes − dépenses : recettes brutes', 'المقابيض الخـام', 'm'],
            'agr_stocks' => ['Stocks', 'المخزونـات', 'm'],
            'agr_dep' => ['Dépenses d’exploitation justifiées', 'طرح مصاريف الاستغلال المبرّرة', 'm'],
            'agr_subv2' => ['Subventions', 'مبلغ المنح', 'm'],
            'agr_forf' => ['c) Bénéfice fiscal selon les critères sectoriels', 'الربح الجبائي', 'm'],
            'agr_qp' => ['d) Quote-part dans les sociétés de personnes (I)', 'المناب', 'r'],
            'agr_pv' => ['Plus-value de cession des droits dans ces sociétés (II)', 'القيمة الزائدة', 'm'],
        ]],
        'fon' => ['Revenus fonciers', 'المداخيل العقارية', [
            'fon_ca' => ['a) Régime réel – chiffre d’affaires réalisé', 'رقـم المــعاملات المحقق', 'm'],
            'fon_res_compta' => ['Résultat comptable', 'النتيجة المحاسبية', 'r'],
            'fon_res_fiscal' => ['Résultat fiscal', 'النتيجة الجبائية', 'r'],
            'fon_bati' => ['b) Immeubles bâtis – recettes brutes (taxes comprises)', 'المقابيض الخــام المحققة (بما في ذلك الأداءات)', 'm'],
            'fon_bati_tax' => ['Taxe sur les immeubles bâtis et contribution au FNAH payées', 'المعلوم على العقارات المبنية والمساهمة لفائدة الصندوق الوطني لتحسين السكن اللذين تم دفعهما', 'm'],
            'fon_nb' => ['Terrains non bâtis – recettes brutes (taxes comprises)', 'المقابيض الخام المحققة (بما في ذلك الأداءات)', 'm'],
            'fon_nb_dep' => ['Dépenses justifiées', 'المصاريف المدعمة بوثائق', 'm'],
            'fon_nb_tax' => ['Taxe sur les terrains non bâtis effectivement payée', 'المعلوم على الأراضي غير المبنية المدفوع فعليا', 'm'],
            'fon_qp' => ['c) Quote-part dans les sociétés de personnes (I)', 'المناب', 'r'],
            'fon_pv' => ['Plus-value de cession des droits dans ces sociétés (II)', 'القيمة الزائدة', 'm'],
        ]],
        'sal' => ['Traitements, salaires, pensions et rentes viagères', 'المرتبات والأجور والجرايات والإيرادات العمرية', [
            'sal_brut' => ['a) Montant brut des traitements et salaires', 'المبلغ الجملي للمرتبات والأجور', 'm'],
            'sal_nat' => ['Avantages en nature', 'قيمة الامتيازات العينية', 'm'],
            'pen_brut' => ['b) Montant brut des pensions et rentes viagères', 'المبلغ الجملي للجرايات والإيرادات العمرية', 'm'],
            'pen_nat' => ['Avantages en nature', 'قيمة الامتيازات العينية', 'm'],
        ]],
        'rcm' => ['Revenus de valeurs mobilières et de capitaux mobiliers', 'مداخيل الأوراق المالية ورؤوس الأموال المنقولة', [
            'rcm_nd' => ['a) Revenus de valeurs mobilières non distribués officiellement', 'مداخيل الأوراق المالية غير الموزعة بصفة رسمية', 'm'],
            'rcm_cons' => ['Rémunérations des membres des conseils et comités', 'المكافآت والمنح المسندة لأعضاء المجالس والهيئات واللجان', 'm'],
            'rcm_vm_aut' => ['Autres rémunérations similaires', 'المكافآت الأخرى الشبيهة', 'm'],
            'rcm_ep_bq' => ['b) Intérêts des comptes spéciaux d’épargne – banques', 'المفتوحة لدى البنوك', 'm'],
            'rcm_ep_cent' => ['… – Caisse d’épargne nationale tunisienne', 'المفتوحة لدى صندوق الادخار الوطني التونسي', 'm'],
            'rcm_creances' => ['Intérêts des créances, dépôts et cautionnements', 'فوائض الديون وفوائض وتأجيرات الضمانات', 'm'],
            'rcm_titres' => ['Intérêts des titres participatifs', 'فوائد سندات المساهمة', 'm'],
            'rcm_oblig' => ['Intérêts des emprunts obligataires', 'فوائد القروض الرقاعية', 'm'],
            'rcm_bons' => ['Intérêts des bons de caisse', 'فوائد وصولات الإيداع', 'm'],
            'rcm_fcc' => ['Revenus des parts et boni de liquidation des fonds communs de créances', 'مداخيل الحصص وباقي التصفية للصندوق المشترك للديون', 'm'],
            'rcm_verts' => ['Intérêts des emprunts obligataires verts, sociaux et durables', 'فوائد القروض الرقاعية الخضراء والقروض الرقاعية المسؤولة اجتماعيا والقروض الرقاعية المستدامة', 'm'],
            'rcm_autres' => ['Autres intérêts et revenus', 'فوائد وعائدات أخرى', 'm'],
            'rcm_div' => ['c) Dividendes et revenus assimilés (montant brut)', 'حصص الأسهم والمداخيل المشابهة', 'm'],
        ]],
        'aut' => ['Autres revenus', 'مداخيل أخرى', [
            'etr_sal' => ['1. Revenus étrangers non imposés à l’étranger – a) traitements et salaires', 'المبلغ الجملي للمرتبات والأجور', 'm'],
            'etr_pen' => ['b) Pensions et rentes viagères', 'المبلغ الجملي للجرايات والإيرادات العمرية', 'm'],
            'etr_pen80' => ['Abattement de 80 % (montants transférés en Tunisie) au lieu de 25 %', 'طرح %80', 'x'],
            'etr_aut' => ['c) Autres revenus de source étrangère', 'مداخيل أخرى ذات مصدر أجنبي', 'm'],
            'jeux' => ['2. Gains des jeux de pari, de hasard et de loterie (II)', 'مداخيل محققة من ألعاب الرهان والحظ واليانصيب', 'm'],
            'dep_pers' => ['3. Revenus déterminés d’après les dépenses personnelles et l’accroissement du patrimoine (III)', 'المداخيل التي يتم ضبطها حسب النفقات الشخصية الظاهرة والجلية ونمو الثروة', 'm'],
        ]],
        'ded' => ['Déductions communes', 'تخفيضات مشتركة', [
            'ded_ep_bq' => ['Intérêts des comptes spéciaux d’épargne – banques (dans la limite de 6 000 D)', 'فوائد الحسابات الخاصة للادخار المفتوحة لدى البنوك في حدود 6.000 د', 'm'],
            'ded_ep_cent' => ['… – CENT (limite commune de 6 000 D)', 'فوائد الحسابات الخاصة للادخار المفتوحة لدى صندوق الادخار الوطني التونسي في حدود 6.000 د', 'm'],
            'ded_oblig' => ['Intérêts des emprunts obligataires (limite de 10 000 D)', 'فوائد القروض الرقاعية في حدود مبلغ سنوي يساوي 10.000 د', 'm'],
            'ded_bons' => ['Intérêts des bons du Trésor assimilables (limite commune de 10 000 D)', 'فوائد رقاع الخزينة القابلة للتنظير في حدود مبلغ سنوي يساوي 10.000 د', 'm'],
            'ded_rentes' => ['Rentes payées à titre obligatoire et gratuit', 'إيرادات مدفوعة بصفة وجوبية ومجانية', 'm'],
            'ded_verts' => ['Intérêts des emprunts obligataires verts, sociaux et durables (limite de 10 000 D)', 'فوائد القروض الرقاعية الخضراء والقروض الرقاعية المسؤولة اجتماعيا والقروض الرقاعية المستدامة في حدود 10.000 د', 'm'],
            'chef' => ['Chef de famille (300 D)', 'رئيس العائلة', 'x'],
            'parents' => ['Nombre de parents à charge (5 % du revenu net imposable, 450 D au plus par parent)', 'والدين في الكفالة', 't'],
            'ded_univ' => ['Remboursement des prêts universitaires (principal et intérêts)', 'إرجاع القروض الجامعية أصلا وفائدة', 'm'],
            'ded_coti' => ['Cotisations sociales des non-salariés', 'الاشتراكات المدفوعة من قبل العملة غير الأجراء المنخرطين بأحد الأنظمة القانونية للضمان الاجتماعي', 'm'],
            'ded_logement' => ['Intérêts des prêts pour l’acquisition ou la construction d’un logement (coût ≤ 200 000 D)', 'الفوائض المدفوعة بعنوان القروض المتعلقة باقتناء أو بناء مسكن واحد لا تتعدى كلفة اقتنائه أو بنائه 200.000 دينار دون اعتبار الأداء على القيمة المضافة وكذلك هامش الربح المدفوع في إطار عقود بيع مرابحة', 'm'],
        ]],
        'calc' => ['Calcul de l’impôt et minimum d’impôt', 'احتســـاب الضريبـــة', [
            'min_loc_02' => ['Minimum : chiffre d’affaires local TTC soumis à 0,2 %', 'الخاضع للضريبة الدنيا بنسبة 0.2%', 'm'],
            'min_loc_01' => ['Chiffre d’affaires local TTC soumis à 0,1 %', 'الخاضع للضريبة الدنيا بنسبة 0.1%', 'm'],
            'min_exp_02' => ['Chiffre d’affaires à l’export soumis à 0,2 %', 'الخاضع للضريبة الدنيا بنسبة 0.2%', 'm'],
            'min_exp_01' => ['Chiffre d’affaires à l’export soumis à 0,1 %', 'الخاضع للضريبة الدنيا بنسبة 0.1%', 'm'],
            'min_qp' => ['Quote-part de l’associé dans le minimum des sociétés de personnes', 'مناب الشريك في شركات الأشخاص وما شابهها في الضريبة الدنيا', 'm'],
            'min_30' => ['Minimum de 30 % ou 45 %', 'الضريبة الدنيا بنسبة 30% أو/و 45%', 'm'],
            'exo_min' => ['Non soumis au minimum d’impôt (déduction totale des revenus d’exploitation)', '', 'x'],
        ]],
        'liq' => ['Liquidation de l’impôt', 'التســوية', [
            'ded_rs' => ['Retenues à la source', 'الخصم من المورد', 'm'],
            'ded_acp' => ['Acomptes provisionnels payés', 'الأقساط الاحتياطية المدفوعة', 'c'],
            'ded_exc' => ['Excédent d’impôt de la déclaration de l’année précédente', 'فائض ضريبة متأت من تصريح السنة الفارطة', 'm'],
            'ded_imp' => ['Avance sur les produits importés', 'التسبقة على المنتجات الموردة', 'm'],
            'ded_qp' => ['Quote-part dans l’avance due par les sociétés de personnes', 'القسط في التسبقة المستوجبة على شركات الأشخاص وما شابهها', 'm'],
            'ded_alc' => ['Avance sur les ventes de boissons alcoolisées', 'التسبقة على بيوعات المشروبات الكحولية', 'm'],
            'ded_1pc' => ['Avance de 1 % sur les ventes des industriels et grossistes aux personnes physiques', 'التسبقة بنسبة 1% الموظفة على بيوعات المؤسسات الصناعية ومؤسسات تجارة الجملة إلى الأشخاص الطبيعيين', 'm'],
            'ded_div' => ['Retenue sur dividendes ne dépassant pas 10 000 D par an', 'الخصم من المورد بعنوان حصص الأسهم إذا لم يتجاوز مبلغها 10.000 دينار سنويا', 'm'],
            'ded_reduc' => ['Réduction d’impôt (acquisition d’un logement 2021–2022, 200 D par mois)', 'التخفيض في الضريبة على الدخل المستوجبة في حدود 200 دينار شهريا للأشخاص الذين يقومون خلال سنتي 2021 و2022 باقتناء مسكن يتم تمويله عن طريق قرض أو عقد بيع مرابحة', 'm'],
            'ded_aut' => ['Autres montants déductibles', 'مبالغ أخرى قابلة للطرح', 'm'],
            'restit' => ['Montants restitués (déclarations rectificatives)', 'المبالغ المسترجعة', 'm'],
        ]],
        'exo' => ['Revenus exonérés (pour information)', 'المداخيل المعفاة', [
            'exo_div' => ['Dividendes distribués par des personnes morales établies en Tunisie et parts de fonds communs', 'حصص الأسهم والمداخيل المشابهة الموزعة من قبل الذوات المعنوية المستقرة بالبلاد التونسية وحصص الصناديق المشتركة', 'm'],
            'exo_cea' => ['Intérêts des comptes épargne pour l’investissement (dans la limite de 4 000 D)', 'فوائد حسابات الادخار للاستثمار في حدود 4000 دينار', 'm'],
            'exo_fin_service' => ['Indemnité de fin de service', 'مكافأة نهاية الخدمة', 'm'],
            'exo_devises' => ['Intérêts des dépôts et titres en devises ou en dinars convertibles', 'فوائض الإيداعات أو السندات أو الصكوك الإسلامية بالعملة الأجنبية أو بالدينار القابل للتحويل', 'm'],
            'exo_etudes' => ['Intérêts des comptes épargne-études', 'فوائد حسابات الادخار من أجل الدراسة المفتوحة لدى البنوك لفائدة الأبناء', 'm'],
            'exo_aut' => ['Autres revenus exonérés', 'مداخيل أخرى معفاة', 'm'],
            'ni_etr' => ['Revenus de source étrangère imposés dans le pays d’origine (non imposables)', 'مداخيل ذات مصدر أجنبي خضعت للضريبة ببلد المنشأ', 'm'],
            'ni_aut' => ['Autres revenus non imposables', 'مداخيل أخرى غير خاضعة للضريبة', 'm'],
        ]],
        'contrib' => ['Contribution sociale de solidarité et autres taxes', 'المساهمة الاجتماعية التضامنية', [
            'css_rs' => ['Contribution retenue à la source (à déduire)', 'طرح المساهمة المخصومة من المورد من المساهمة المستوجبة (د)', 'm'],
            'visite' => ['Droit de visite', 'معلوم الزيارة', 'm'],
            'fonds_base' => ['Fonds d’encouragement des salariés : montant (7 %)', 'المعلوم على صندوق ترغيب الأجراء الذي لم يقع توزيعه', 'm'],
            'pen_ir' => ['Pénalités de retard – impôt sur le revenu', '', 'm'],
            'pen_exo' => ['Pénalités sur revenus exonérés ou à retenue libératoire non déclarés (1 %)', 'الخطايا المستوجبة على المداخيل المعفاة أو الخاضعة للخصم من المورد التحرري غير المصرح بها', 'm'],
            'pen_aut' => ['Pénalités de retard – contribution sociale et autres taxes', '', 'm'],
            'paye' => ['Impôt déjà payé (déclarations rectificatives)', 'الطرح (الضريبة المدفوعة)', 'm'],
        ]],
    ];

    public const TABLEAUX_IR = [
        'cadres' => ['Cadre juridique et déclaration d’investissement', 'الإطار القانوني', [
            'code' => ['Code', 'الرمز', 't'], 'cadre' => ['Cadre juridique', 'الإطار', 't'], 'struct_code' => ['Code de la structure', 'الرمز', 't'],
            'struct' => ['Structure', 'الهيكل', 't'], 'num' => ['N° de la déclaration', 'العدد', 't'], 'date' => ['Date', 'التاريخ', 'd'], 'entree' => ['Entrée en activité', 'تاريخ الدخول في طور النشاط الفعلي', 'd']], 3],
        'pertes' => ['Pertes des années antérieures non encore déduites', 'خسائر بعنوان السنوات الفارطة لم يقع طرحها', [
            'annee' => ['Année de la perte', 'سنـــــة الخسارة', 't'], 'montant' => ['Montant', 'المبلغ (د)', 'm']], 3],
        'ded_act' => ['Déductions au titre des revenus et bénéfices d’activité', 'بعنوان المداخيل والأرباح المتأتية من النشاط', [
            'code' => ['Code', 'الرمز', 't'], 'cadre' => ['Cadre légal', 'الإطار القانوني', 't'], 'montant' => ['Montant déduit', 'المبالغ المطروحة (د)', 'm']], 3],
        'reinv' => ['Déduction des revenus réinvestis', 'طرح المداخيل المعاد استثمارها', [
            'code' => ['Code', 'الرمز', 't'], 'cadre' => ['Cadre légal', 'الإطار القانوني للطرح', 't'], 'investi' => ['Montant investi', 'المبالغ المستثمرة', 'm'],
            'mf' => ['Matricule fiscal', 'المعرف الجبائي', 't'], 'nom' => ['Raison sociale', 'الاسم الاجتماعي', 't'], 'deduit' => ['Montant déduit', 'المبالغ المطروحة (د)', 'm']], 3],
        'acomptes' => ['Acomptes provisionnels payés', 'مراجع دفع الأقساط الاحتياطية', [
            'recette' => ['Recette des finances', 'قباضة المــالية', 't'], 'num' => ['N° de quittance', 'رقـــم', 't'], 'date' => ['Date', 'تاريـــخ', 'd'], 'montant' => ['Montant', 'المبـــلغ (د)', 'm']], 3],
        'banques' => ['Comptes bancaires ou postaux', 'أرقام الحسابات البنكية أو البريدية', [
            'banque' => ['Banque ou Office national des postes', 'المؤسسة البنكية أو الديوان الوطني للبريد', 't'], 'rib' => ['N° de compte', 'رقم الحساب البنكي أو البريدي', 't'], 'ouverture' => ['Date d’ouverture', 'تاريخ فتح الحساب', 'd']], 3],
        'branches' => ['Établissements secondaires (bénéfices industriels et commerciaux)', 'بيانات مفصلة تتعلق بنشاط مؤسسات تابعة لمؤسسة رئيسية', [
            'num' => ['N° de l’établissement', 'عدد الفرع', 't'], 'adr' => ['Adresse', 'عنوان الفرع', 't'], 'act' => ['Activité', 'النشاط', 't'],
            'stk_deb' => ['Stock début', 'أول السنة المالية', 'm'], 'stk_fin' => ['Stock fin', 'آخر السنة المالية', 'm'], 'achats' => ['Achats', 'قيمة الشراءات خلال السنة المالية (د)', 'm']], 2],
        'enfants' => ['Enfants à charge (du plus âgé au plus jeune)', 'بيانات حول الأطفال في الكفالة', [
            'nom' => ['Nom et prénom', 'الاسم واللقب', 't'], 'naissance' => ['Date de naissance', 'تاريخ الولادة', 'd'],
            'etat' => ['Situation (1 : moins de 20 ans ; 2 : étudiant de moins de 25 ans sans bourse ; 3 : handicapé)', 'الحالة', 't']], 4],
    ];

    /** Revenu net d'une catégorie : résultat fiscal ou forfaitaire, quote-part et plus-value dans les sociétés de personnes. */
    public static function categoriesIr(array $f): array
    {
        $n = fn ($k) => self::n($f, $k);
        $o = [];
        $o['bic'] = r3($n('bic_res_fiscal') + $n('bic_qp') + $n('bic_pv'));
        $o['bnc_forf'] = r3(max(0, $n('bnc_rec_ttc') ?: $n('bnc_rec_loc') + $n('bnc_rec_exp')) * 0.8);   // 80 % des recettes brutes TTC
        $o['bnc'] = r3($n('bnc_res_fiscal') + $o['bnc_forf'] + $n('bnc_forf_subv') + $n('bnc_qp') + $n('bnc_pv'));
        $o['agr_rd'] = r3($n('agr_rec') + $n('agr_stocks') - $n('agr_dep') + $n('agr_subv2'));
        $o['agr'] = r3($n('agr_res_fiscal') + max(0, $o['agr_rd']) + $n('agr_forf') + $n('agr_qp') + $n('agr_pv'));    // perte de l'assiette recettes-dépenses non retenue
        $o['fon_bati_abat'] = r3($n('fon_bati') * 0.25);
        $o['fon_bati_res'] = r3($n('fon_bati') - $o['fon_bati_abat'] - $n('fon_bati_tax'));
        $o['fon_nb_res'] = r3($n('fon_nb') - $n('fon_nb_dep') - $n('fon_nb_tax'));
        $o['fon'] = r3($n('fon_res_fiscal') + max(0, $o['fon_bati_res']) + max(0, $o['fon_nb_res']) + $n('fon_qp') + $n('fon_pv'));
        $o['sal_tot'] = r3($n('sal_brut') + $n('sal_nat'));
        $o['sal_frais'] = r3(min($o['sal_tot'] * 0.10, 2000));
        $o['sal_a'] = r3($o['sal_tot'] - $o['sal_frais']);
        $o['pen_tot'] = r3($n('pen_brut') + $n('pen_nat'));
        $o['pen_abat'] = r3($o['pen_tot'] * 0.25);
        $o['pen_b'] = r3($o['pen_tot'] - $o['pen_abat']);
        $o['sal'] = r3($o['sal_a'] + $o['pen_b']);
        $o['rcm_a'] = r3($n('rcm_nd') + $n('rcm_cons') + $n('rcm_vm_aut'));
        $o['rcm_b'] = r3(array_sum(array_map($n, ['rcm_ep_bq', 'rcm_ep_cent', 'rcm_creances', 'rcm_titres', 'rcm_oblig', 'rcm_bons', 'rcm_fcc', 'rcm_verts', 'rcm_autres'])));
        $o['rcm'] = r3($o['rcm_a'] + $o['rcm_b']);
        $o['etr_frais'] = r3(min($n('etr_sal') * 0.10, 2000));
        $o['etr_a'] = r3($n('etr_sal') - $o['etr_frais']);
        $o['etr_abat'] = r3($n('etr_pen') * (! empty($f['etr_pen80']) ? 0.80 : 0.25));
        $o['etr_b'] = r3($n('etr_pen') - $o['etr_abat']);
        $o['aut_I'] = r3($o['etr_a'] + $o['etr_b'] + $n('etr_aut'));
        $o['aut'] = r3($o['aut_I'] + $n('dep_pers'));
        return $o;
    }

    /**
     * Bénéfice (revenu) net imposable arrondi pour l'impôt sur le revenu : la fraction de dinar compte pour un dinar entier
     * (arrondi au dinar supérieur), comme sur la déclaration annuelle.
     */
    public static function arrondiImposable(float $montant): float
    {
        return (float) ceil(round(max(0, $montant), 3));
    }

    /** Impôt selon le barème progressif. */
    public static function bareme(float $revenu): float
    {
        $impot = 0.0; $bas = 0.0;
        foreach (self::BAREME as [$haut, $taux]) {
            if ($revenu <= $bas) break;
            $impot += (min($revenu, $haut) - $bas) * $taux / 100;
            $bas = $haut;
        }
        return r3($impot);
    }

    /** Calcul de la déclaration de l'impôt sur le revenu. */
    public static function calculIr(array $f): array
    {
        $n = fn ($k) => self::n($f, $k);
        $o = self::categoriesIr($f);
        $o['rng'] = r3($o['bic'] + $o['bnc'] + $o['agr'] + $o['fon'] + $o['sal'] + $o['rcm'] + $o['aut']);   // revenu global net
        $o['pertes'] = self::somme($f, 'pertes', 'montant');
        $o['pertes_imput'] = r3(min(max(0, $o['rng']), $o['pertes']));
        $o['pertes_reste'] = r3($o['pertes'] - $o['pertes_imput']);
        $o['rng_net'] = r3($o['rng'] - $o['pertes_imput']);
        $o['ded_act'] = self::somme($f, 'ded_act', 'montant');
        $o['reinv'] = self::somme($f, 'reinv', 'deduit');

        // Déductions communes (plafonds du modèle)
        $ep = min($n('ded_ep_bq') + $n('ded_ep_cent'), 6000);
        $ob = min($n('ded_oblig') + $n('ded_bons'), 10000);
        $verts = min($n('ded_verts'), 10000);
        $enf = 0.0;
        foreach (array_slice((array) ($f['enfants'] ?? []), 0, 4) as $e) $enf += match ((string) ($e['etat'] ?? '')) { '1' => 100, '2' => 1000, '3' => 2000, default => 0 };
        if (! $enf) foreach (['enf1', 'enf2', 'enf3', 'enf4'] as $k) $enf += match ((string) ($f[$k] ?? '')) { '1' => 100, '2' => 1000, default => 0 };
        $enf += 2000 * (int) ($f['enf_handicap'] ?? 0) * (empty($f['enfants']) ? 1 : 0);
        $o['ded_enf'] = r3($enf);
        $o['ded_chef'] = ! empty($f['chef']) ? 300.0 : 0.0;
        $avant = max(0, $o['rng_net'] - $o['ded_act'] - $o['reinv'] - $ep - $ob - $verts - $n('ded_rentes') - $o['ded_chef'] - $enf - $n('ded_univ') - $n('ded_coti') - $n('ded_logement'));
        $o['ded_parents'] = r3(min($avant * 0.05, 450) * min(2, (int) ($f['parents'] ?? 0)));
        $o['ded_communes'] = r3($ep + $ob + $verts + $n('ded_rentes') + $o['ded_chef'] + $enf + $o['ded_parents'] + $n('ded_univ') + $n('ded_coti') + $n('ded_logement'));

        // Revenu net imposable (fraction de dinar comptée pour un dinar entier) et impôt
        $o['rni'] = r3(max(0, $o['rng_net'] - $o['ded_act'] - $o['reinv'] - $o['ded_communes']));
        $o['rni_arrondi'] = self::arrondiImposable($o['rni']);
        $o['impot_bareme'] = self::bareme($o['rni_arrondi']);

        // Minimum d'impôt des BIC et BNC : 0,2 % (au moins 300 D) ou 0,1 % (au moins 200 D) du chiffre d'affaires TTC
        $min02 = $n('min_loc_02') + $n('min_exp_02'); $min01 = $n('min_loc_01') + $n('min_exp_01');
        $activite = $min02 + $min01 > 0 || $n('bic_ca_ttc') + $n('bnc_ca_ttc') + $n('bnc_rec_ttc') > 0;
        $min = $min02 * 0.002 + $min01 * 0.001;
        $o['min_ca'] = ! $activite || ! empty($f['exo_min']) ? 0.0 : r3(max($min, $min02 > 0 ? 300 : 200));
        $o['min'] = r3(max($o['min_ca'], $n('min_qp'), $n('min_30')));
        $o['impot'] = r3(max($o['impot_bareme'], $o['min']));

        // Liquidation
        $o['acomptes'] = self::somme($f, 'acomptes', 'montant');
        $o['II'] = r3($n('ded_rs') + $o['acomptes'] + $n('ded_exc') + $n('ded_imp') + $n('ded_qp') + $n('ded_alc') + $n('ded_1pc') + $n('ded_div') + $n('ded_reduc') + $n('ded_aut'));
        $o['solde'] = r3($o['impot'] - $o['II'] + $n('restit'));

        // Contribution sociale de solidarité : taux du paramétrage (sauf salariés et retraités dont le revenu net ne dépasse pas 5 000 D)
        $salarieSeul = $o['bic'] + $o['bnc'] + $o['agr'] + $o['fon'] + $o['rcm'] + $o['aut'] == 0;
        // Base : le bénéfice net imposable retenu pour l'IRPP (arrondi au dinar supérieur) × taux du paramétrage (0,5 % par défaut)
        $o['css_taux'] = (float) \App\Models\Setting::fiscal()['css'];
        $o['css_base'] = $o['rni_arrondi'];
        $o['css_du'] = $salarieSeul && $o['rni'] <= 5000 ? 0.0 : r3($o['css_base'] * $o['css_taux'] / 100);
        $o['css_net'] = r3($o['css_du'] - $n('css_rs'));
        $o['fonds'] = r3($n('fonds_base') * 0.07);
        $o['exo_tot'] = r3(array_sum(array_map($n, ['exo_div', 'exo_cea', 'exo_fin_service', 'exo_devises', 'exo_etudes', 'exo_aut'])));
        $o['ni_tot'] = r3($n('ni_etr') + $n('ni_aut'));

        $L = ['ir' => [$o['solde'], $n('paye'), $n('pen_ir')], 'exo' => [0.0, 0.0, $n('pen_exo')], 'css' => [$o['css_net'], 0.0, $n('pen_aut')],
            'fonds' => [$o['fonds'], 0.0, 0.0], 'visite' => [$n('visite'), 0.0, 0.0]];
        $o['synthese'] = array_map(fn ($x) => ['I' => r3($x[0]), 'II' => r3($x[1]), 'III' => r3($x[0] - $x[1]), 'pen' => r3($x[2]), 'tot' => r3(max(0, $x[0] - $x[1]) + $x[2])], $L);
        $o['tot'] = r3(array_sum(array_column($o['synthese'], 'tot')));
        return $o;
    }

    public static function impotsIr(array $f): array
    {
        if (isset($f['imp'])) return array_values(array_intersect(array_keys(self::IMPOTS_IR), (array) $f['imp']));
        $c = self::calculIr($f);
        return array_values(array_filter(['ir', 'visite', 'fonds'], fn ($k) => match ($k) { 'ir' => true, 'visite' => self::n($f, 'visite') > 0, 'fonds' => $c['fonds'] > 0 }));
    }

    /** Pré-remplissage de la déclaration IR depuis les états financiers (entreprise individuelle) et la paie n'existe pas pour l'exploitant. */
    /**
     * Situation familiale de la fiche client, pour les déductions de la déclaration IR : chef de famille (300 D),
     * enfants à charge (100, 1 000 ou 2 000 D chacun, 4 premiers) et parents à charge (5 % du revenu net, 450 D au plus par parent).
     * Seules les rubriques renseignées sur la fiche sont reprises.
     */
    public static function famille(Client $c): array
    {
        $etat = ['moins_20' => '1', 'etudiant' => '2', 'handicape' => '3'];
        $sit = ['celibataire' => '1', 'marie' => '2', 'divorce' => '3', 'veuf' => '4'];
        $f = [];
        if (isset($sit[$c->situation_familiale])) $f['situation'] = $sit[$c->situation_familiale];
        if ($c->chef_famille !== null) $f['chef'] = $c->chef_famille ? '1' : '';
        if ($c->chef_famille && $c->enfants_charge) {
            $f['enfants'] = collect($c->enfants_charge)->map(fn ($e) => ['nom' => $e['prenom'] ?? '', 'naissance' => '', 'etat' => $etat[$e['type'] ?? ''] ?? ''])->values()->all();
        }
        if ($c->parents_charge !== null) $f['parents'] = (string) $c->parents_charge;
        return $f;
    }

    /**
     * Résultat fiscal d'une personne physique : résultat comptable (balance) + IRPP et CSS comptabilisés (comptes 69, non déductibles)
     * + réintégrations du passage au résultat fiscal.
     */
    /** Libellé de la ligne de déduction reprise du passage au résultat fiscal. */
    public const CADRE_PASSAGE = 'Déductions du passage au résultat fiscal';

    public static function resultatFiscalIr(array $P, array $L): float
    {
        return r3($P['res'] + $P['is'] + (float) ($L['rei'] ?? 0));
    }

    public static function preremplirIr(array &$f, Client $c, int $y, array $P, float $tvac, float $rf, array $L): void
    {
        foreach (self::famille($c) as $k => $v) $f[$k] = $v;
        $bnc = (bool) preg_match('/lib[ée]ral|bnc|m[ée]decin|avocat|architecte|expert|conseil|ing[ée]nieur/iu', (string) $c->forme.' '.$c->paie_activite);
        $p = $bnc ? 'bnc_' : 'bic_';
        $f[$p.'ca_loc'] = r3($P['ca']);
        $f[$p.'ca_ttc'] = r3($P['ca'] + $tvac);
        // Résultat fiscal = résultat comptable + IRPP + CSS (comptes 69) + réintégrations du passage au résultat fiscal
        $f[$p.'res_fiscal'] = self::resultatFiscalIr($P, $L);
        // Les déductions du passage ne diminuent pas le résultat fiscal : elles figurent parmi les déductions au titre de l'activité.
        // La ligne reprise du passage est remplacée à chaque pré-remplissage ; les lignes saisies à la main sont conservées.
        $lignes = array_values(array_filter((array) ($f['ded_act'] ?? []), fn ($l) => ($l['cadre'] ?? null) !== self::CADRE_PASSAGE));
        if ((float) ($L['ded'] ?? 0) != 0) $lignes[] = ['code' => '', 'cadre' => self::CADRE_PASSAGE, 'montant' => r3((float) $L['ded'])];
        $f['ded_act'] = $lignes;
        if (! $bnc) { $f['bic_achats'] = r3($P['ach']); }
        $f['min_loc_02'] = r3($P['ca'] + $tvac);
        if (! empty($L['rs'])) $f['ded_rs'] = (float) $L['rs'];
        if (! empty($L['acp']) && empty($f['acomptes'])) $f['acomptes'] = [['montant' => (float) $L['acp']]];
        // Résultat comptable : celui de la balance (produits de la classe 7 − charges de la classe 6), donc après l'IRPP et la CSS
        // comptabilisés en 69 ; le résultat fiscal part du résultat avant impôt (IRPP et CSS, non déductibles, réintégrés).
        $f[$p.'res_compta'] = r3($P['res']);
    }
}
