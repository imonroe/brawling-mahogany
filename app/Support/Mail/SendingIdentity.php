<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Team;
use App\Support\Messages\RenderMessage;
use Illuminate\Mail\Mailables\Address;

/**
 * Who a message appears to be from (PRD §8.5 · issues #12, #94).
 *
 * ## The address is the product's and the name is the team's
 *
 * These are two different slots and they answer to two different masters. The
 * **address** must be an identity the sending domain is authorised for, or the
 * message fails SPF and DKIM and lands in spam — which for this product is the
 * same failure as not sending it, except that nobody finds out. So it is
 * always `config('mail.from.address')`, the one verified identity, and no team
 * setting can move it.
 *
 * The **display name** is what a person reads in their inbox, and there the
 * right answer is the team. A seller who has been working with Bosart Group
 * for six weeks receives *"your home is on the market"* — from a product they
 * have never heard of, if the name is left at the application's. The address
 * beside it is unfamiliar too, so the name has to explain it.
 *
 * Hence *"Bosart Group via Goldieflow"*: the agency first, because that is who
 * the client thinks is writing, and the product after, because that is what
 * makes an unfamiliar sending address make sense rather than look forged. It
 * is also, near enough, what Gmail appends by itself when the From domain and
 * the display name diverge — so saying it plainly beats having the mail client
 * say it for us.
 *
 * ## And the reply goes to the agent
 *
 * PRD §8.5 wants *"a per-team sending identity with reply-to pointing at the
 * actual agent"*. Reply-To is the slot that can carry an arbitrary address
 * without any DNS involvement, which is why `message_templates.from_identity`
 * was defined as a reply-to rather than a from when it shipped — recorded in
 * the Decision Log at the time, and this is the other half of that decision
 * arriving.
 *
 * ## Which mailables use it
 *
 * The ones whose recipient thinks a **team** is writing to them: an automated
 * message to a client, and an invitation from an agency. Not the ones where
 * the product is genuinely the author — a template test send, or S91's alert
 * telling a team that their own automations are failing. Those keep the
 * application's own name, because attributing them to the team would be a
 * small lie in the one line a reader trusts most.
 */
final class SendingIdentity
{
    /**
     * The From line for a message a team is sending.
     */
    public static function forTeam(Team $team): Address
    {
        return new Address(
            config()->string('mail.from.address'),
            self::displayName($team),
        );
    }

    public static function displayName(Team $team): string
    {
        $team_name = trim((string) ($team->sending_identity_name ?? '')) !== ''
            ? (string) $team->sending_identity_name
            : $team->name;

        $product = trim((string) config('app.name'));

        /*
         * Through the same CR/LF strip a subject goes through, and for the
         * same reason: this is a mail **header**, the value is typed into a
         * settings form, and `RenderMessage` is where that rule already lives.
         * A newline here would let a team split the header.
         */
        $name = RenderMessage::headerSafe($team_name);

        if ($product === '' || mb_strtolower($product) === mb_strtolower($name)) {
            return $name;
        }

        return $name.' via '.RenderMessage::headerSafe($product);
    }
}
