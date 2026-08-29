<?php

declare(strict_types=1);

namespace App\Http\Requests\Extraction;

use App\Enums\ExtractionKind;
use App\Models\Deal;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Start reading a document (S65 · issue #115).
 */
class StartExtractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $deal = $this->route('deal');

        return $deal instanceof Deal
            && ($this->user()?->can('create', [\App\Models\Extraction::class, $deal]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'documentId' => ['required', 'string', $this->onThisDeal()],
            'kind' => ['required', Rule::enum(ExtractionKind::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documentId.required' => 'Choose a document to read.',
            'kind.required' => 'Say whether this is a contract or an inspection report.',
        ];
    }

    public function kind(): ExtractionKind
    {
        return ExtractionKind::from((string) $this->validated('kind'));
    }

    public function document(): Document
    {
        /** @var Document $document */
        $document = Document::query()->findOrFail((string) $this->validated('documentId'));

        return $document;
    }

    /**
     * The document must hang off *this* deal.
     *
     * A closure over the model's own query, so the team-scoped global scope is
     * in play — `Rule::exists` builds a raw query the scope never sees, which
     * is the trap `SaveEventRequest` records. And the `documentable` predicate
     * is the second half: a document on the team's *other* deal passes the
     * tenancy check and would put one deal's contract on another's calendar.
     */
    private function onThisDeal(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            $deal = $this->route('deal');

            if (! $deal instanceof Deal || ! is_string($value)) {
                $fail('That document is not on this deal.');

                return;
            }

            $exists = Document::query()
                ->whereKey($value)
                ->where('documentable_type', $deal->getMorphClass())
                ->where('documentable_id', $deal->getKey())
                ->exists();

            if (! $exists) {
                $fail('That document is not on this deal.');
            }
        };
    }
}
