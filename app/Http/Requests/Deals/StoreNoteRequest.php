<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Models\ActivityEvent;
use App\Models\Deal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Writing a note on a deal (PRD §4.4 F4.11 · IA §9 · issue #72).
 *
 * IA §7: a note is **written** and a contact is **logged**, and they are
 * different records. This is the first; `ContactLogController` is the second.
 *
 * ## `is_client_visible` is a checkbox, and `boolean` is why it is safe
 *
 * F4.11 is *"internal by default, with an explicit client-visible toggle"*,
 * and **internal by default is the whole feature**. An unchecked HTML checkbox
 * sends nothing at all, so the rule has to hold for an absent field as well as
 * a false one — `boolean` accepts absence, `validated()` omits it, and the
 * controller's `?? false` is what turns "the browser said nothing" into "no".
 * A `required` here would do the opposite of what it sounds like: it would
 * reject the ordinary internal note.
 */
class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deal = $this->route('deal');

        $person = $this->user();

        return $deal instanceof Deal
            && $person !== null
            && $person->can('create', ActivityEvent::class)
            && $person->can('view', $deal);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'is_client_visible' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'A note needs something in it.',
        ];
    }

    public function body(): string
    {
        return trim((string) $this->validated('body'));
    }

    /**
     * **Never inferred, never remembered.** F4.11 is explicit that an agent who
     * made one note client-visible last Tuesday must not silently publish the
     * next one, and the only way to be sure of that is that nothing but this
     * request's own checkbox can turn it on.
     */
    public function isClientVisible(): bool
    {
        return $this->boolean('is_client_visible');
    }
}
