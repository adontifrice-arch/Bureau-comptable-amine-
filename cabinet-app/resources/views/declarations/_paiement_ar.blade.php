{{-- Bloc « خاص بالإدارة » (paiement à la recette des finances) et attestation signée, commun aux déclarations officielles en arabe. --}}
<table class="g mt-5"><tr>
    <td class="lib" style="width:60%;vertical-align:top">
        <div class="mb-2 text-center font-bold">خـــاص بالإدارة</div>
        <div class="flex flex-wrap items-center gap-3">القباضة المالية بـ <span class="pt" style="min-width:8rem"></span> رمز القباضة {!! $cases('', 3) !!} رمز الدفع {!! $cases('', 1) !!}</div>
        <div class="mt-3 flex items-center gap-3">رقم الوصل {!! $cases('', 7) !!}</div>
        <div class="mt-3 flex items-center gap-3">تاريخ الوصل <span>اليوم</span>{!! $cases('', 2) !!}<span>الشهر</span>{!! $cases('', 2) !!}<span>السنة</span>{!! $cases('', 4) !!}</div>
        <div class="mt-6 text-center">طابع القبــاضة</div><div class="h-10"></div>
    </td>
    <td class="lib" style="vertical-align:top">إني الممضي أسفله أشهد بصحة البيانات التي تضمنها هذا التصريح.
        <div class="mt-6"><span class="pt" style="min-width:7rem"></span> في <span class="pt" style="min-width:7rem"></span></div>
        <div class="mt-6 text-center font-bold">الإمضــــــــاء</div><div class="h-10"></div></td>
</tr></table>
<div class="mt-1 text-[10px]">ضع في رمز الدفع: 1 – في صورة الدفع الكلي؛ 2 – في صورة الدفع المؤجّل.</div>
