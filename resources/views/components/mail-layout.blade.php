@props(['heading', 'actionLabel', 'actionUrl'])
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} · Luanti-Portal</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; color: #1f2937; font-family: Arial, sans-serif; line-height: 1.6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <tr>
                        <td style="padding: 32px; overflow-wrap: anywhere; word-break: break-word;">
                            <p style="margin: 0 0 16px; color: #6b7280; font-size: 14px;">Luanti-Portal</p>
                            <h1 style="margin: 0 0 24px; font-size: 24px; line-height: 1.3;">{{ $heading }}</h1>
                            {{ $slot }}
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px 0;">
                                <tr>
                                    <td style="background-color: #1f2937; border-radius: 6px;">
                                        <a href="{{ $actionUrl }}" style="display: inline-block; padding: 12px 20px; color: #ffffff; font-weight: bold; text-decoration: none;">{{ $actionLabel }}</a>
                                    </td>
                                </tr>
                            </table>
                            {{ $footer }}
                            <p style="margin: 24px 0 8px; color: #6b7280; font-size: 14px;">Funktioniert der Button nicht? Kopiere diesen Link in deinen Browser:</p>
                            <p style="margin: 0; font-size: 14px; word-break: break-all;"><a href="{{ $actionUrl }}" style="color: #1f2937;">{{ $actionUrl }}</a></p>
                            <p style="margin: 24px 0 0; color: #6b7280; font-size: 14px;">Bitte teile diesen persönlichen Link nicht mit anderen.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
