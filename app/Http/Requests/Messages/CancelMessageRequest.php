<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use App\Models\ActionInstance;
use App\Models\Person;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Stopping one queued message before it goes out (F5.8 · issue #93).
 *
 * The reason is optional, and deliberately unlike an override's. F4.9 demands
 * a typed reason because an override *defers an obligation* somebody else set;
 * stopping a message removes something the product was about to do on the
 * team's behalf, and requiring an essay before somebody can stop an email they
 * can see is wrong about to whom the decision belongs. What is not optional is
 * the record: the row stays, cancelled, with whatever was said.
 */
class CancelMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $message = $this->route('message');

        return $message instanceof ActionInstance
            && $this->user()?->can('cancel', $message) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated()['reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    public function person(): Person
    {
        $person = $this->user();

        if (! $person instanceof Person) {
            throw ValidationException::withMessages(['cancel' => 'You are not signed in.']);
        }

        return $person;
    }
}
