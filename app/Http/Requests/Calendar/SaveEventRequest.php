<?php

declare(strict_types=1);

namespace App\Http\Requests\Calendar;

use App\Enums\EventType;
use App\Models\Deal;
use App\Models\Event;
use App\Models\Property;
use App\Models\TeamMembership;
use App\Support\Calendar\Recurrence;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * S58's form (PRD §4.8 F8.1 · issue #105).
 *
 * ## The times arrive as wall clock and leave as UTC
 *
 * A person picking 9am means 9am where they are, which is the team's zone —
 * PRD §9's display half, arriving at the *input* side where it is easier to
 * get wrong. The browser sends `2026-09-01T09:00` with no offset precisely so
 * this is unambiguous: a serialised instant would carry the *browser's* zone,
 * and a colleague filing an inspection from an airport would book it an hour
 * out.
 *
 * ## Every pointer is checked for existence, and the global scope does the rest
 *
 * `Rule::exists` against a team-scoped table cannot see another team's row —
 * the model's global scope is not in play for a raw exists, so these use the
 * model query instead. Cross-tenant is refused by the composite foreign key
 * regardless, but a 422 naming the field is a better answer than a 500 from
 * Postgres.
 */
class SaveEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event
            ? ($this->user()?->can('update', $event) ?? false)
            : ($this->user()?->can('create', Event::class) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(EventType::values())],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:200'],

            'startsAt' => ['required', 'date'],
            /*
             * `after_or_equal` rather than `after`: a zero-length marker at a
             * moment is a legitimate thing to put in a diary, and the database
             * CHECK draws the line in the same place.
             */
            'endsAt' => ['nullable', 'date', 'after_or_equal:startsAt'],
            'isAllDay' => ['boolean'],

            'dealId' => ['nullable', 'string', $this->belongsToTeam(Deal::class)],
            'propertyId' => ['nullable', 'string', $this->belongsToTeam(Property::class)],

            'attendees' => ['array', 'max:50'],
            'attendees.*' => ['string', $this->belongsToTeam(TeamMembership::class)],

            'recurrence' => ['nullable', 'array'],
            'recurrence.frequency' => [
                'required_with:recurrence',
                Rule::in([Recurrence::DAILY, Recurrence::WEEKLY, Recurrence::MONTHLY]),
            ],
            'recurrence.interval' => ['nullable', 'integer', 'between:1,52'],
            'recurrence.until' => ['nullable', 'date', 'after_or_equal:startsAt'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'endsAt.after_or_equal' => 'An event cannot end before it starts.',
            'recurrence.until.after_or_equal' => 'A repeat cannot end before the first occurrence.',
            'attendees.*.exists' => 'One of the people invited is not in this team’s directory.',
        ];
    }

    /**
     * The row, with the times moved into UTC.
     *
     * `is_all_day` normalises the start to the team's midnight rather than
     * keeping whatever hour a picker sent. The flag says the columns are a
     * day, and a stored 14:30 under it would surface the moment somebody
     * sorted by `starts_at` or read the row without the flag.
     *
     * @return array<string, mixed>
     */
    public function eventAttributes(string $timezone): array
    {
        $allDay = (bool) $this->boolean('isAllDay');

        $start = CarbonImmutable::parse((string) $this->input('startsAt'), $timezone);

        if ($allDay) {
            $start = $start->startOfDay();
        }

        $end = $this->filled('endsAt') && ! $allDay
            ? CarbonImmutable::parse((string) $this->input('endsAt'), $timezone)
            : null;

        return [
            'type' => (string) $this->input('type'),
            'title' => trim((string) $this->input('title')),
            'description' => $this->nullableText('description'),
            'location' => $this->nullableText('location'),
            'starts_at' => $start->utc(),
            'ends_at' => $end?->utc(),
            'is_all_day' => $allDay,
            'deal_id' => $this->nullableId('dealId'),
            'property_id' => $this->nullableId('propertyId'),
            'attendees' => array_values(array_unique(array_map(
                static fn (mixed $id): string => (string) $id,
                (array) $this->input('attendees', []),
            ))),
            'recurrence' => $this->recurrence(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recurrence(): ?array
    {
        $input = $this->input('recurrence');

        if (! is_array($input) || ! is_string($input['frequency'] ?? null)) {
            return null;
        }

        return Recurrence::fromArray($input)?->toArray();
    }

    private function nullableText(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function nullableId(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * A row of this model, in the team the request resolved.
     *
     * Written as a closure over the model's own query rather than
     * `Rule::exists`, because `exists` builds a raw query that the
     * `BelongsToTeam` global scope never sees — so it would happily confirm a
     * row belonging to another team, and the request would 422 or 500 later
     * for a reason nobody could read.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function belongsToTeam(string $model, string $column = 'id'): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model, $column): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            if (! $model::query()->where($column, $value)->exists()) {
                $fail('That is not something this team can put on an event.');
            }
        };
    }
}
