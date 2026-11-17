{{-- Liste détaillée des factures émises en suspension des taxes sur le chiffre d'affaires (modèle papier DGI, en arabe), suivie du reçu de dépôt. --}}
@php
    [$an, $t] = \App\Services\Exports\FichierDgi::trimestre($periode);
    $cases = fn ($s, $n) => collect(mb_str_split(str_pad((string) $s, $n)))->map(fn ($c) => '<span class="bx">'.e(trim($c)).'</span>')->implode('');
    preg_match('/\b(\d{4})\b(?!.*\b\d{4}\b)/', (string) ($d->champs['adresse'] ?? ''), $cp);
    $dn = fn ($v) => (float) $v ? number_format((float) $v, 3, ',', ' ') : '';
    $pc = fn ($v) => (float) $v ? str_replace('.', ',', (string) (float) $v).' %' : '';
    $titre = 'قائمة مفصلة في الفواتير التي تم اصدارها بتأجيل توظيف الأداءات على رقم المعاملات';
    $L = $d->annexes['l'] ?? [];
@endphp
<x-layouts.app title="Liste détaillée des factures en suspension">
    <div class="no-print"><x-page-header title="Liste détaillée des factures émises en suspension" :subtitle="$client->nom.' · '.\App\Http\Controllers\DeclarationController::libellePeriode('sven', $periode)" :back="route('declarations.index', ['type' => 'sven', 'periode' => $periode])">
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>

    @include('declarations._style_ar')
    <style>@media print { @page { size: A4 landscape; margin: 10mm; } } .brd table.l th, .brd table.l td { font-size: 11px; padding: .25rem .3rem; }</style>
    <div dir="rtl" lang="ar" class="brd mx-auto bg-white p-6 text-sm shadow-sm print:shadow-none">
        <div class="flex items-start justify-between">
            <div class="text-xs font-bold leading-5">الجمهورية التونسية<br>وزارة المالية<br>الإدارة العامة للأداءات</div>
            <h1 class="text-center text-lg font-bold leading-7">قائمة مفصلة في الفواتير التي تم اصدارها<br>بتأجيل توظيف الأداءات على رقم المعاملات</h1>
            <div class="w-40"></div>
        </div>
        <div class="mt-3 flex items-center justify-center gap-3"><span>الثلاثية</span>{!! $cases($t, 1) !!}<span class="ms-4">السنة</span>{!! $cases($an, 4) !!}</div>
        <table class="g mx-auto mt-3" style="width:auto">
            <tr><th>المعرف الجبائي</th><th>رمز الأداء على القيمة المضافة</th><th>رمز الصنف</th></tr>
            <tr><td>{!! $cases($mf ? $mf['num'].$mf['cle'] : '', 8) !!}</td><td>{!! $cases($mf['tva'] ?? '', 1) !!}</td><td>{!! $cases($mf['cat'] ?? '', 1) !!}</td></tr>
        </table>
        <div class="mt-3 space-y-1.5">
            <div><b>الاسم واللقب أو الاسم الاجتماعي :</b> <span class="pt" dir="auto">{{ $client->nom }}</span></div>
            <div class="flex flex-wrap items-center gap-3"><b>العنوان أو المقر الاجتماعي :</b> <span class="pt" dir="auto" style="min-width:24rem">{{ $d->champs['adresse'] ?? '' }}</span><b>الترقيم البريدي</b> {!! $cases($cp[1] ?? '', 4) !!}</div>
            <div><b>النشاط :</b> <span class="pt" dir="auto">{{ $d->champs['activite'] ?? '' }}</span></div>
        </div>

        <table class="g l mt-4">
            <thead>
                <tr><th rowspan="3">العدد<br>الرتبي</th><th colspan="2">الفاتورة</th><th colspan="4">معلومات خاصّة بالحريف</th><th colspan="2">القرار الإداري المتعلّق<br>بعملية البيع</th><th colspan="7">عملية البيع بتأجيل توظيف الأداء</th></tr>
                <tr><th rowspan="2">الرقم</th><th rowspan="2">التاريخ</th><th colspan="2">المعرف الجبائي أو رقم بطاقة التعريف الوطنية أو بطاقة الإقامة بالنسبة إلى الأجانب أو معرف آخر</th>
                    <th rowspan="2">الإسم واللقب أو الإسم الإجتماعي</th><th rowspan="2">العنوان</th><th rowspan="2">العدد</th><th rowspan="2">التاريخ</th>
                    <th rowspan="2">الثمن بدون اعتبار الأداء (د)</th><th colspan="2">المعلوم الموظف لفائدة صندوق تنمية القدرة التنافسية</th><th colspan="2">المعلوم على الاستهلاك</th><th colspan="2">الأداء على القيمة المضافة</th></tr>
                <tr><th>الرمز (1)</th><th>المعرف</th><th>النسبة</th><th>مبلغ الأداء (د)</th><th>النسبة</th><th>مبلغ الأداء (د)</th><th>النسبة</th><th>مبلغ الأداء (د)</th></tr>
            </thead>
            <tbody>
                @forelse($L as $i => $l)
                    <tr><td>{{ $i + 1 }}</td><td dir="ltr">{{ $l['fac'] }}</td><td dir="ltr">{{ dfr($l['fac_date']) }}</td><td>{{ $l['idk'] ?? '' }}</td><td dir="ltr">{{ $l['id'] ?? '' }}</td>
                        <td dir="auto">{{ $l['nom'] }}</td><td dir="auto">{{ $l['adr'] ?? '' }}</td><td dir="ltr">{{ $l['att'] ?? '' }}</td><td dir="ltr">{{ !empty($l['att_date']) ? dfr($l['att_date']) : '' }}</td>
                        <td dir="ltr">{{ $dn($l['ht']) }}</td><td dir="ltr">{{ $pc($l['fodec_taux'] ?? 0) }}</td><td dir="ltr">{{ $dn($l['fodec'] ?? 0) }}</td><td dir="ltr">{{ $pc($l['dc_taux'] ?? 0) }}</td><td dir="ltr">{{ $dn($l['dc'] ?? 0) }}</td>
                        <td dir="ltr">{{ $pc($l['taux'] ?? 0) }}</td><td dir="ltr">{{ $dn($l['tva']) }}</td></tr>
                @empty
                    <tr><td colspan="16" class="h-24">—</td></tr>
                @endforelse
            </tbody>
            <tfoot><tr class="font-semibold"><td colspan="9">المجمــــوع</td><td dir="ltr">{{ $dn($tot['ht']) }}</td><td></td><td dir="ltr">{{ $dn($tot['fodec']) }}</td><td></td><td dir="ltr">{{ $dn($tot['dc']) }}</td><td></td><td dir="ltr">{{ $dn($tot['tva']) }}</td></tr></tfoot>
        </table>
        <div class="mt-2 text-[11px] leading-5">(1) الرمــز : 1 – المعرف الجبائي بالنسبة إلى الشركات والأشخاص الطبيعيين الذين يتعاطون نشاطا تجاريا أو صناعيا أو إسداء خدمات أو حرفيا أو نشاط غير تجاري.
            2 – بطاقة التعريف الوطنية بالنسبة إلى الأشخاص الطبيعيين غير المعرّفين بمعرف جبائي. 3 – بطاقة الإقامة للأجانب. 4 – معرف الأشخاص غير المقيمين أو غير المستقرين بالبلاد التونسية إن وجد.</div>

        {{-- Reçu de dépôt de la liste --}}
        <div class="saut mx-auto max-w-3xl pt-6">
            <div class="font-bold">الجمهورية التونسية</div><div class="font-bold">وزارة المـــالية</div>
            <div class="mt-1">مكتب مراقبة الأداءات بـ <span class="pt"></span></div>
            <div class="mt-3 flex items-center gap-3">الرمز {!! $cases('', 2) !!}</div>
            <h2 class="mt-4 text-center text-lg font-bold leading-7">وصل إيداع في قائمة مفصلة في الفواتير التي تمّ<br>إصدارها بتأجيل توظيف الأداءات على رقم المعاملات</h2>
            <div class="mt-4 flex items-center gap-3"><span>الثلاثية</span>{!! $cases($t, 1) !!}<span class="ms-4">السنة</span>{!! $cases($an, 4) !!}</div>
            <div class="mt-3 flex items-center gap-3">تاريخ إيداع القائمة : <span>اليوم</span>{!! $cases('', 2) !!}<span>الشهر</span>{!! $cases('', 2) !!}<span>السنة</span>{!! $cases('', 4) !!}</div>
            <table class="g mt-3" style="width:auto; margin-inline-start:auto">
                <tr><th>المعرف الجبائي</th><th>رمز الأداء على القيمة المضافة</th><th>رمز الصنف</th></tr>
                <tr><td>{!! $cases($mf ? $mf['num'].$mf['cle'] : '', 8) !!}</td><td>{!! $cases($mf['tva'] ?? '', 1) !!}</td><td>{!! $cases($mf['cat'] ?? '', 1) !!}</td></tr>
            </table>
            <div class="cadre space-y-3">
                <div>الاسم واللقب أو الاسم الاجتمـــاعي : <span class="pt font-semibold" dir="auto">{{ $client->nom }}</span></div>
                <div>العنوان : <span class="pt" dir="auto">{{ $d->champs['adresse'] ?? '' }}</span></div>
                <div class="flex items-center gap-3">الترقيم البريدي {!! $cases($cp[1] ?? '', 4) !!}</div>
            </div>
            <div class="cadre">
                <table class="g">
                    <tr><th>عدد الفواتير</th><th>مجموع الثمن بدون اعتبار الأداءات (د)</th><th>مجموع المعلوم الموظف لفائدة صندوق تنمية القدرة التنافسية (د)</th><th>مجموع المعلوم على الاستهلاك (د)</th><th>مجموع الأداء على القيمة المضافة (د)</th></tr>
                    <tr class="font-semibold" dir="ltr"><td>{{ $tot['factures'] }}</td><td>{{ number_format($tot['ht'], 3, ',', ' ') }}</td><td>{{ number_format($tot['fodec'], 3, ',', ' ') }}</td><td>{{ number_format($tot['dc'], 3, ',', ' ') }}</td><td>{{ number_format($tot['tva'], 3, ',', ' ') }}</td></tr>
                </table>
                <p class="mt-4">إني الممضي أسفله أشهد بصحة المعلومات المتضمنة بهذه القائمة.</p>
                <p class="mt-2 ps-24 font-semibold">ختم وإمضاء المصرّح</p>
                <div class="h-14"></div>
            </div>
            <div class="cadre space-y-3">
                <div class="text-center font-bold">خاص بالإدارة</div>
                <div>يشهد رئيس مكتب مراقبة الأداءات بـ : <span class="pt" style="min-width:24rem"></span></div>
                <div>أنه تسلم من السيد <span class="pt" style="min-width:24rem"></span></div>
                <div>{{ $titre }} للثلاثية <b>{{ $t }}</b> لسنة <b>{{ $an }}</b></div>
                <div class="flex items-center gap-3"><span>اليوم</span>{!! $cases('', 2) !!}<span>الشهر</span>{!! $cases('', 2) !!}<span>السنة</span>{!! $cases('', 4) !!}</div>
                <p class="ps-24 font-semibold">ختم وإمضاء رئيس المكتب</p>
                <div class="h-12"></div>
            </div>
        </div>
    </div>
</x-layouts.app>
