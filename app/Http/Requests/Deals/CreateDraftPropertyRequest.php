<?php

declare(strict_types=1);

namespace App\Http\Requests\Deals;

use App\Http\Requests\Properties\PropertyRules;
use App\Models\DealDraft;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A property created inline, from step three of the wizard (S14 · #74).
 *
 * The subject of a new listing is not in the directory yet — that is the
 * common case for this step, not the exception — so the wizard has to be able
 * to make one without leaving.
 *
 * S37's rules exactly, including the parcel-number uniqueness that folds case
 * the way its partial index does and the `state_code` upper-casing that IA
 * §10's "City, ST ZIP" depends on. See `CreateDraftClientRequest` for why this
 * is a separate class rather than a branch.
 */
class CreateDraftPropertyRequest extends FormRequest
{
    use PropertyRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', DealDraft::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->propertyRules();
    }

    /**
     * The links S37 collects, or none.
     *
     * @return array<array-key, array<string, mixed>>
     */
    public function links(): array
    {
        /** @var array<array-key, array<string, mixed>> $links */
        $links = $this->validated('links', []);

        return $links;
    }
}
