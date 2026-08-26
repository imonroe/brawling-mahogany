<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AutomationActionType;
use App\Enums\AutomationState;
use App\Http\Controllers\Controller;
use App\Models\ActionInstance;
use App\Models\Team;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F5.9's rails, where a person can actually reach them (PRD §4.5 · #96).
 *
 * The rails themselves live in `SendRails`, in the worker. This is the screen
 * that turns them on and off, and it exists because of the finding `CLAUDE.md`
 * records from S17: **a row nothing can reach is a rule nobody is
 * following.** A kill switch with no hand on it is a column, and F5.9
 * describes it as *"one toggle"* — *"when a team calls to say stop, something
 * is wrong, the answer must be one toggle, and it must catch everything
 * already queued."*
 *
 * ## Its own screen rather than a panel on S72
 *
 * S72 is the team's name, its logo and its accent colour — things somebody
 * changes once and looks at. This is the screen somebody opens in a hurry
 * because a client has just phoned, and burying the stop button under a colour
 * picker is how it takes forty seconds to find instead of five.
 *
 * ## The reason is asked for and is not required
 *
 * Same judgement as stopping one message. Requiring an essay before somebody
 * can stop every outbound email their team sends gets the priority exactly
 * backwards. What is not optional is the record: the audit entry names who
 * pulled it and when, whatever was typed.
 */
class SendSafetyController extends Controller
{
    public function edit(TeamContext $teams): Response
    {
        $team = $teams->get();

        $this->authorize('update', $team);

        return Inertia::render('Settings/SendSafety', [
            'settings' => [
                'sendsDisabled' => $team->sendsAreDisabled(),
                'sendsDisabledReason' => $team->sends_disabled_reason,
                'sendsDisabledAt' => $team->sends_disabled_at?->toIso8601String(),
                'sandboxMode' => $team->sandbox_mode,
                'hourlySendLimit' => $team->hourly_send_limit,
                'dailySendLimit' => $team->daily_send_limit,
                'approvalRequiredUntil' => $team->approval_required_until?->toIso8601String(),
                'approvalIsMandatory' => $team->approvalIsMandatory(),
            ],
            /*
             * What the switch would actually catch, counted rather than
             * described. F5.9's *"it must catch everything already queued"* is
             * a promise, and a number is the difference between a person
             * believing it and hoping so.
             */
            'queued' => ActionInstance::query()
                ->whereIn('state', [
                    AutomationState::Pending->value,
                    AutomationState::AwaitingApproval->value,
                ])
                ->whereNull('message_key')
                ->count(),
            /*
             * Counted the same way `SendRails` counts it, emails only — the
             * number under the limit has to be the number the limit is
             * actually about, or the screen tells a team they are at 58 of 60
             * when the ceiling sees 12.
             */
            'sentInTheLastHour' => ActionInstance::query()
                ->where('state', AutomationState::Sent)
                ->where('action_type', AutomationActionType::SendEmail)
                ->where('executed_at', '>=', now()->subHour())
                ->count(),
        ]);
    }

    public function update(Request $request, TeamContext $teams, AuditLogger $audit): RedirectResponse
    {
        $team = $teams->get();

        $this->authorize('update', $team);

        $validated = $request->validate([
            'sends_disabled' => ['required', 'boolean'],
            'sends_disabled_reason' => ['nullable', 'string', 'max:500'],
            'sandbox_mode' => ['required', 'boolean'],
            /*
             * A ceiling, and a floor of one rather than zero.
             *
             * Zero is `sends_disabled` said a second way, and two spellings of
             * one state is how a team ends up with sending off and the switch
             * reading "on". The upper bounds are the shape of a small team's
             * day rather than a provider's limit: nothing legitimate in this
             * product sends five hundred emails in an hour, so a number that
             * large is somebody typing rather than deciding.
             */
            'hourly_send_limit' => ['required', 'integer', 'between:1,500'],
            'daily_send_limit' => ['required', 'integer', 'between:1,5000'],

            /*
             * F5.7's window, as something a person can end.
             *
             * `automation.md` said *"you can turn this off in team settings
             * once you trust what you have written"* while the screen rendered
             * it read-only — S17's finding, one control over: a row nothing
             * can reach is a rule nobody is following. A safety default a team
             * cannot leave is not a default, it is a limitation, and a team
             * that cannot leave it will find a way around it that nobody
             * audits.
             */
            'hold_all_for_review' => ['required', 'boolean'],
        ]);

        $wasDisabled = $team->sendsAreDisabled();
        $nowDisabled = (bool) $validated['sends_disabled'];

        /*
         * The timestamp is only rewritten when the switch actually moves.
         * *"Sending has been off since Tuesday"* is what somebody needs to
         * read on the screen, and saving the form to change the hourly limit
         * must not quietly reset it to today.
         *
         * Computed here rather than as a `match` inside the `forceFill` below,
         * and that is not only taste: `SingleMutationPathTest` reads a write
         * call's array for a **variable key** — `->update([$column => …])`,
         * which is a column name held in a variable and still a column name —
         * and a match arm ending `! $wasDisabled =>` is that shape exactly. It
         * flagged this file, correctly by its own rule, and the right answer
         * was to stop writing a conditional inside an array of columns.
         */
        $disabledAt = match (true) {
            $nowDisabled && ! $wasDisabled => now(),
            $nowDisabled => $team->sends_disabled_at,
            default => null,
        };

        /*
         * Same rule as the timestamp above: a window already running is left
         * where it is. Saving the form to change an hourly limit must not
         * silently extend the review period by another month, and a team that
         * ends the window and then changes its mind starts a fresh one rather
         * than resuming the remains of the old.
         */
        $holdForReview = (bool) $validated['hold_all_for_review'];

        $reviewWindow = match (true) {
            $holdForReview && ! $team->approvalIsMandatory() => now()->addDays(Team::APPROVAL_WINDOW_DAYS),
            $holdForReview => $team->approval_required_until,
            default => null,
        };

        $team->forceFill([
            'sends_disabled_at' => $disabledAt,
            'sends_disabled_reason' => $nowDisabled
                ? ($validated['sends_disabled_reason'] ?? null)
                : null,
            'sandbox_mode' => (bool) $validated['sandbox_mode'],
            'hourly_send_limit' => $validated['hourly_send_limit'],
            'daily_send_limit' => $validated['daily_send_limit'],
            'approval_required_until' => $reviewWindow,
        ])->save();

        /*
         * Audited rather than timelined, and this is one of the entries PRD §9
         * asks for by name. Turning sending off stops every client
         * communication the team has automated; turning it back on starts
         * them. Neither belongs on a deal's activity feed — it is about no
         * deal in particular — and both have to outlive that feed's retention.
         */
        $audit->recordChange('team.send_safety_updated', $team, $validated['sends_disabled_reason'] ?? null);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $nowDisabled
                ? __('Sending is off. Nothing queued will go out until you turn it back on.')
                : __('Sending settings saved.'),
        ]);

        return to_route('team.send-safety.edit');
    }
}
