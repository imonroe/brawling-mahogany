<?php

declare(strict_types=1);

namespace App\Support\StatusPage;

use App\Enums\DocumentVisibility;
use App\Enums\StageState;
use App\Enums\SystemRole;
use App\Models\Deal;
use App\Models\DealParticipant;
use App\Models\Document;
use App\Models\KeyDate;
use App\Models\Property;
use App\Models\Stage;
use App\Models\StatusPageLink;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\Workflow;
use App\Support\Branding\AccentContrast;
use App\Support\Branding\TeamLogo;
use App\Support\Formatting\Format;

/**
 * What a client sees (PRD §4.7 F7.2, F7.4–F7.6 · IA §9 · issue #111).
 *
 * ## This is a reassurance surface, not a workspace
 *
 * PRD §4.7 was cut back in v0.2 on direct customer feedback. Emily: the client
 * interface *"doesn't matter at all."* Heather: *"I don't want my client
 * checking off a to-do list."* Client-visible tasks and client uploads are
 * **cut and staying cut**, and F7.6 is a *call your agent* block rather than a
 * messaging system.
 *
 * ## IA §9's three rules are enforced here, not in the template
 *
 * They are the whole reason this class exists rather than a controller reading
 * relations into props. A template that had the stage rows in hand could
 * render `$stage->name` in one place and forget, and the failure is silent
 * until a seller reads *"Chase lender"* on their own page.
 *
 *  - **The client sees `milestone_label`, never the internal stage name.**
 *    A stage with no label gets its position described instead — never an
 *    invented one, which is the rule `Stage::clientAnnouncement()` already
 *    holds for the milestone email.
 *  - **No instructions directed at the client.** Every sentence below states a
 *    fact. *"Your inspection is scheduled for Thursday"* is correct;
 *    *"Action needed: confirm inspection time"* is not.
 *  - **No alarming words.** *Blocked*, *failed*, *overdue* and *error* never
 *    reach a client. A `blocked` stage renders as In Progress (IA §8), and a
 *    date that has passed is simply a date that has passed.
 *
 * Skipped stages are hidden entirely, which is IA §7's point about Skip and
 * Override being legally distinct arriving on the client surface: a stage that
 * did not apply to this deal is not a step this client's sale has.
 */
final class ClientStatus
{
    /**
     * How many *"Important Dates"* a client is shown.
     *
     * A short list, and only ahead. IA §9's no-alarming-words rule is about
     * vocabulary; this is the same instinct about volume — a client reading a
     * fourteen-row contingency chain is a client with questions the page was
     * built to prevent.
     */
    public const DATES_SHOWN = 4;

    public function __construct(private readonly TeamLogo $logos) {}

    /**
     * @return array<string, mixed>
     */
    public function for(StatusPageLink $link): array
    {
        $deal = $link->deal;
        $team = $link->team;

        if (! $deal instanceof Deal || ! $team instanceof Team) {
            return [];
        }

        $deal->loadMissing([
            'dealType',
            'propertyLinks.property',
            'participants.membership',
            'workflows.stages',
        ]);

        return [
            'team' => $this->branding($team),
            'deal' => $this->hero($deal),
            'status' => $this->statusCard($deal),
            'steps' => $this->steps($deal),
            'dates' => $this->dates($deal),
            'contact' => $this->contact($deal, $team),
            'hasDocuments' => $this->documentQuery($deal)->exists(),
        ];
    }

    /**
     * The team's own frame (F7.5).
     *
     * ## The accent is theirs; the text on it is computed
     *
     * Design System §15.6 settles warn-versus-adjust by surface, and the
     * deciding fact is whether anybody is standing there. On S72 the owner is
     * looking at a preview and is warned. Here nobody is: a client reads this
     * once, on a phone, and a heading they cannot read is the phone call this
     * page exists to prevent. So the colour is the one they chose and the
     * black-or-white on it is worked out — the same split `BrandedEmail` makes.
     *
     * The near-black passed in is the **app's** `--foreground`, not the email
     * palette's: two design systems, two values, and a shared helper picking
     * one for both would be inventing a third.
     *
     * @return array<string, mixed>
     */
    private function branding(Team $team): array
    {
        $accent = is_string($team->brand_accent_color) && AccentContrast::isHex($team->brand_accent_color)
            ? mb_strtoupper($team->brand_accent_color)
            : null;

        return [
            'name' => $team->name,
            'accent' => $accent,
            'accentForeground' => $accent === null
                ? null
                : AccentContrast::foregroundFor($accent, self::FOREGROUND_DARK),
            /*
             * A data URI, for the reason the email embeds one: the bytes are
             * on a private disk and a client has **no session** to fetch them
             * with. A raster asset cannot participate in the token layer
             * (§2.6), so it gets a plate that stays light in both schemes.
             */
            'logo' => $this->logoDataUri($team),
        ];
    }

    /**
     * The app's near-black, as a hex.
     *
     * `--foreground` in `app.css` is an oklch value with the hex beside it in
     * a comment; this is that hex. Duplicated deliberately rather than parsed:
     * a colour-space conversion in PHP to answer a contrast question would be
     * a second implementation of the stylesheet, and `tests/js/tokens.test.ts`
     * already holds the pair to each other.
     */
    private const FOREGROUND_DARK = '#0A0E11';

    private function logoDataUri(Team $team): ?string
    {
        $bytes = $this->logos->contents($team);
        $mime = $bytes === null ? null : $this->logos->mimeType($team);

        // Both or neither — a part this class cannot label renders as a broken
        // icon beside the team's name, which is worse than the wordmark.
        return $mime === null || $bytes === null
            ? null
            : 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * The hero: what this is, and where.
     *
     * IA §2's client-facing label for a deal is *"Your Sale"* / *"Your
     * Purchase"*, which comes from the deal type's side rather than from the
     * deal's own name — `Deal::displayName()` is an internal string that falls
     * back to a client's surname, and *"Bosart Purchase"* is not a heading to
     * put in front of the Bosarts.
     *
     * @return array<string, mixed>
     */
    private function hero(Deal $deal): array
    {
        $property = $this->subjectProperty($deal);

        return [
            'kind' => $deal->dealType?->side->clientLabel() ?? 'Your Transaction',
            'addressLine1' => Format::addressLines($property)['line1'],
            'addressLine2' => Format::addressLines($property)['line2'],
        ];
    }

    /**
     * The most important element on the page (Design System §9.6).
     *
     * §9.6 gives it two paragraphs and is emphatic about the second:
     *
     * > *"There is nothing you need to do right now. Emily will call you as
     * > soon as the inspection report comes back."*
     * >
     * > That second paragraph is the "nothing is happening" state the Screen
     * > Inventory flags as mattering most. It is not an empty state to be
     * > designed later — it is the default copy, present in every status.
     *
     * So it is composed here for **every** state, not only for the quiet week.
     *
     * @return array<string, mixed>
     */
    private function statusCard(Deal $deal): array
    {
        $stage = $this->currentStage($deal);

        $where = $stage instanceof Stage
            ? $this->clientLabelFor($stage, $deal)
            : null;

        return [
            /*
             * A fact, never an instruction. The stage's own client-facing
             * label is a sentence somebody wrote for a client — *"Your home is
             * on the market"* — so it stands on its own.
             */
            'headline' => $where ?? 'Everything is under way.',
            /*
             * The reassurance, present in every status. Named for the agent
             * where there is one, because *"your agent will call you"* is a
             * weaker promise than a person's name.
             */
            'reassurance' => 'There is nothing you need to do right now.',
        ];
    }

    /**
     * The timeline, at client scale (Design System §9.6).
     *
     * One row per stage of the deal's workflows, in order, with **no gates, no
     * workflows, no overrides, no tasks and no checkboxes**. Two workflows on
     * one deal are drawn as one list here rather than as two rails: a client
     * has one sale, and F4.7's concurrency is an internal fact about how the
     * team runs it.
     *
     * @return list<array<string, mixed>>
     */
    private function steps(Deal $deal): array
    {
        $rows = [];

        foreach ($deal->workflows as $workflow) {
            foreach ($workflow->stages->sortBy('sort_order') as $stage) {
                /*
                 * IA §9: skipped stages are hidden **entirely**. A stage that
                 * did not apply to this deal is not a step this client's sale
                 * has, and showing it greyed out would invite the question the
                 * page exists to prevent.
                 */
                if ($stage->state === StageState::Skipped) {
                    continue;
                }

                $label = $this->clientLabelFor($stage, $deal);

                if ($label === null) {
                    continue;
                }

                $rows[] = [
                    'id' => $stage->getKey(),
                    'label' => $label,
                    /*
                     * Three positions, not five states. IA §8's stage
                     * vocabulary has `blocked`, and a client never sees it —
                     * a blocked stage is *happening now*, because from where
                     * the client sits that is what it is.
                     */
                    'position' => $this->positionOf($stage),
                    'when' => $this->whenOf($stage),
                ];
            }
        }

        return $rows;
    }

    /**
     * What a client is told a stage is called (IA §9).
     *
     * `milestone_label` and nothing else. A stage with no label is **omitted**
     * rather than named from `stages.name`: internal names say things like
     * *"Chase lender"* and *"Nudge the other agent"*, which are accurate,
     * useful, and not for sharing. `Stage::clientAnnouncement()` already makes
     * this decision for the milestone email, and it is the same decision.
     *
     * The consequence is real and correct: a workflow whose author labelled no
     * stage shows a client an empty timeline, and the status card carries the
     * page on its own. That is better than a page that leaks the team's
     * internal shorthand, and S41 is where somebody fixes it.
     */
    private function clientLabelFor(Stage $stage, Deal $deal): ?string
    {
        return $stage->clientAnnouncement();
    }

    private function positionOf(Stage $stage): string
    {
        return match (true) {
            $stage->state === StageState::Complete => 'done',
            $stage->isInProgress() => 'now',
            default => 'next',
        };
    }

    /**
     * The sub-line: *"Finished 2 August"* / *"Happening now"* / *"Expected 22
     * August"* (Design System §9.6, verbatim).
     *
     * Client date format throughout (IA §10): *"Thursday, August 20"*, full
     * month, no year unless it differs.
     */
    private function whenOf(Stage $stage): ?string
    {
        return match (true) {
            $stage->state === StageState::Complete && $stage->actual_end !== null => 'Finished '.Format::clientDate($stage->actual_end),
            $stage->state === StageState::Complete => 'Finished',
            $stage->isInProgress() => 'Happening now',
            $stage->planned_start !== null => 'Expected '.Format::clientDate($stage->planned_start),
            default => null,
        };
    }

    /**
     * *Important Dates* — IA §2's client-facing label for `key_dates`.
     *
     * ## Only what is ahead, and never how late anything is
     *
     * IA §9 bans *overdue* from this surface, and the honest way to keep that
     * rule is not to soften the word — it is not to show the row. A deadline
     * that has passed is either met, in which case saying so is the timeline's
     * job, or missed, in which case *"the agent handles it by phone"* is IA
     * §9's own instruction and a line on a web page is the wrong channel.
     *
     * An extracted date nobody has confirmed is excluded for the reason it is
     * excluded everywhere else (#116): a proposal is not a date, and this is
     * the surface where a wrong one would do the most damage.
     *
     * @return list<array<string, mixed>>
     */
    private function dates(Deal $deal): array
    {
        return array_values(KeyDate::query()
            ->confirmed()
            ->where('deal_id', $deal->getKey())
            ->whereDate('date', '>=', KeyDate::today())
            ->orderBy('date')
            ->limit(self::DATES_SHOWN)
            ->get()
            ->map(fn (KeyDate $date): array => [
                'id' => (string) $date->getKey(),
                'name' => $date->name,
                'date' => Format::clientDate($date->date),
            ])
            ->all());
    }

    /**
     * F7.6 — *"call or email your agent"*, and **not** a messaging system.
     *
     * Heather's professionalism point: chasing a client through an interface
     * reads as less professional than a phone call, not more. So this is a
     * name and a number, and the client's next action is to use their phone.
     *
     * The agent is the deal's own — a participant in an agent role — falling
     * back to the team. A client who is told to ring *"your agent"* with no
     * name has been told nothing.
     *
     * @return array<string, mixed>
     */
    private function contact(Deal $deal, Team $team): array
    {
        $membership = $this->agentFor($deal) ?? $this->ownerOf($team);

        return [
            'name' => $membership instanceof TeamMembership ? $membership->fullName() : $team->name,
            'phone' => $membership?->phone,
            'email' => $membership?->email,
        ];
    }

    /**
     * The agent on this deal, if the roster names one.
     *
     * `deals` has **no owning-agent column** — the gap `DealHeader` records
     * for §8.4's meta row — so the roster is the only place a person is
     * attached to a deal at all. `co_agent` is the team's own side;
     * `opposing_agent` is emphatically not, and naming them here would be the
     * worst possible answer to *"who do I call?"*.
     */
    private function agentFor(Deal $deal): ?TeamMembership
    {
        $participant = $deal->participants
            ->first(fn (DealParticipant $participant): bool => $participant->participant_role->isTeamSide());

        $membership = $participant?->membership;

        return $membership instanceof TeamMembership ? $membership : null;
    }

    /**
     * The team's owner, as the fallback, and **an owner who is still here**.
     *
     * `SendingIdentity` records what the missing `active()` costs one surface
     * along: `oldest('id')` picks the *founding* membership, so a client is
     * told to ring whoever set the team up however long ago they left —
     * silently, because the row still exists. Every owner query in this
     * codebase filters revocation, and this is a third.
     */
    private function ownerOf(Team $team): ?TeamMembership
    {
        $owner = TeamMembership::withoutTeamScope()
            ->where('team_id', $team->getKey())
            ->active()
            ->holdingSystemRole(SystemRole::TeamOwner->value)
            ->oldest('id')
            ->first();

        return $owner instanceof TeamMembership ? $owner : null;
    }

    /**
     * S63's list — only what somebody deliberately marked client-visible.
     *
     * `DocumentVisibility` is internal by default and client-visible only by
     * explicit choice (#98), so *"anything explicitly scoped client-visible"*
     * is a column rather than a judgement.
     *
     * **`scan_state` is not on this surface at all.** A badge reading *clean*
     * over a photograph of a cheque would be believed, and *not scanned* is
     * exactly the kind of word IA §9's no-alarming-words rule exists to keep
     * out. S63 shows a document or does not show it.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Document>
     */
    public function documentQuery(Deal $deal): \Illuminate\Database\Eloquent\Builder
    {
        return Document::query()
            ->where('documentable_type', $deal->getMorphClass())
            ->where('documentable_id', $deal->getKey())
            ->where('visibility', DocumentVisibility::ClientVisible->value)
            ->orderByDesc('created_at');
    }

    private function subjectProperty(Deal $deal): ?Property
    {
        $link = $deal->propertyLinks->firstWhere('is_subject', true);

        $property = $link?->property;

        return $property instanceof Property ? $property : null;
    }

    private function currentStage(Deal $deal): ?Stage
    {
        foreach ($deal->workflows as $workflow) {
            $active = $workflow->activeStage();

            if ($active instanceof Stage) {
                return $active;
            }
        }

        return null;
    }
}
