<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrganizationInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $role,
        public readonly string $invitationUrl,
        public readonly string $inviterName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invitation to join {$this->organizationName} on edTechLive");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.organization-invitation');
    }
}
