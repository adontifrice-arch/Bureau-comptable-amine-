{{--
    Bordereau de transmission du support magnétique (modèles DGI, en arabe) :
    achats → liste des bons de commandes et factures d'achats en suspension ; ventes → liste détaillée des factures émises en suspension.
--}}
@php
    $vente = $type === 'sven';
    [$an, $t] = \App\Services\Exports\FichierDgi::trimestre($periode);
    $cases = fn ($s, $n) => collect(mb_str_split(str_pad((string) $s, $n)))->map(fn ($c) => '<span class="bx">'.e(trim($c)).'</span>')->implode('');
    preg_match('/\b(\d{4})\b(?!.*\b\d{4}\b)/', (string) ($d->champs['adresse'] ?? ''), $cp);
    $dn = fn ($v) => number_format((float) $v, 3, ',', ' ');
    $objet = $vente ? 'قائمة مفصلة في الفواتير التي تم اصدارها بتأجيل توظيف الأداءات على رقم المعاملات'
                    : 'قائمة في أذون التزود و فواتير الشراء بنظام توقيف العمل بالأداء على رقم المعاملات';
@endphp
<x-layouts.app title="Bordereau de transmission">
    <div class="no-print"><x-page-header title="Bordereau de transmission" :subtitle="$client->nom.' · '.\App\Http\Controllers\DeclarationController::SUSPENSION[$type].' · '.\App\Http\Controllers\DeclarationController::libellePeriode($type, $periode)" :back="route('declarations.index', ['type' => $type, 'periode' => $periode])">
        <form class="flex items-end gap-2"><input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="periode" value="{{ $periode }}">
            <div><label class="label">Date de transmission</label><input type="date" name="date" value="{{ $date->format('Y-m-d') }}" class="input" onchange="this.form.submit()"></div></form>
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>

    @include('declarations._style_ar')
    <div dir="rtl" lang="ar" class="brd mx-auto max-w-3xl bg-white p-8 text-sm shadow-sm print:shadow-none">
        <div class="font-bold">الجمهورية التونسية</div>
        <div class="font-bold">وزارة المالية</div>
        @if($vente)<div class="mt-1 text-xs font-bold">الإدارة العامة للأداءات</div>@else<div class="mt-1">المصلحة (1) : <span class="pt"></span></div>@endif

        <h1 class="mt-6 text-center text-xl font-bold underline">جدول إحالة على حامل ممغنط</h1>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-4">
            <div class="max-w-sm font-bold leading-6">{{ $objet }}</div>
            <div class="flex items-center gap-3"><span>الثلاثية</span>{!! $cases(str_pad($t, 2, '0', STR_PAD_LEFT), 2) !!}<span class="ms-3">السنة</span>{!! $cases($an, 4) !!}</div>
        </div>
        <div class="mt-4 flex items-center gap-3"><span>تاريخ الإحالة</span><span>اليوم</span>{!! $cases($date->format('d'), 2) !!}<span>الشهر</span>{!! $cases($date->format('m'), 2) !!}<span>السنة</span>{!! $cases($date->format('Y'), 4) !!}</div>

        <table class="g mt-4" style="width:auto; margin-inline-start:auto">
            <tr><th>المعرف الجبائي</th><th>رمز الأداء على القيمة المضافة</th><th>رمز الصنف</th></tr>
            <tr><td>{!! $cases($mf ? $mf['num'].$mf['cle'] : '', 8) !!}</td><td>{!! $cases($mf['tva'] ?? '', 1) !!}</td><td>{!! $cases($mf['cat'] ?? '', 1) !!}</td></tr>
        </table>

        <div class="cadre space-y-3">
            <div>الإسم واللقب أو الإسم الاجتماعي : <span class="pt font-semibold" dir="auto">{{ $client->nom }}</span></div>
            <div>العنوان : <span class="pt" dir="auto">{{ $d->champs['adresse'] ?? '' }}</span></div>
            <div class="flex items-center gap-3">الترقيم البريدي {!! $cases($cp[1] ?? '', 4) !!}</div>
            <div class="pt-2">معلومات تتعلق بالحامل :</div>
            <div class="flex flex-wrap items-center gap-8">نوعية الحامل <sup>(1)</sup>
                <span class="flex items-center gap-2">قرص لنظام القراءة بالليزر {!! $cases('X', 1) !!}</span>
                <span class="flex items-center gap-2">دعائم مغناطيسية للمعالجة الآلية للمعلومات {!! $cases('', 1) !!}</span></div>
        </div>

        <div class="cadre">
            <table class="g">
                @if($vente)
                    <tr><th>عدد الفواتير</th><th>مجموع الثمن بدون اعتبار الأداءات (د)</th><th>مجموع المعلوم الموظف لفائدة صندوق تنمية القدرة التنافسية (د)</th><th>مجموع المعلوم على الاستهلاك (د)</th><th>مجموع الأداء على القيمة المضافة (د)</th></tr>
                    <tr class="font-semibold" dir="ltr"><td>{{ $tot['factures'] }}</td><td>{{ $dn($tot['ht']) }}</td><td>{{ $dn($tot['fodec']) }}</td><td>{{ $dn($tot['dc']) }}</td><td>{{ $dn($tot['tva']) }}</td></tr>
                @else
                    <tr><th>عدد أذون التزود</th><th>عدد الفواتير</th><th>مجموع الثمن بدون اعتبار الأداءات<br>(د)</th><th>مجموع الأداء على القيمة المضافة<br>(د)</th></tr>
                    <tr class="font-semibold" dir="ltr"><td>{{ $tot['bons'] }}</td><td>{{ $tot['factures'] }}</td><td>{{ $dn($tot['ht']) }}</td><td>{{ $dn($tot['tva']) }}</td></tr>
                @endif
            </table>
            <p class="mt-4">إني الممضي أسفله أشهد بصحة المعلومات المتضمنة بهذا الحامل</p>
            <p class="mt-2 ps-24 font-semibold">ختم وإمضاء المصرّح</p>
            <div class="h-16"></div>
        </div>

        <div class="my-5 border-t-2 border-dashed border-black"></div>

        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-5">وصل تسليم ({{ $vente ? 1 : 2 }}) :
                <span class="flex items-center gap-1">وقتي {!! $cases('', 1) !!}</span><span class="flex items-center gap-1">أوّلي {!! $cases('', 1) !!}</span>
                <span class="flex items-center gap-1">تصحيحي أوّل {!! $cases('', 1) !!}</span><span class="flex items-center gap-1">تصحيحي ثاني {!! $cases('', 1) !!}</span>
                <span class="flex items-center gap-1">نهائي {!! $cases('', 1) !!}</span></div>
            <div>{{ $vente ? 'يشهد رئيس مكتب مراقبة الأداءات بـ :' : 'يشهد (3) :' }} <span class="pt" style="min-width:26rem"></span></div>
            <div>أنه تسلم من السيد <span class="pt" style="min-width:26rem"></span></div>
            <div class="leading-7">حامل ممغنط يتعلق ب{{ $vente ? 'قائمة مفصلة في الفواتير التي تم اصدارها بتأجيل توظيف الأداءات على رقم المعاملات' : 'قائمة في أذون التزود و فواتير الشراء بنظام توقيف العمل بالأداء على رقم المعاملات' }}
                بعنوان الثلاثية <b>{{ $t }}</b> لسنة <b>{{ $an }}</b> ويتعين على المعني بالأمر الاتصال {{ $vente ? 'بمكتب مراقبة الأداءات' : 'بالمصلحة' }} يوم <span class="pt" style="min-width:5rem"></span> من شهر <span class="pt" style="min-width:6rem"></span> لإعلامه بمآل الحامل.</div>
            <div class="flex items-center gap-3">بتاريخ <span>اليوم</span>{!! $cases('', 2) !!}<span>الشهر</span>{!! $cases('', 2) !!}<span>السنة</span>{!! $cases('', 4) !!}</div>
            <p class="ps-24 font-semibold">{{ $vente ? 'ختم وإمضاء رئيس المكتب' : 'ختم وإمضاء المصلحة (1)' }}</p>
            <div class="h-14"></div>
        </div>

        <ol class="mt-4 list-none space-y-0.5 border-t border-black pt-2 text-xs">
            @if($vente)
                <li>(1) ضع (X) في القسيمة المناسبة</li>
            @else
                <li>(1) إدارة المؤسسات الكبرى أو مكتب مراقبة الأداءات المختص ترابيا</li>
                <li>(2) ضع (X) في الخانة المناسبة</li>
                <li>(3) مدير إدارة المؤسسات الكبرى أو رئيس مكتب مراقبة الأداءات المختص ترابيا</li>
            @endif
        </ol>
    </div>
</x-layouts.app>
