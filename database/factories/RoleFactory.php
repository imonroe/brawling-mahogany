<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /*
     * `team_id`, `key` and `is_system` are all outside `Role`'s fillable list
     * — a request body must never choose a tenant, and `key` is what every
     * permission check is written against, so a customer choosing `team_owner`
     * would be a customer choosing what a name means in this product. A
     * factory that needs a specific one forces it; the HTTP boundary keeps
     * its guarantee.
     */
    use ForcesAttributes;

    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'team_id' => null,
            'key' => Str::slug($name, '_').'_'.Str::lower(Str::random(5)),
            'name' => $name,
            'is_system' => false,
        ];
    }
}
