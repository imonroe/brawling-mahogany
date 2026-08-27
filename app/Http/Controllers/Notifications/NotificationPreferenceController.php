<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\Person;
use App\Support\Tenancy\TeamContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S78 — notification preferences (F12.4 · issue #101).
 *
 * ## Per team, and the screen says so
 *
 * A person in two agencies has two evenings' worth of context and one evening.
 * The **quiet hours window** is nonetheless per team, because it is evaluated
 * in the *team's* timezone (PRD §9) — a stager working a Denver agency and a
 * Chicago one who set one window would be quiet at the wrong hour for one of
 * them. The screen edits the team currently in context and names it.
 *
 * ## Authorized by the predicate, like S08
 *
 * The row is keyed on the person asking and the team they are in. There is no
 * permission to hold: everybody chooses how they are told.
 */
class NotificationPreferenceController extends Controller
{
    public function edit(Request $request, TeamContext $teams): Response
    {
        $person = $request->user();

        abort_unless($person instanceof Person, 403);

        $team = $teams->get();
        $preference = $this->preferenceFor($person);

        return Inertia::render('Settings/Notifications', [
            'teamName' => $team?->name,
            'timezone' => $team?->timezone,
            'types' => array_map(
                fn (NotificationType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'description' => $type->description(),
                    'channels' => array_map(
                        static fn (NotificationChannel $channel): string => $channel->value,
                        $preference->channelsFor($type),
                    ),
                ],
                NotificationType::cases(),
            ),
            /*
             * Only the ones somebody may choose. `in_app` is not a preference
             * — the panel is the record and ADR 0003's second door for the
             * other two — and `push` is not offerable until #103 exists,
             * because a switch that does nothing is worse than an absent one.
             */
            'channels' => array_values(array_map(
                static fn (NotificationChannel $channel): array => [
                    'value' => $channel->value,
                    'label' => $channel->label(),
                ],
                array_filter(
                    NotificationChannel::cases(),
                    static fn (NotificationChannel $channel): bool => $channel->isOptional()
                        && $channel->availableFrom() === null,
                ),
            )),
            'comingSoon' => array_values(array_filter(array_map(
                static fn (NotificationChannel $channel): ?string => $channel->availableFrom(),
                NotificationChannel::cases(),
            ))),
            'quietHours' => [
                'start' => $this->asTime($preference->quiet_hours_start),
                'end' => $this->asTime($preference->quiet_hours_end),
            ],
        ]);
    }

    public function update(Request $request, TeamContext $teams): RedirectResponse
    {
        $person = $request->user();

        abort_unless($person instanceof Person, 403);

        $selectable = array_values(array_map(
            static fn (NotificationChannel $channel): string => $channel->value,
            array_filter(
                NotificationChannel::cases(),
                static fn (NotificationChannel $channel): bool => $channel->isOptional()
                    && $channel->availableFrom() === null,
            ),
        ));

        $validated = $request->validate([
            'channels' => ['array'],
            'channels.*' => ['array'],
            'channels.*.*' => [Rule::in($selectable)],
            /*
             * Both or neither. Half a window is a rule nothing can evaluate,
             * and the failure it produces is the worst kind: a person believes
             * they have set quiet hours and the sends go out anyway.
             */
            'quiet_hours_start' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_end'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_start'],
        ]);

        $channels = [];

        foreach (NotificationType::cases() as $type) {
            $chosen = $validated['channels'][$type->value] ?? null;

            if (! is_array($chosen)) {
                continue;
            }

            $channels[$type->value] = array_values(array_unique(array_filter(
                $chosen,
                static fn (mixed $value): bool => is_string($value),
            )));
        }

        $preference = $this->preferenceFor($person);

        $preference->forceFill([
            'team_id' => $teams->get()?->getKey(),
            'person_id' => $person->getKey(),
            'channels' => $channels,
            'quiet_hours_start' => $validated['quiet_hours_start'] ?? null,
            'quiet_hours_end' => $validated['quiet_hours_end'] ?? null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);

        return to_route('notification-preferences.edit');
    }

    private function preferenceFor(Person $person): NotificationPreference
    {
        return NotificationPreference::query()
            ->where('person_id', $person->getKey())
            ->first() ?? new NotificationPreference;
    }

    /**
     * `HH:MM:SS` off the column, `HH:MM` on the form.
     *
     * An `<input type="time">` round-trips the short form and rejects the
     * long one in some browsers, so the seconds are dropped on the way out
     * rather than left for the screen to trim.
     */
    private function asTime(?string $stored): ?string
    {
        return is_string($stored) && $stored !== '' ? mb_substr($stored, 0, 5) : null;
    }
}
