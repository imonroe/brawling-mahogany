<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\SystemRole;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Support\Messages\RenderMessage;
use App\Support\Tenancy\TeamContext;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\App;
use RuntimeException;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Throwable;

/**
 * Who a message appears to be from (PRD §8.5 · issues #12, #94).
 *
 * ## Three slots, and they move together or not at all
 *
 * This is one object rather than three static calls, and that is the whole
 * design. Round 1 of review found the version that shipped the **address** and
 * the **name** without the **reply**: a client read *"Bosart Group"*, hit Reply
 * and reached the product's own mailbox. Worse than doing nothing, because the
 * line it replaced said *Goldieflow* and at least matched where replies went.
 *
 * A caller cannot make that mistake against this class, because there is no way
 * to obtain `$from` without also obtaining `$replyTo` — and when no reply
 * address can be resolved, `$from` **drops the team's name** rather than
 * claiming an identity nobody can answer to. The invariant is structural
 * instead of remembered.
 *
 * ## The address is the product's
 *
 * The **address** must be an identity SES is authorised to send as, for two
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
 * ## The name is the team's, beside a product name that is not `APP_NAME`
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
 * The product half reads `config('app.product_name')` and **not**
 * `config('app.name')`. Round 2 of review found the first version reading
 * `app.name`, which is `APP_NAME`, which CLAUDE.md's rename note deliberately
 * pins at the `Brawling Mahogany` codename because it is slugged into the
 * session cookie and three cache prefixes. Clients were being sent *"Bosart
 * Group via Brawling Mahogany"*. One name doing two jobs is why nobody could
 * change it; two names doing one each is the fix.
 *
 * ## And the reply goes to a human
 *
 * PRD §8.5 wants *"a per-team sending identity with reply-to pointing at the
 * actual agent"*. Reply-To is the slot that can carry an arbitrary address
 * without any DNS involvement, which is why `message_templates.from_identity`
 * was defined as a reply-to rather than a from when it shipped.
 *
 * Three candidates, most specific first — see {@see resolveReply()}. The third
 * exists because the first two are both **nullable and unset by default**:
 * every team that existed before this shipped, and every team created since by
 * `ProvisionTeam`, the admin console or a factory, has a null
 * `sending_identity_email`. A fallback chain whose every link is empty for the
 * ordinary case is CLAUDE.md's *"a default nobody can leave is not a default"*
 * in its other form — a default nobody ever reaches.
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
    private function __construct(
        /** What the recipient sees in the inbox line. */
        public readonly Address $from,
        /**
         * Where a reply lands. Empty only when nobody on the team can be
         * reached — in which case `$from` has already dropped their name.
         *
         * @var list<Address>
         */
        public readonly array $replyTo,
    ) {}

    /**
     * Both halves of a team's sending identity, resolved together.
     *
     * @param  string|null  $preferred  A more specific reply address than the team's, when one exists.
     * @param  string|null  $preferredName  Who that address belongs to, when it is not the team itself.
     *
     * @throws RuntimeException when no sending address is configured
     */
    public static function for(Team $team, ?string $preferred = null, ?string $preferredName = null): self
    {
        $reply = self::resolveReply($team, $preferred, $preferredName);

        return new self(
            from: new Address(
                self::verifiedAddress(),
                /*
                 * **The name follows the reply.** Naming the agency over a
                 * reply that reaches nobody is the round 1 defect; refusing to
                 * name them is the only honest thing left when there is no
                 * address to answer on. A client then sees the product, which
                 * is at least true of where the message came from.
                 */
                $reply instanceof Address
                    ? self::displayName($team)
                    : self::productName(),
            ),
            replyTo: $reply instanceof Address ? [$reply] : [],
        );
    }

    /**
     * The reply address a team can actually be reached on, or null.
     *
     * The order is most specific first:
     *
     * 1. **A message template's own `from_identity`.** Chosen per template —
     *    *"replies to the inspection chaser go to the coordinator"*.
     * 2. **`teams.sending_identity_email`.** The field S72 has labelled
     *    *"Reply-to address"* since Slice 1, and which reached no header at all
     *    until this change: `MergeFields::contactBlock()` put it in the *body*
     *    and that was the only reader. A writer with no reader, which is
     *    CLAUDE.md's *"a reader with no writer is as dead as a row nothing can
     *    reach"* running the other way.
     * 3. **A team owner's own address.** Both fields above are nullable and
     *    nothing sets either one — not the migration, not `ProvisionTeam`, not
     *    `TeamFactory`. So for every team that exists today the first two links
     *    are empty, and without this one the ordinary case is the defect round 1
     *    found rather than the exception to it. An owner is a person on the
     *    agency whose name is on the From line, which is exactly what §8.5's
     *    *"pointing at the actual agent"* asks for.
     */
    private static function resolveReply(Team $team, ?string $preferred, ?string $preferredName): ?Address
    {
        /*
         * The name travels with the address it belongs to. An invitation
         * replies to the person who sent it, so pairing their address with
         * the *team's* name puts two different people in one header — the
         * recipient reads "Bosart Group" and writes to Emily.
         */
        $candidates = [
            [$preferred, $preferredName ?? self::teamName($team)],
            [$team->sending_identity_email, self::teamName($team)],
        ];

        foreach ($candidates as [$candidate, $name]) {
            $address = self::address($candidate, RenderMessage::headerSafe($name));

            if ($address instanceof Address) {
                return $address;
            }
        }

        return self::ownerReply($team);
    }

    /**
     * A team owner's address, or null when the team has no reachable owner.
     *
     * Scoped through `holdingSystemRole()` rather than a key match, because a
     * team may compose a role of its own called *"Team Owner"* — `roles` is a
     * shared namespace with no global scope, so `Str::slug('Team Owner', '_')`
     * collides with the shipped key exactly. `TeamMembership` already holds
     * that distinction; this asks it rather than re-deriving it.
     */
    private static function ownerReply(Team $team): ?Address
    {
        /*
         * **Scoped to this team, not lifted.** `TeamMembership` carries the
         * global scope, and a mailable renders wherever the queue happens to
         * run it — `envelope()` is called by the worker, not by the request
         * that raised the row. Reaching for `withoutTeamScope()` here would be
         * the wrong hatch: `UnscopedQueryConventionTest` allows it for a
         * question about the *actor* or a context with no tenant, and this is
         * neither — it reads one team's memberships, which is tenant data.
         *
         * `runFor()` answers the question under the right tenant instead, so
         * the scope narrows to exactly the team whose name is on the From line.
         */
        $owner = App::make(TeamContext::class)->runFor(
            $team,
            fn (): ?TeamMembership => TeamMembership::query()
                ->where('team_id', $team->getKey())
                /*
                 * **Revoked owners are not owners.** Without this the founding
                 * membership wins on `oldest('id')` however long ago they left,
                 * so a client's reply goes to somebody who no longer works
                 * there — and silently, since the address still exists.
                 *
                 * Both other owner queries in the codebase filter revocation
                 * (`ResolveRecipients::owners()`, `RevokeMembership`), and the
                 * first of those is F5.9's sandbox redirect target: without it
                 * here, the rail and the reply chain name different people for
                 * the same team.
                 */
                ->active()
                ->holdingSystemRole(SystemRole::TeamOwner->value)
                ->whereNotNull('email')
                /*
                 * Deterministic, so two messages from the same team do not name
                 * two different people. `id` rather than `created_at`: the
                 * latter is `timestamp(0)` and a seeded team's memberships share
                 * a second, which is the tiebreaker defect S47's queue lists
                 * already paid for.
                 */
                ->oldest('id')
                ->first(),
        );

        if (! $owner instanceof TeamMembership) {
            return null;
        }

        return self::address(
            $owner->email,
            RenderMessage::headerSafe($owner->fullName()),
        );
    }

    /**
     * One candidate, parsed by the thing that will actually send it.
     *
     * **Symfony rejects addresses Laravel's `email` rule accepts.** The
     * settings form validates with `email`, which passes `emily(work)@…` — a
     * legal RFC 5322 comment — and `Address` then throws inside `Mail::send()`,
     * in a worker, after `message_key` has already been claimed. The row fails
     * permanently, is never retried, and the team is told *"the mail transport
     * rejected this message"*, which points at SES for a value typed into a
     * form here.
     *
     * `TeamController::update()` refuses one on the way in now. This is the other end of
     * that pair, for the rows already stored and for a template's
     * `from_identity`, which has its own form: a candidate that cannot be
     * parsed is **skipped**, so the chain falls through to an address that can
     * be. A message that reaches the client with a slightly wrong Reply-To beats
     * a message that never leaves.
     */
    private static function address(mixed $candidate, string $name): ?Address
    {
        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $candidate = trim($candidate);

        try {
            /*
             * **Symfony's `Address`, not Laravel's.** The first version of this
             * guard constructed `Illuminate\Mail\Mailables\Address`, which is
             * a plain DTO that validates nothing — so the try/catch caught
             * nothing and the throw still happened, four layers along, in the
             * worker. Exactly the mistake `SendableEmailAddress` was written
             * about, made inside the fix for it: the only check guaranteed to
             * agree with the one that matters is the one that matters.
             */
            new SymfonyAddress($candidate);
        } catch (Throwable) {
            return null;
        }

        return new Address($candidate, $name);
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
     * `config/mail.php` defaults the key, so this needs `MAIL_FROM_ADDRESS` to
     * be present and empty rather than absent — `MAIL_FROM_ADDRESS=null` in a
     * `.env`, which Laravel casts to a real null and returns in place of the
     * default. A blank string is refused too, which `config()->string()` would
     * hand straight through because it is a string.
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
     * The inbox line for a message a team is sending.
     */
    public static function displayName(Team $team): string
    {
        /*
         * Through the same CR/LF strip a subject goes through, and for the
         * same reason: this is a mail **header**, the value is typed into a
         * settings form, and `RenderMessage` is where that rule already lives.
         * A newline here would let a team split the header.
         */
        $name = RenderMessage::headerSafe(self::teamName($team));

        $product = self::productName();

        /*
         * An empty team name would render *" via Goldieflow"*, with the leading
         * space and no agency — worse than the product's own name alone, which
         * is at least a name. `teams.name` is not nullable, but
         * `sending_identity_name` is, and `headerSafe()` can empty a string
         * that was only ever control characters.
         */
        if ($name === '' || mb_strtolower($product) === mb_strtolower($name)) {
            return $name === '' ? $product : $name;
        }

        return $product === '' ? $name : $name.' via '.$product;
    }

    /**
     * What the product is called — `APP_PRODUCT_NAME`, never `APP_NAME`.
     *
     * See the class docblock: `APP_NAME` is an infrastructure identifier that
     * still carries the pre-rename codename on purpose.
     */
    private static function productName(): string
    {
        return RenderMessage::headerSafe(trim((string) config('app.product_name')));
    }

    /** The team's own chosen sending name, or the team's name. */
    private static function teamName(Team $team): string
    {
        return trim((string) ($team->sending_identity_name ?? '')) !== ''
            ? (string) $team->sending_identity_name
            : $team->name;
    }
}
