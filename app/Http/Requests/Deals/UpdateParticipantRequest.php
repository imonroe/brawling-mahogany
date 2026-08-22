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
            'is_primary' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
