<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasProductDefaults;
use Carbon\CarbonInterface;
use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * S78 — how one person wants to be told, in one team (F12.4 · issue #101).
 *
 * @property string $id
 * @property string $team_id
 * @property string $person_id
 * @property array<string, list<string>>|null $channels
 * @property string|null $quiet_hours_start
 * @property string|null $quiet_hours_end
 */
#[Fillable([])]
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use BelongsToTeam, HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channels' => 'array',
        ];
    }

    /**
     * Which channels this person wants for this type.
     *
     * Falls back to the type's own defaults, which is the whole reason this
     * table holds only what somebody changed: a type added in a later slice
     * has no row for anybody, and the answer for a person who has never opened
     * S78 and for one who opened it before the type existed should be the
     * same.
     *
     * `in_app` is added back whatever is stored. A person can switch off the
     * email and the push; they cannot switch off the record, because the panel
     * is ADR 0003's second door for both of the others.
     *
     * @return list<NotificationChannel>
     */
    public function channelsFor(NotificationType $type): array
    {
        $stored = ($this->channels ?? [])[$type->value] ?? null;

        $channels = is_array($stored)
            ? array_values(array_filter(array_map(
                static fn (string $value): ?NotificationChannel => NotificationChannel::tryFrom($value),
                $stored,
            )))
            : $type->defaultChannels();

        $channels = array_values(array_filter(
            $channels,
            static fn (NotificationChannel $channel): bool => $channel->isOptional(),
        ));

        return [NotificationChannel::InApp, ...$channels];
    }

    /**
     * When the outbound channels may go, or null for now.
     *
     * ## The window is wall-clock in the team's zone, and that is load-bearing
     *
     * PRD §9 stores in UTC and displays in the team's timezone, and a quiet
     * hours window is a *display* concept: somebody setting 21:00 means nine in
     * the evening where they are, in March and in July alike. Comparing a
     * stored UTC instant would drift by an hour twice a year, which is exactly
     * the season a *"nobody wants a 6am push"* rule is judged on.
     *
     * ## A wrapping window is the ordinary case
     *
     * 21:00 → 07:00 crosses midnight, so the comparison is an OR rather than a
     * BETWEEN. A window that does not wrap (12:00 → 14:00, somebody's lunch)
     * still works, and the two are told apart by which end is larger rather
     * than by a flag somebody has to remember to set.
     */
    public function holdUntil(NotificationType $type, string $timezone, ?CarbonInterface $at = null): ?CarbonInterface
    {
        if ($type->bypassesQuietHours() || ! $this->hasQuietHours()) {
            return null;
        }

        $at ??= Carbon::now();

        $local = $at->copy()->setTimezone($timezone);

        $start = $this->at($local, (string) $this->quiet_hours_start);
        $end = $this->at($local, (string) $this->quiet_hours_end);

        $wraps = $start->greaterThan($end);

        $inside = $wraps
            ? $local->greaterThanOrEqualTo($start) || $local->lessThan($end)
            : $local->greaterThanOrEqualTo($start) && $local->lessThan($end);

        if (! $inside) {
            return null;
        }

        /*
         * The end of the window, which for a wrapping one is **tomorrow's**
         * end if we are still on the evening side of midnight. Getting that
         * wrong holds a 22:00 notification until 07:00 *that morning* — an
         * instant already fifteen hours past — and the sweep releases it
         * immediately, which is a quiet-hours setting that does nothing while
         * looking like it works.
         */
        if ($wraps && $local->greaterThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return $end->utc();
    }

    public function hasQuietHours(): bool
    {
        return $this->quiet_hours_start !== null && $this->quiet_hours_end !== null;
    }

    /**
     * A stored `HH:MM:SS` placed on the day this instant falls in, locally.
     */
    private function at(CarbonInterface $local, string $time): CarbonInterface
    {
        [$hour, $minute] = array_map('intval', explode(':', $time) + [1 => '0']);

        return $local->copy()->setTime($hour, $minute);
    }
}
