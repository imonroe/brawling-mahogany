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
            ...$this->credentialRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        /*
         * A login, and only a login (#140).
         *
         * Registration no longer asks for a name, because there is nowhere to
         * put one: a name belongs to a team's view of somebody, and a person
         * who has just registered belongs to no team. They get theirs when
         * they accept an invitation, and until then they see the switcher's
         * "no access" state — which is the correct thing for an account with
         * no team, and the state PRD §5.1 expects while the operator
         * provisions one.
         */
        return Person::create([
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
