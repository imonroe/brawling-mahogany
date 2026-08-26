<?php

declare(strict_types=1);

namespace App\Support\Workflow\Gates\Evaluators;

use App\Enums\DocumentCategory;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Gate;
use App\Models\Property;
use App\Models\Stage;
use App\Support\Workflow\Gates\GateEvaluator;
use App\Support\Workflow\Gates\GateVerdict;
use Illuminate\Database\Eloquent\Model;

/**
 * A document of a required category is attached (PRD §4.4 F4.8 · issue #104).
 *
 * Built against the interface in #67 and returning an explanatory unmet until
 * the documents module existed; this is that wiring. It is one of the two
 * evaluators `CLAUDE.md` still owed a *"is this path actually reachable"*
 * check — a gate type whose only way to be satisfied is a screen nobody built
 * is a rule nobody is following.
 *
 * ## Where it looks, and why that is configurable
 *
 * A listing agreement belongs to the **deal**; an inspection report often
 * belongs to the **stage** it was produced in; a survey belongs to the
 * **property**. So `attached_to` says which, defaulting to the deal because
 * that is the answer for most of the categories a team gates on.
 *
 * ## The link target is the whole of PRD §5.4
 *
 * *"Each unmet gate links directly to the thing that clears it."* Not a
 * documents index — the **upload dialog with the category already chosen**.
 * §5.4's worked example is this gate refusing an advance, and the flow it
 * describes is one click from the refusal to the upload.
 *
 * ## A refused document never clears it
 *
 * By construction rather than by a check: a refusal (#99, #100) is discarded
 * before anything reaches permanent storage, so there is no row for this to
 * find. Worth stating because the opposite would be a quiet way for a gate to
 * pass — and a signed listing agreement is not on the restricted list, so the
 * common case never meets the question.
 */
final class DocumentPresentEvaluator implements GateEvaluator
{
    public static function type(): string
    {
        return 'document_present';
    }

    public static function label(): string
    {
        return 'Document present';
    }

    public function evaluate(Gate $gate): GateVerdict
    {
        $configuration = $gate->configuration();

        $category = DocumentCategory::tryFrom((string) ($configuration['category'] ?? ''));

        if (! $category instanceof DocumentCategory) {
            /*
             * A gate configured with no category, or with one that has since
             * been renamed. **Unmet, not met** — the same direction every
             * unconfigured evaluator takes, because a gate nobody can read is
             * a gate that must not wave an advance through.
             */
            return GateVerdict::unmet(
                'This requirement does not say which kind of document it needs. Edit the workflow template to choose one.',
            );
        }

        $stage = $gate->stage;
        $subject = $this->subject($gate, $stage);

        if ($subject === null) {
            return GateVerdict::unmet(
                'This requirement is looking for a document on something that is no longer here.',
            );
        }

        $found = Document::query()
            ->where('documentable_type', $subject->getMorphClass())
            ->where('documentable_id', $subject->getKey())
            ->where('category', $category->value)
            ->exists();

        if ($found) {
            return GateVerdict::met($category->label().' attached.');
        }

        return GateVerdict::unmet(
            'No '.mb_strtolower($category->label()).' has been attached yet.',
            /*
             * PRD §5.4: the link goes to the thing that clears it, with the
             * category already chosen — so the person arrives at an upload
             * that is already the right one rather than at a page where they
             * have to work out what was being asked for.
             */
            [
                'type' => 'document_upload',
                'category' => $category->value,
                'attachTo' => $this->attachment($configuration),
                'stage' => $stage->getKey(),
                'deal' => $stage->workflow?->deal_id,
            ],
        );
    }

    /**
     * What the document has to be attached to.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function attachment(array $configuration): string
    {
        $target = (string) ($configuration['attachedTo'] ?? $configuration['attached_to'] ?? 'deal');

        return in_array($target, ['deal', 'stage', 'property'], true) ? $target : 'deal';
    }

    private function subject(Gate $gate, Stage $stage): ?Model
    {
        $deal = $stage->workflow?->deal;

        return match ($this->attachment($gate->configuration())) {
            'stage' => $stage,
            'property' => $deal instanceof Deal ? $this->subjectProperty($deal) : null,
            default => $deal,
        };
    }

    private function subjectProperty(Deal $deal): ?Property
    {
        $deal->loadMissing('propertyLinks.property');

        $link = $deal->propertyLinks->firstWhere('is_subject', true);

        $property = $link?->property;

        return $property instanceof Property ? $property : null;
    }
}
