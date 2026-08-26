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

        /*
         * ## When there is no membership, the product is the sender
         *
         * PRD §5.1 step 1 — a platform operator provisions a team and invites
         * its first owner — reaches here with an inviter who has **no
         * membership in a team created four lines earlier**, and a team with no
         * owner yet. So every link of the reply chain is empty, and the From
         * signs as the product rather than naming an agency nobody can answer
         * for. That is the rule this class exists to hold, arriving at its
         * least obvious case, and it is right: the recipient is being invited
         * to become the first member of a team that has none.
         *
         * A reply still reaches somebody — with no `Reply-To`, a reply goes to
         * the `From` address, which is the product's own verified mailbox. And
         * ADR 0003 means the invitation was never email-only anyway: the
         * console hands the operator the accept link directly.
         */
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
        $team = $this->invitation->team()->sole();
        $inviter = $this->invitation->invitedBy;

        return new Content(
            view: 'mail.team-invitation',
            text: 'mail.team-invitation-text',
            with: [
                'brand' => BrandedEmail::for($team),
                'teamName' => $team->name,
                /*
                 * **The membership's name, or none — never the fallback.**
                 *
                 * `displayNameWithin()` ends at `$this->email`, and its own
                 * docblock argues that is not a disclosure: it is written for
                 * an *audit entry*, read by people already inside the team. An
                 * invitation is read by a stranger with no account, and putting
                 * a platform operator's **sign-in address** in front of them is
                 * a different question with a different answer.
                 *
                 * Both views already branch on this being null, and the branch
                 * is the truer sentence anyway — *"You've been invited to join
                 * Bosart Group"*, with no claim about who sent it.
                 */
                'inviterName' => $inviter?->membershipIn($team)?->fullName(),
                'acceptUrl' => route('invitations.show', ['token' => $this->token]),
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
