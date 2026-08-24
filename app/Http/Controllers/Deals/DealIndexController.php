<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deals;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Queries\DealDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S13 — the deals index (PRD §4.9 F9.1 · issue #78).
 *
 * ## Every filter is a query parameter, and that is the feature
 *
 * A filtered view is a URL somebody can send a colleague. `PropertyController`
 * settled this for S35 and the reasoning is the same here: state in the
 * component is state nobody else can see, and "the three deals I mean" is a
 * sentence a team says every day.
 *
 * It is also why search goes to the server. PRD §9 sizes a team at twenty-five
 * active deals and several hundred closed, and filtering several hundred rows
 * in the browser means shipping several hundred rows to the browser.
 *
 * ## Two columns of the seven are honestly absent
 *
 * `deals` carries no owning-agent column, so §7.3's `owner` cell is hidden
 * rather than filled with a guess — the same departure Screen Inventory
 * already records for S15's header, for the same reason. #78's "filter by
 * assignee" goes with it: a filter over a column that does not exist is a
 * control that cannot work.
 *
 * The `date` cell is the soonest **open task** due date, not a key date.
 * `key_dates` is S18, in Slice 4. A task due date is the nearest true answer
 * the schema can give today, and `Deal::withNextDueDate()` is the one line
 * that changes when S18 lands.
 */
class DealIndexController extends Controller
{
    public function index(Request $request, DealDirectory $directory): Response
    {
        $this->authorize('viewAny', Deal::class);

        $segment = (string) $request->query('segment', 'open');
        $search = trim((string) $request->query('search', ''));
        $dealType = trim((string) $request->query('dealType', ''));
        $sort = (string) $request->query('sort', '');
        $direction = (string) $request->query('direction', 'asc');

        /*
         * **Everything is resolved before anything is queried.**
         *
         * `sort` and `dealType` are both echoed back to the page, and the page
         * draws affordances from them: `Table` tints a column's chevron and
         * sets `aria-sort` when `sort` equals its key, so echoing an
         * unvalidated key made a header light up and announce "ascending" over
         * rows the server had not reordered.
         *
         * The ordering matters as much as the validation, and getting it wrong
         * is what this method did first. `dealType` was resolved *after*
         * `segmentCounts()` — so the counts filtered by the junk value while
         * the rows filtered by the cleaned one, and a `?dealType=banana` URL
         * listed two deals under a filter bar reading "Open (0) · All (0)".
         * `DealDirectory`'s own docblock promises that cannot happen: *"one
         * builder shared by the rows and the counts, so a filter bar can never
         * disagree with the list under it."* Sharing the builder is not enough
         * if the two callers are handed different arguments.
         *
         * Worse on a team whose deals are all closed: the page reads
         * `segmentCounts`' `all` count to decide whether it is empty or merely
         * filtered, so a zeroed bar brought back "No deals yet. Create your
         * first deal." over a team with deals.
         */
        $sort = in_array($sort, DealDirectory::SORTS, true) ? $sort : '';

        $types = $directory->dealTypeOptions();
        $dealType = in_array($dealType, array_column($types, 'value'), true) ? $dealType : '';

        $segments = $directory->segmentCounts($search, $dealType === '' ? null : $dealType);

        /*
         * Anything unrecognised falls back to `open` rather than emptying the
         * screen — `PropertyController` says why: a hand-typed query string
         * should not look like a bug. Resolved against the segments the query
         * object actually offers, so the two can never disagree about what a
         * valid segment is.
         */
        $known = array_column($segments, 'value');
        $segment = in_array($segment, $known, true) ? $segment : 'open';

        return Inertia::render('Deals/Index', [
            'segment' => $segment,
            'segmentCounts' => $segments,
            'search' => $search,
            'dealType' => $dealType === '' ? 'all' : $dealType,
            'dealTypeOptions' => $types,
            'sort' => $sort,
            'direction' => strtolower($direction) === 'desc' ? 'desc' : 'asc',
            'deals' => $directory->paginate(
                segment: $segment,
                search: $search,
                dealTypeId: $dealType === '' ? null : $dealType,
                sort: $sort,
                direction: $direction,
            ),
        ]);
    }
}
