<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorldRequestStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array{recipientName: string, requestName: string, organizationName: string, startDate: string, endDate: string, provisioningAt: string, startsImmediately: bool, portalUrl: string} $details */
    public function __construct(public string $kind, public array $details) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->kind === 'approval' ? 'Dein Weltantrag wurde genehmigt · Luanti-Portal' : 'Deine Welt ist bereit · Luanti-Portal',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.world-request-status', text: 'mail.world-request-status-text',
        );
    }
}
