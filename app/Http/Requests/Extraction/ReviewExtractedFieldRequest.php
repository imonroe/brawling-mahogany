<?php

declare(strict_types=1);

namespace App\Http\Requests\Extraction;

use App\Models\Extraction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Accept one proposal, as proposed or as edited (S66, S67 · #116, #117).
 *
 * `value` is nullable and that is the plain-confirm case — the person agreed
 * with what was on the screen. It is **not** absent-means-empty: a `nullable`
 * field the form did not send is absent from `$validated` rather than null in
 * it, which CLAUDE.md records as a 500 on the ordinary case one module over.
 * `value()` reads through `??` for exactly that reason.
 */
class ReviewExtractedFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        $extraction = $this->route('extraction');

        return $extraction instanceof Extraction
            && ($this->user()?->can('confirm', $extraction) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'value' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function value(): ?string
    {
        $value = $this->validated()['value'] ?? null;

        return is_string($value) ? $value : null;
    }
}
