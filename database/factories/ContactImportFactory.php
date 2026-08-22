<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContactImportSource;
use App\Enums\ContactImportState;
use App\Models\ContactImport;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactImport>
 */
class ContactImportFactory extends Factory
{
    protected $model = ContactImport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'source' => ContactImportSource::Csv,
            'state' => ContactImportState::Pending,
            'original_filename' => 'contacts.csv',
        ];
    }
}
