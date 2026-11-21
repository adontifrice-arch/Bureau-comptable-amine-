<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f3f6f7;font-family:Arial,Helvetica,sans-serif;color:#1e293b;font-size:14px;line-height:1.55">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">
<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0">
    <tr><td style="background:#0b3b46;padding:18px 28px;color:#ffffff;font-weight:bold;font-size:15px">{{ $cab['nom'] }}</td></tr>
    <tr><td style="padding:28px">
        @yield('contenu')
    </td></tr>
    <tr><td style="padding:16px 28px;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px">
        {{ $cab['nom'] }}@if($cab['adresse']) · {{ $cab['adresse'] }}@endif @if($cab['telephone']) · {{ $cab['telephone'] }}@endif<br>
        Cet e-mail est envoyé automatiquement par l’espace client du cabinet.
    </td></tr>
</table>
</td></tr></table>
</body>
</html>
