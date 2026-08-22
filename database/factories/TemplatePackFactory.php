<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TemplatePack;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TemplatePack>
 */
class TemplatePackFactory extends Factory
{
    protected $model = TemplatePack::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // `company()` rather than `words()`, matching TeamFactory: faker's
        // `words()` is declared string|array and needs a cast PHPStan cannot
        // verify, and a pack name reads like a product name anyway.
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => $this->faker->sentence(),
            'is_installed_by_default' => false,
            'sort_order' => 0,
        ];
    }
}
