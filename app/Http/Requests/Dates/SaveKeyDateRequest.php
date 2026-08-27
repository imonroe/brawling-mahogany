<?php

declare(strict_types=1);

namespace App\Http\Requests\Dates;

use App\Enums\OffsetBasis;
use App\Models\Deal;
use App\Models\KeyDate;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * S18's form (PRD §4.8 F8.2 · issues #106, #107).
 *
 * ## A date is either typed or derived, and the form says which
 *
 * `mode` is one three-way choice rather than a set of independent fields, for
 * the reason `SaveAutomationRequest` gives about S44: four dropdowns that can
 * be combined into nonsense is the shape to avoid. A derived date needs an
 * anchor, an offset and a basis together — the migration's CHECK refuses a
 * half-built derivation — and a typed date needs a day.
 *
 * ## The anchor is validated as *on this deal*
 *
 * Not merely as an existing key date. A composite foreign key already refuses
 * another **team's** row, but nothing in the database refuses another *deal's*
 * — both are the same team's `key_dates`. A closing date on one deal anchoring
 * to a mutual acceptance on another would build a cascade across two
 * transactions that have nothing to do with each other.
 *
 * Cycles are not checked here. `KeyDateGraph::wouldLoop()` is the one place
 * that answers it, because the answer depends on the whole chain rather than
 * on this field — and a second implementation in a validator is the second
 * opinion this codebase keeps refusing to keep.
 */
class SaveKeyDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $keyDate = $this->route('keyDate');
        $deal = $this->route('deal');

        if ($keyDate instanceof KeyDate) {
            return $this->user()?->can('update', $keyDate) ?? false;
        }

        return $deal instanceof Deal && ($this->user()?->can('create', [KeyDate::class, $deal]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $derived = $this->input('mode') === 'derived';

        return [
            'name' => ['required', 'string', 'max:120'],
            'mode' => ['required', Rule::in(['typed', 'derived'])],

            'date' => [Rule::requiredIf(! $derived), 'nullable', 'date'],

            'anchorKeyDateId' => [
                Rule::requiredIf($derived),
                'nullable',
                'string',
                $this->onThisDeal(),
            ],
            /*
             * Signed, and bounded at a year in each direction. A contingency
             * measured in more than a year is not a contingency, and an
             * unbounded integer is how a fat-fingered `1000` puts a closing
             * date in 2029 and cascades four other dates there with it.
             */
            'offsetDays' => [Rule::requiredIf($derived), 'nullable', 'integer', 'between:-365,365'],
            'offsetBasis' => [Rule::requiredIf($derived), 'nullable', Rule::in(OffsetBasis::values())],

            'isCritical' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            /*
             * Null and an empty array are different answers (see
             * `KeyDate::reminderDays()`): absent means *"use the default for
             * this kind of date"*, and `[]` means somebody deliberately turned
             * the reminders off.
             */
            'reminderOffsets' => ['nullable', 'array', 'max:6'],
            'reminderOffsets.*' => ['integer', 'between:0,90'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required' => 'Give the date a day.',
            'anchorKeyDateId.required' => 'Choose the date this one is counted from.',
            'offsetDays.required' => 'Say how many days from it.',
            'offsetBasis.required' => 'Say whether those are calendar days or business days.',
        ];
    }

    /**
     * The shape `SaveKeyDate` reads.
     *
     * A derived date sends **no** `date`: `applyAttributes()` reads the
     * presence of a date as *"somebody typed over this"* and detaches the row
     * from its anchor, so including one here would detach every date the form
     * saved a moment after deriving it.
     *
     * @return array<string, mixed>
     */
    public function keyDateAttributes(): array
    {
        $attributes = [
            'name' => (string) $this->input('name'),
            'is_critical' => $this->boolean('isCritical'),
            'notes' => $this->input('notes'),
        ];

        if ($this->has('reminderOffsets')) {
            $attributes['reminder_offsets'] = $this->input('reminderOffsets');
        }

        if ($this->input('mode') === 'derived') {
            return [
                ...$attributes,
                'anchor_key_date_id' => (string) $this->input('anchorKeyDateId'),
                'offset_days' => (int) $this->input('offsetDays'),
                'offset_basis' => (string) $this->input('offsetBasis'),
            ];
        }

        return [...$attributes, 'date' => (string) $this->input('date')];
    }

    /**
     * A key date on the deal in the route.
     *
     * The model's own query, so the team-scoped global scope is in play — a
     * `Rule::exists` builds a raw query the scope never sees, which is the trap
     * `SaveEventRequest` records one screen along.
     */
    private function onThisDeal(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $deal = $this->route('deal');

            if (! is_string($value) || ! $deal instanceof Deal) {
                return;
            }

            $exists = KeyDate::query()
                ->whereKey($value)
                ->where('deal_id', $deal->getKey())
                ->exists();

            if (! $exists) {
                $fail('That date is not on this deal.');
            }
        };
    }
}
