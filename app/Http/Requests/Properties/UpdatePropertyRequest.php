<?php

declare(strict_types=1);

namespace App\Http\Requests\Properties;

use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePropertyRequest extends FormRequest
{
    use PropertyRules;

    public function authorize(): bool
    {
        $property = $this->route('property');

        return $property instanceof Property
            && ($this->user()?->can('update', $property) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        return $this->propertyRules($property instanceof Property ? $property : null);
    }

    /**
     * The links the form sent, or `null` when it did not send any.
     *
     * By **presence**, not by value. `links: []` means "remove them all" and
     * an absent `links` means "leave them alone", and those are different
     * instructions that a nullable value cannot tell apart. #148 shipped the
     * same confusion with `notes` and made it unclearable.
     *
     * @return list<array<string, mixed>>|null
     */
    public function links(): ?array
    {
        if (! $this->has('links')) {
            return null;
        }

        /** @var list<array<string, mixed>> $links */
        $links = $this->validated('links', []);

        return $links;
    }
}
