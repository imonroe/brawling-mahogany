<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dates;

use App\Enums\OffsetBasis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dates\SaveKeyDateRequest;
use App\Models\Deal;
use App\Models\KeyDate;
use App\Models\Person;
use App\Queries\DealDates;
use App\Support\Dates\AnchorWouldLoop;
use App\Support\Dates\DateChange;
use App\Support\Dates\KeyDateGraph;
use App\Support\Dates\SaveKeyDate;
use App\Support\Deals\DealHeader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S18 — a deal's Dates & Deadlines (PRD §4.8 F8.2 · IA §2 · issue #107).
 *
 * IA §2 and §11: the code says `key_dates`, the screen says **Dates &
 * Deadlines** — Emily's exact phrase — and a client would see *Important
 * Dates*. Never "Key dates" in front of a person.
 *
 * ## The cascade preview is a POST that writes nothing
 *
 * #106 requires the preview to be *"accurate against"* the apply, and the only
 * way to guarantee that is for both to be the same computation:
 * `SaveKeyDate::preview()` and `::edit()` call `KeyDateGraph::cascadeFrom()`.
 * It is a `POST` rather than a `GET` because it carries a proposed change —
 * a URL holding one would be bookmarkable and re-runnable against a deal that
 * has since moved, which is a preview of something that is no longer true.
 *
 * It returns JSON rather than an Inertia response: the dialog asks while the
 * person is still deciding, and a full page visit to answer *"what would this
 * move"* would redraw the screen underneath the question.
 */
class DealDateController extends Controller
{
    public function index(Deal $deal, DealDates $dates): Response
    {
        $this->authorize('viewAny', [KeyDate::class, $deal]);

        $deal->load(['dealType', 'participants.membership', 'propertyLinks.property', 'workflows.stages']);

        return Inertia::render('Deals/Dates', [
            'dealHeader' => DealHeader::for($deal),
            'dealUrl' => "/deals/{$deal->getKey()}",
            'dates' => $dates->forDeal($deal),
            'anchorOptions' => $this->anchorOptions($deal),
            'offsetBases' => OffsetBasis::options(),
            'canManage' => $this->request()->user()?->can('create', [KeyDate::class, $deal]) ?? false,
        ]);
    }

    public function store(SaveKeyDateRequest $request, Deal $deal, SaveKeyDate $save): RedirectResponse
    {
        try {
            $save->add($deal, $request->keyDateAttributes(), $this->actor($request));
        } catch (AnchorWouldLoop $exception) {
            return back()->withErrors(['anchorKeyDateId' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Date added.')]);

        return to_route('deals.dates.index', $deal);
    }

    public function update(
        SaveKeyDateRequest $request,
        Deal $deal,
        KeyDate $keyDate,
        SaveKeyDate $save,
    ): RedirectResponse {
        try {
            $result = $save->edit($keyDate, $request->keyDateAttributes(), $this->actor($request));
        } catch (AnchorWouldLoop $exception) {
            return back()->withErrors(['anchorKeyDateId' => $exception->getMessage()]);
        }

        /*
         * The toast says what the cascade did, because the person agreed to it
         * in a dialog a moment ago and the confirmation is what closes the
         * loop. IA §10: a count is a numeral plus a pluralised noun.
         */
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $result->movedCount() === 0
                ? __('Date updated.')
                : __(':count other dates moved with it.', ['count' => $result->movedCount()]),
        ]);

        return to_route('deals.dates.index', $deal);
    }

    /**
     * What would move, if this change were saved.
     *
     * Authorised as an **update**, not as a read: it takes a proposed change
     * and it is the dialog that precedes a write. Gating it on `view` would
     * let a read-only broker discover the shape of a change they cannot make.
     */
    public function preview(
        SaveKeyDateRequest $request,
        Deal $deal,
        SaveKeyDate $save,
    ): JsonResponse {
        $keyDate = KeyDate::query()
            ->where('deal_id', $deal->getKey())
            ->whereKey($request->input('keyDateId'))
            ->first();

        if (! $keyDate instanceof KeyDate) {
            /*
             * A date being *created* moves nothing: nothing can point at a row
             * that does not exist yet. An empty preview is the correct answer
             * rather than a 404, so the dialog behaves the same on both paths.
             */
            return response()->json(['moved' => []]);
        }

        $this->authorize('update', $keyDate);

        try {
            $moved = $save->preview($keyDate, $request->keyDateAttributes());
        } catch (AnchorWouldLoop $exception) {
            return response()->json(['moved' => [], 'error' => $exception->getMessage()], 422);
        }

        return response()->json([
            'moved' => array_map(static fn (DateChange $change): array => $change->toArray(), $moved),
        ]);
    }

    public function destroy(Request $request, Deal $deal, KeyDate $keyDate, SaveKeyDate $save): RedirectResponse
    {
        $this->authorize('delete', $keyDate);

        $save->remove($keyDate, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Date removed.')]);

        return to_route('deals.dates.index', $deal);
    }

    /**
     * The dates a new one may be counted from.
     *
     * Everything on the deal, because a date being created cannot yet be part
     * of a cycle. The editor narrows further per row — `KeyDateGraph::
     * anchorCandidatesFor()` drops anything that already depends on the row
     * being edited — and that narrowing is sent with each row rather than
     * recomputed in the browser, because the graph is the server's.
     *
     * @return list<array<string, mixed>>
     */
    private function anchorOptions(Deal $deal): array
    {
        $graph = KeyDateGraph::forDeal($deal);

        $options = [];

        foreach ($graph->all() as $candidate) {
            $options[] = [
                'id' => $candidate->getKey(),
                'name' => $candidate->name,
                'date' => $candidate->date->toDateString(),
                /*
                 * Which rows may **not** anchor to this one, so the editor can
                 * hide them rather than refuse them after the fact.
                 */
                'blockedFor' => array_values(array_map(
                    static fn (KeyDate $blocked): string => (string) $blocked->getKey(),
                    array_filter(
                        $graph->all(),
                        fn (KeyDate $row): bool => $graph->wouldLoop($row, (string) $candidate->getKey()),
                    ),
                )),
            ];
        }

        return $options;
    }

    private function request(): Request
    {
        return request();
    }

    private function actor(Request $request): ?Person
    {
        $person = $request->user();

        return $person instanceof Person ? $person : null;
    }
}
