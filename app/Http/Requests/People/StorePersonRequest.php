<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

use App\Models\TeamMembership;
use Illuminate\Foundation\Http\FormRequest;

class StorePersonRequest extends FormRequest
{
    use PersonRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', TeamMembership::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->personRules();
    }
}
