<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ActionInstance;
use App\Models\Team;
use App\Support\Mail\BrandedEmail;
use App\Support\Mail\MilestoneAnnouncement;
use App\Support\Messages\RenderedMessage;
use App\Support\Messages\RenderMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The one mailable that can reach a client (PRD §4.5 · issue #92).
 *
 * Everything else this product sends goes to somebody who already has an
 * account: an invitation, a reset link, S46's test send to its own author.
 * This one carries a team's own words to the people on a deal, which is what
 * PRD §4.5 means by *"damages a real relationship and cannot be recalled"*.
 *
 * It is deliberately dumb. It renders what `ActionInstance::rendered()` hands
 * it and decides nothing: who it goes to was decided by
 * {@see \App\Support\Automation\SendRails}, and whether it goes at all was
 * decided there too. A mailable that re-derived a recipient would be a fourth
 * place the rails could be walked past.
 *
 * ## The From address is not the template's
 *
 * `message_templates.from_identity` is a **reply-to**, not a from. An outbound
 * address has to be one the sending domain is authorised for or it fails SPF
 * and DKIM and lands in spam — which for this product is the same failure as
 * not sending it, except that nobody finds out. So the envelope's from stays
 * the application's verified identity and the team's address is where a reply
 * goes. #94 is where a team gets a verified identity of its own, and this is
 * the line that changes when it does.
 *
 * ## The branded layout, and the announcement around it
 *
 * S86's frame (#97) is applied in `content()` below, and with it S87: when
 * this instance is a **stage completion on a milestone stage**, the frame
 * opens with what the team told their client that milestone is called. The
 * body is still only ever the team's own words — see
 * {@see MilestoneAnnouncement}, which argues why S87 is a frame rather than a
 * second mailable with its own way past F5.7 and F5.9.
 */
class AutomatedMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ActionInstance $instance,
        public readonly RenderedMessage $rendered,
        public readonly Team $team,
        /**
         * Whether the rails rewrote the recipient (F5.9's sandbox).
         *
         * The banner exists because a redirected message is otherwise
         * indistinguishable from a real one in the owner's inbox — and the
         * owner is exactly the person who would forward it to the client
         * believing it had already gone.
         */
        public readonly bool $redirected = false,
    ) {}

    public function envelope(): Envelope
    {
        /*
         * The fallback goes through the same strip as the rendered subject.
         *
         * `RenderMessage::asHeader()` takes CR and LF out of a subject
         * *because a subject is a mail header*, and the fallback was the one
         * value reaching that slot without the pass — a team name, typed into
         * a settings form, unstripped. Symfony's header encoder very probably
         * neutralises it; the rule is written down one file over and this is
         * the same slot.
         */
        $subject = $this->rendered->subject ?? RenderMessage::headerSafe($this->team->name);

        if ($this->redirected) {
            $subject = '[Sandbox] '.$subject;
        }

        /*
         * The line is built above rather than inline, and it is not a style
         * choice. `ActivityFeedIsolationTest` reads every `subject:` named
         * argument in `app/` to prove each activity-event subject type has a
         * permission rule, and a mail envelope's `subject:` is a different
         * `subject:` — with `$this->redirected` behind it, it resolved to a
         * "Redirected" morph class the feed had never heard of.
         *
         * PR #175 fixed the same collision from the other side by narrowing
         * that regex. Narrowing it far enough to tell these two apart would
         * mean teaching it which named arguments belong to which callee, which
         * a regex cannot do — so the mailable keeps a plain variable here and
         * the enumerating test keeps working.
         */
        return new Envelope(
            replyTo: $this->replyToAddresses(),
            subject: $subject,
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: array_filter([
                /*
                 * Our own key, on the wire, so a bounce or a complaint can be
                 * traced back to the row that sent it. #95 correlates on the
                 * provider's id where there is one; this is what survives the
                 * case where there is not.
                 */
                'X-Goldieflow-Message-Key' => $this->instance->message_key,
            ], is_string(...)),
        );
    }

    public function content(): Content
    {
        /*
         * Built here rather than in the constructor, and it matters.
         *
         * This mailable is queued and `SerializesModels`, so a constructor
         * argument is serialized into the job payload — and `BrandedEmail`
         * carries a logo's bytes. `content()` runs after the worker has
         * unserialized the job, so the frame is resolved on the way to the
         * transport and the queue carries an id.
         *
         * A second consequence, and the more useful one: the branding is
         * whatever the team's is **when the message is sent**, not when the
         * advance raised it. A message held for approval for two days goes out
         * wearing the logo the team has now.
         */
        return new Content(
            view: 'mail.automated-message',
            text: 'mail.automated-message-text',
            with: [
                'brand' => BrandedEmail::for($this->team),
                'milestone' => MilestoneAnnouncement::for($this->instance, $this->team, $this->rendered),
                'teamName' => $this->team->name,
                'bodyHtml' => $this->rendered->bodyHtml,
                'bodyText' => $this->rendered->bodyText,
                'redirected' => $this->redirected,
            ],
        );
    }

    /**
     * @return array<int, Address>
     */
    private function replyToAddresses(): array
    {
        $identity = $this->instance->messageTemplate?->from_identity;

        return is_string($identity) && $identity !== ''
            ? [new Address($identity, $this->team->name)]
            : [];
    }
}
