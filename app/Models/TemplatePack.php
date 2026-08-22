<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasProductDefaults;
use Database\Factories\TemplatePackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An installable bundle of workflow templates (PRD F4.13 · issue #64).
 *
 * Emily's framing, and a natural packaging and pricing unit: **Listing,
 * Buyer, Rental Placement**, with Commercial deferred to a later pack
 * (PRD §2.2). Her read on relative value is worth keeping in view when the
 * seeded content gets written — *"the buyer's pack is way less than the
 * listing. The listing is the most important part."*
 *
 * Not team-scoped, and not because it was forgotten. A pack is a catalogue
 * entry, identical for everybody; installing one **copies** its templates into
 * a team rather than granting a reference to them, which is the same
 * separation the template/instance split makes one level down. A team that
 * edits its installed Listing workflow must not be editing the catalogue.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_installed_by_default
 * @property string|null $price_tier
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'slug', 'description', 'is_installed_by_default', 'price_tier', 'sort_order'])]
class TemplatePack extends Model
{
    /** @use HasFactory<TemplatePackFactory> */
    use HasFactory, HasProductDefaults;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_installed_by_default' => 'boolean',
        ];
    }

    /**
     * The catalogue copies, which carry no `team_id`.
     *
     * @return HasMany<WorkflowTemplate, $this>
     */
    public function workflowTemplates(): HasMany
    {
        return $this->hasMany(WorkflowTemplate::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
