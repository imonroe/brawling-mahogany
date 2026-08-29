<?php

declare(strict_types=1);

namespace App\Http\Controllers\Extraction;

use App\Http\Controllers\Controller;
use App\Http\Requests\Extraction\AcceptExtractedFieldsRequest;
use App\Http\Requests\Extraction\ReviewExtractedFieldRequest;
use App\Http\Requests\Extraction\StartExtractionRequest;
use App\Models\Deal;
use App\Models\ExtractedField;
use App\Models\Extraction;
use App\Models\Person;
use App\Queries\DealExtraction;
use App\Support\Deals\DealHeader;
use App\Support\Extraction\ConfirmExtractedField;
use App\Support\Extraction\ExtractionNotReviewable;
use App\Support\Extraction\ExtractionRefused;
use App\Support\Extraction\StartExtraction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S65, S66 and S67 (#115, #116, #117).
 *
 * **One `show` for two screens.** Screen Inventory gives S66 and S67 the same
 * route, discriminated by `extractions.kind`, and the page component branches
 * on it. Two controllers would have been two answers to *"what does a reviewer
 * see"*, and the second one would have drifted.
 */
class DealExtractionController extends Controller
{
    public function store(StartExtractionRequest $request, Deal $deal, StartExtraction $extractions): RedirectResponse
    {
        /** @var Person $person */
        $person = $request->user();

        try {
            $extraction = $extractions->start($request->document(), $deal, $request->kind(), $person);
        } catch (ExtractionRefused $refusal) {
            /*
             * A refusal is a response, not an error (#99's rule, one module
             * over). It carries what was refused and what to do instead, and it
             * goes back as a field error on the control that was pressed rather
             * than as a toast that scrolls away — somebody who has just been
             * told their file has no readable words in it needs that sentence
             * to still be there when they look for it.
             */
            return back()->withErrors(['documentId' => $refusal->getMessage()]);
        }

        return to_route('deals.extractions.show', [$deal, $extraction]);
    }

    public function show(Request $request, Deal $deal, Extraction $extraction, DealExtraction $query): Response
    {
        $this->authorize('view', $extraction);

        $deal->load(['dealType', 'participants.membership', 'propertyLinks.property', 'workflows.stages']);

        return Inertia::render('Deals/Extraction', [
            'dealHeader' => DealHeader::for($deal),
            'dealUrl' => "/deals/{$deal->getKey()}",
            'extraction' => $query->for($extraction, $deal),
            'fields' => $query->fields($extraction, $deal),
            'progress' => $query->progress($extraction),
            'canConfirm' => $request->user()?->can('confirm', $extraction) ?? false,
        ]);
    }

    public function confirm(
        ReviewExtractedFieldRequest $request,
        Deal $deal,
        Extraction $extraction,
        ExtractedField $field,
        ConfirmExtractedField $confirmations,
    ): RedirectResponse {
        /** @var Person $person */
        $person = $request->user();

        try {
            $confirmations->confirm($field, $request->value(), $person);
        } catch (ExtractionNotReviewable $refusal) {
            return back()->withErrors(['value' => $refusal->getMessage()]);
        }

        return back();
    }

    public function reject(
        Request $request,
        Deal $deal,
        Extraction $extraction,
        ExtractedField $field,
        ConfirmExtractedField $confirmations,
    ): RedirectResponse {
        $this->authorize('confirm', $extraction);

        /** @var Person $person */
        $person = $request->user();

        try {
            $confirmations->reject($field, $person);
        } catch (ExtractionNotReviewable $refusal) {
            return back()->withErrors(['value' => $refusal->getMessage()]);
        }

        return back();
    }

    /**
     * Accept several proposed tasks (S67 only — the request refuses a contract).
     *
     * The loop confirms one at a time through the same path a single press
     * uses, so each accepted task gets its own provenance and its own audit
     * entry. PRD §9 asks the log to cover *"extraction reviews"*, and one entry
     * for twelve decisions would record the press rather than the reviews.
     */
    public function accept(
        AcceptExtractedFieldsRequest $request,
        Deal $deal,
        Extraction $extraction,
        ConfirmExtractedField $confirmations,
    ): RedirectResponse {
        /** @var Person $person */
        $person = $request->user();

        $fields = ExtractedField::query()
            ->where('extraction_id', $extraction->getKey())
            ->whereIn('id', $request->ids())
            ->orderBy('sort_order')
            ->get();

        foreach ($fields as $field) {
            try {
                $confirmations->confirm($field, null, $person);
            } catch (ExtractionNotReviewable) {
                /*
                 * Somebody else got to this one, or a stale tab sent an id that
                 * has since been decided. Skipped rather than failing the batch:
                 * refusing eleven good accepts over one already-decided row is
                 * the wrong trade, and the screen reloads showing what actually
                 * happened either way.
                 */
                continue;
            }
        }

        return back();
    }
}
