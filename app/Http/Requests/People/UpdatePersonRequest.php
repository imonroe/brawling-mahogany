<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

use App\Models\TeamMembership;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonRequest extends FormRequest
{
    use PersonRules;

    public function authorize(): bool
    {
        $membership = $this->route('membership');

        return $membership instanceof TeamMembership
            && ($this->user()?->can('update', $membership) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->personRules();
    }
}
