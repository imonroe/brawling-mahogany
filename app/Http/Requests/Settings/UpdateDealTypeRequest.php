<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\DealType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDealTypeRequest extends FormRequest
{
    use DealTypeRules;

    public function authorize(): bool
    {
        $dealType = $this->route('dealType');

        return $dealType instanceof DealType
            && ($this->user()?->can('update', $dealType) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Ignoring its own row, or renaming a type's *side* alone would be
        // refused for colliding with itself.
        $dealType = $this->route('dealType');

        return $this->dealTypeRules($dealType instanceof DealType ? $dealType : null);
    }
}
