<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\TeamInvitation;
use App\Support\Mail\BrandedEmail;
use App\Support\Mail\SendingIdentity;
use App\Support\Messages\RenderMessage;
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

        /*
         * The inviting team, for the reason `SendingIdentity` gives: the
         * recipient knows the agency and has never heard of the product. And
         * somewhere for *"who is this?"* to go — an invitation is the one
         * message that reaches somebody with no account, no context, and every
         * reason to be suspicious of it, and a From line naming an agency over
         * a reply that reaches nobody is exactly the shape of the thing they
         * should be suspicious of.
         *
         * The inviter is the more specific reply, because they are the person
         * who did this and the one who can explain it — **under their own
         * name**, not the team's, or the recipient reads "Bosart Group" and
         * writes to Emily. The team's settings and then a team owner stand
         * behind it, for an invitation whose sender has since left.
         */
        /*
         * The inviter's **membership** address, not `Person::email`.
         *
         * `people.email` is a login credential; `team_memberships.email` is the
         * address a team recorded for somebody, which is what IA §11's
         * "Person, not User" split is for. Publishing the first to a stranger
         * who has not accepted an invitation yet tells them which address signs
         * in — and the display name beside it already came from the membership,
         * so the two halves of this header were reading different tables.
         */
        $membership = $inviter?->membershipIn($team);

        $identity = SendingIdentity::for(
            $team,
            $membership?->email,
            $membership?->fullName(),
        );

        return new Envelope(
            from: $identity->from,
            replyTo: $identity->replyTo,
            /*
             * Through the strip, like the From name beside it. CLAUDE.md
             * claims every tenant string reaching a header goes through
             * `headerSafe()`; this was the one that did not, and a subject is
             * a header for exactly the reason `RenderMessage` says it is.
             */
            subject: 'You’ve been invited to '.RenderMessage::headerSafe($team->name),
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
