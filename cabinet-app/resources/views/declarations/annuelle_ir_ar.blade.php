{{-- Déclaration de l'impôt sur le revenu des personnes physiques, modèle officiel DGI en arabe (التصريح بالضريبة على دخل الأشخاص الطبيعيين), remplie. --}}
@php
    $A = \App\Services\DeclarationAnnuelle::class;
    $f = $A::champs($d->champs ?? [], $client, (int) $periode); $C = $A::calculIr($f); $imp = $A::impotsIr($f);
    $mf = \App\Services\Exports\FichierDgi::matricule($client->matricule_fiscal);
    preg_match('/\b(\d{4})\b(?!.*\b\d{4}\b)/', (string) $client->adresse, $cp);
    $cases = fn ($s, $n) => '<span class="cases" dir="ltr">'.collect(mb_str_split(str_pad((string) $s, $n)))->map(fn ($c) => '<span class="bx">'.e(trim($c)).'</span>')->implode('').'</span>';
    $jma = fn ($v) => ($x = $v ? \Carbon\Carbon::parse($v) : null) ? '<span>اليوم</span>'.$cases($x->format('d'), 2).'<span>الشهر</span>'.$cases($x->format('m'), 2).'<span>السنة</span>'.$cases($x->format('Y'), 4)
        : '<span>اليوم</span>'.$cases('', 2).'<span>الشهر</span>'.$cases('', 2).'<span>السنة</span>'.$cases('', 4);
    $dn = fn ($x) => (float) $x ? number_format((float) $x, 3, ',', ' ') : '';
    $n = fn ($k) => $A::n($f, $k);
    $X = fn ($b) => $b ? 'X' : '';
    $rp = fn ($v) => $v === null || $v === '' || ! (float) $v ? '' : ((float) $v < 0 ? 'خ' : 'ر');
    $T = fn ($t) => array_values((array) ($f[$t] ?? []));
    // Ligne « libellé | montant », éventuellement avec une case ربح / خسارة
    $L = function ($lib, $v, $n = 0, $res = false, $gras = false) use ($dn, $cases, $rp) {
        $pad = $n ? 'padding-inline-start:'.(1.2 * $n).'rem' : '';
        return '<tr><td class="lib'.($gras ? ' font-bold' : '').'" style="'.$pad.'">'.e($lib).($res ? ' &nbsp; ر = ربح &nbsp; خ = خسارة '.$cases($rp($v), 1) : '').'</td><td class="mt">'.$dn($res ? abs((float) $v) : $v).'</td></tr>';
    };
    $titre = fn ($t, $n = 0) => '<tr><td class="lib font-bold" style="'.($n ? 'padding-inline-start:'.(1.2 * $n).'rem' : '').'">'.e($t).'</td><td></td></tr>';
    $cat = ['bic_r' => $n('bic_res_fiscal') || $n('bic_ca_ttc'), 'bic_q' => $n('bic_qp') || $n('bic_pv'), 'bnc_r' => $n('bnc_res_fiscal') || $n('bnc_ca_ttc'), 'bnc_f' => $n('bnc_rec_ttc') || $n('bnc_rec_loc'), 'bnc_q' => $n('bnc_qp') || $n('bnc_pv'),
        'agr_r' => $n('agr_res_fiscal') || $n('agr_ca_tot'), 'agr_m' => $n('agr_rec'), 'agr_f' => $n('agr_forf'), 'agr_q' => $n('agr_qp') || $n('agr_pv'),
        'fon_r' => $n('fon_res_fiscal') || $n('fon_ca'), 'fon_f' => $n('fon_bati') || $n('fon_nb'), 'fon_q' => $n('fon_qp') || $n('fon_pv'),
        'sal' => (bool) $C['sal'], 'rcm' => $C['rcm'] || $n('rcm_div'), 'aut' => $C['aut'] || $n('jeux'), 'exo' => (bool) $C['exo_tot'], 'ni' => (bool) $C['ni_tot']];
@endphp
<x-layouts.app title="التصريح بالضريبة على دخل الأشخاص الطبيعيين">
    <div class="no-print"><x-page-header title="Formulaire officiel en arabe" :subtitle="$client->nom.' · revenus '.$periode" :back="route('declarations.index', ['type' => 'ann', 'periode' => $periode])">
        <a href="{{ route('declarations.print', ['type' => 'ann', 'periode' => $periode]) }}" class="btn-secondary">Version française</a>
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    @include('declarations._style_ar')
    @include('declarations._style_ar_formulaire')

    <div dir="rtl" lang="ar" class="brd mx-auto max-w-5xl bg-white p-6 text-sm shadow-sm print:max-w-none print:p-0 print:shadow-none">
        <div class="flex items-start justify-between">
            <div class="text-xs font-bold leading-5">الجمهورية التونسية<br>وزارة المالية</div>
            <h1 class="text-2xl font-bold" style="color:#9a3412">التصريح بالضريبة على دخل الأشخاص الطبيعيين</h1>
            <div class="text-xs font-bold leading-5">الإدارة العامة للأداءات</div>
        </div>
        <div class="mt-5 flex flex-wrap items-center justify-between gap-6">
            <span>رمز التصريح <sup>(1)</sup> {!! $cases($f['acte'] ?? '0', 1) !!}</span>
            <span>دخل سنة <sup>(2)</sup> {!! $cases($periode, 4) !!}</span>
        </div>
        <table class="g mt-3 text-xs">
            <tr><th rowspan="2">المعرف الجبائي</th><th rowspan="2">رمز الأداء على القيمة المضافة (أ.ق. م.)</th><th rowspan="2">رمز الصنف</th><th colspan="3">رقم بطاقة التعريف الوطنية أو رقم بطاقة الإقامة للأجانب أو رقم جواز السفر</th></tr>
            <tr><th>الرمز <sup>(3)</sup></th><th>الرقم</th><th>تاريخ الإصدار</th></tr>
            <tr><td>{!! $cases($mf ? $mf['num'].$mf['cle'] : '', 8) !!}</td><td>{!! $cases($mf['tva'] ?? '', 1) !!}</td><td>{!! $cases($mf['cat'] ?? '', 1) !!}</td>
                <td>{{ $f['id_code'] ?? '' }}</td><td dir="ltr">{{ $f['id_num'] ?? '' }}</td><td>{{ ! empty($f['id_date']) ? dfr($f['id_date']) : '' }}</td></tr>
        </table>
        <div class="mt-3 space-y-2">
            <div class="flex flex-wrap items-center gap-3 font-bold">تاريخ افتتاح السنة المالية: {!! $jma($f['ouv'] ?? null) !!}</div>
            <div class="flex flex-wrap items-center gap-3 font-bold">تاريخ ختم السنة المالية: {!! $jma($f['clo'] ?? null) !!}</div>
        </div>
        <div class="mt-3 font-bold">الضريبة أو المعلوم المدفوع على أساس هذا التصريح <sup>(4)</sup></div>
        <table class="g mt-1"><tr><th>الضريبة على الدخل</th><th>معلوم الزيارة</th><th>المعلوم على صندوق ترغيب الأجراء الذي لم يقع توزيعه</th></tr>
            <tr>@foreach(['ir', 'visite', 'fonds'] as $k)<td class="coche"><span class="bx">{{ $X(in_array($k, $imp, true)) }}</span></td>@endforeach</tr></table>
        <div class="cadre space-y-2">
            <div>الاسم: <span class="pt" dir="auto">{{ $f['prenom'] ?? '' }}</span> اللقب: <span class="pt" dir="auto">{{ $f['nom'] ?? $client->nom }}</span></div>
            <div class="font-bold">بالنسبة إلى الأشخاص الطبيعيين من غير المعرفين بمعرف جبائي:</div>
            <div class="flex flex-wrap items-center gap-3">تاريخ الولادة: {!! $jma($f['naissance'] ?? null) !!} الجنسية <span class="pt" dir="auto" style="min-width:7rem">{{ $f['nationalite'] ?? '' }}</span> بلد المنشأ (الولادة) <span class="pt" dir="auto" style="min-width:7rem">{{ $f['pays_naissance'] ?? '' }}</span></div>
            <div>العنــوان: <span class="pt" dir="auto" style="min-width:30rem">{{ $client->adresse }}</span></div>
            <div class="flex items-center gap-3">الترقيم البريدي {!! $cases($cp[1] ?? '', 4) !!}</div>
            <div>النشــــاط الرئيسي: <span class="pt" dir="auto">{{ $f['activite'] ?? '' }}</span> رقم الهاتف <span class="pt" dir="ltr">{{ $f['telephone'] ?? $client->telephone }}</span></div>
        </div>
        <div class="cadre space-y-3">
            <div class="flex flex-wrap items-center gap-4">الحالة المدنية <sup>(4)</sup> :
                @foreach($A::SITUATIONS as $k => [, $ar])<span>{{ $ar }}</span>{!! $cases($X(($f['situation'] ?? '') == $k), 1) !!}@endforeach</div>
            <div class="flex flex-wrap items-center gap-4">عدد الأطفال المعوقين في الكفالة {!! $cases($f['enf_handicap'] ?? '', 1) !!}
                <span class="ms-6">بقية الأطفال في الكفالة <sup>(5)</sup></span>
                @foreach(['الأول' => 'enf1', 'الثاني' => 'enf2', 'الثالث' => 'enf3', 'الرابع' => 'enf4'] as $l => $k)<span>{{ $l }}</span>{!! $cases($f[$k] ?? '', 1) !!}@endforeach</div>
        </div>
        <div class="mt-3 font-bold">أصناف المداخيل <sup>(4)</sup></div>
        <table class="g mt-1 text-[10px]">
            <tr><th colspan="2">1 أرباح صناعية وتجارية</th><th colspan="3">2 أرباح مهن غير تجارية</th><th colspan="4">3 أرباح الأنشطة الفلاحية أو الصيد البحري</th><th colspan="3">4 مداخيل عقارية</th><th rowspan="2">5 أجور وجرايات ذات مصدر تونسي</th><th rowspan="2">6 مداخيل أوراق مالية ورؤوس أموال منقولة</th><th rowspan="2">7 مداخيل أخرى</th><th rowspan="2">8 مداخيل معفاة</th><th rowspan="2">9 مداخيل غير خاضعة للضريبة</th></tr>
            <tr><th>نظام حقيقي</th><th>المناب والقيمة الزائدة المتعلقين بشركات الأشخاص</th><th>نظام حقيقي</th><th>قاعدة تقديرية</th><th>المناب والقيمة الزائدة</th><th>نظام حقيقي</th><th>مقابيض مصاريف</th><th>مقاييس قطاعية</th><th>المناب والقيمة الزائدة</th><th>نظام حقيقي</th><th>قاعدة تقديرية</th><th>المناب والقيمة الزائدة</th></tr>
            <tr>@foreach(['bic_r', 'bic_q', 'bnc_r', 'bnc_f', 'bnc_q', 'agr_r', 'agr_m', 'agr_f', 'agr_q', 'fon_r', 'fon_f', 'fon_q', 'sal', 'rcm', 'aut', 'exo', 'ni'] as $k)<td class="coche"><span class="bx">{{ $X($cat[$k]) }}</span></td>@endforeach</tr>
        </table>
        <table class="g mt-3 text-xs">
            <tr><th colspan="2">الإطـــار القانوني الذي تنشط فيه المؤسسة</th><th colspan="2">الهيكل الذي تم لديه إيداع التصريح بالاستثمار</th><th colspan="2">مراجع التصريح بالاستثمار</th><th rowspan="2">تاريخ الدخول في طور النشاط الفعلي</th></tr>
            <tr><th>الرمز</th><th>الإطار</th><th>الرمز</th><th>الهيكل</th><th>العدد</th><th>التاريخ</th></tr>
            @for($i = 0; $i < 3; $i++) @php $l = $T('cadres')[$i] ?? []; @endphp
                <tr><td>{{ $l['code'] ?? '' }}</td><td dir="auto">{{ $l['cadre'] ?? '' }}</td><td>{{ $l['struct_code'] ?? '' }}</td><td dir="auto">{{ $l['struct'] ?? '' }}</td><td>{{ $l['num'] ?? '' }}</td><td>{{ ! empty($l['date']) ? dfr($l['date']) : '' }}</td><td>{{ ! empty($l['entree']) ? dfr($l['entree']) : '' }}</td></tr>
            @endfor
        </table>

        {{-- Page 2 : BIC et BNC --}}
        <div class="cadre saut"><div class="flex items-center gap-4">مواصلة استغلال مؤسسة محالة وناشطة في إطار التشريع المتعلق بالامتيازات الجبائية <span>نعم</span>{!! $cases($X(! empty($f['privilege'])), 1) !!}<span>لا</span>{!! $cases($X(empty($f['privilege'])), 1) !!}</div></div>
        <table class="g mt-3"><thead><tr><th>الأرباح الصناعية والتجارية</th><th>المبلغ (د)</th></tr></thead><tbody>
            {!! $titre('أ) النظام الحقيقي') !!}
            @foreach(['bic_stk_deb', 'bic_stk_fin', 'bic_achats', 'bic_ca_loc', 'bic_ca_exp', 'bic_ca_ttc', 'bic_ca_srv', 'bic_ca_conso', 'bic_subv'] as $k){!! $L('– '.$A::IR['bic'][2][$k][1], $n($k), 1) !!}@endforeach
            {!! $L('– النتــيجة المــحاسبية', $n('bic_res_compta'), 1, true) !!}{!! $L('– النتــيجة الجــبائية', $n('bic_res_fiscal'), 1, true) !!}
            {!! $titre('ب) المناب في الربح أو في الخسارة في شركات الأشخاص وما شابهها الناشطة في قطاع الصناعة والتجارة والقيمة الزائدة المحققة من التفويت في الحقوق في هذه الشركات') !!}
            {!! $L('– المناب (I)', $n('bic_qp'), 1, true) !!}{!! $L('– القيمة الزائدة (II)', $n('bic_pv'), 1) !!}{!! $L('النتيجة (I+II)', $n('bic_qp') + $n('bic_pv'), 1, true) !!}
        </tbody><tfoot>{!! $L('النتيجة الصافيـة', $C['bic'], 0, true, true) !!}</tfoot></table>
        <table class="g mt-3"><thead><tr><th>أرباح المهن غير التجارية</th><th>المبلغ (د)</th></tr></thead><tbody>
            {!! $titre('أ) النظام الحقيقي') !!}
            @foreach(['bnc_ca_loc', 'bnc_ca_exp', 'bnc_ca_ttc', 'bnc_subv'] as $k){!! $L('– '.$A::IR['bnc'][2][$k][1], $n($k), 1) !!}@endforeach
            {!! $L('– النتــيجة المــحاسبية', $n('bnc_res_compta'), 1, true) !!}{!! $L('– النتــيجة الجــبائية', $n('bnc_res_fiscal'), 1, true) !!}
            {!! $titre('ب) ضبط الربح على أساس قاعدة تقديرية') !!}
            {!! $L('– المقابيض بعنوان الخدمات المحلية', $n('bnc_rec_loc'), 1) !!}{!! $L('– المقابيض بعنوان الخدمات المصدرة', $n('bnc_rec_exp'), 1) !!}
            {!! $L('– مجموع المقابيض الخام بما في ذلك الأداءات', $n('bnc_rec_ttc') ?: $n('bnc_rec_loc') + $n('bnc_rec_exp'), 1) !!}{!! $L('– الربح الجبائي (80% من المقابيض الخام بما في ذلك الأداءات)', $C['bnc_forf'], 1) !!}{!! $L('– مبلغ المنح', $n('bnc_forf_subv'), 1) !!}
            {!! $titre('ج) المناب في الربح أو في الخسارة في شركات الأشخاص وما شابهها التي تحقق أرباح غير تجارية والقيمة الزائدة المحققة من التفويت في الحقوق في هذه الشركات') !!}
            {!! $L('– المناب (I)', $n('bnc_qp'), 1, true) !!}{!! $L('– القيمة الزائدة (II)', $n('bnc_pv'), 1) !!}{!! $L('النتيجة (I+II)', $n('bnc_qp') + $n('bnc_pv'), 1, true) !!}
        </tbody><tfoot>{!! $L('النتيجة الصافيـة', $C['bnc'], 0, true, true) !!}</tfoot></table>

        {{-- Page 3 : agricoles et fonciers --}}
        <table class="g mt-3 saut"><thead><tr><th>أرباح الأنشطة الفلاحية أو الصيد البحري</th><th>المبلغ (د)</th></tr></thead><tbody>
            {!! $titre('أ) النظام الحقيقي') !!}
            @foreach(['agr_ca_loc', 'agr_ca_exp', 'agr_ca_tot', 'agr_subv'] as $k){!! $L('– '.$A::IR['agr'][2][$k][1], $n($k), 1) !!}@endforeach
            {!! $L('– النتــيجة المــحاسبية', $n('agr_res_compta'), 1, true) !!}{!! $L('– النتــيجة الجبائية', $n('agr_res_fiscal'), 1, true) !!}
            {!! $titre('ب) ضبط الربح على أساس الفارق الإيجابي بين المقابيض والمصاريف') !!}
            {!! $L('– المقابيض الخـام', $n('agr_rec'), 1) !!}{!! $L('– المخزونـات', $n('agr_stocks'), 1) !!}{!! $L('المجـــمـــوع', $n('agr_rec') + $n('agr_stocks'), 2) !!}
            {!! $L('– طرح مصاريف الاستغلال المبرّرة', $n('agr_dep'), 1) !!}{!! $L('– مبلغ المنح', $n('agr_subv2'), 1) !!}{!! $L('– النتيجة', $C['agr_rd'], 1, true) !!}{!! $L('– الربح الجبائي', max(0, $C['agr_rd']), 1) !!}
            {!! $titre('ج) ضبط الربح على أساس مقاييس قطاعية') !!}{!! $L('– الربح الجبائي', $n('agr_forf'), 1) !!}
            {!! $titre('د) المناب في الربح أو في الخسارة في شركات الأشخاص وما شابهها الناشطة في قطاع الفلاحة والصيد البحري والقيمة الزائدة المحققة من التفويت في الحقوق في هذه الشركات') !!}
            {!! $L('– المناب (I)', $n('agr_qp'), 1, true) !!}{!! $L('– القيمة الزائدة (II)', $n('agr_pv'), 1) !!}{!! $L('النتيجة (I+II)', $n('agr_qp') + $n('agr_pv'), 1, true) !!}
        </tbody><tfoot>{!! $L('النتيجة الصافية', $C['agr'], 0, true, true) !!}</tfoot></table>
        <table class="g mt-3"><thead><tr><th>المداخيل العقارية</th><th>المبلغ (د)</th></tr></thead><tbody>
            {!! $titre('أ) النظام الحقيقي') !!}{!! $L('– رقـم المــعاملات المحقق', $n('fon_ca'), 1) !!}{!! $L('– النتــيجة المــحاسبية', $n('fon_res_compta'), 1, true) !!}{!! $L('– النتــيجة الجــبائية', $n('fon_res_fiscal'), 1, true) !!}
            {!! $titre('ب) ضبط المداخيل على أساس قاعدة تقديرية') !!}{!! $titre('* مداخيل العقــارات المبنية', 1) !!}
            {!! $L('– المقابيض الخــام المحققة (بما في ذلك الأداءات)', $n('fon_bati'), 1) !!}{!! $L('– طرح 25% بعنوان المصاريف والأعباء والاستهلاكات', $C['fon_bati_abat'], 1) !!}{!! $L('– الفــارق', $n('fon_bati') - $C['fon_bati_abat'], 1) !!}
            {!! $L('– يطرح منه : * المعلوم على العقارات المبنية والمساهمة لفائدة الصندوق الوطني لتحسين السكن اللذين تم دفعهما', $n('fon_bati_tax'), 1) !!}{!! $L('– النتيجة', $C['fon_bati_res'], 1, true) !!}{!! $L('– الربح الجبائي', max(0, $C['fon_bati_res']), 1) !!}
            {!! $titre('* مداخيل الأراضي غير المبنية', 1) !!}
            {!! $L('– المقابيض الخام المحققة (بما في ذلك الأداءات)', $n('fon_nb'), 1) !!}{!! $L('– يطرح منها : * المصاريف المدعمة بوثائق', $n('fon_nb_dep'), 1) !!}{!! $L('* المعلوم على الأراضي غير المبنية المدفوع فعليا', $n('fon_nb_tax'), 2) !!}
            {!! $L('– النتيجة الصافية', $C['fon_nb_res'], 1, true) !!}{!! $L('– الربح الجبائي', max(0, $C['fon_nb_res']), 1) !!}
            {!! $titre('ج) المناب في الربح أو في الخسارة في شركات الأشخاص وما شابهها التي تحقق مداخيلا عقارية والقيمة الزائدة المحققة من التفويت في الحقوق في هذه الشركات') !!}
            {!! $L('– المناب (I)', $n('fon_qp'), 1, true) !!}{!! $L('– القيمة الزائدة (II)', $n('fon_pv'), 1) !!}{!! $L('النتيجة (I+II)', $n('fon_qp') + $n('fon_pv'), 1, true) !!}
        </tbody><tfoot>{!! $L('النتيجة الصافية', $C['fon'], 0, true, true) !!}</tfoot></table>

        {{-- Page 4 : salaires, capitaux mobiliers, autres revenus --}}
        <table class="g mt-3 saut"><thead><tr><th>المرتبات والأجور والجرايات والإيرادات العمرية</th><th>المبلغ (د)</th></tr></thead><tbody>
            {!! $titre('أ) المرتبات والأجور') !!}{!! $L('– المبلغ الجملي للمرتبات والأجور', $n('sal_brut'), 1) !!}{!! $L('– قيمة الامتيازات العينية', $n('sal_nat'), 1) !!}{!! $L('المجـــمـــوع', $C['sal_tot'], 2) !!}
            {!! $L('– طرح 10% بعنوان المصاريف المهنية (دون أن يتجاوز الطرح 2000 دينار)', $C['sal_frais'], 1) !!}{!! $L('الدخـل الخام (أ)', $C['sal_a'], 2, false, true) !!}
            {!! $titre('ب) الجرايات والإيرادات العمرية') !!}{!! $L('– المبلغ الجملي للجرايات والإيرادات العمرية', $n('pen_brut'), 1) !!}{!! $L('– قيمة الامتيازات العينية', $n('pen_nat'), 1) !!}{!! $L('المجـــمـــوع', $C['pen_tot'], 2) !!}
            {!! $L('– طرح 25%', $C['pen_abat'], 1) !!}{!! $L('الدخـل الصـــافي (ب)', $C['pen_b'], 2, false, true) !!}
        </tbody><tfoot>{!! $L('المجـــمـــوع (أ + ب)', $C['sal'], 0, false, true) !!}</tfoot></table>
        <table class="g mt-3"><thead><tr><th>مداخيل الأوراق المالية ورؤوس الأموال المنقولة</th><th>المبلغ (د)</th></tr></thead><tbody>
            {!! $titre('أ) مداخيل الأوراق المالية') !!}{!! $L('– مداخيل الأوراق المالية غير الموزعة بصفة رسمية', $n('rcm_nd'), 1) !!}{!! $L('– المكافآت والمنح المسندة لأعضاء المجالس والهيئات واللجان', $n('rcm_cons'), 1) !!}{!! $L('– المكافآت الأخرى الشبيهة', $n('rcm_vm_aut'), 1) !!}{!! $L('الدخـل الصافي (أ)', $C['rcm_a'], 2, false, true) !!}
            {!! $titre('ب) مداخيل رؤوس الأموال المنقولة') !!}{!! $titre('– الفوائد والعائدات الخام في الحسابات الخاصة للادخار :', 1) !!}
            {!! $L('* المفتوحة لدى البنوك', $n('rcm_ep_bq'), 2) !!}{!! $L('* المفتوحة لدى صندوق الادخار الوطني التونسي', $n('rcm_ep_cent'), 2) !!}
            @foreach(['rcm_creances', 'rcm_titres', 'rcm_oblig', 'rcm_bons', 'rcm_fcc', 'rcm_verts', 'rcm_autres'] as $k){!! $L('– '.$A::IR['rcm'][2][$k][1], $n($k), 1) !!}@endforeach
            {!! $L('الدخـــل الخـــام (ب)', $C['rcm_b'], 2, false, true) !!}{!! $L('ج) حصص الأسهم والمداخيل المشابهة', $n('rcm_div'), 0, false, true) !!}
        </tbody><tfoot>{!! $L('المجـــمـــوع (أ + ب)', $C['rcm'], 0, false, true) !!}</tfoot></table>
        <table class="g mt-3"><thead><tr><th>مداخيل أخرى</th><th>المبلغ (د)</th></tr></thead><tbody>
            {!! $titre('1. مداخيل أخرى ذات مصدر أجنبي لم تخضع لدفع الضريبة في بلد المنشأ') !!}{!! $titre('أ) المرتبات والأجور', 1) !!}
            {!! $L('* المبلغ الجملي للمرتبات والأجور', $n('etr_sal'), 2) !!}{!! $L('* طرح 10% بعنوان المصاريف المهنية (دون أن يتجاوز الطرح 2000 دينار)', $C['etr_frais'], 2) !!}{!! $L('الدخل الخام (أ)', $C['etr_a'], 2, false, true) !!}
            {!! $titre('ب) الجرايات والإيرادات العمرية', 1) !!}{!! $L('* المبلغ الجملي للجرايات والإيرادات العمرية', $n('etr_pen'), 2) !!}
            <tr><td class="lib" style="padding-inline-start:2.4rem">* طرح: 25 % {!! $cases($X($n('etr_pen') && empty($f['etr_pen80'])), 1) !!} &nbsp; 80 % {!! $cases($X(! empty($f['etr_pen80'])), 1) !!}</td><td class="mt">{{ $dn($C['etr_abat']) }}</td></tr>
            {!! $L('الدخل الصافي (ب)', $C['etr_b'], 2, false, true) !!}{!! $L('ج) مداخيل أخرى ذات مصدر أجنبي (ج)', $n('etr_aut'), 1) !!}{!! $L('المجمـــوع (I) = (أ + ب + ج)', $C['aut_I'], 1, false, true) !!}
            {!! $L('2. مداخيل محققة من ألعاب الرهان والحظ واليانصيب (II)', $n('jeux'), 0, false, true) !!}
            {!! $L('3. المداخيل التي يتم ضبطها حسب النفقات الشخصية الظاهرة والجلية ونمو الثروة (III)', $n('dep_pers'), 0, false, true) !!}
        </tbody><tfoot>{!! $L('المجمـــوع (I+III)', $C['aut'], 0, false, true) !!}</tfoot></table>

        {{-- Page 5 : revenu global, pertes, déductions --}}
        <table class="g mt-3 saut"><tbody>{!! $L('الدخل الجملي الصافي :', $C['rng'], 0, true, true) !!}</tbody></table>
        <div class="mt-3 flex items-center gap-4 font-bold">خسائر بعنوان السنوات الفارطة لم يقع طرحها : <span>نعم</span>{!! $cases($X($C['pertes'] > 0), 1) !!}<span>لا</span>{!! $cases($X($C['pertes'] <= 0), 1) !!}</div>
        <table class="g mt-1"><thead><tr><th>سنـــــة الخسارة</th><th>المبلغ (د)</th></tr></thead><tbody>
            @for($i = 0; $i < 3; $i++) @php $l = $T('pertes')[$i] ?? []; @endphp<tr><td>{{ $l['annee'] ?? '' }}</td><td class="mt">{{ $dn($l['montant'] ?? 0) }}</td></tr>@endfor
            <tr><td class="font-bold">مجموع الخسائر القابلة للطرح</td><td class="mt">{{ $dn($C['pertes']) }}</td></tr><tr><td class="font-bold">باقي الخسائر غير المطروح</td><td class="mt">{{ $dn($C['pertes_reste']) }}</td></tr>
        </tbody></table>
        <table class="g mt-2"><tbody>{!! $L('الدخل الجملي الصافي (د) :', $C['rng_net'], 0, true, true) !!}</tbody></table>
        <h2 class="bande">طـروحــــات</h2>
        <div class="font-bold">1) بعنوان المداخيل والأرباح المتأتية من النشاط</div>
        <table class="g mt-1 text-xs"><thead><tr><th>الرمز</th><th>الإطار القانوني</th><th>المبالغ المطروحة (د)</th></tr></thead><tbody>
            @for($i = 0; $i < 3; $i++) @php $l = $T('ded_act')[$i] ?? []; @endphp<tr><td>{{ $l['code'] ?? '' }}</td><td dir="auto">{{ $l['cadre'] ?? '' }}</td><td class="mt">{{ $dn($l['montant'] ?? 0) }}</td></tr>@endfor
        </tbody><tfoot><tr><td colspan="2">المجمـــــوع</td><td class="mt">{{ $dn($C['ded_act']) }}</td></tr></tfoot></table>
        <div class="mt-3 font-bold">2) تخفيضات مشتركة</div>
        <table class="g mt-1"><thead><tr><th>التخفيض</th><th>المبلغ (د)</th></tr></thead><tbody>
            @foreach(['ded_ep_bq', 'ded_ep_cent', 'ded_oblig', 'ded_bons', 'ded_rentes', 'ded_verts'] as $k){!! $L('– '.$A::IR['ded'][2][$k][1], $n($k)) !!}@endforeach
            {!! $L('– رئيس العائلة', $C['ded_chef']) !!}{!! $L('– أطفال في الكفالة', $C['ded_enf']) !!}
            <tr><td class="lib">– والدين في الكفالة {!! $cases($f['parents'] ?? '', 1) !!}</td><td class="mt">{{ $dn($C['ded_parents']) }}</td></tr>
            @foreach(['ded_univ', 'ded_coti', 'ded_logement'] as $k){!! $L('– '.$A::IR['ded'][2][$k][1], $n($k)) !!}@endforeach
        </tbody><tfoot><tr><td>المجمـــوع</td><td class="mt">{{ $dn($C['ded_communes']) }}</td></tr></tfoot></table>
        <h2 class="bande">طرح المداخيل المعاد استثمارها</h2>
        <table class="g text-xs"><thead><tr><th rowspan="2">الرمز</th><th rowspan="2">الإطار القانوني للطرح</th><th rowspan="2">المبالغ المستثمرة</th><th colspan="2">الشركة التي تم الاستثمار لديها</th><th rowspan="2">المبالغ المطروحة (د)</th></tr><tr><th>المعرف الجبائي</th><th>الاسم الاجتماعي</th></tr></thead><tbody>
            @for($i = 0; $i < 3; $i++) @php $l = $T('reinv')[$i] ?? []; @endphp<tr><td>{{ $l['code'] ?? '' }}</td><td dir="auto">{{ $l['cadre'] ?? '' }}</td><td class="mt">{{ $dn($l['investi'] ?? 0) }}</td><td>{{ $l['mf'] ?? '' }}</td><td dir="auto">{{ $l['nom'] ?? '' }}</td><td class="mt">{{ $dn($l['deduit'] ?? 0) }}</td></tr>@endfor
        </tbody><tfoot><tr><td colspan="5">المجمـــوع</td><td class="mt">{{ $dn($C['reinv']) }}</td></tr></tfoot></table>

        {{-- Pages 5–6 : calcul de l'impôt --}}
        <h2 class="bande">احتســـاب الضريبـــة</h2>
        <div class="font-bold">1) ضبط الدخل الجملي الخاضع للضريبة <span class="text-xs font-normal">(المبالغ بالدينار)</span></div>
        <table class="g mt-1"><tbody>{!! $L('- الدخل الصافي الخاضع للضريبة', $C['rni']) !!}{!! $L('- الدخل الجملي الصافي الخـاضع للضريبة (اعتبار الجزء من الدينار دينارا كاملا)', $C['rni_arrondi']) !!}</tbody></table>
        <div class="mt-3 font-bold saut">2) ضبط الضريبة المستوجبة حسب الجدول التالي</div>
        <table class="g mt-1 text-xs"><tr><th>الشرائـــح</th><th>النسب</th><th>النسب الفعلية في الحد الأقصى</th></tr>
            @foreach([['0 إلى 5.000 دينار', '%0', '%0'], ['5000.1 إلى 10.000 دينار', '%15', '%7,50'], ['10.000,1 إلى 20.000 دينار', '%25', '%16,25'], ['20.000,1 إلى 30.000 دينار', '%30', '%20,83'],
                ['30.000,1 إلى 40.000 دينار', '%33', '%23,88'], ['40.000,1 إلى 50.000 دينار', '%36', '%26,30'], ['50.000,1 إلى 70.000 دينار', '%38', '%29,64'], ['ما فوق 70.000 دينار', '%40', '-']] as [$a, $b, $c])
                <tr><td class="lib">{{ $a }}</td><td>{{ $b }}</td><td>{{ $c }}</td></tr>
            @endforeach
            <tr class="font-bold"><td class="lib">الضريبة المستوجبة حسب الجدول</td><td colspan="2" class="mt">{{ $dn($C['impot_bareme']) }}</td></tr></table>
        <div class="mt-3 font-bold">3) ضبط الضريبة الدنيا <span class="text-xs font-normal">(المبالغ بالدينار)</span></div>
        <table class="g mt-1 text-[10px]"><tr><th colspan="5">احتساب الضريبة الدنيا على رقم المعاملات</th><th rowspan="3">مناب الشريك في شركات الأشخاص وما شابهها في الضريبة الدنيا</th><th rowspan="3">الضريبة الدنيا بنسبة 30% أو/و 45%</th></tr>
            <tr><th colspan="2">رقم المعاملات المحلي بـما في ذلك الأداءات</th><th colspan="2">رقم المعاملات المتأتي من التصدير</th><th rowspan="2">الضريبة الدنيا المحتسبة على أساس رقم المعاملات أو الحدّ الأدنى للضريبة</th></tr>
            <tr><th>الخاضع للضريبة الدنيا بنسبة 0.2%</th><th>الخاضع للضريبة الدنيا بنسبة 0.1%</th><th>الخاضع للضريبة الدنيا بنسبة 0.2%</th><th>الخاضع للضريبة الدنيا بنسبة 0.1%</th></tr>
            <tr>@foreach(['min_loc_02', 'min_loc_01', 'min_exp_02', 'min_exp_01'] as $k)<td class="mt">{{ $dn($n($k)) }}</td>@endforeach<td class="mt">{{ $dn($C['min_ca']) }}</td><td class="mt">{{ $dn($n('min_qp')) }}</td><td class="mt">{{ $dn($n('min_30')) }}</td></tr></table>
        <div class="mt-3 font-bold">4) مراجع دفع الأقساط الاحتياطية</div>
        <table class="g mt-1 text-xs"><thead><tr><th rowspan="2">الأقســـاط</th><th rowspan="2">قباضة المــالية</th><th colspan="2">الوصـــــولات</th><th rowspan="2">المبـــلغ (د)</th></tr><tr><th>رقـــم</th><th>تاريـــخ</th></tr></thead><tbody>
            @foreach(['القسط الأول', 'القسط الثـــاني', 'القسط الثالث'] as $i => $q) @php $l = $T('acomptes')[$i] ?? []; @endphp
                <tr><td>{{ $q }}</td><td dir="auto">{{ $l['recette'] ?? '' }}</td><td>{{ $l['num'] ?? '' }}</td><td>{{ ! empty($l['date']) ? dfr($l['date']) : '' }}</td><td class="mt">{{ $dn($l['montant'] ?? 0) }}</td></tr>
            @endforeach
        </tbody><tfoot><tr><td colspan="4">المجمــــــوع</td><td class="mt">{{ $dn($C['acomptes']) }}</td></tr></tfoot></table>
        <h2 class="bande">التســـوية <span class="text-xs font-normal">(المبالغ بالدينار)</span></h2>
        <table class="g"><tbody>
            {!! $L('– الضريبة على الدخل المستوجبة أو الضريبة الدنيا', $C['impot'], 0, false, true) !!}
            {!! $titre('– الخصم من المورد والأقساط والفوائض والتسبقات القابلة للطرح:') !!}
            @foreach(['ded_rs', 'ded_acp', 'ded_exc', 'ded_imp', 'ded_qp', 'ded_alc', 'ded_1pc', 'ded_div', 'ded_reduc', 'ded_aut'] as $k){!! $L('• '.$A::IR['liq'][2][$k][1], $k === 'ded_acp' ? $C['acomptes'] : $n($k), 1) !!}@endforeach
            {!! $L('– المبالغ المسترجعة', $n('restit')) !!}
            <tr><td class="lib font-bold">– فــائض أو مستوجب: ف = فائض &nbsp; م = مستوجب {!! $cases($C['solde'] >= 0 ? 'م' : 'ف', 1) !!}</td><td class="mt font-bold">{{ $dn(abs($C['solde'])) }}</td></tr>
        </tbody></table>

        {{-- Page 7 : revenus exonérés, CSS, synthèse --}}
        <table class="g mt-3 saut"><thead><tr><th>المداخيل المعفاة</th><th>المبلغ (د)</th></tr></thead><tbody>
            @foreach(['exo_div', 'exo_cea', 'exo_fin_service', 'exo_devises', 'exo_etudes', 'exo_aut'] as $k){!! $L('- '.$A::IR['exo'][2][$k][1], $n($k)) !!}@endforeach
        </tbody><tfoot><tr><td>المجمـــــوع</td><td class="mt">{{ $dn($C['exo_tot']) }}</td></tr></tfoot></table>
        <table class="g mt-3"><thead><tr><th>المداخيل غير الخاضعة للضريبة</th><th>المبلغ (د)</th></tr></thead><tbody>
            {!! $L('- مداخيل ذات مصدر أجنبي خضعت للضريبة ببلد المنشأ', $n('ni_etr')) !!}{!! $L('- مداخيل أخرى غير خاضعة للضريبة (أذكرها)', $n('ni_aut')) !!}
        </tbody><tfoot><tr><td>المجمـــــوع</td><td class="mt">{{ $dn($C['ni_tot']) }}</td></tr></tfoot></table>
        <div class="mt-3 font-bold">المساهمة الاجتماعية التضامنية</div>
        <table class="g mt-1"><tbody>
            {!! $L('- الدخل السنوي الصافي (د) (I)', $C['css_base']) !!}{!! $L('- المساهمة الاجتماعية التضامنية المستوجبة (د) (I*'.str_replace('.', ',', (string) +$C['css_taux']).' %)', $C['css_du']) !!}{!! $L('- طرح المساهمة المخصومة من المورد من المساهمة المستوجبة (د)', $n('css_rs')) !!}
            <tr><td class="lib">- فــائض أو مستوجب (د) : ف = فائض م = مستوجب {!! $cases($C['css_net'] >= 0 ? 'م' : 'ف', 1) !!}</td><td class="mt">{{ $dn(abs($C['css_net'])) }}</td></tr>
        </tbody></table>
        <div class="mt-3 grid grid-cols-3 gap-4">
            <div class="col-span-2"><div class="font-bold">المعلوم على صندوق ترغيب الأجراء الذي لم يقع توزيعه</div>
                <table class="g mt-1"><tr><th>المبلـــغ (د)</th><th>النسبــــة</th><th>المعلوم المستوجب (د)</th></tr><tr><td class="mt">{{ $dn($n('fonds_base')) }}</td><td>%7</td><td class="mt">{{ $dn($C['fonds']) }}</td></tr></table></div>
            <div><div class="font-bold">معلوم الزيارة</div><table class="g mt-1"><tr><th>المبلـــغ (د)</th></tr><tr><td class="mt">{{ $dn($n('visite')) }}</td></tr></table></div>
        </div>
        <h2 class="bande">الخلاصة <span class="text-xs font-normal">(المبالغ بالدينار)</span></h2>
        @php $syn = ['ir' => 'الضريبة على الدخل المستوجبة', 'exo' => 'الخطايا المستوجبة على المداخيل المعفاة أو الخاضعة للخصم من المورد التحرري غير المصرح بها', 'css' => 'المساهمة الاجتماعية التضامنية', 'fonds' => 'المعلوم على صندوق ترغيب الأجراء', 'visite' => 'معلوم الزيارة']; @endphp
        <table class="g text-xs"><thead><tr><th>الضريبة أو المعلوم</th><th>الضريبة المستوجبة: م أو فائض الضريبة: ف (I)</th><th>الطرح (الضريبة المدفوعة) (II)</th><th>الضريبة المستوجبة: م أو فائض الضريبة: ف (I -II =III)</th><th>خطايا التأخير</th><th>المجمـــوع</th></tr></thead><tbody>
            @foreach($syn as $k => $l) @php $x = $C['synthese'][$k]; $box = in_array($k, ['ir', 'css'], true); @endphp
                <tr><td class="lib">{{ $l }}</td><td class="mt">{{ $dn(abs($x['I'])) }} @if($box){!! $cases($x['I'] >= 0 ? 'م' : 'ف', 1) !!}@endif</td><td class="mt">{{ $dn($x['II']) }}</td>
                    <td class="mt">{{ $dn(abs($x['III'])) }} @if($box){!! $cases($x['III'] >= 0 ? 'م' : 'ف', 1) !!}@endif</td><td class="mt">{{ $dn($x['pen']) }}</td><td class="mt">{{ $dn($x['tot']) }}</td></tr>
            @endforeach
        </tbody><tfoot><tr><td>المجمـــوع (د)</td><td></td><td></td><td></td><td class="mt">{{ $dn(array_sum(array_column($C['synthese'], 'pen'))) }}</td><td class="mt">{{ $dn($C['tot']) }}</td></tr></tfoot></table>

        {{-- Page 8 : renseignements --}}
        <h2 class="bande saut">إرشـــــادات أخــــرى</h2>
        <div class="text-center font-bold">أرقام الحسابات البنكية أو البريدية</div>
        <table class="g mt-1 text-xs"><tr><th>العدد الرتبي</th><th>المؤسسة البنكية أو الديوان الوطني للبريد</th><th>رقم الحساب البنكي أو البريدي</th><th>تاريخ فتح الحساب</th></tr>
            @for($i = 0; $i < 3; $i++) @php $l = $T('banques')[$i] ?? []; @endphp<tr><td>{{ $i + 1 }}</td><td dir="auto">{{ $l['banque'] ?? '' }}</td><td dir="ltr">{{ $l['rib'] ?? '' }}</td><td>{{ ! empty($l['ouverture']) ? dfr($l['ouverture']) : '' }}</td></tr>@endfor</table>
        <div class="mt-4 text-center font-bold">بيانات مفصلة تتعلق بنشاط مؤسسات تابعة لمؤسسة رئيسية (الأرباح الصناعية والتجارية)</div>
        <table class="g mt-1 text-xs"><tr><th rowspan="2">عدد الفرع</th><th rowspan="2">عنوان الفرع</th><th rowspan="2">النشاط</th><th colspan="2">قيمة المخزون (د)</th><th rowspan="2">قيمة الشراءات خلال السنة المالية (د)</th></tr><tr><th>أول السنة المالية</th><th>آخر السنة المالية</th></tr>
            @for($i = 0; $i < 4; $i++) @php $l = $T('branches')[$i] ?? []; @endphp<tr><td>{{ $l['num'] ?? ($i === 0 ? '000' : '') }}</td><td dir="auto">{{ $l['adr'] ?? '' }}</td><td dir="auto">{{ $l['act'] ?? '' }}</td><td class="mt">{{ $dn($l['stk_deb'] ?? 0) }}</td><td class="mt">{{ $dn($l['stk_fin'] ?? 0) }}</td><td class="mt">{{ $dn($l['achats'] ?? 0) }}</td></tr>@endfor</table>
        <div class="mt-4 text-center font-bold">بيانات حول الأطفال في الكفالة</div>
        <table class="g mt-1 text-xs"><tr><th rowspan="2">العدد الرتبي</th><th colspan="3">الأطفـــــال في الكفـــالة</th></tr><tr><th>الاسم واللقب</th><th>تاريخ الولادة</th><th>الحالة</th></tr>
            @for($i = 0; $i < 4; $i++) @php $l = $T('enfants')[$i] ?? []; @endphp<tr><td>{{ $i + 1 }}</td><td dir="auto">{{ $l['nom'] ?? '' }}</td><td>{{ ! empty($l['naissance']) ? dfr($l['naissance']) : '' }}</td><td>{{ $l['etat'] ?? '' }}</td></tr>@endfor</table>
        @include('declarations._paiement_ar', ['cases' => $cases])
    </div>
</x-layouts.app>
