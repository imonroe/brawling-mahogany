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
        // Ignoring their own row, or editing anything at all on somebody with
        // an address would refuse for colliding with themselves.
        $membership = $this->route('membership');

        return $this->personRules($membership instanceof TeamMembership ? $membership : null);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            /*
             * Laravel's own is *"The status field is prohibited"*, which says
             * what the rule did and not what the reader should do. #162 is
             * about somebody being confused by this exact field.
             */
            'status.prohibited' => 'Somebody on your team is not a lead or a client. '
                .'Their role and their access are managed on the members screen.',
        ];
    }
}
