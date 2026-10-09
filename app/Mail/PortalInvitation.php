<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PortalInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation, public string $acceptUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Einladung zum Luanti-Portal');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.portal-invitation');
    }
}
