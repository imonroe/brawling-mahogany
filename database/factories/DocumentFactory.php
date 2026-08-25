<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentCategory;
use App\Models\Document;
use Database\Factories\Concerns\ForcesAttributes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    use ForcesAttributes;

    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => DocumentCategory::Photo,
            'disk' => 'documents',
            // Opaque, the way the storage service writes them.
            'path' => 'fixtures/'.Str::ulid()->toString().'.jpg',
            'original_name' => 'front-elevation.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 240_000,
            'sort_order' => 0,
        ];
    }
}
