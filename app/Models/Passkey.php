<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * A registered passkey, owned by a Person.
 *
 * `laravel/passkeys` assumes the authenticatable is called `User` and its
 * foreign key `user_id`. Ours is a Person (IA §11), so the column is
 * `person_id` — and the two places the package reads `$passkey->user_id`
 * directly (`VerifyPasskey`, `PasskeyRegistrationController`) are served by
 * the accessor below rather than by a column that disagrees with every other
 * foreign key in the schema.
 *
 * Deliberately **not** team-scoped, for the same reason `people` is not: a
 * credential belongs to a human, not to a tenancy, and a person who works for
 * two teams signs in once. The isolation suite records the exception.
 *
 * @property string $person_id
 */
class Passkey extends BasePasskey
{
    protected $table = 'passkeys';

    /**
     * The package's `user()` relation, pointed at the column the rename left.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = Person::class;

        return $this->belongsTo($model, 'person_id');
    }

    /**
     * What the package reads when it asks who owns this credential.
     *
     * @return Attribute<string, never>
     */
    protected function userId(): Attribute
    {
        return Attribute::get(fn (): string => $this->person_id);
    }
}
