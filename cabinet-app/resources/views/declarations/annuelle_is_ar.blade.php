{{-- Déclaration de l'impôt sur les sociétés sur le modèle officiel DGI en arabe (التصريح بالضريبة على الشركات), remplie. --}}
@php
    $A = \App\Services\DeclarationAnnuelle::class;
    $f = $A::champs($d->champs ?? [], $client, (int) $periode); $C = $A::calculIs($f); $imp = $A::impotsIs($f);
    $mf = \App\Services\Exports\FichierDgi::matricule($client->matricule_fiscal);
    preg_match('/\b(\d{4})\b(?!.*\b\d{4}\b)/', (string) $client->adresse, $cp);
    $cases = fn ($s, $n) => '<span class="cases" dir="ltr">'.collect(mb_str_split(str_pad((string) $s, $n)))->map(fn ($c) => '<span class="bx">'.e(trim($c)).'</span>')->implode('').'</span>';
    $date = fn ($v) => $v ? \Carbon\Carbon::parse($v) : null;
    $jma = fn ($v) => ($x = $date($v)) ? '<span>اليوم</span>'.$cases($x->format('d'), 2).'<span>الشهر</span>'.$cases($x->format('m'), 2).'<span>السنة</span>'.$cases($x->format('Y'), 4)
        : '<span>اليوم</span>'.$cases('', 2).'<span>الشهر</span>'.$cases('', 2).'<span>السنة</span>'.$cases('', 4);
    $dn = fn ($x) => (float) $x ? number_format((float) $x, 3, ',', ' ') : '';
    $rp = fn ($x) => $x === null || $x === '' ? '' : ((float) $x < 0 ? 'خ' : 'ر');
    $n = fn ($k) => $A::n($f, $k);
    $X = fn ($b) => $b ? 'X' : '';
    $T = fn ($t) => array_values((array) ($f[$t] ?? []));
    $col = fn ($t) => (float) $C['taux'] == $t;
@endphp
<x-layouts.app title="التصريح بالضريبة على الشركات">
    <div class="no-print"><x-page-header title="Formulaire officiel en arabe" :subtitle="$client->nom.' · exercice '.$periode" :back="route('declarations.index', ['type' => 'ann', 'periode' => $periode])">
        <a href="{{ route('declarations.print', ['type' => 'ann', 'periode' => $periode]) }}" class="btn-secondary">Version française</a>
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    @include('declarations._style_ar')
    @include('declarations._style_ar_formulaire')

    <div dir="rtl" lang="ar" class="brd mx-auto max-w-5xl bg-white p-6 text-sm shadow-sm print:max-w-none print:p-0 print:shadow-none">
        <div class="flex items-start justify-between">
            <div class="text-xs font-bold leading-5">الجمهورية التونسية<br>وزارة المالية</div>
            <h1 class="text-2xl font-bold" style="color:#9a3412">التصريح بالضريبة على الشركات</h1>
            <div class="text-xs font-bold leading-5">الإدارة العامة للأداءات</div>
        </div>
        <div class="mt-5 flex flex-wrap items-center justify-between gap-6">
            <span>رمز التصريح <sup>(1)</sup> {!! $cases($f['acte'] ?? '0', 1) !!}</span>
            <span>نتائج سنة <sup>(2)</sup> {!! $cases($periode, 4) !!}</span>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-4">
            <table class="g" style="width:auto">
                <tr><th>المعرف الجبائي</th><th>رمز الأداء على القيمة المضافة</th><th>رمز الصنف</th></tr>
                <tr><td>{!! $cases($mf ? $mf['num'].$mf['cle'] : '', 8) !!}</td><td>{!! $cases($mf['tva'] ?? '', 1) !!}</td><td>{!! $cases($mf['cat'] ?? '', 1) !!}</td></tr>
            </table>
            <table class="g" style="width:auto"><tr><td class="lib">تصريح وقتـــي</td><td class="coche"><span class="bx">{{ $X(($f['nature'] ?? 'd') === 'p') }}</span></td></tr>
                <tr><td class="lib">تصريح نهـــائي</td><td class="coche"><span class="bx">{{ $X(($f['nature'] ?? 'd') === 'd') }}</span></td></tr></table>
        </div>
        <div class="mt-3 space-y-2">
            <div class="flex flex-wrap items-center gap-3 font-bold">تاريخ افتتاح السنة المالية: {!! $jma($f['ouv'] ?? null) !!}</div>
            <div class="flex flex-wrap items-center gap-3 font-bold">تاريخ ختم السنة المالية: {!! $jma($f['clo'] ?? null) !!}</div>
        </div>
        <div class="cadre space-y-2">
            <div>الاسم الاجتماعي: <span class="pt" dir="auto" style="min-width:30rem">{{ $client->nom }}</span></div>
            <div>الصفة القانونية: <span class="pt" dir="auto">{{ $f['forme'] ?? '' }}</span> الجنسية <span class="pt" dir="auto">{{ $f['nationalite'] ?? '' }}</span></div>
            <div>عنوان المقر الاجتماعي: <span class="pt" dir="auto" style="min-width:30rem">{{ $client->adresse }}</span></div>
            <div class="flex items-center gap-3">الترقيم البريدي {!! $cases($cp[1] ?? '', 4) !!}</div>
            <div>النشاط الرئيسي: <span class="pt" dir="auto" style="min-width:30rem">{{ $f['activite'] ?? '' }}</span></div>
        </div>
        <table class="g mt-3 text-xs">
            <tr><th>الضريبة أو المعلوم المدفوع على أساس هذا التصريح</th><th>الضريبة على الشركات</th><th>معلوم الزيارة</th><th>المعلوم على صندوق ترغيب الأجراء</th><th>الضريبة على أرباح المنشآت الدائمة التونسية والضريبة على الأرباح الموزعة بالخارج من قبل الشركات غير المقيمة التي لها منشآت دائمة بتونس</th></tr>
            <tr><td>ضع علامة x في القسيمة المناسبة</td>@foreach(['is', 'visite', 'fonds', 'etab'] as $k)<td class="coche"><span class="bx">{{ $X(in_array($k, $imp, true)) }}</span></td>@endforeach</tr>
        </table>
        <table class="g mt-3 text-xs">
            <tr><th colspan="2">الإطار القانوني الذي تنشط فيه المؤسسة</th><th colspan="2">الهيكل الذي تم لديه إيداع التصريح بالاستثمار</th><th colspan="2">مراجع التصريح بالاستثمار</th><th rowspan="2">تاريخ الدخول في طور النشاط الفعلي / تاريخ إدراج الأسهم العادية بالسوق البديلة لبورصة الأوراق المالية</th></tr>
            <tr><th>الرمز <sup>(4)</sup></th><th>الإطـــار</th><th>الرمز <sup>(5)</sup></th><th>الإطـــار</th><th>العدد</th><th>التاريخ</th></tr>
            @for($i = 0; $i < 3; $i++) @php $l = $T('cadres')[$i] ?? []; @endphp
                <tr><td>{{ $l['code'] ?? '' }}</td><td dir="auto">{{ $l['cadre'] ?? '' }}</td><td>{{ $l['struct_code'] ?? '' }}</td><td dir="auto">{{ $l['struct'] ?? '' }}</td><td>{{ $l['num'] ?? '' }}</td><td>{{ ! empty($l['date']) ? dfr($l['date']) : '' }}</td><td>{{ ! empty($l['entree']) ? dfr($l['entree']) : '' }}</td></tr>
            @endfor
        </table>
        <div class="cadre"><div class="flex items-center gap-4">مواصلة استغلال مؤسسة محالة وناشطة في إطار التشريع المتعلق بالامتيازات الجبائية <sup>(3)</sup> <span>نعم</span>{!! $cases($X(! empty($f['privilege'])), 1) !!}<span>لا</span>{!! $cases($X(empty($f['privilege'])), 1) !!}</div></div>

        <h2 class="bande">I- معطيات لتوظيف الضريبة <span class="text-xs font-normal">(بالدينار)</span></h2>
        <table class="g"><tbody>
            @foreach($A::IS['I'][2] as $k => [, $ar, $nat])
                <tr><td class="lib">- {{ $ar }}@if($nat === 'r') &nbsp; : ربح : ر &nbsp; خسارة : خ {!! $cases($rp($f[$k] ?? null), 1) !!}@endif</td><td class="mt">{{ $dn(abs($n($k))) }}</td></tr>
            @endforeach
        </tbody></table>

        <h2 class="bande saut">II- الأربـــاح التي تــــمّ طـــرحـــها بعنوان الاستغلال أو إعادة الاستثمار</h2>
        <table class="g text-xs"><thead><tr><th rowspan="2">الرمز <sup>(1)</sup></th><th rowspan="2">الإطار القانوني للطرح</th><th rowspan="2">المبالغ المستثمرة (د)</th><th colspan="2">المؤسسة التي تم بها الاستثمار</th><th rowspan="2">المبالغ المطروحة (د)</th></tr><tr><th>المعرف الجبائي</th><th>الاسم الاجتماعي</th></tr></thead><tbody>
            @for($i = 0; $i < max(2, count($T('reinv'))); $i++) @php $l = $T('reinv')[$i] ?? []; @endphp
                <tr><td>{{ $l['code'] ?? '' }}</td><td dir="auto">{{ $l['cadre'] ?? '' }}</td><td class="mt">{{ $dn($l['investi'] ?? 0) }}</td><td>{{ $l['mf'] ?? '' }}</td><td dir="auto">{{ $l['nom'] ?? '' }}</td><td class="mt">{{ $dn($l['deduit'] ?? 0) }}</td></tr>
            @endfor
        </tbody><tfoot><tr><td colspan="2"></td><td class="mt">{{ $dn($A::somme($f, 'reinv', 'investi')) }}</td><td colspan="2">المجمـــــوع</td><td class="mt">{{ $dn($C['reinv_tot']) }}</td></tr></tfoot></table>

        <h2 class="bande">III- بيانات تتعلق بالمؤسسات المعفاة من الضريبة على الشركات</h2>
        <table class="g text-xs"><thead><tr><th>الرمز <sup>(2)</sup></th><th>الإطار القانوني</th><th>رقم المعاملات الخام (د)</th><th>النتيجة المحاسبية (د) ربح : ر خسارة : خ</th><th>النتيجة الجبائية (د) ربح : ر خسارة : خ</th><th>مداخيل رؤوس الأموال المنقولة التي خضعت لخصم من المورد نهائي وغير قابل للإرجاع (د)</th></tr></thead><tbody>
            @php $l = $T('exoneres')[0] ?? []; @endphp
            <tr><td>{{ $l['code'] ?? '' }}</td><td dir="auto">{{ $l['cadre'] ?? '' }}</td><td class="mt">{{ $dn($l['ca'] ?? 0) }}</td><td class="mt">{{ $dn(abs((float) ($l['res_compta'] ?? 0))) }} {!! ($l['res_compta'] ?? '') !== '' ? $cases($rp($l['res_compta']), 1) : '' !!}</td>
                <td class="mt">{{ $dn(abs((float) ($l['res_fiscal'] ?? 0))) }} {!! ($l['res_fiscal'] ?? '') !== '' ? $cases($rp($l['res_fiscal']), 1) : '' !!}</td><td class="mt">{{ $dn($l['rcm'] ?? 0) }}</td></tr>
        </tbody></table>

        <h2 class="bande">IV- الأرباح والمحاصيل غـير الخـاضعة للـضريـبة</h2>
        <table class="g"><thead><tr><th>نـــــوع الأربـــــــــاح والمحـــــــاصيـــــــل</th><th>المبلــــغ (د)</th></tr></thead><tbody>
            @foreach($A::IS['IV'][2] as $k => [, $ar])<tr><td class="lib">- {{ $ar }}</td><td class="mt">{{ $dn($n($k)) }}</td></tr>@endforeach
        </tbody><tfoot><tr><td>المجمـــوع</td><td class="mt">{{ $dn($C['ni_tot']) }}</td></tr></tfoot></table>

        <h2 class="bande">V- احتســـاب الضريبة المستوجبة <span class="text-xs font-normal">(بالدينار)</span></h2>
        <table class="g text-xs"><thead><tr><th>النسبة</th>@foreach($A::TAUX_IS as $t)<th>%{{ $t }}</th>@endforeach<th>المجمـــوع</th></tr></thead><tbody>
            @foreach(['benef_av', 'benef_net', 'ca_min_ttc', 'ca_min', 'ca_homol', 'ca_min_exp'] as $k)
                <tr><td class="lib">- {{ $A::IS['V'][2][$k][1] }}</td>@foreach($A::TAUX_IS as $t)<td class="mt">{{ $col($t) ? $dn($n($k)) : '' }}</td>@endforeach<td class="mt">{{ $dn($n($k)) }}</td></tr>
            @endforeach
            <tr><td class="lib">- الضريبة المستوجبة</td>@foreach($A::TAUX_IS as $t)<td class="mt">{{ $col($t) ? $dn($C['impot_calc']) : '' }}</td>@endforeach<td class="mt">{{ $dn($C['impot_calc']) }}</td></tr>
            <tr><td class="lib">- الضريبة الدنيا باعتبار الامتيازات الجبائية</td>@foreach($A::TAUX_IS as $t)<td class="mt">{{ $col($t) ? $dn($n('min_avant')) : '' }}</td>@endforeach<td class="mt">{{ $dn($n('min_avant')) }}</td></tr>
            <tr><td class="lib">- الضريبة الدنيا المحتسبة على أساس رقم المعاملات أو الحد الأدنى للضريبة الدنيا <sup>(10)</sup></td>@foreach($A::TAUX_IS as $t)<td class="mt">{{ $col($t) ? $dn($C['min_ca']) : '' }}</td>@endforeach<td class="mt">{{ $dn($C['min_ca']) }}</td></tr>
        </tbody></table>

        <h2 class="bande saut">VI- مراجع دفع الأقساط الاحتياطية</h2>
        <table class="g text-xs"><thead><tr><th rowspan="2">الأقساط</th><th rowspan="2">القباضة المالية</th><th colspan="2">الوصـــــولات</th><th rowspan="2">المبلغ (د)</th></tr><tr><th>التاريخ</th><th>العدد</th></tr></thead><tbody>
            @foreach(['القسط الأوّل', 'القسط الثاني', 'القسط الثالث'] as $i => $q) @php $l = $T('acomptes')[$i] ?? []; @endphp
                <tr><td class="font-bold">{{ $q }}</td><td dir="auto">{{ $l['recette'] ?? '' }}</td><td>{{ ! empty($l['date']) ? dfr($l['date']) : '' }}</td><td>{{ $l['num'] ?? '' }}</td><td class="mt">{{ $dn($l['montant'] ?? 0) }}</td></tr>
            @endforeach
        </tbody><tfoot><tr><td colspan="4">المجموع</td><td class="mt">{{ $dn($C['acomptes']) }}</td></tr></tfoot></table>

        <h2 class="bande">VII- التســـــوية</h2>
        <table class="g"><thead><tr><th></th><th>المبلغ (د)</th></tr></thead><tbody>
            <tr><td class="lib">- الضريبة على الشركات المستوجبة: ( I )</td><td class="mt">{{ $dn($C['is_du']) }}</td></tr>
            <tr><td class="lib">- الخصم من المورد والأقساط والفوائض والتسبقات القابلة للطرح : ( II )</td><td class="mt">{{ $dn($C['II']) }}</td></tr>
            @foreach(['ded_rs' => 'الخصم من المـــورد', 'ded_acp' => 'الأقساط الاحتــياطية المدفوعة', 'ded_exc' => 'الفوائض السابقة', 'ded_imp' => 'التسبقة على المنتجات الموردة', 'ded_alc' => 'التسبقة على بيوعات المشروبات الكحولية',
                'ded_qp' => 'حصة الشركة في التسبقة المستوجبة على الشركات التي تخضع لنظام شركات الأشخاص وما شابهها', 'ded_aut' => 'مبالغ أخرى قابلة للطرح'] as $k => $l)
                <tr><td class="lib" style="padding-inline-start:1.5rem">• {{ $l }}</td><td class="mt">{{ $dn($k === 'ded_acp' ? $C['acomptes'] : $n($k)) }}</td></tr>
            @endforeach
            <tr><td class="lib">- مبلغ الضريبة على الشركات المسترجع : ( III )</td><td class="mt">{{ $dn($n('restit')) }}</td></tr>
            <tr><td class="lib font-bold">- الحاصل : ( II - III + I = IV ) &nbsp; فائض : ف أو مستوجب : م {!! $cases($C['IV'] >= 0 ? 'م' : 'ف', 1) !!}</td><td class="mt font-bold">{{ $dn(abs($C['IV'])) }}</td></tr>
        </tbody></table>

        <h2 class="bande">VIII- المساهمات لفائدة حساب تنويع مصادر الضمان الاجتماعي</h2>
        <div class="font-bold">1. المساهمة المستوجبة على البنوك والمؤسسات المالية ومؤسسات التأمين وإعادة التأمين وعلى مشغلي شبكات الاتصال وعلى وكلاء بيع السيارات</div>
        <table class="g mt-1"><tbody>
            <tr><td class="lib">- الربح الصافي الخاضع للضريبة (د)</td><td class="mt">{{ $C['cc_du'] ? $dn($n('benef_net')) : '' }}</td></tr>
            <tr><td class="lib">- المساهمة لفائدة حساب تنويع مصادر الضمان الاجتماعي المستوجبة <sup>(4)</sup> (د)</td><td class="mt">{{ $dn($C['cc_du']) }}</td></tr>
            <tr><td class="lib">- طرح فائض المساهمة الصادر عن تصريح السنة الفارطة (د)</td><td class="mt">{{ $dn($n('cc_exc')) }}</td></tr>
            <tr><td class="lib">- فـائض أو مستوجب (د) : ف = فائض م = مستوجب {!! $C['cc_du'] || $n('cc_exc') ? $cases($C['cc_net'] >= 0 ? 'م' : 'ف', 1) : $cases('', 1) !!}</td><td class="mt">{{ $dn(abs($C['cc_net'])) }}</td></tr>
        </tbody></table>
        <div class="mt-3 font-bold">2. المساهمة الاجتماعية التضامنية <sup>(5)</sup></div>
        <table class="g mt-1"><tbody>
            <tr><td class="lib">- الربح الصافي الخاضع للضريبة (د)</td><td class="mt">{{ $dn($n('benef_net')) }}</td></tr>
            <tr><td class="lib">- المساهمة الاجتماعية التضامنية المستوجبة (د)</td><td class="mt">{{ $dn($C['css_du']) }}</td></tr>
            <tr><td class="lib">- طرح فائض المساهمة الاجتماعية التضامنية الصادر عن تصريح السنة الفارطة (د)</td><td class="mt">{{ $dn($n('css_exc')) }}</td></tr>
            <tr><td class="lib">- فـائض أو مستوجب (د) : ف = فائض م = مستوجب {!! $cases($C['css_net'] >= 0 ? 'م' : 'ف', 1) !!}</td><td class="mt">{{ $dn(abs($C['css_net'])) }}</td></tr>
        </tbody></table>

        <h2 class="bande">IX- معلوم الزيارة</h2>
        <table class="g" style="width:auto"><tr><th>المبلـــغ (د)</th></tr><tr><td class="mt" style="min-width:14rem">{{ $dn($n('visite')) }}</td></tr></table>
        <h2 class="bande">X- المعلوم على صندوق ترغيب الأجـراء الذي لم يتم توزيعه</h2>
        <table class="g"><tr><th>المبلـــغ (د)</th><th>النسبــــة</th><th>المعلوم المستوجب (د)</th></tr><tr><td class="mt">{{ $dn($n('fonds_base')) }}</td><td class="font-bold">%7</td><td class="mt">{{ $dn($C['fonds']) }}</td></tr></table>
        <h2 class="bande">XI- الضريبة على أرباح المنشآت الدائمة التونسية والضريبة على الأرباح الموزعة بالخارج من قبل الشركات غير المقيمة التي لها منشآت دائمة بتونس</h2>
        <div class="cadre space-y-2"><div>الاسم الاجتماعي للشركة غير المقيمة <span class="pt" dir="auto">{{ $f['etab_nom'] ?? '' }}</span></div>
            <div class="flex flex-wrap items-center gap-3">بــلد الإقامة: <span class="pt" dir="auto">{{ $f['etab_pays'] ?? '' }}</span> شركة مقيمة ببلد أو إقليم ذي نظام جبائي تفاضلي <span>نعم</span>{!! $cases($X(! empty($f['etab_paradis'])), 1) !!}<span>لا</span>{!! $cases($X(empty($f['etab_paradis']) && ! empty($f['etab_nom'])), 1) !!}</div></div>
        <table class="g mt-2"><tr><th>مبلـــغ الأرباح المحققة من قبل المنشآت الدائمة التونسية (د)</th><th>مبلـــغ الأرباح الموزعة بالخارج <sup>(8)</sup> (د)</th><th>النسبـــة <sup>(9)</sup></th><th>الضريبة المستوجبة (د)</th></tr>
            <tr><td class="mt">{{ $dn($n('etab_benef')) }}</td><td class="mt">{{ $dn($n('etab_distr')) }}</td><td>{{ $C['etab'] ? '%'.$C['etab_taux'] : '' }}</td><td class="mt">{{ $dn($C['etab']) }}</td></tr></table>

        <h2 class="bande saut">XII- الخلاصة <span class="text-xs font-normal">(بالدينار)</span></h2>
        @php $syn = ['is' => 'الضريبة على الشركات', 'exo' => 'الخطايا المستوجبة على الأرباح المعفاة والأرباح التي خضعت لخصم من المورد تحرري غير المصرّح بها', 'visite' => 'معلوم الزيارة',
            'fonds' => 'المعلوم على صندوق ترغيب الأجراء الذي لم يتم توزيعه', 'etab' => 'الضريبة على أرباح المنشآت الدائمة التونسية والضريبة على الأرباح الموزعة من قبل الشركات غير المقيمة التي لها منشآت دائمة بتونس',
            'cc' => 'المساهمة المستوجبة على البنوك والمؤسسات المالية وعلى مؤسسات التأمين وإعادة التأمين وعلى مشغلي شبكات الاتصال وعلى وكلاء بيع السيارات', 'css' => 'المساهمة الاجتماعية التضامنية']; @endphp
        <table class="g text-xs"><thead><tr><th>الضريبة أو المعلوم</th><th>الضريبة المستوجبة: م أو فائض الضريبة: ف (I)</th><th>الطرح (الضريبة المدفوعة) (II)</th><th>الضريبة المستوجبة: م أو فائض الضريبة: ف ( II -I =III)</th><th>خطايا التأخير</th><th>المجمـــوع</th></tr></thead><tbody>
            @foreach($syn as $k => $l) @php $x = $C['synthese'][$k]; @endphp
                <tr><td class="lib">- {{ $l }}</td><td class="mt">{{ $dn(abs($x['I'])) }} @if($k === 'is'){!! $cases($x['I'] >= 0 ? 'م' : 'ف', 1) !!}@endif</td><td class="mt">{{ $dn($x['II']) }}</td>
                    <td class="mt">{{ $dn(abs($x['III'])) }} @if($k === 'is'){!! $cases($x['III'] >= 0 ? 'م' : 'ف', 1) !!}@endif</td><td class="mt">{{ $dn($x['pen']) }}</td><td class="mt">{{ $dn($x['tot']) }}</td></tr>
            @endforeach
        </tbody><tfoot><tr><td>المجموع</td><td></td><td></td><td></td><td class="mt">{{ $dn(array_sum(array_column($C['synthese'], 'pen'))) }}</td><td class="mt">{{ $dn($C['tot']) }}</td></tr></tfoot></table>

        <div class="mt-4 text-center font-bold">أرقام الحسابات البنكية أو البريدية</div>
        <table class="g mt-1 text-xs"><tr><th>العدد الرتبي</th><th>المؤسسة البنكية أو الديوان الوطني للبريد</th><th>رقم الحساب البنكي أو البريدي</th><th>تاريخ فتح الحساب</th></tr>
            @for($i = 0; $i < 3; $i++) @php $l = $T('banques')[$i] ?? []; @endphp<tr><td>{{ $i + 1 }}</td><td dir="auto">{{ $l['banque'] ?? '' }}</td><td dir="ltr">{{ $l['rib'] ?? '' }}</td><td>{{ ! empty($l['ouverture']) ? dfr($l['ouverture']) : '' }}</td></tr>@endfor</table>
        @include('declarations._paiement_ar', ['cases' => $cases])

        <h2 class="bande saut">XIII- إرشادات تكميلية</h2>
        @foreach([['بيانات حول المحاسب أو الخبير المحاسب', 'compta', 'اسم ولقب المحاسب أو الخبير المحاسب أو الاسم الاجتماعي'], ['بيانات حول المستشار الجبائي', 'conseil', 'اسم ولقب المستشار الجبائي أو الاسم الاجتماعي'], ['بيانات حول مراقب الحسابات', 'cac', 'اسم ولقب مراقب الحسابات أو الاسم الاجتماعي']] as [$t, $p, $l])
            <div class="mt-3 text-center font-bold">{{ $t }}</div>
            <div class="cadre !mt-1 space-y-2"><div>{{ $l }}: <span class="pt" dir="auto" style="min-width:24rem">{{ $f[$p.'_nom'] ?? '' }}</span></div>
                @if($p === 'compta')<div class="flex items-center gap-3">الصفة: <span>أجيرا</span>{!! $cases($X(! empty($f['compta_salarie'])), 1) !!}<span>غير ذلك</span>{!! $cases($X(empty($f['compta_salarie']) && ! empty($f['compta_nom'])), 1) !!}</div>@endif
                <div>العنوان: <span class="pt" dir="auto" style="min-width:24rem">{{ $f[$p.'_adr'] ?? '' }}</span></div>
                <div>المعرف الجبائي: <span class="pt" dir="ltr">{{ $f[$p.'_mf'] ?? '' }}</span></div></div>
        @endforeach
        <div class="mt-3 text-center font-bold">بيانات حول رأس المال الاجتماعي</div>
        <table class="g mt-1"><tr><td class="lib">مبلـغ رأس المـــال الاجتماعي بالدينـــار</td><td class="mt">{{ $dn($n('capital')) }}</td></tr><tr><td class="lib">قيمـــة الســـهم أو المنـــاب الاجتماعي بالـدينـــار</td><td class="mt">{{ $dn($n('nominal')) }}</td></tr>
            <tr><td class="lib">عـدد الأســـهم أو المنـــابات الاجتماعية</td><td class="mt">{{ $f['nb_parts'] ?? '' }}</td></tr></table>
        <div class="mt-3 text-center font-bold">بيانات حول توزيع رأس المال بين الشركاء</div>
        <table class="g mt-1 text-xs"><tr><th rowspan="2">الاسم واللقب أو الاسم الاجتماعي</th><th rowspan="2">العنـــوان</th><th colspan="2">بطاقة التعريف الوطنية أو المعرف الجبائي</th><th rowspan="2">عدد الأسهم أو المنابات الاجتماعية</th><th rowspan="2">المبلغ الصافي للأرباح الموزعة خلال السنة الماضية على كل شريك</th></tr><tr><th>الرمز</th><th>المعرف</th></tr>
            @foreach($T('associes') ?: [[]] as $l)<tr><td dir="auto">{{ $l['nom'] ?? '' }}</td><td dir="auto">{{ $l['adr'] ?? '' }}</td><td>{{ $l['code'] ?? '' }}</td><td dir="ltr">{{ $l['id'] ?? '' }}</td><td>{{ $l['parts'] ?? '' }}</td><td class="mt">{{ $dn($l['dividendes'] ?? 0) }}</td></tr>@endforeach</table>
    </div>
</x-layouts.app>
