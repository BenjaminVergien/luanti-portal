<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Einladung zum Luanti-Portal</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; color: #1f2937; font-family: Arial, sans-serif; line-height: 1.6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <tr>
                        <td style="padding: 32px; overflow-wrap: anywhere; word-break: break-word;">
                            <h1 style="margin: 0 0 24px; font-size: 24px; line-height: 1.3;">Einladung zum Luanti-Portal</h1>

                            @if($invitation->organization)
                                <p style="margin: 0 0 20px;">Du wurdest zur Einrichtung <strong style="white-space: pre-wrap;">{{ $invitation->organization->name }}</strong> als <strong>{{ $invitation->membership_role->label() }}</strong> eingeladen.</p>
                            @else
                                <p style="margin: 0 0 20px;">Du wurdest als <strong>{{ $invitation->internal_role->label() }}</strong> eingeladen.</p>
                            @endif

                            <p style="margin: 0 0 24px;">Melde dich mit der eingeladenen E-Mail-Adresse an oder registriere dich zuerst. Bestätige anschließend deine E-Mail-Adresse und nimm die Einladung an.</p>

                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 0 24px;">
                                <tr>
                                    <td style="background-color: #1f2937; border-radius: 6px;">
                                        <a href="{{ $acceptUrl }}" style="display: inline-block; padding: 12px 20px; color: #ffffff; font-weight: bold; text-decoration: none;">Einladung öffnen</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; color: #6b7280; font-size: 14px;">Die Einladung gilt bis {{ $invitation->expires_at->format('d.m.Y H:i') }} Uhr (Europe/Berlin).</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
