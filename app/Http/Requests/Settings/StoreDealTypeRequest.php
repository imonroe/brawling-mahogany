<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\DealType;
use Illuminate\Foundation\Http\FormRequest;

class StoreDealTypeRequest extends FormRequest
{
    use DealTypeRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', DealType::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->dealTypeRules();
    }
}
