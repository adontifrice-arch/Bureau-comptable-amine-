@php $g = $F['g']; $P = $F['P']; $gp = $Fp['g']; $Pp = $Fp['P'];
     $row = fn($l, $v, $vp = null, $b = false) => '<tr class="'.($b ? 'font-semibold bg-slate-50 dark:bg-slate-800/40' : '').'"><td class="px-4 py-2">'.e($l).'</td><td class="px-4 py-2 text-right font-mono tabular-nums">'.tnd($v, false).'</td><td class="px-4 py-2 text-right font-mono tabular-nums text-slate-400">'.($vp === null ? '' : tnd($vp, false)).'</td></tr>';
     $head = '<thead><tr class="text-left text-[11px] uppercase tracking-wide text-slate-500"><th class="px-4 py-2"></th><th class="px-4 py-2 text-right">'.$annee.'</th><th class="px-4 py-2 text-right">'.($annee - 1).'</th></tr></thead>'; @endphp
@if(in_array('bil', $show))
<div class="grid gap-6 lg:grid-cols-2">
    <div class="card overflow-hidden"><div class="card-h"><h2 class="card-t">Actif</h2></div><table class="w-full text-sm divide-y divide-slate-100 dark:divide-slate-800">{!! $head !!}
        {!! $row('Immobilisations nettes', $g['imm'], $gp['imm']) !!}{!! $row('Stocks', $g['stk'], $gp['stk']) !!}{!! $row('Clients et comptes rattachés', $g['cli'], $gp['cli']) !!}{!! $row('Autres actifs courants (dont TVA déductible)', $g['aac'], $gp['aac']) !!}{!! $row('Liquidités et équivalents', $g['liq'], $gp['liq']) !!}{!! $row('TOTAL ACTIF', $F['tA'], $Fp['tA'], true) !!}</table></div>
    <div class="card overflow-hidden"><div class="card-h"><h2 class="card-t">Capitaux propres et passif</h2></div><table class="w-full text-sm divide-y divide-slate-100 dark:divide-slate-800">{!! $head !!}
        {!! $row('Capital social', $g['cap'], $gp['cap']) !!}{!! $row('Réserves et résultats reportés', $g['rep'], $gp['rep']) !!}{!! $row('Résultat de l’exercice', $g['res'], $gp['res']) !!}{!! $row('Total capitaux propres', $g['cp'], $gp['cp'], true) !!}
        {!! $row('Emprunts', $g['emp'], $gp['emp']) !!}{!! $row('Fournisseurs et comptes rattachés', $g['fou'], $gp['fou']) !!}{!! $row('Autres passifs courants (État, CNSS, personnel)', $g['apc'], $gp['apc']) !!}{!! $row('Concours bancaires', $g['cob'], $gp['cob']) !!}{!! $row('TOTAL CAPITAUX PROPRES ET PASSIF', $F['tP'], $Fp['tP'], true) !!}</table></div>
</div>
<div class="mt-3 text-right"><span class="{{ abs($F['tA'] - $F['tP']) < .01 ? 'badge-green' : 'badge-red' }}">{{ abs($F['tA'] - $F['tP']) < .01 ? 'Bilan équilibré' : 'Écart actif / passif : '.tnd($F['tA'] - $F['tP']) }}</span></div>
@endif
@if(in_array('res', $show))
<div class="card mt-6 overflow-hidden"><div class="card-h"><h2 class="card-t">État de résultat</h2></div><table class="w-full text-sm divide-y divide-slate-100 dark:divide-slate-800">{!! $head !!}
    {!! $row('Chiffre d’affaires', $P['ca'], $Pp['ca']) !!}{!! $row('Autres produits d’exploitation', $P['ap'], $Pp['ap']) !!}{!! $row('Total des produits d’exploitation', $P['prod'], $Pp['prod'], true) !!}
    {!! $row('Achats consommés', -$P['ach'], -$Pp['ach']) !!}{!! $row('Services extérieurs et autres charges', -$P['srv'], -$Pp['srv']) !!}{!! $row('Charges de personnel', -$P['per'], -$Pp['per']) !!}{!! $row('Impôts et taxes', -$P['imp'], -$Pp['imp']) !!}{!! $row('Dotations aux amortissements', -$P['dot'], -$Pp['dot']) !!}
    {!! $row('Résultat d’exploitation', $P['rex'], $Pp['rex'], true) !!}{!! $row('Produits financiers', $P['pfi'], $Pp['pfi']) !!}{!! $row('Charges financières', -$P['fin'], -$Pp['fin']) !!}@if($P['ext'] || $Pp['ext']){!! $row('Gains et pertes extraordinaires', $P['ext'], $Pp['ext']) !!}@endif{!! $row('Résultat avant impôt', $P['rav'], $Pp['rav'], true) !!}{!! $row('Impôt sur les bénéfices', -$P['is'], -$Pp['is']) !!}{!! $row('RÉSULTAT NET DE L’EXERCICE', $P['res'], $Pp['res'], true) !!}</table></div>
@endif
@if(in_array('fis', $show) && $saved)
<div class="card mt-6 overflow-hidden"><div class="card-h"><h2 class="card-t">Résultat fiscal et impôt</h2></div><table class="w-full text-sm divide-y divide-slate-100">
    @foreach([['Résultat comptable après impôt', $fisc['res']], ['Réintégrations (dont impôt sur les bénéfices comptabilisé)', r3($fisc['impots'] + $fisc['rei'])], ['Déductions', -$fisc['ded']], ['Résultat fiscal avant déduction des provisions', $fisc['rf'], 1], ['Résultat imposable', $fisc['imposable'], 1], ['Impôt théorique', $fisc['th']], ['Minimum d’impôt', $fisc['min']], ['Impôt dû', $fisc['du'], 1], ['Acomptes provisionnels', -$fisc['acp']], ['Retenues à la source subies', -$fisc['rs']], ['Solde à payer (négatif : crédit d’impôt)', $fisc['sol'], 1]] as $x)
        {!! $row($x[0], $x[1], null, !empty($x[2])) !!}
    @endforeach</table></div>
@endif
