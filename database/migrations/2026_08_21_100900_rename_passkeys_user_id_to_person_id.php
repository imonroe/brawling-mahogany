<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `passkeys.user_id` follows `users` to `people`.
 *
 * Eloquent derives a `hasMany` foreign key from the parent's class name, so
 * the moment `User` became `Person` the relation started looking for
 * `person_id`. Renaming the column is the honest fix: the alternative is an
 * override on the relation whose only job is to disagree with the convention.
 *
 * `laravel/passkeys` reads `$passkey->user_id` directly in two places, which
 * App\Models\Passkey keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passkeys', function (Blueprint $table): void {
            $table->renameColumn('user_id', 'person_id');
        });
    }

    public function down(): void
    {
        Schema::table('passkeys', function (Blueprint $table): void {
            $table->renameColumn('person_id', 'user_id');
        });
    }
};
