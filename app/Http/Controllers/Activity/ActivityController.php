<?php

declare(strict_types=1);

namespace App\Http\Controllers\Activity;

use App\Enums\ActivityCategory;
use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Queries\ActivityFeed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S12 — the team activity feed (PRD §4.9 F9.4 · issue #81).
 *
 * IA §11 names what this shows **Activity**, never History, Log, or Audit.
 * *Audit* is `audit_log`, which is append-only, has its own retention and its
 * own permission, and is read on S72 by somebody asking a different question.
 * The two must not converge on one screen.
 *
 * ## Paginates and filters without a full reload
 *
 * `events` is an Inertia merge prop keyed on `id`, so "load more" is a partial
 * reload carrying the next cursor and the rows *append* rather than replace.
 * Changing the filter is an ordinary visit, which is not partial — so the same
 * prop resets, which is exactly what changing a filter should do.
 */
class ActivityController extends Controller
{
    public function index(Request $request, ActivityFeed $feed): Response
    {
        $this->authorize('viewAny', ActivityEvent::class);

        $category = ActivityCategory::tryFrom((string) $request->query('category', 'all'))
            ?? ActivityCategory::All;

        $cursor = trim((string) $request->query('cursor', ''));

        $page = $feed->paginate($category, $cursor === '' ? null : $cursor);

        return Inertia::render('Activity/Index', [
            'category' => $category->value,
            'categories' => ActivityCategory::options(),
            'emptyMessage' => $category->emptyMessage(),
            /*
             * `matchOn('id')`, not a bare merge. Two people advancing stages
             * while somebody scrolls can push a row from page one onto page
             * two; without a key the same event appears twice, and a duplicate
             * on a timeline reads as the thing having happened twice.
             */
            'events' => Inertia::merge(fn (): array => $feed->rows($page->items()))->matchOn('id'),
            'nextCursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
