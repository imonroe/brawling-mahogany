<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * What a person is being told (PRD §4.12 F12.4 · issue #101).
 *
 * Issue #101's list, and #103's is the same one: *"deadline reminders · task
 * assignment · gate cleared · override performed · automation failure"*, plus
 * F5.3's *post internal notification* action, which is a person deliberately
 * telling the team something rather than the product noticing it.
 *
 * ## Nothing here is client-facing, deliberately
 *
 * #103 says it in as many words about push, and it is true of the whole table:
 * every one of these is a fact about how the *team's* work is going. A client
 * hears from this product through F5.7's approval queue and nowhere else, so
 * there is no route by which a preference set here can reach one.
 */
enum NotificationType: string implements HasLabel
{
    use ProvidesOptions;

    case TaskAssigned = 'task_assigned';
    case DeadlineApproaching = 'deadline_approaching';
    case GateCleared = 'gate_cleared';
    case GateOverridden = 'gate_overridden';
    case AutomationFailed = 'automation_failed';
    case Announcement = 'announcement';

    public function label(): string
    {
        return match ($this) {
            self::TaskAssigned => 'A task is assigned to me',
            self::DeadlineApproaching => 'Something of mine is due soon',
            self::GateCleared => 'A requirement clears',
            self::GateOverridden => 'Somebody overrides a requirement',
            self::AutomationFailed => 'An automation fails',
            self::Announcement => 'Somebody posts a note to the team',
        };
    }

    /**
     * A sentence for S78, because the label alone does not say who gets it.
     *
     * Two of these are *mine* and four are the *team's*, and somebody choosing
     * whether to be emailed needs to know which — *"a requirement clears"*
     * reads very differently if it means every requirement on every deal.
     */
    public function description(): string
    {
        return match ($this) {
            self::TaskAssigned => 'When somebody assigns you a task, or reassigns one to you.',
            self::DeadlineApproaching => 'The day before a task assigned to you is due.',
            self::GateCleared => 'When a requirement clears on a deal you are working on.',
            self::GateOverridden => 'When somebody advances a stage over a requirement that was not met. This one is worth knowing about: an override is recorded permanently and creates a follow-up task.',
            self::AutomationFailed => 'When an automated message does not go out. The team already gets one email about this; the notification is so it is on your list too.',
            self::Announcement => 'When an automation is set up to post a note to the team at a point in a workflow.',
        };
    }

    /**
     * The heading on a lock screen (#103).
     *
     * ## Why this is not {@see self::label()}
     *
     * Round 1 of review printed all six and the answer was obvious:
     * `label()` is written for **S78's preference rows**, where each line
     * completes the sentence *"tell me when…"* — so it reads *"A task is
     * assigned to me"*, and `description()` explains the setting rather than
     * the event (*"When somebody assigns you a task, or reassigns one to
     * you."*). Rendered onto a phone that is announcing something that has
     * just happened, the pair reads as nonsense.
     *
     * The allowlist principle was right and the constants were wrong: a push
     * is still composed only from a value chosen here, never from anything a
     * tenant typed. These are simply the values written for the surface they
     * land on — present tense, short enough for the truncation a lock screen
     * applies, and none of them a sentence about a *setting*.
     */
    public function pushTitle(): string
    {
        return match ($this) {
            self::TaskAssigned => 'New task for you',
            self::DeadlineApproaching => 'Due tomorrow',
            self::GateCleared => 'Requirement cleared',
            self::GateOverridden => 'Requirement overridden',
            self::AutomationFailed => 'An automation needs looking at',
            self::Announcement => 'Note for the team',
        };
    }

    /**
     * The line under it — what happened, in the fewest words that still say
     * something. The property, when there is one, is prepended by
     * {@see \App\Support\Push\PushPayload}; this half never names anybody.
     */
    public function pushBody(): string
    {
        return match ($this) {
            self::TaskAssigned => 'A task has been assigned to you.',
            self::DeadlineApproaching => 'A task assigned to you is due tomorrow.',
            self::GateCleared => 'A requirement has cleared.',
            self::GateOverridden => 'A stage advanced over a requirement that was not met.',
            self::AutomationFailed => 'Open it to see what happened.',
            self::Announcement => 'An automation posted a note to the team.',
        };
    }

    /**
     * Which channels somebody gets before they have said anything (F12.4).
     *
     * **Only the ones people would ask for.** A product that emails everybody
     * about everything on day one teaches them to filter it, and a filtered
     * channel is a channel that does not work on the day it matters — which is
     * `AlertOnFailures`' argument about its own alert, applied to the defaults
     * rather than to the frequency.
     *
     * So the two that are *about me and time-sensitive* carry email out of the
     * box, and the rest start as the panel only. Somebody who wants more turns
     * it on; nobody has to turn anything off to stop being spammed.
     *
     * @return list<NotificationChannel>
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::TaskAssigned,
            self::DeadlineApproaching => [NotificationChannel::InApp, NotificationChannel::Email],
            default => [NotificationChannel::InApp],
        };
    }

    /**
     * Whether this one goes out during quiet hours anyway (issue #101).
     *
     * *"A notification suppressed by quiet hours is delayed, not dropped —
     * except where it is time-critical, which is a per-type decision to make
     * deliberately here."*
     *
     * So it is made here, and the answer is **nothing yet**. Every type on the
     * list is about work that is done in working hours: a task assigned at
     * 2am is a task somebody starts at nine, and a requirement clearing
     * overnight changes nothing anybody can act on before morning. Holding it
     * until the window opens costs nothing real, and F12.4's whole sentence is
     * *"nobody wants a 6am push."*
     *
     * The place a `true` will eventually belong is a deadline that is **today**
     * rather than tomorrow, or a legal date that moves — Slice 4's key dates.
     * Writing that branch now would be writing a rule for a case the product
     * cannot produce, which is a thing this codebase has been bitten by.
     */
    public function bypassesQuietHours(): bool
    {
        return false;
    }

    /**
     * What makes two of these *the same thing happening twice*.
     *
     * #101: *"twelve 'task assigned' notifications from one workflow
     * instantiation should read as one line, not twelve."* Grouping is by type
     * **and deal**, because that is the unit a person acts on — they open the
     * deal, not the task — and because instantiating a workflow is precisely
     * the event that produces twelve of one type on one deal in one second.
     *
     * `gate_overridden` is deliberately **not** grouped. IA §7 makes an
     * override legally distinct and `AdvanceWorkflow` writes four artefacts
     * for one; collapsing two of them into *"2 overrides"* is the summary that
     * stops somebody reading either.
     */
    public function groups(): bool
    {
        return $this !== self::GateOverridden;
    }

    /**
     * The plural, for a grouped line.
     *
     * Written per type rather than by appending an `s`, because *"3 requirement
     * clears"* is not English and the line a person reads on the busiest screen
     * in the shell is not the place to save six lines of code.
     */
    public function grouped(int $count): string
    {
        return match ($this) {
            self::TaskAssigned => $count.' tasks were assigned to you',
            self::DeadlineApproaching => $count.' of your tasks are due soon',
            self::GateCleared => $count.' requirements cleared',
            self::GateOverridden => $count.' requirements were overridden',
            self::AutomationFailed => $count.' automations failed',
            self::Announcement => $count.' notes were posted',
        };
    }
}
