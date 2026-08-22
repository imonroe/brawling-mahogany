<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Look somebody up by an address typed however they typed it.
 *
 * `people.email` is stored folded to lower case and its unique index is over
 * `lower(email)`, so a lookup that compares verbatim can miss a person who
 * exists. That matters most on the screen where it is least visible: the
 * password reset form, where a miss is indistinguishable from "no such
 * account" — and issue #43 requires that screen to answer identically either
 * way, so nobody would ever see the difference.
 *
 * Overriding the provider rather than each call site means login, password
 * reset, password confirmation, and anything Laravel adds later all ask the
 * same question.
 */
class PersonUserProvider extends EloquentUserProvider
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (isset($credentials['email']) && is_string($credentials['email'])) {
            $credentials['email'] = mb_strtolower(trim($credentials['email']));
        }

        return parent::retrieveByCredentials($credentials);
    }
}
