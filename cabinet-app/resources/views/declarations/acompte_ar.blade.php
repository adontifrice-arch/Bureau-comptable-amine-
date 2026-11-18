{{-- Déclaration de l'acompte provisionnel sur le modèle officiel DGI en arabe (التصريح بالقسط الاحتياطي), remplie. --}}
@php
    $P = \App\Services\DeclarationAcompte::class;
    $f = $d->champs ?? []; $C = $P::calcul($f); [$an, $num] = $P::periode($periode);
    $mf = \App\Services\Exports\FichierDgi::matricule($client->matricule_fiscal);
    preg_match('/\b(\d{4})\b(?!.*\b\d{4}\b)/', (string) $client->adresse, $cp);
    $cases = fn ($s, $n) => '<span class="cases" dir="ltr">'.collect(mb_str_split(str_pad((string) $s, $n)))->map(fn ($c) => '<span class="bx">'.e(trim($c)).'</span>')->implode('').'</span>';
    $dn = fn ($x) => (float) $x ? number_format((float) $x, 3, ',', ' ') : '';
    $v = fn ($k) => (float) ($f[$k] ?? 0);
    $rp = fn ($x) => ! (float) $x ? '' : ((float) $x < 0 ? 'خ' : 'ر');
    $ligne = fn ($lib, $x, $box = null, $gras = false) => '<tr><td class="lib'.($gras ? ' font-bold' : '').'">'.$lib.($box !== null ? ' &nbsp; '.$cases($box, 1) : '').'</td><td class="mt">'.$dn($x).'</td></tr>';
@endphp
<x-layouts.app title="التصريح بالقسط الاحتياطي">
    <div class="no-print"><x-page-header title="Formulaire officiel en arabe" :subtitle="$client->nom.' · '.$P::libelle($periode)" :back="route('declarations.index', ['type' => 'acp', 'periode' => $periode])">
        <a href="{{ route('declarations.print', ['type' => 'acp', 'periode' => $periode]) }}" class="btn-secondary">Version française</a>
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>
    @include('declarations._style_ar')
    @include('declarations._style_ar_formulaire')

    <div dir="rtl" lang="ar" class="brd mx-auto max-w-4xl bg-white p-6 text-sm shadow-sm print:max-w-none print:p-0 print:shadow-none">
        <div class="text-base font-bold leading-7">الجمهورية التونسية<br>وزارة المالية<br>الإدارة العامة للأداءات</div>
        <h1 class="mt-2 text-center text-2xl font-bold">التصريح بالقسط الاحتياطي</h1>
        <div class="mt-5 flex flex-wrap items-center justify-between gap-6 font-bold">
            <span>رمز التصريح <sup>(1)</sup> {!! $cases($f['acte'] ?? '0', 1) !!}</span>
            <span>القسط <sup>(2)</sup> {!! $cases($num, 1) !!}</span>
            <span>سنة {!! $cases($an, 4) !!}</span>
        </div>
        <table class="g mt-4"><tr><th>رقم السجل التجاري</th><th>المعرف الجبائي</th><th>رمز الأداء على القيمة المضافة</th><th>رمز الصنف</th></tr>
            <tr><td dir="ltr">{!! $cases($f['rc'] ?? '', max(10, mb_strlen($f['rc'] ?? ''))) !!}</td><td>{!! $cases($mf ? $mf['num'].$mf['cle'] : '', 8) !!}</td><td>{!! $cases($mf['tva'] ?? '', 1) !!}</td><td>{!! $cases($mf['cat'] ?? '', 1) !!}</td></tr></table>
        <div class="cadre space-y-3">
            <div class="font-bold">الاسم واللقب أو الاسم الاجتماعي : <span class="pt font-normal" dir="auto" style="min-width:28rem">{{ $client->nom }}</span></div>
            <div class="flex flex-wrap items-center gap-3 font-bold">العنوان أو المقر الاجتماعي: <span class="pt font-normal" dir="auto" style="min-width:24rem">{{ $client->adresse }}</span> الترقيم البريدي {!! $cases($cp[1] ?? '', 4) !!}</div>
            <div class="font-bold">النشاط : <span class="pt font-normal" dir="auto" style="min-width:28rem">{{ $f['activite'] ?? $client->paie_activite }}</span></div>
        </div>

        <h2 class="mt-5 text-center font-bold">احتساب القسط الاحتياطي</h2>
        <table class="g mt-2"><thead><tr><th>أساس احتساب القسط الاحتياطي</th><th>المبلغ (د)</th></tr></thead><tbody>
            {!! $ligne('قاعدة احتساب الضريبة على الدخل أو الضريبة على الشركات بعنوان مداخيل أو أرباح السنة الفارطة &nbsp; ر: ربح &nbsp; خ: خسارة', abs($v('base')), $rp($v('base'))) !!}
            {!! $ligne('القيمة الزائدة <sup>(3)</sup>', $v('pv')) !!}
            {!! $ligne('القيمة الناقصة <sup>(3)</sup>', $v('mv')) !!}
            {!! $ligne('قاعدة احتساب الضريبة النظرية قاعدة احتساب الأقساط الاحتياطية <sup>(4)</sup> &nbsp; ر: ربح &nbsp; خ: خسارة', abs($C['base_th']), $rp($C['base_th'])) !!}
            {!! $ligne('الضريبة النظرية قاعدة احتساب الأقساط الاحتياطية <sup>(5)</sup>', $C['impot_th']) !!}
            {!! $ligne('30% من الضريبة النظرية (I)', $C['I'], null, true) !!}
            <tr><td class="lib font-bold">الخصم من المورد والأقساط والفوائض والتسبقات القابلة للطرح : (II)</td><td class="mt">{{ $dn($C['II']) }}</td></tr>
            @foreach(['exc_annuel' => 'فائض الضريبة الناتج عن التصريح بعنوان مداخيل أو أرباح السنة الفارطة', 'rs' => 'المبالغ المخصومة من المورد <sup>(6)</sup>', 'av_imp' => 'التسبقة بنسبة 10% على المنتجات الموردة',
                'av_1pc' => 'التسبقة بنسبة 1% الموظفة على بيوعات المؤسسات الصناعية ومؤسسات تجارة الجملة إلى الأشخاص الطبيعيين الخاضعين للضريبة على الدخل على أساس قاعدة تقديرية في صنف أرباح المهن غير التجارية',
                'exc_prec' => 'فائض القسط الاحتياطي السابق', 'paye' => 'مبلغ القسط المدفوع <sup>(7)</sup>'] as $k => $l)
                <tr><td class="lib" style="padding-inline-start:1.2rem">- {!! $l !!}</td><td class="mt">{{ $dn($v($k)) }}</td></tr>
            @endforeach
            {!! $ligne('الباقي ( II - I = III ): &nbsp; (ب) مستوجب أو (ف) فائض', abs($C['III']), $C['III'] >= 0 ? 'ب' : 'ف', true) !!}
            {!! $ligne('الخطايا', $C['penalites']) !!}
        </tbody><tfoot><tr><td>المجمـــــــوع</td><td class="mt">{{ $dn($C['tot']) }}</td></tr></tfoot></table>
        <div class="mt-2 text-[10px] leading-4">(1) 0 : تلقائي - 1: تسوية - 2: تصحيح - 3 : توظيف إجباري. (2) ضع 1 : القسط الأول- 2 : القسط الثاني - 3 : القسط الثالث.
            (3) الناتجة عن التفويت في الأصول الثابتة المادية و الأصول الثابتة غير المادية وفي محفظة السندات. (4) قاعدة احتساب الضريبة النظرية = قاعدة احتساب الضريبة + القيمة الناقصة - القيمة الزائدة.
            (5) لا يمكن أن تقل الضريبة المحتسبة عن الحد الأدنى للضريبة. (6) أرفق التصريح بقائمة مفصلة في المبالغ المخصومة من المورد. (7) في صورة إيداع تصريح تصحيحي فقط.</div>
        <div class="saut"></div>
        @include('declarations._paiement_ar', ['cases' => $cases])
    </div>
</x-layouts.app>
