<?php

namespace App\Notifications;

use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class PortalResetPassword extends ResetPassword
{
    protected function buildMailMessage(mixed $url): MailMessage
    {
        return (new MailMessage)
            ->subject('Passwort zurücksetzen · Luanti-Portal')
            ->view(['html' => 'mail.portal-auth', 'text' => 'mail.portal-auth-text'], [
                'heading' => 'Passwort zurücksetzen',
                'introduction' => 'Für dein Konto im Luanti-Portal wurde ein neues Passwort angefordert.',
                'actionLabel' => 'Neues Passwort festlegen',
                'actionUrl' => $url,
                'expiryMinutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
                'nextStep' => 'Lege über den Link ein neues Passwort fest. Danach kannst du dich damit im Portal anmelden.',
                'ignoreMessage' => 'Du hast kein neues Passwort angefordert? Dann kannst du diese E-Mail ignorieren. Dein bisheriges Passwort bleibt gültig.',
            ]);
    }
}
