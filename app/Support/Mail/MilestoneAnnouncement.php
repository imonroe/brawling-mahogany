<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\AutomationTrigger;
use App\Models\Deal;
use App\Models\Stage;
use App\Models\Team;
use App\Support\Messages\MergeContext;
use App\Support\Messages\MergeFields;
use App\Support\Messages\RenderedMessage;

/**
 * S87 — what a client is told a milestone *is* (issue #97 · PRD §5.4, §5.7).
 *
 * `Stage::clientAnnouncement()` has existed since Slice 2 with the IA §9
 * argument written on it and **no caller anywhere**, which is `CLAUDE.md`'s
 * S17 finding in its quieter form: not a rule nobody follows, but a promise
 * nothing keeps. This is the caller.
 *
 * ## It is not a second email, and that is the whole design
 *
 * The obvious reading of S87 is a `MilestoneNotificationMail`. That would be a
 * second path to a client's inbox, past F5.7's approval queue and F5.9's three
 * rails — the thing PRD §4.5 calls the highest-blast-radius feature in the
 * product, with a second front door cut into it for a layout.
 *
 * So a milestone notification is an ordinary automated message that happens to
 * be *about* a milestone, and this is the frame it wears. Every rail, the
 * queue, the dedupe and the timeline entry are unchanged, because nothing
 * about the send changed.
 *
 * ## Why the words come from the stage and the body does not
 *
 * The headline is `milestone_label` — a sentence a team wrote on their own
 * stage template, in client vocabulary, which IA §9 requires and which no
 * merge field can be trusted to have been used. The body is the team's
 * template, untouched. Nothing here writes a sentence on a team's behalf.
 *
 * ## The MLS link, and the one duplicate worth preventing
 *
 * PRD §5.4's worked example is *"the seller email with the MLS link"*, and
 * `{{ mls_link }}` is a merge field a template may already carry. A frame
 * that added its own button regardless would send half the teams in the
 * product a message with the same URL in it twice — once as the team wrote it
 * and once as we decided.
 *
 * So the button appears only when the **rendered body does not already carry
 * that URL**. Checked against the rendered text rather than against the
 * template's tokens: an author can write the listing URL out in full instead
 * of using the field, and a token scan would not see it.
 *
 * ## The status page link slot is still empty, and the reason changed
 *
 * PRD §5.7 step 1 is *"the seller receives a milestone email containing a
 * status page link"*, and the page is #110. The issue names both — *"with and
 * without status link"* — so the slot exists, takes precedence over the MLS
 * link when it is filled, and is null everywhere today.
 *
 * It was null through Slice 3 because the page did not exist. Slice 4 built
 * the page and the slot stayed null, which is worth saying out loud rather
 * than leaving as an oversight somebody later "fixes" by filling it: what is
 * missing now is a **credential a message may carry**. `IssueStatusPageLink`
 * mints a 30-minute single-use link and revokes every live grant that person
 * holds on the deal — correct for a client asking for a new link, and wrong
 * for an automation, which would end the session of a client browsing the page
 * at the moment a stage completed. Pointing at the session they already hold
 * is not available either: the session token is stored hashed, deliberately,
 * so nothing can render it back into a URL. `MergeFields::status_page_link`
 * carries the same note, and is unavailable for the same reason.
 *
 * ## Snapshotted at raise time, into the payload, beside the words
 *
 * The obvious implementation resolves this in the mailable, at send time. That
 * puts a **live** read of the deal in the same email as a body that was
 * rendered when the automation fired — the address in the header and the
 * address in the paragraph under it, answering the same question from two
 * different moments. `CLAUDE.md` records the shape on S16: a cache and a live
 * value inside one card is incoherent *within one card*.
 *
 * The sharper reason is F5.7. A message can sit in the approval queue for
 * days, and what an approver reads on S48 **is the payload**. Anything in the
 * email not derived from the payload was never approved by anybody — so the
 * announcement belongs there, snapshotted with the words it was rendered
 * beside.
 *
 * The team's **branding** is deliberately not snapshotted, and the distinction
 * is the point: a logo and an accent are the team's own identity, the same on
 * every message, and a message held for two days should go out wearing the
 * logo the team has now. The announcement is deal content, which is the same
 * class of thing as the body.
 */
final readonly class MilestoneAnnouncement
{
    private function __construct(
        public string $headline,
        public ?string $propertyAddress,
        public ?string $mlsLink,
        public ?string $statusPageLink,
    ) {}

    /**
     * The announcement an instance being raised would carry, or null.
     *
     * Null is the ordinary answer. Most automated messages are not about a
     * milestone, and the frame draws a plain branded message for those.
     *
     * Called from {@see \App\Support\Automation\RaiseAutomations}, inside the
     * advance's transaction, with the same `MergeContext` the words were just
     * rendered against.
     */
    public static function snapshot(
        AutomationTrigger $trigger,
        ?Stage $stage,
        Deal $deal,
        Team $team,
        RenderedMessage $rendered,
    ): ?self {
        /*
         * A milestone is *the notable completion of a stage* (IA §2), so only
         * a completion announces one. A `stage_start` email on a milestone
         * stage would open with "Your home is on the market" on the morning
         * the photographer was booked.
         */
        if ($trigger !== AutomationTrigger::StageCompletion) {
            return null;
        }

        if (! $stage instanceof Stage) {
            return null;
        }

        $headline = $stage->clientAnnouncement();

        if ($headline === null) {
            return null;
        }

        /*
         * The same resolver the body was rendered through, so the address in
         * the frame and the address in the words cannot disagree. #75 folded
         * two `deal` payloads into one for exactly this reason, and a header
         * that named a different property from the paragraph under it would be
         * that problem back, on the surface that cannot be corrected after the
         * fact.
         */
        $facts = MergeFields::resolve(MergeContext::for($deal, $team, $stage));

        $address = self::orNull($facts['property_address'] ?? '');
        $mls = self::orNull($facts['mls_link'] ?? '');

        return new self(
            headline: $headline,
            propertyAddress: $address,
            mlsLink: $mls === null || self::bodyCarries($rendered, $mls) ? null : $mls,
            // #110. The slot, not a guess at the URL.
            statusPageLink: null,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'headline' => $this->headline,
            'propertyAddress' => $this->propertyAddress,
            'mlsLink' => $this->mlsLink,
            'statusPageLink' => $this->statusPageLink,
        ];
    }

    /**
     * Read one back out of an instance's payload.
     *
     * Null for anything that is not a complete announcement, which covers the
     * two cases that matter and needs no migration for either: an instance
     * raised before #97 has no `milestone` key at all, and one raised from a
     * stage that is not a milestone never had one. Both render a plain frame.
     */
    public static function fromPayload(mixed $payload): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        $headline = self::orNull($payload['headline'] ?? null);

        if ($headline === null) {
            return null;
        }

        return new self(
            headline: $headline,
            propertyAddress: self::orNull($payload['propertyAddress'] ?? null),
            mlsLink: self::orNull($payload['mlsLink'] ?? null),
            statusPageLink: self::orNull($payload['statusPageLink'] ?? null),
        );
    }

    /**
     * The same suppression, applied again after an approver edits the words.
     *
     * S48 lets an approver rewrite the body, and *"let me add the listing
     * link"* is an ordinary thing to do with it — which would put the URL in
     * the message twice, once typed and once as the button this frame draws.
     * Re-asked rather than left alone, and it is still not a live read: the
     * edited words are already in the payload, so this is string work on
     * snapshotted values.
     */
    public function withoutLinkAlreadyIn(RenderedMessage $rendered): self
    {
        if ($this->mlsLink === null || ! self::bodyCarries($rendered, $this->mlsLink)) {
            return $this;
        }

        return new self(
            headline: $this->headline,
            propertyAddress: $this->propertyAddress,
            mlsLink: null,
            statusPageLink: $this->statusPageLink,
        );
    }

    /**
     * The one call to action, or none.
     *
     * PRD §5.7 makes the status page the destination a milestone email exists
     * to lead to, so it wins when there is one. Two buttons would make the
     * client choose between them, and §5.7's whole point is that there is
     * nothing for them to do.
     *
     * @return array{url: string, label: string}|null
     */
    public function callToAction(): ?array
    {
        if ($this->statusPageLink !== null) {
            return ['url' => $this->statusPageLink, 'label' => 'See where things stand'];
        }

        if ($this->mlsLink !== null) {
            return ['url' => $this->mlsLink, 'label' => 'View the listing'];
        }

        return null;
    }

    /**
     * Both halves, and both spellings of the URL.
     *
     * `RenderMessage` escapes a merged value into `body_html` and leaves it
     * alone in `body_text`, so a listing URL with a query string is
     * `?a=1&amp;b=2` in one half and `?a=1&b=2` in the other. Searching for
     * one spelling finds it in one body and misses it in the other — and a
     * template with no plain-text half of its own leaves only the body where
     * the miss happens. Two spellings across two bodies is four cheap
     * `str_contains` calls and no false negative.
     */
    private static function bodyCarries(RenderedMessage $rendered, string $url): bool
    {
        $spellings = array_unique([$url, htmlspecialchars($url, ENT_QUOTES, 'UTF-8')]);

        foreach ([$rendered->bodyHtml, $rendered->bodyText] as $body) {
            if (! is_string($body) || $body === '') {
                continue;
            }

            foreach ($spellings as $spelling) {
                if (str_contains($body, $spelling)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function orNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
