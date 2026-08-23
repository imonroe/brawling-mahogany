<?php

declare(strict_types=1);

namespace App\Http\Requests\Properties;

use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;

class StorePropertyRequest extends FormRequest
{
    use PropertyRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Property::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->propertyRules();
    }

    /**
     * The links, or none.
     *
     * A create has nothing to leave alone, so the two meanings `null` and
     * `[]` carry on the update path collapse into one here.
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
