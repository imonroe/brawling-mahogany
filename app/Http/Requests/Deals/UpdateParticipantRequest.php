<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Enums\ParticipantRole;
use App\Models\DealParticipant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $participant = $this->route('participant');

        return $participant instanceof DealParticipant
            && ($this->user()?->can('update', $participant) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The membership is not editable: changing *who* a participant is
        // would silently rewrite history the timeline already recorded.
        // Remove them and add the right person.
        return [
            'participant_role' => ['required', Rule::enum(ParticipantRole::class)],
            /*
             * `DealRoster::replace()` tells "not sent" from "set to empty" by
             * **presence in `$changes`**, not by nullability — the nullable
             * shape was tried and could not work, because
             * `ConvertEmptyStringsToNull` erases the difference before
             * anything here sees it.
             *
             * They are still nullable for different reasons. A null `notes`
             * means clear it, which is a thing somebody can want. A null
             * `is_primary` means nothing at all — there is no third state for
             * a checkbox — so the controller drops it rather than reading it
             * as `false`, which would have demoted a main contact for a key
             * that carried no instruction.
             */
            'is_primary' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
