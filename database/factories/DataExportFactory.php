<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DataExportState;
use App\Models\DataExport;
use App\Models\Team;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataExport>
 */
class DataExportFactory extends Factory
{
    use ForcesAttributes;

    protected $model = DataExport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'state' => DataExportState::Pending,
        ];
    }
}
