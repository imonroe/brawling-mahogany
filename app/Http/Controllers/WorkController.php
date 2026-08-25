<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Person;
use App\Queries\MyWork;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S11 — My Work (PRD §4.9 F9.2 · #80).
 *
 * *"Every task assigned to me across all deals, ordered by urgency. Heather's
 * primary screen."*
 *
 * ## What it is authorized on, and why it is not a task permission
 *
 * `viewAny` on **`Deal`**, which resolves to `deals.view`. `TaskPolicy` is
 * deliberately built on the deal — every one of its methods takes a `Deal` or
 * a `Task` and asks a `deals.*` key, and its docblock refuses to invent a
 * `tasks.*` one because *"no shipped role holds it"*. A cross-deal queue has
 * no single deal to ask about, so it asks the question one level up: somebody
 * who may see this team's deals may see the tasks on them. The rows are
 * narrowed to their own assignments regardless, which is a stronger filter
 * than any permission would be.
 */
class WorkController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Deal::class);

        /** @var Person $person */
        $person = $request->user();

        /*
         * The segment is read from the query string and validated by the
         * `match` inside `MyWork`, which falls back to `open`. A bad value is
         * a stale bookmark, not an attack — and `open` is the honest default
         * for a screen whose question is "what do I do next".
         */
        $segment = (string) $request->query('segment', 'open');

        $work = MyWork::forPerson($person, $segment);

        return Inertia::render('Work', [
            'segment' => in_array($segment, ['open', 'overdue', 'all'], true) ? $segment : 'open',
            'groups' => $work['groups'],
            'counts' => $work['counts'],
        ]);
    }
}
