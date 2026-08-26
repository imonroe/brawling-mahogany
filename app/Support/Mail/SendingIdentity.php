<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\Team;
use App\Support\Messages\RenderMessage;
use Illuminate\Mail\Mailables\Address;
use RuntimeException;

/**
 * Who a message appears to be from (PRD §8.5 · issues #12, #94).
 *
 * ## The address is the product's and the name is the team's
 *
 * These are two different slots and they answer to two different masters. The
 * **address** must be an identity SES is authorised to send as, for two
 * reasons, and neither of them is SPF:
 *
 * 1. SES **rejects the call** for an unverified identity. The message does not
 *    go anywhere at all.
 * 2. A `From` on a domain the message is not DKIM-aligned with fails
 *    **DMARC**, and lands in spam where it does not fail outright.
 *
 * SPF is evaluated against the **envelope** MAIL FROM, not this header, so a
 * `From` cannot fail it — which matters because getting the mechanism wrong is
 * how somebody later concludes the rule is negotiable. Either reason alone is
 * enough: for this product a message in a spam folder is the same failure as
 * not sending it, except that nobody finds out. So the address is always
 * `config('mail.from.address')`, and no team setting can move it.
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
     *
     * @throws RuntimeException when no sending address is configured
     */
    public static function forTeam(Team $team): Address
    {
        return new Address(
            self::verifiedAddress(),
            self::displayName($team),
        );
    }

    /**
     * The one address every message leaves as.
     *
     * `config()->string()` would throw here on a null, which is right — there
     * is no sane fallback, and guessing one is how a message goes out claiming
     * an identity SES will reject. But it throws Laravel's own sentence, in a
     * queue worker, about a config key; this throws the operator's sentence,
     * naming the variable they have to set and the file that says what to set
     * it to. Same failure, one step less to diagnose.
     *
     * The shape is `AppServiceProvider::configureMailGuardrail()`'s: read
     * loosely, decide here, and refuse rather than proceed on a value the rest
     * of the class has already promised is verified.
     *
     * @throws RuntimeException
     */
    private static function verifiedAddress(): string
    {
        $address = config('mail.from.address');

        if (! is_string($address) || trim($address) === '') {
            throw new RuntimeException(
                'MAIL_FROM_ADDRESS must be set to an identity SES is authorised to send as. '
                .'See docs/Environment and secrets.md §2.',
            );
        }

        return trim($address);
    }

    /**
     * Where a reply should land, or an empty list when there is nowhere.
     *
     * **This is the half that makes the From line honest.** Putting the
     * agency's name in the inbox line while a reply goes to the product's own
     * mailbox is worse than not doing it at all: the old line said *Goldieflow*
     * and at least matched where the reply went. A client who reads *"Bosart
     * Group"*, hits Reply and reaches nobody has been told something untrue by
     * the one header they act on.
     *
     * `teams.sending_identity_email` is the field S72 already labels
     * **"Reply-to address"**, and until now nothing read it into a header —
     * `MergeFields::contactBlock()` put it in the *body* and that was all.
     * `CLAUDE.md`'s *"a reader with no writer is as dead as a row nothing can
     * reach"*, running the other way: a writer with no reader, in the settings
     * card this change gives meaning to.
     *
     * @param  string|null  $preferred  A more specific address than the team's, when one exists.
     * @return list<Address>
     */
    public static function replyTo(Team $team, ?string $preferred = null): array
    {
        /*
         * The more specific address wins. A message template's own
         * `from_identity` is chosen per template — *"replies to the inspection
         * chaser go to the coordinator"* — and the team's is the default for
         * everything that does not say otherwise.
         */
        foreach ([$preferred, $team->sending_identity_email] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            return [new Address(trim($candidate), self::replyName($team))];
        }

        return [];
    }

    /**
     * The name beside the reply address.
     *
     * The team's own, without the *"via"* — a reply is going **to** them, so
     * the product has no part in it. Through `headerSafe()` like every other
     * tenant string that reaches a header; the version of this that shipped
     * did not, which is a rule written into one caller and not the one beside
     * it.
     */
    private static function replyName(Team $team): string
    {
        $name = trim((string) ($team->sending_identity_name ?? '')) !== ''
            ? (string) $team->sending_identity_name
            : $team->name;

        return RenderMessage::headerSafe($name);
    }

    public static function displayName(Team $team): string
    {
        $teamName = trim((string) ($team->sending_identity_name ?? '')) !== ''
            ? (string) $team->sending_identity_name
            : $team->name;

        $product = trim((string) config('app.name'));

        /*
         * Through the same CR/LF strip a subject goes through, and for the
         * same reason: this is a mail **header**, the value is typed into a
         * settings form, and `RenderMessage` is where that rule already lives.
         * A newline here would let a team split the header.
         */
        $name = RenderMessage::headerSafe($teamName);

        if ($product === '' || mb_strtolower($product) === mb_strtolower($name)) {
            return $name;
        }

        return $name.' via '.RenderMessage::headerSafe($product);
    }
}
