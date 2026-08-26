<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\TeamInvitation;
use App\Support\Mail\BrandedEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The team invitation email (Screen Inventory S90).
 *
 * The first email the product sends, and deliberately a plain one. The branded
 * layout is S86 in Slice 3; building it early would mean building the branding
 * system twice. What this needs to be now is correct, accessible, and to carry
 * a plain-text alternative — which the two views give it.
 */
class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        $team = $this->invitation->team()->sole();

        return new Envelope(
            subject: "You’ve been invited to {$team->name}",
        );
    }

    public function content(): Content
    {
        $inviter = $this->invitation->invitedBy;

        return new Content(
            view: 'mail.team-invitation',
            text: 'mail.team-invitation-text',
            with: [
                'brand' => BrandedEmail::for($this->invitation->team()->sole()),
                'teamName' => $this->invitation->team()->sole()->name,
                'inviterName' => $inviter?->displayNameWithin($this->invitation->team()->sole()),
                'acceptUrl' => route('invitations.show', ['token' => $this->token]),
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
