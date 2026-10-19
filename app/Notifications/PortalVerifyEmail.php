<?php

namespace App\Notifications;

use Filament\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class PortalVerifyEmail extends VerifyEmail
{
    protected function buildMailMessage(mixed $url): MailMessage
    {
        return (new MailMessage)
            ->subject('E-Mail-Adresse bestätigen · Luanti-Portal')
            ->view(['html' => 'mail.portal-auth', 'text' => 'mail.portal-auth-text'], [
                'heading' => 'E-Mail-Adresse bestätigen',
                'introduction' => 'Willkommen im Luanti-Portal! Bestätige deine E-Mail-Adresse, um deinen Zugang zu aktivieren.',
                'actionLabel' => 'E-Mail-Adresse bestätigen',
                'actionUrl' => $url,
                'expiryMinutes' => config('auth.verification.expire', 60),
                'nextStep' => 'Danach kannst du deine Einrichtung erstellen, einer bestehenden Einrichtung beitreten oder eine persönliche Einladung annehmen.',
                'ignoreMessage' => 'Du hast kein Konto angelegt? Dann kannst du diese E-Mail ignorieren. Ohne Bestätigung wird der Zugang nicht aktiviert.',
            ]);
    }
}
