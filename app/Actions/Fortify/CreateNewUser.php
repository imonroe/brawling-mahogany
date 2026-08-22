<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Person;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify's registration hook.
 *
 * The interface name is Fortify's; what it creates is a Person (IA §11). A
 * person created this way is one of the minority who hold credentials — see
 * App\Models\Person.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered person.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): Person
    {
        // The address is folded before the unique rule compares it — see
        // App\Concerns\ProfileValidationRules::foldEmail().
        $input = $this->foldEmail($input);

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return Person::create([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'] ?? null,
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
