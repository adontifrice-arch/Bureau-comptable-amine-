{{-- Déclaration mensuelle sur le modèle officiel DGI en arabe (التصريح الشهري بالأداءات), remplie avec les bases, taux et montants. --}}
@php
    $M = \App\Services\DeclarationMensuelle::class;
    $f = $M::convertir($d->champs ?? []); $C = $M::calcul($f); $imp = $M::impots($f);
    [$an, $mois] = explode('-', $periode);
    $mf = \App\Services\Exports\FichierDgi::matricule($client->matricule_fiscal);
    preg_match('/\b(\d{4})\b(?!.*\b\d{4}\b)/', (string) $client->adresse, $cp);
    // Cases : chiffres et matricule écrits de gauche à droite, même dans le formulaire en arabe
    $cases = fn ($s, $n) => '<span class="cases" dir="ltr">'.collect(mb_str_split(str_pad((string) $s, $n)))->map(fn ($c) => '<span class="bx">'.e(trim($c)).'</span>')->implode('').'</span>';
    $dn = fn ($x) => (float) $x ? number_format((float) $x, 3, ',', ' ') : '';
    $v = fn ($k) => $M::valeur($f, $k);
    $tx = fn ($k) => ($t = $v($k)['t']) ? str_replace('.', ',', (string) (float) $t) : '';
    $x = fn ($k) => in_array($k, $imp, true) ? 'X' : '';
    $S = $C['sections'];
    // Retenues à la source : [libellé, rubrique ou null (titre), taux imprimé, retrait]
    $RS = [
        ['1- المرتبات والأجور والجرايات والإيرادات العمرية التي تحمّلت خصما من المورد طبقا للقانون العام', 'rs1', '', 0],
        ['2- المرتبات والأجور والمكافآت والمنح والامتيازات العينية الأخرى المدفوعة للأجانب', 'rs2', '%20 (%25 في صورة تحمل عبء الضريبة)', 0],
        ['3- المساهمة الاجتماعية التضامنية المستوجبة على المرتبات والأجور والمنح والجرايات والإيرادات العمرية', 'rs3', '', 0],
        ['4- العمولات وأجور الوساطة والأكرية ومكافآت الأنشطة غير التجارية', null, '', 0],
        ['* المدفوعة للمقيمين والمستقرين', null, '', 1], ['• أشخاص طبيعيون', 'rs4a', '%10', 2], ['• أشخاص معنويون', 'rs4b', '%10', 2],
        ['* المدفوعة لغير المقيمين وغير المستقرين', null, '', 1], ['• أشخاص طبيعيون', 'rs4c', '%15 (%17,64 في صورة تحمل عبء الضريبة)', 2], ['• أشخاص معنويون', 'rs4d', '%15 (%17,64 في صورة تحمل عبء الضريبة)', 2],
        ['5- الأتعاب المدفوعة للأشخاص الطبيعيين غير الخاضعين للنظام الحقيقي', 'rs5', '%10', 0],
        ['6- الأتعاب المدفوعة للأشخاص المعنويين الخاضعين للضريبة على الشركات والتجمعات وشركات الأشخاص وما شابهها وللأشخاص الطبيعيين الخاضعين للضريبة على الدخل حسب النظام الحقيقي', 'rs6', '%3', 0],
        ['7- المكافآت المدفوعة إلى الفنانين والمبدعين', 'rs7', '%5', 0],
        ['8- أكرية النزل المدفوعة للأشخاص المعنويين الخاضعين للضريبة على الشركات والتجمعات وشركات الأشخاص وما شابهها وللأشخاص الطبيعيين الخاضعين للضريبة على الدخل حسب النظام الحقيقي', 'rs8', '%5', 0],
        ['9- المكافآت المدفوعة مقابل النجاعة في إسداء الخدمات لفائدة الغير', 'rs9', '%10', 0],
        ['10- فوائد الإيداعات بالحسابات الخاصة للادخار لدى المؤسسات البنكية وصندوق الادخار الوطني التونسي وفوائد القروض الرقاعية بما في ذلك القروض الرقاعية الخضراء والمسؤولة اجتماعيا والمستدامة وفوائد رقاع الخزينة القابلة للتنظير وفوائد القروض الوطنية', 'rs10', '%20 (%25 في صورة تحمل عبء الضريبة)', 0],
        ['11- مداخيل رؤوس الأموال المنقولة الأخرى', null, '', 0],
        ['* الراجعة للمقيمين', null, '', 1], ['• أشخاص طبيعيون', 'rs11a', '%20', 2], ['• أشخاص معنويون', 'rs11b', '%20 (%25 في صورة تحمل عبء الضريبة)', 2],
        ['* الراجعة لغير المقيمين', null, '', 1], ['• أشخاص طبيعيون', 'rs11c', '%20 (%25 في صورة تحمل عبء الضريبة)', 2], ['• أشخاص معنويون', 'rs11d', '%20 (%25 في صورة تحمل عبء الضريبة)', 2],
        ['12- حصص الأسهم', null, '', 0],
        ['* الراجعة إلى الأشخاص الطبيعيين المقيمين', 'rs12a', '%10 (%11,11 في صورة تحمل عبء الضريبة)', 1],
        ['* الراجعة إلى الأشخاص المعنويين والأشخاص الطبيعيين غير المقيمين', 'rs12b', '%10 (%11,11 في صورة تحمل عبء الضريبة)', 1],
        ['13- المكافآت والمنح المسندة لأعضاء المجالس والهيئات واللجان', null, '', 0],
        ['* الراجعة للمقيمين', null, '', 1], ['• أشخاص طبيعيون', 'rs13a', '%20', 2], ['• أشخاص معنويون', 'rs13b', '%20', 2],
        ['* الراجعة لغير المقيمين', null, '', 1], ['• أشخاص طبيعيون', 'rs13c', '%20 (%25 في صورة تحمل عبء الضريبة)', 2], ['• أشخاص معنويون', 'rs13d', '%20 (%25 في صورة تحمل عبء الضريبة)', 2],
        ['14- التأجيرات المدفوعة للأجراء وغير الأجراء مقابل عمل وقتي أو ظرفي خارج نشاطهم الأصلي', 'rs14', '%15', 0],
        ['15- فوائد القروض المدفوعة للمؤسسات البنكية غير المستقرة بالبلاد التونسية', 'rs15', '%10 (%11,11 في صورة تحمل عبء الضريبة)', 0],
        ['16- سعر التفويت في العقارات وفي الحقوق المتعلّقة بها المصرح به بالعقد', null, '', 0],
        ['* بالنسبة إلى المقيمين أو المستقرّين', 'rs16a', '%2,5', 1],
        ['* بالنسبة إلى غير المقيمين وغير المستقرّين', null, '', 1], ['• أشخاص طبيعيون', 'rs16b', '%2,5 (%2,56 في صورة تحمل عبء الضريبة)', 2],
        ['• أشخاص معنويون غير مستقرون بالنسبة للعقارات والحقوق المتعلّقة بها والحقوق الاجتماعية في الشركات المدنية العقارية', 'rs16c', '%15 (%17,64 في صورة تحمل عبء الضريبة)', 2],
        ['17- المبالغ المدفوعة بعنوان الاقتناءات من سلع ومعدّات وتجهيزات وخدمات التي تساوي أو تفوق 1000 دينار بما في ذلك الأداء على القيمة المضافة', null, '', 0],
        ['* لدى المؤسّسات الخاضعة للضّريبة على الشّركات بنسبة 20%', 'rs17a', '%1', 1], ['* لدى المؤسّسات الخاضعة للضّريبة على الشّركات بنسبة 10%', 'rs17b', '%0,5', 1],
        ['* لدى المؤسّسات الفرديّة المنتفعة بطرح ثلثي المداخيل', 'rs17c', '%0,5', 1], ['* لدى المؤسّسات الأخرى', 'rs17d', '%1,5', 1],
        ['18- الخصم من المورد بعنوان الأداء على القيمة المضافة الموظف على المبالغ المدفوعة من قبل مصالح الدولة والجماعات المحلية والمؤسسات والمنشآت العمومية والتي تساوي أو تفوق 1000 دينار بما في ذلك الأداء على القيمة المضافة', 'rs18', '%25', 0],
        ['19- الخصم من المورد بعنوان الأداء على القيمة المضافة المتعلق بالعمليات المنجزة مع الأشخاص الذين ليست لهم مؤسسة بالبلاد التونسية', 'rs19', '%100', 0],
        ['20- المكافآت المدفوعة إلى غير المقيمين المستقرين الذين ينجزون أشغال بناء أو عمليات تركيب أو خدمات أخرى لا تتجاوز مدتها ستة أشهر', 'rs20', '%5 أو %10 أو %15 حسب الحالة', 0],
        ['21- المبالغ المدفوعة إلى غير المقيمين الذين ينشطون في إطار منشأة دائمة والذين لا يودعون التصريح في الوجود قبل بدأ النشاط', null, '', 0],
        ['* منشآت دائمة تابعة لمؤسسات مقيمة ببلد أو إقليم ذي نظام جبائي تفاضلي', 'rs21a', '%25 (%33,33 في صورة تحمل عبء الضريبة)', 1],
        ['* منشآت دائمة أخرى', 'rs21b', '%15 (%17,64 في صورة تحمل عبء الضريبة)', 1],
        ['22- التسبقة الموظفة على بيوعات المؤسسات الصناعية ومؤسسات تجارة الجملة إلى الأشخاص الطبيعيين الخاضعين للضريبة على الدخل حسب النظام التقديري في صنف الأرباح الصناعية والتجارية أو على أساس قاعدة تقديرية في صنف أرباح المهن غير التجارية', 'rs22', '%1', 0],
        ['23- التسبقة الموظّفة على بيوعات مصنّعي ومعبئي الخمور والجعة والمشروبات الكحولية من المنتجات المذكورة', 'rs23', '%5', 0],
        ['24- القيمة الزائدة المتأتية من تفويت الأشخاص غير المقيمين وغير المستقرين في الأسهم أو في المنابات الاجتماعية أو في حصص الصناديق المنصوص عليها بالتشريع المتعلق بها', null, '', 0],
        ['* أشخاص طبيعيون', 'rs24a', '%10 (%11,11 في صورة تحمل عبء الضريبة)', 1], ['* أشخاص معنويون', 'rs24b', '%20 (%25 في صورة تحمل عبء الضريبة)', 1],
        ['25- المكافآت الأخرى المدفوعة إلى غير المقيمين غير المستقرّين', null, '', 0],
        ['* أشخاص طبيعيون', 'rs25a', '%15 (%17,64 في صورة تحمل عبء الضريبة)', 1], ['* أشخاص معنويون', 'rs25b', '%15 (%17,64 في صورة تحمل عبء الضريبة)', 1],
        ['26- المكافآت الراجعة إلى أشخاص مقيمين أو مستقرين ببلد أو إقليم ذي نظام جبائي تفاضلي', 'rs26', '%25 (%33,33 في صورة تحمل عبء الضريبة)', 0],
        ['27- مبلغ العمولة الراجعة إلى الموزعين المعتمدين لمشغلي شبكات الاتصالات', null, '', 0],
        ['* أشخاص طبيعيون', 'rs27a', '%1,5', 1], ['* أشخاص معنويون', 'rs27b', '%1', 1],
        ['28- الخصم من المورد بعنوان معاليم التسجيل المستوجبة على عقود الصفقات العمومية', 'rs28', '', 0],
        ['29- الخصم من المورد بعنوان المبالغ المتأتية من ألعاب الرهان والحظ واليانصيب', 'rs29', '%25 (%33,33 في صورة تحمل عبء الضريبة)', 0],
        ['30- بيوعات مؤسسات الإنتاج الصناعي والمؤسسات الناشطة في قطاع التجارة لفائدة المتدخلين في توزيع السلع والمنتجات والخدمات والتي لا تتجاوز قيمتها الجملية 20.000 دينار سنويا', 'rs30', '%3', 0],
        ['31- المبالغ التي يدفعها مسديو خدمات التوصيل إلى الأشخاص الذين يتولون بيع سلعهم ومنتجاتهم عبر الأنترنت وعبر وسائل البث السمعي والبصري ولا يستظهرون ببطاقة تعريف جبائي', 'rs31', '%3', 0],
    ];
    // Autres taxes : [libellé, rubrique ou null, taux imprimé, retrait]
    $AUT = [
        ['1- استخلاصات لفائدة صندوق تنمية القدرة التنافسية في قطاعات الصناعة والخدمات والصناعات التقليدية', null, '', 0],
        ['- المعلوم المهني', 'au1a', '%1', 1], ['- المعلوم على المصبرات الغذائية', 'au1b', '%1', 1],
        ['2- استخلاصات لفائدة صندوق تنمية القدرة التنافسية في القطاع الفلاحي والصيد البحري', null, '', 0],
        ['- المعلوم الموظف على منتوجات الصيد البحري', 'au2a', '%2', 1], ['- المعلوم على القطانيا والصوجا', 'au2b', '%2,5', 1],
        ['- المعلوم على الخضر والغلال', 'au2c', '%2', 1], ['- المعلوم على اللحوم', 'au2d', '0,050 د/ كغ', 1],
        ['3- استخلاصات لفائدة صندوق تنمية القدرة التنافسية في قطاع السياحة', null, '', 0],
        ['- المعلوم الموظف على مستغلي المؤسسات السياحية والمطاعم السياحية المصنفة', 'au3a', '%1', 1],
        ['- المعلوم على الرحلات الجوية والبحرية الدولية :', null, '', 1],
        ['* الرحلات الجوية والبحرية من صنف الدرجة الأولى أو صنف الأعمال', 'au3b', '60 د/ مسافر', 2], ['* الرحلات الأخرى', 'au3c', '40 د/ مسافر', 2],
        ['4- الأتاوة لفائدة صندوق تنمية المواصلات وتكنولوجيات المعلومات والاتصال', 'au4', '%5', 0],
        ['5- استخلاصات لفائدة الصندوق الوطني للتشغيل:', null, '', 0],
        ['- المعلوم على البيوعات المحلية للشاي والقهوة', 'au5a', '0,150 د / كغ', 1], ['- المعلوم التعويضي على الإسمنت', 'au5b', '2 د / طن', 1], ['- المعلوم على بيع الإسمنت', 'au5c', '1 د / طن', 1],
        ['6- المعلوم الموظف على الطماطم المعدة للتحويل:', null, '', 0],
        ['- مساهمة منتجي الطماطم', 'au6a', '0,005 د / كغ', 1], ['- مساهمة مستغلي وحدات التحويل', 'au6b', '0,028 د / كغ', 1],
        ['7- المعلوم للمحافظة على البيئة لفائدة صندوق مقاومة التلوث', 'au7', '%7', 0],
        ['8- استخلاصات لفائدة صندوق الانتقال الطاقي:', null, '', 0],
        ['- معلوم على أجهزة تكييف الهواء', 'au8a', '10د /1000واط', 1], ['- معلوم على المصابيح والأنابيب', 'au8b', '%60', 1], ['- معلوم على المنتجات الطاقية المستهلكة', 'au8c', '', 1],
        ['9- معلوم على الألعاب والمسابقات التي تتم المشاركة فيها من خلال مختلف وسائل تكنولوجيات الاتصال', 'au9', '%40', 0],
        ['10- معلوم لفائدة صندوق التشجيع على الإبداع الأدبي والفني', 'au10', '%1', 0],
        ['11- معلوم لفائدة صندوق تمويل الراحة البيولوجية في قطاع الصيد البحري', 'au11', '%1', 0],
        ['12- أتاوة الدعم لفائدة الصندوق العام للتعويض:', null, '', 0],
        ['- الأتاوة الموظفة على المؤسسات السياحية التي تقدم خدمات إيواء الحرفاء والحانات والمطاعم السياحية المصنفة والمطاعم المستغلّة تحت التسمية الأصلية لتسمية أو لعلامة تجارية أجنبية والمقاهي من الصنف الثاني والصنف الثالث وقاعات الشاي وصناعات المشروبات الغازية والجعة والخمور والمشروبات الكحولية', 'au12a', '%3', 1],
        ['- الأتاوة الموظفة على الملاهي والنوادي الليلية غير التابعة لمؤسسة سياحية والكباريات ومحلات صنع المرطبات', 'au12b', '%5', 1],
        ['13- معلوم تضامني لفائدة صندوق تعويض الأضرار الفلاحية الناجمة عن الجوائح الطبيعية موظف على:', null, '', 0],
        ['- الخضر والغلال', 'au13a', '%1', 1], ['- الحبوب المجمعة من قبل ديوان الحبوب', 'au13b', '%1', 1], ['- الزيتون', 'au13c', '%1', 1], ['- منتجات الصيد البحري', 'au13d', '%1', 1],
        ['14- معلوم الإقامة بالمؤسّسات السياحية', 'au14', '', 0],
        ['15- معلوم لفائدة صندوق دعم الصحة العمومية', 'au15', '%1', 0],
        ['16- المعلوم على السكر', 'au16', '0.100 د / كغ', 0],
        ['17- المعلوم على مشتقات الحليب', null, '', 0],
        ['- القوتة', 'au17a', '1.5 د /كغ', 1], ['- القشدة', 'au17b', '2 د /كغ', 1], ['- الأجبان الأخرى والأجبان المبشورة وغيرها باستثناء المطبوخة والطازجة', 'au17c', '3 د /كغ', 1],
        ['18- استخلاصات لفائدة حساب تنويع مصادر الضمان الاجتماعي:', null, '', 0],
        ['- مبلغ إضافي قدره 0,100 دينار على كل عملية شحن لرصيد الهاتف الجوال تساوي أو تفوق 5 دنانير', 'au18a', '', 1],
        ['- اقتطاع دينارين من السعر اليومي لكراء السيارات، عن كل سيارة، يدفع من قبل مؤسسات كراء السيارات', 'au18b', '', 1],
        ['19- اقتطاع لفائدة صندوق النهوض بالأشخاص ذوي الإعاقة', 'au19', '%1', 0],
    ];
    $ligne = function (array $r, bool $avecBase = true) use ($v, $dn) {
        [$lib, $k, $t, $n] = $r;
        $pad = ['', 'padding-inline-start:1.2rem', 'padding-inline-start:2.4rem'][$n];
        if (! $k) return '<tr><td class="lib" style="'.$pad.'">'.e($lib).'</td><td></td><td></td><td></td></tr>';
        $x = $v($k);
        return '<tr><td class="lib" style="'.$pad.'">'.e($lib).'</td><td class="mt">'.($avecBase ? $dn($x['b']) : '').'</td><td class="tx">'.e($t).'</td><td class="mt">'.$dn($x['m']).'</td></tr>';
    };
@endphp
<x-layouts.app title="التصريح الشهري بالأداءات">
    <div class="no-print"><x-page-header title="Formulaire officiel en arabe" :subtitle="$client->nom.' · '.mois_fr($periode)" :back="route('declarations.index', ['type' => 'men', 'periode' => $periode])">
        <a href="{{ route('declarations.print', ['type' => 'men', 'periode' => $periode]) }}" class="btn-secondary">Version française</a>
        <button onclick="window.print()" class="btn-primary"><x-icon name="printer" class="h-4 w-4"/> Imprimer / PDF</button></x-page-header></div>

    @include('declarations._style_ar')
    <style>
        .brd .cases { display: inline-flex; direction: ltr; unicode-bidi: isolate; vertical-align: middle; }
        .brd h2.bande { background: #e5e7eb; text-align: center; font-weight: 700; padding: .35rem; margin: 1.2rem 0 .5rem; }
        .brd table.g td.lib { text-align: right; }
        .brd table.g td.mt { text-align: left; direction: ltr; font-family: 'Courier New', monospace; white-space: nowrap; min-width: 7rem; }
        .brd table.g td.tx { white-space: nowrap; font-size: 11px; }
        .brd table.g th { background: #f3f4f6; }
        .brd table.g tfoot td { font-weight: 700; }
        .brd td.coche { height: 2.4rem; }
        .brd td.coche .bx { font-size: 1.1rem; font-weight: 700; }
        @media print { @page { size: A4; margin: 10mm; } .brd table.g td, .brd table.g th { padding: .2rem .35rem; } }
    </style>

    <div dir="rtl" lang="ar" class="brd mx-auto max-w-5xl bg-white p-6 text-sm shadow-sm print:max-w-none print:p-0 print:shadow-none">
        {{-- Page 1 : entête --}}
        <div class="flex items-start justify-between">
            <div class="text-xs font-bold leading-5">الجمهورية التونسية<br>وزارة المالية</div>
            <h1 class="text-2xl font-bold" style="color:#9a3412">التصريح الشهري بالأداءات</h1>
            <div class="text-xs font-bold leading-5">الإدارة العامة للأداءات</div>
        </div>
        <div class="mt-5 flex flex-wrap items-center justify-center gap-6">
            <span>رمز التصريح <sup>(1)</sup> {!! $cases($f['acte'] ?? '0', 1) !!}</span>
            <span>الشهر {!! $cases($mois, 2) !!}</span>
            <span>السنة {!! $cases($an, 4) !!}</span>
        </div>
        <table class="g mx-auto mt-3" style="width:auto">
            <tr><th>المعرف الجبائي</th><th>رمز الأداء على القيمة المضافة</th><th>رمز الصنف</th></tr>
            <tr><td>{!! $cases($mf ? $mf['num'].$mf['cle'] : '', 8) !!}</td><td>{!! $cases($mf['tva'] ?? '', 1) !!}</td><td>{!! $cases($mf['cat'] ?? '', 1) !!}</td></tr>
        </table>
        <div class="cadre space-y-2">
            <div>الاسم واللقب أو الاسم الاجتماعي: <span class="pt" dir="auto" style="min-width:30rem">{{ $client->nom }}</span></div>
            <div>العنوان أو المقر الاجتماعي: <span class="pt" dir="auto" style="min-width:30rem">{{ $client->adresse }}</span></div>
            <div class="flex items-center gap-3">الترقيم البريدي {!! $cases($cp[1] ?? '', 4) !!}</div>
            <div class="flex flex-wrap items-center gap-3">النشاط: <span class="pt" dir="auto">{{ $client->paie_activite }}</span>
                <span class="ms-6">تاريخ توقيف النشاط</span> <span>اليوم</span>{!! $cases('', 2) !!}<span>الشهر</span>{!! $cases('', 2) !!}<span>السنة</span>{!! $cases('', 4) !!}</div>
        </div>
        <div class="mt-4 font-bold">نوع الأداء أو المعلوم المدفوع على أساس هذا التصريح (ضع علامة (x) في الخانة المناسبة)</div>
        <table class="g mt-2 text-xs">
            <tr><th rowspan="2">الخصم من المورد</th><th rowspan="2">الأداء على التكوين المهني</th><th rowspan="2">المساهمة في صندوق النهوض بالمسكن لفائدة الأجراء</th><th rowspan="2">المعلوم على الاستهلاك</th>
                <th rowspan="2">الأداء على القيمة المضافة</th><th rowspan="2">معاليم أخرى على رقم المعاملات</th><th rowspan="2">معلوم الطابع الجبائي</th><th colspan="3">المعاليم الراجعة للجماعات المحلية</th></tr>
            <tr><th>المعلوم على المؤسسات</th><th>المعلوم على النزل</th><th>معلوم الإجازة</th></tr>
            <tr>@foreach(['rs', 'tfp', 'fop', 'dc', 'tva', 'aut', 'tim', 'tcl_ent', 'tcl_hot', 'tcl_lic'] as $k)<td class="coche"><span class="bx">{{ $x($k) }}</span></td>@endforeach</tr>
        </table>

        {{-- Retenue à la source --}}
        <h2 class="bande">الخصم من المورد</h2>
        <table class="g"><thead><tr><th>البيانات <sup>(2)</sup></th><th>أساس الخصم (د)</th><th>النسبة</th><th>مبلغ الخصم (د)</th></tr></thead><tbody>
            @foreach($RS as $r){!! $ligne($r, $r[1] !== 'rs28') !!}@endforeach
        </tbody><tfoot><tr><td>المجمـــــوع</td><td></td><td></td><td class="mt">{{ $dn($S['rs']['du']) }}</td></tr></tfoot></table>
        <div class="mt-1 text-[10px] leading-4">(1) 0 : تلقائي – 1 : تسوية – 2 : تصحيح – 3 : توظيف إجباري – 4 : توقيف نشاط. (2) بما في ذلك المبالغ التي تم دفعها لحساب الغير.</div>

        {{-- Taxe de formation professionnelle --}}
        <h2 class="bande saut">الأداء على التكوين المهني</h2>
        <div class="flex items-center gap-4">الانتفاع بالتسبقة على الأداء على التكوين المهني <span>نعم</span>{!! $cases($v('tfp_d')['m'] > 0 ? 'X' : '', 1) !!}<span>لا</span>{!! $cases($v('tfp_d')['m'] > 0 ? '' : 'X', 1) !!}</div>
        <table class="g mt-2"><thead><tr><th>البيانات</th><th>أساس الأداء (د)</th><th>النسبة</th><th>الأداء المستوجب (د) I</th><th>الأداء القابل للطرح (د) II</th></tr></thead><tbody>
            <tr><td class="lib">* الصناعات المعملية</td><td class="mt">{{ $dn($v('tfp1')['b']) }}</td><td>%1</td><td class="mt">{{ $dn($v('tfp1')['m']) }}</td><td></td></tr>
            <tr><td class="lib">* الأنشطة الأخرى</td><td class="mt">{{ $dn($v('tfp2')['b']) }}</td><td>%2</td><td class="mt">{{ $dn($v('tfp2')['m']) }}</td><td></td></tr>
            <tr><td class="lib">- طرح مبلغ التسبقة</td><td></td><td></td><td></td><td class="mt">{{ $dn($v('tfp_d')['m']) }}</td></tr>
            @foreach(['- طرح مبلغ فائض التسبقة الذي لم يتسن طرحه', '- طرح مبلغ العائدات التي لم يتسن طرحها', '- مبالغ إضافية للطرح بعنوان التسبقة أو العائدات', '- مبالغ للطرح من فائض العائدات التي لم يتسن طرحها'] as $l)<tr><td class="lib">{{ $l }}</td><td></td><td></td><td></td><td></td></tr>@endforeach
        </tbody><tfoot><tr><td>المجمـــــوع</td><td></td><td></td><td class="mt">{{ $dn($S['tfp']['I']) }}</td><td class="mt">{{ $dn($S['tfp']['II']) }}</td></tr>
            <tr><td colspan="3" class="lib">الباقي: (ب) مستوجب أو (ف) فائض (I) - (II) &nbsp; {!! $cases($S['tfp']['I'] >= $S['tfp']['II'] ? 'ب' : 'ف', 1) !!}</td><td colspan="2" class="mt">{{ $dn(abs($S['tfp']['I'] - $S['tfp']['II'])) }}</td></tr></tfoot></table>
        <div class="mt-2 flex items-center gap-4">مؤسسة معفاة <span>نعم</span>{!! $cases('', 1) !!}<span>لا</span>{!! $cases('', 1) !!}</div>

        {{-- FOPROLOS --}}
        <h2 class="bande">المساهمة في صندوق النهوض بالمسكن لفائدة الأجراء</h2>
        <table class="g"><thead><tr><th>أساس المساهمة (د)</th><th>النسبة</th><th>المساهمة المستوجبة (د)</th></tr></thead>
            <tbody><tr><td class="mt" style="text-align:center">{{ $dn($v('fop')['b']) }}</td><td>%1</td><td class="mt" style="text-align:center">{{ $dn($v('fop')['m']) }}</td></tr></tbody></table>

        {{-- Droit de consommation --}}
        <h2 class="bande">المعلوم على الاستهلاك</h2>
        <table class="g"><thead><tr><th>البيانات</th><th>المبلغ (د)</th><th>النسبة</th><th>المعلوم المستوجب (د) (I)</th><th>المعلوم القابل للطرح (د) (II)</th></tr></thead><tbody>
            <tr><td class="lib">1 – رقم المعاملات دون اعتبار المعلوم على الاستهلاك والأداء على القيمة المضافة</td><td class="mt">{{ $dn($v('dc1')['b']) }}</td><td>{{ $tx('dc1') }}</td><td class="mt">{{ $dn($v('dc1')['m']) }}</td><td></td></tr>
            <tr><td class="lib">2 – الشراءات</td><td></td><td></td><td></td><td></td></tr>
            <tr><td class="lib" style="padding-inline-start:1.2rem">- شراءات محلية</td><td class="mt">{{ $dn($v('dc_al')['b']) }}</td><td>{{ $tx('dc_al') }}</td><td></td><td class="mt">{{ $dn($v('dc_al')['m']) }}</td></tr>
            <tr><td class="lib" style="padding-inline-start:1.2rem">- شراءات موردة</td><td class="mt">{{ $dn($v('dc_ai')['b']) }}</td><td>{{ $tx('dc_ai') }}</td><td></td><td class="mt">{{ $dn($v('dc_ai')['m']) }}</td></tr>
        </tbody></table>
        <table class="g mt-2"><tbody>
            <tr><td class="lib">الباقي: (ب) مستوجب أو (ف) فائض (I) - (II)</td><td class="mt">{{ $dn(abs($v('dc1')['m'] - $v('dc_al')['m'] - $v('dc_ai')['m'])) }}</td></tr>
            <tr><td class="lib">الفائض من الشهر السابق</td><td class="mt">{{ $dn($v('dc_prec')['m']) }}</td></tr>
            <tr><td class="lib">المبالغ المسترجعة</td><td></td></tr>
            <tr><td class="lib">الباقي المستوجب أو الفائض (ب) أو (ف) &nbsp; {!! $cases($S['dc']['I'] >= $S['dc']['II'] ? 'ب' : 'ف', 1) !!}</td><td class="mt">{{ $dn(abs($S['dc']['I'] - $S['dc']['II'])) }}</td></tr>
        </tbody></table>

        {{-- TVA --}}
        <h2 class="bande saut">الأداء على القيمة المضافة (أ.ق.م)</h2>
        <table class="g"><thead><tr><th>البيانات</th><th>المبلغ (د)</th><th>النسبة</th><th>أ.ق.م. المستوجب (د) (I)</th><th>أ.ق. م. القابل للطرح (د) (II)</th></tr></thead><tbody>
            <tr><td class="lib">1- رقم المعاملات الخاضع للأداء على القيمة المضافة دون اعتبار أ. ق. م</td><td></td><td></td><td></td><td></td></tr>
            @foreach(['tva7' => '%7', 'tva13' => '%13', 'tva19' => '%19', 'tvax1' => null, 'tvax2' => null] as $k => $t)
                <tr><td></td><td class="mt">{{ $dn($v($k)['b']) }}</td><td>{{ $t ?? ($v($k)['b'] ? '%'.$tx($k) : '') }}</td><td class="mt">{{ $dn($v($k)['m']) }}</td><td></td></tr>
            @endforeach
            <tr><td class="lib" style="padding-inline-start:1.2rem">- عمليات التسليم للنفس</td><td class="mt">{{ $dn($v('tva_soi')['b']) }}</td><td>{{ $v('tva_soi')['b'] ? '%'.$tx('tva_soi') : '' }}</td><td class="mt">{{ $dn($v('tva_soi')['m']) }}</td><td></td></tr>
            <tr><td class="lib">2- الشراءات الخاضعة لـ أ. ق. م. دون اعتبار أ. ق. م. والتي تخوّل حق الطرح</td><td></td><td></td><td></td><td></td></tr>
            @foreach([['- شراء عقارات:', 'tvd_imm', 1], ['- شراء معدات:', null, 1], ['* محلية', 'tvd_eql', 2], ['* موردة', 'tvd_eqi', 2], ['- شراءات أخرى:', null, 1], ['* محلية', 'tvd_al', 2], ['* موردة', 'tvd_ai', 2]] as [$l, $k, $n])
                <tr><td class="lib" style="padding-inline-start:{{ $n * 1.2 }}rem">{{ $l }}</td><td class="mt">{{ $k ? $dn($v($k)['b']) : '' }}</td><td></td><td></td><td class="mt">{{ $k ? $dn($v($k)['m']) : '' }}</td></tr>
            @endforeach
            <tr><td class="lib">3- طروحات أخرى:</td><td></td><td></td><td></td><td></td></tr>
            <tr><td class="lib" style="padding-inline-start:1.2rem">- أ. ق. م. مستوجب على المبالغ التي تساوي أو تفوق 1000 دينار بما في ذلك أ. ق. م. ومبلغ الخصم من المورد</td><td class="mt">{{ $dn($v('tvd_r25')['b']) }}</td><td>%25</td><td></td><td class="mt">{{ $dn($v('tvd_r25')['m']) }}</td></tr>
            <tr><td class="lib" style="padding-inline-start:1.2rem">- أ. ق. م. المستوجب على العمليات المنجزة مع الأشخاص الذين ليست لهم مؤسسات بالبلاد التونسية</td><td class="mt">{{ $dn($v('tvd_r100')['b']) }}</td><td>%100</td><td></td><td class="mt">{{ $dn($v('tvd_r100')['m']) }}</td></tr>
            <tr><td class="lib" style="padding-inline-start:1.2rem">- أ. ق. م. تقديري على وسائل النقل</td><td></td><td></td><td></td><td class="mt">{{ $dn($v('tvd_transp')['m']) }}</td></tr>
            <tr><td class="lib" style="padding-inline-start:1.2rem">- عمليات التسليم للنفس</td><td></td><td></td><td></td><td class="mt">{{ $dn($v('tvd_soi')['m']) }}</td></tr>
            <tr><td class="lib">4- التعديلات:</td><td></td><td></td><td></td><td></td></tr>
            <tr><td class="lib" style="padding-inline-start:1.2rem">- طرح إضافي:</td><td></td><td></td><td></td><td></td></tr>
            <tr><td class="lib" style="padding-inline-start:2.4rem">* بعنوان عمليات الفسخ والإلغاء</td><td></td><td></td><td></td><td class="mt">{{ $dn($v('tvd_annul')['m']) }}</td></tr>
            <tr><td class="lib" style="padding-inline-start:2.4rem">* بعنوان عمليات أخرى</td><td></td><td></td><td></td><td class="mt">{{ $dn($v('tvd_aut')['m']) }}</td></tr>
            <tr><td class="lib" style="padding-inline-start:1.2rem">- إعادة دفع</td><td></td><td></td><td class="mt">{{ $dn($v('tva_rev')['m']) }}</td><td></td></tr>
        </tbody><tfoot><tr><td colspan="3">المجمـــــوع</td><td class="mt">{{ $dn($C['tva_I']) }}</td><td class="mt">{{ $dn($C['tva_II']) }}</td></tr></tfoot></table>
        <table class="g mt-2"><tbody>
            <tr><td class="lib">الباقي: (ب) مستوجب أو (ف) فائض (I) - (II) &nbsp; {!! $cases($C['tva_I'] >= $C['tva_II'] ? 'ب' : 'ف', 1) !!}</td><td class="mt">{{ $dn(abs($C['tva_I'] - $C['tva_II'])) }}</td></tr>
            <tr><td class="lib">الفائض من الشهر السابق</td><td class="mt">{{ $dn($C['tva_prec']) }}</td></tr>
            <tr><td class="lib">المبلغ المسترجع</td><td class="mt">{{ $dn($v('tva_restit')['m']) }}</td></tr>
            <tr><td class="lib font-bold">الباقي: (ب) مستوجب أو (ف) فائض &nbsp; {!! $cases($C['tvap'] >= 0 ? 'ب' : 'ف', 1) !!}</td><td class="mt font-bold">{{ $dn(abs($C['tvap'])) }}</td></tr>
        </tbody></table>

        <div class="mt-4 font-bold">إرشـــادات أخــرى</div>
        <table class="g mt-1"><thead><tr><th>البيانات</th><th>المبلغ (د)</th><th>أ.ق.م القابل للطرح (د)</th><th>أ.ق.م المستوجب (د)</th></tr></thead><tbody>
            @foreach([['- شراءات بتوقيف العمل بالأداء على القيمة المضافة', 'inf_susp'], ['- شراءات بتوقيف العمل بالأداء على القيمة المضافة بعنوان المواد والتجهيزات التي تدخل ضمن مكونات إنجاز صفقات بالخارج تساوي أو تفوق 3 مليون دينار', null],
                ['- شراءات معفاة', 'inf_exo'], ['- شراءات لدى غير الخاضعين للأداء على القيمة المضافة', 'inf_nass'], ['- شراءات أخرى خاضعة للأداء على القيمة المضافة دون اعتبار الأداء على القيمة المضافة ولا تنتفع بحق الطرح', null],
                ['- تصدير بضائع وخدمات', 'inf_exp'], ['- بيوعات بتوقيف العمل بالأداء على القيمة المضافة', 'inf_vsusp'], ['- رقم المعاملات المعفى من الأداء على القيمة المضافة', 'inf_caexo'], ['- التفويت على الرصيف', null]] as [$l, $k])
                <tr><td class="lib">{{ $l }}</td><td class="mt">{{ $k ? $dn($v($k)['b']) : '' }}</td><td></td><td></td></tr>
            @endforeach
        </tbody></table>

        {{-- Autres taxes --}}
        <h2 class="bande saut">معاليم أخرى</h2>
        <table class="g"><thead><tr><th>المعلـــــوم</th><th>الأســـاس (د)</th><th>النسبة</th><th>المعلوم المستوجب (د)</th></tr></thead><tbody>
            @foreach($AUT as $r){!! $ligne($r) !!}@endforeach
        </tbody><tfoot><tr><td>المجمـــــوع</td><td></td><td></td><td class="mt">{{ $dn($S['aut']['du']) }}</td></tr></tfoot></table>

        {{-- Droit de timbre --}}
        <h2 class="bande saut">معلوم الطابع الجبائي</h2>
        <table class="g"><tbody><tr><td class="lib">1) المعلوم المستوجب على المبالغ المفوترة والمبالغ المستخلصة بعنوان خدمات الهاتف وخدمات الإنترنت (د) (I)</td><td class="mt">{{ $dn($v('tim1')['m']) }}</td></tr></tbody></table>
        <div class="mt-3 font-bold">2) المعلوم المستوجب على تذاكر البيع المسلمة من قبل المغازات التجارية والمستغلين لعلامة تجارية أجنبية</div>
        <table class="g mt-1"><thead><tr><th>عدد الفروع</th><th>عدد التذاكر</th><th>المعلوم المستوجب (د) (II)</th></tr></thead><tbody><tr><td></td><td>{{ $v('tim2')['b'] ? (int) $v('tim2')['b'] : '' }}</td><td class="mt">{{ $dn($v('tim2')['m']) }}</td></tr></tbody></table>
        <div class="mt-3 font-bold">3) المعلوم المستوجب على الفواتير المسلمة من قبل المساحات التجارية الكبرى المنصوص عليها بمجلة التهيئة الترابية والتعمير</div>
        <table class="g mt-1"><thead><tr><th>طبيعة الفواتير</th><th>عدد الفواتير</th><th>المبلغ المستوجب عن كل فاتورة (د)</th><th>المعلوم المستوجب (د)</th></tr></thead><tbody>
            <tr><td class="lib">الفواتير التي يتراوح مبلغها من 50 دينار إلى 100 دينار</td><td>{{ $v('tim3a')['b'] ? (int) $v('tim3a')['b'] : '' }}</td><td>1,5</td><td class="mt">{{ $dn($v('tim3a')['m']) }}</td></tr>
            <tr><td class="lib">الفواتير التي يفوق مبلغها 100 دينار</td><td>{{ $v('tim3b')['b'] ? (int) $v('tim3b')['b'] : '' }}</td><td>2</td><td class="mt">{{ $dn($v('tim3b')['m']) }}</td></tr>
        </tbody><tfoot><tr><td colspan="3">المجمـــــوع (III)</td><td class="mt">{{ $dn($v('tim3a')['m'] + $v('tim3b')['m']) }}</td></tr></tfoot></table>
        <div class="mt-3 font-bold">4) المعلوم المستوجب على الوثائق الأخرى</div>
        <table class="g mt-1"><thead><tr><th>رمز الوثيقة</th><th>نوع الوثيقة</th><th>عدد الفروع</th><th>عدد الوثائق</th><th>المعلوم المستوجب (د)</th></tr></thead><tbody>
            <tr><td>{{ $v('tim4')['b'] ? 3 : '' }}</td><td>{{ $v('tim4')['b'] ? 'فاتورة' : '' }}</td><td></td><td>{{ $v('tim4')['b'] ? (int) $v('tim4')['b'] : '' }}</td><td class="mt">{{ $dn($v('tim4')['m']) }}</td></tr>
            <tr><td>{{ $v('tim5')['m'] ? 9 : '' }}</td><td>{{ $v('tim5')['m'] ? 'وثائق أخرى' : '' }}</td><td></td><td></td><td class="mt">{{ $dn($v('tim5')['m']) }}</td></tr>
        </tbody><tfoot><tr><td colspan="4">المجمـــــوع (IV)</td><td class="mt">{{ $dn($v('tim4')['m'] + $v('tim5')['m']) }}</td></tr></tfoot></table>
        <table class="g mt-2"><tbody><tr><td class="lib font-bold">المجمـــوع العـام (V) = (IV+III+II+I)</td><td class="mt font-bold">{{ $dn($S['tim']['du']) }}</td></tr></tbody></table>

        {{-- Taxes des collectivités locales --}}
        <h2 class="bande">المعلوم على النزل</h2>
        <table class="g"><thead><tr><th></th><th>أساس المعلوم (د)</th><th>النسبة</th><th>المعلوم المستوجب (د)</th></tr></thead>
            <tbody><tr><td class="lib">رقم المعاملات الخام</td><td class="mt">{{ $dn($v('tcl_hotel')['b']) }}</td><td>%2</td><td class="mt">{{ $dn($v('tcl_hotel')['m']) }}</td></tr></tbody></table>

        <h2 class="bande saut">المعلوم على المؤسسات ذات الصبغة الصناعية أو التجارية أو المهنية</h2>
        <div class="font-bold">1) مؤسسات تروّج قصرا منتجات خاضعة لنظام المصادقة الإدارية للأسعار</div>
        <table class="g mt-1"><thead><tr><th></th><th>أساس المعلوم (د)</th><th>النسبة</th><th>المعلوم المستوجب (د)</th></tr></thead><tbody>
            <tr><td class="lib">رقم المعاملات المحلي الخام المحقق</td><td class="mt">{{ $dn($v('tcl_hom')['b']) }}</td><td>%0,1</td><td class="mt">{{ $dn($v('tcl_hom')['m']) }}</td></tr>
            <tr><td class="lib">رقم المعاملات المتأتي من التصدير</td><td></td><td>%0,1</td><td></td></tr>
        </tbody></table>
        <div class="mt-3 font-bold">2) مؤسسات تدفع المعلوم على المؤسسات على أساس 25% من الضريبة على الدخل أو الضريبة على الشركات</div>
        <table class="g mt-1"><thead><tr><th></th><th>أساس المعلوم (د)</th><th>النسبة</th><th>المعلوم المستوجب (د)</th></tr></thead><tbody>
            <tr><td class="lib">الضريبة على الدخل أو على الشركات المستوجبة</td><td class="mt">{{ $dn($v('tcl_is')['b']) }}</td><td>%25</td><td class="mt">{{ $dn($v('tcl_is')['m']) }}</td></tr>
        </tbody></table>
        <div class="mt-3 font-bold">3) مؤسسات أخرى</div>
        <table class="g mt-1"><thead><tr><th></th><th>أساس المعلوم (د)</th><th>النسبة</th><th>المعلوم المستوجب (د)</th></tr></thead><tbody>
            <tr><td class="lib">رقم المعاملات المحلي الخام المحقق</td><td class="mt">{{ $dn($v('tcl_loc')['b']) }}</td><td>%0,2</td><td class="mt">{{ $dn($v('tcl_loc')['m']) }}</td></tr>
            <tr><td class="lib">رقم المعاملات المتأتي من التصدير ومن العمليات المنتفعة بنفس النظام الجبائي للتصدير</td><td class="mt">{{ $dn($v('tcl_exp')['b']) }}</td><td>%0,1</td><td class="mt">{{ $dn($v('tcl_exp')['m']) }}</td></tr>
        </tbody></table>
        <div class="mt-3 font-bold">4) احتساب المعلوم على المؤسسات</div>
        <table class="g mt-1"><tbody>
            <tr><td class="lib">المعلوم على المؤسسات ذات الصبغة الصناعية أو التجارية أو المهنية (I)</td><td class="mt">{{ $dn($C['tcl']['tcl_ent']) }}</td></tr>
            <tr><td class="lib">المعلوم المستوجب بعنوان الأشهر السابقة</td><td></td></tr>
            <tr><td class="lib">- المعلوم المستوجب سنويا (II) &nbsp; - الحد الأدنى للمعلوم على المؤسسات (III) &nbsp; - الباقي المستوجب (III) - (II)</td><td></td></tr>
        </tbody></table>

        <h2 class="bande">معلوم الإجازة</h2>
        <table class="g"><thead><tr><th>الجماعة المحلية</th><th colspan="3">صنف 1</th><th colspan="3">صنف 2</th><th colspan="3">صنف 3</th><th>المجموع العام (د)</th></tr>
            <tr><th></th><th>المبلغ (د)</th><th>عدد المحلات</th><th>المجموع (د)</th><th>المبلغ (د)</th><th>عدد المحلات</th><th>المجموع (د)</th><th>المبلغ (د)</th><th>عدد المحلات</th><th>المجموع (د)</th><th></th></tr></thead>
            <tbody><tr><td></td><td>25</td><td></td><td></td><td>150</td><td></td><td></td><td>300</td><td></td><td></td><td class="mt">{{ $dn($v('tcl_lic')['m']) }}</td></tr></tbody></table>

        {{-- Synthèse --}}
        <h2 class="bande saut">خلاصة الأداءات والمعاليم الواجب دفعها (خاص بالإدارة)</h2>
        @php $syn = [['- الخصم من المورد', 'rs'], ['- الأداء على التكوين المهني', 'tfp'], ['- المساهمة في صندوق النهوض بالمسكن لفائدة الأجراء', 'fop'], ['- المعلوم على الاستهلاك', 'dc'],
            ['- الأداء على القيمة المضافة', 'tva'], ['- معاليم أخرى', 'aut'], ['- معاليم الطابع الجبائي', 'tim']]; $penTcl = $S['tcl']['pen']; @endphp
        <table class="g"><thead><tr><th>الضريبة أو المعلوم</th><th>مبلغ أصل الأداء (I)</th><th>الطرح (الأداء المدفوع) (II)</th><th>الأداء المستوجب (III)=(II-I)</th><th>خطايا التأخير</th><th>المجمـــوع</th></tr></thead><tbody>
            @foreach($syn as [$l, $s])<tr><td class="lib">{{ $l }}</td><td class="mt">{{ $dn($S[$s]['du']) }}</td><td></td><td class="mt">{{ $dn($S[$s]['du']) }}</td><td class="mt">{{ $dn($S[$s]['pen']) }}</td><td class="mt">{{ $dn($S[$s]['du'] + $S[$s]['pen']) }}</td></tr>@endforeach
            <tr><td class="lib">- المعاليم الراجعة للجماعات المحلية:</td><td></td><td></td><td></td><td></td><td></td></tr>
            @foreach([['* المعلوم على النزل', 'tcl_hot'], ['* المعلوم على المؤسسات ذات الصبغة الصناعية أو التجارية أو المهنية', 'tcl_ent'], ['* معلوم الإجازة', 'tcl_lic']] as $i => [$l, $k])
                @php $p = $i === 1 ? $penTcl : 0; @endphp
                <tr><td class="lib" style="padding-inline-start:1.2rem">{{ $l }}</td><td class="mt">{{ $dn($C['tcl'][$k]) }}</td><td></td><td class="mt">{{ $dn($C['tcl'][$k]) }}</td><td class="mt">{{ $dn($p) }}</td><td class="mt">{{ $dn($C['tcl'][$k] + $p) }}</td></tr>
            @endforeach
        </tbody><tfoot><tr><td>المجمـــوع</td><td class="mt">{{ $dn($C['tot'] - array_sum(array_column($S, 'pen'))) }}</td><td></td><td class="mt">{{ $dn($C['tot'] - array_sum(array_column($S, 'pen'))) }}</td><td class="mt">{{ $dn(array_sum(array_column($S, 'pen'))) }}</td><td class="mt">{{ $dn($C['tot']) }}</td></tr></tfoot></table>

        <table class="g mt-5"><tr>
            <td class="lib" style="width:60%;vertical-align:top">
                <div class="flex flex-wrap items-center gap-3">القباضة المالية بـ <span class="pt" style="min-width:8rem"></span> رمز القباضة {!! $cases('', 3) !!} رمز الدفع <sup>(1)</sup> {!! $cases('', 1) !!}</div>
                <div class="mt-3 flex items-center gap-3">رقم الوصل {!! $cases('', 8) !!}</div>
                <div class="mt-3 flex items-center gap-3">تاريخ الوصل <span>اليوم</span>{!! $cases('', 2) !!}<span>الشهر</span>{!! $cases('', 2) !!}<span>السنة</span>{!! $cases('', 4) !!}</div>
                <div class="mt-6 text-center">طابع القبــاضة</div><div class="h-10"></div>
            </td>
            <td class="lib" style="vertical-align:top">إني الممضي أسفله أشهد بصحة البيانات التي تضمنها هذا التصريح.
                <div class="mt-6"><span class="pt" style="min-width:7rem"></span> في <span class="pt" style="min-width:7rem"></span></div>
                <div class="mt-6 text-center">الإمضــــــــاء</div><div class="h-10"></div></td>
        </tr></table>
        <div class="mt-1 text-[10px]">(1) ضع: 1 – في صورة الدفع الكلي، 2 – في صورة الدفع المؤجل.</div>
    </div>
</x-layouts.app>
