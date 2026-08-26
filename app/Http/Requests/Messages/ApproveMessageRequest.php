<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use App\Models\ActionInstance;
use App\Models\Person;
use App\Support\Messages\MessageBodyLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Releasing one queued message, with the approver's edits (S48 · issue #93).
 *
 * ## The edits are optional and the absence of a key is not an empty value
 *
 * `array_key_exists` rather than a null check, all the way through: a form
 * that posts no `bodyHtml` at all is *"I did not touch the HTML"*, and one
 * that posts an empty string is *"I deleted it"*. Collapsing the two would let
 * a push-channel message — which has no HTML body and so posts no field —
 * silently blank a field it never carried, and would make the edit path
 * unable to express clearing one.
 */
class ApproveMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $message = $this->route('message');

        return $message instanceof ActionInstance
            && $this->user()?->can('approve', $message) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'nullable', 'string', 'max:'.MessageBodyLimits::SUBJECT],
            'bodyHtml' => ['sometimes', 'nullable', 'string', 'max:'.MessageBodyLimits::HTML],
            'bodyText' => ['sometimes', 'nullable', 'string', 'max:'.MessageBodyLimits::TEXT],
        ];
    }

    /**
     * Only the fields actually posted, so an untouched one stays untouched.
     *
     * @return array<string, mixed>
     */
    public function edits(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(['subject', 'bodyHtml', 'bodyText']),
        );
    }

    public function person(): Person
    {
        $person = $this->user();

        if (! $person instanceof Person) {
            // Unreachable behind `auth`, and an exception rather than a nullable
            // return so the service is not written to handle a case that cannot
            // arrive.
            throw ValidationException::withMessages(['approve' => 'You are not signed in.']);
        }

        return $person;
    }
}
