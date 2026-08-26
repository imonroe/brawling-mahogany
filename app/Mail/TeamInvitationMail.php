<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\TeamInvitation;
use App\Support\Mail\BrandedEmail;
use App\Support\Mail\SendingIdentity;
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
        $inviter = $this->invitation->invitedBy;

        return new Envelope(
            // The inviting team, for the reason `SendingIdentity` gives: the
            // recipient knows the agency and has never heard of the product.
            from: SendingIdentity::forTeam($team),
            /*
             * And somewhere for *"who is this?"* to go. An invitation is the
             * one message that reaches somebody with no account, no context,
             * and every reason to be suspicious of it — a From line naming an
             * agency over a reply that reaches nobody is exactly the shape of
             * the thing they should be suspicious of.
             *
             * The inviter first, because they are the person who did this and
             * the one who can explain it; the team's own reply-to address
             * after, for an invitation whose sender has since left.
             */
            replyTo: SendingIdentity::replyTo($team, $inviter?->email),
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
