<?php

declare(strict_types=1);

namespace App\Support\Deals;

use App\Models\DealType;
use App\Models\WorkflowTemplate;
use App\Support\Tenancy\TeamContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which workflow templates a deal type may start from (S14 · #74).
 *
 * Two things ask this and must not disagree: the wizard's step four, deciding
 * what to *offer*, and `RecordDealDraft`, deciding whether an answer already
 * given survives a change of deal type. The second was written after the
 * first, which in this codebase is the moment a rule gets restated slightly
 * differently — so it is one query object rather than two copies.
 *
 * ## The rule
 *
 * Filtered by `deal_type_workflow_template` when the type has any
 * associations, and otherwise everything the team can see. A team that has not
 * wired its templates to its types should not meet an empty picker with no
 * explanation.
 */
final readonly class SelectableTemplates
{
    public function __construct(private TeamContext $teams) {}

    /**
     * @return Builder<WorkflowTemplate>
     */
    public function forType(DealType $type): Builder
    {
        $query = WorkflowTemplate::query()
            ->visibleTo($this->teams->requireId(WorkflowTemplate::class))
            ->where('is_active', true);

        if ($type->workflowTemplates()->exists()) {
            $query->whereHas('dealTypes', fn ($types) => $types->whereKey($type->getKey()));
        }

        return $query;
    }

    /**
     * Whether this type can start from this template.
     *
     * The question `RecordDealDraft` asks after step one changes. A template
     * the new type still offers was never an invalid answer, and clearing it
     * would be pure loss — worse, it is the case where clearing is *least*
     * necessary and *most* harmful, because the picker still draws the row as
     * chosen and the person has no reason to look again.
     */
    public function offers(DealType $type, string $templateId): bool
    {
        return $this->forType($type)->whereKey($templateId)->exists();
    }
}
