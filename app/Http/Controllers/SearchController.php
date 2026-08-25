<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Queries\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S07's endpoint (PRD §4.9 F9.3 · #82).
 *
 * JSON rather than an Inertia page, because the overlay is mounted by the
 * shell and opened over whatever screen somebody is on — an Inertia visit
 * would replace that screen, which is the opposite of what an overlay is for.
 *
 * Gated on `deals.view`, which is the broadest of the three things it
 * searches: somebody who cannot see the team's deals has no business getting
 * their names back from a search box. The narrower questions are answered by
 * the global scope, which every query here runs through.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Deal::class);

        $term = trim((string) $request->query('q', ''));

        return response()->json([
            'term' => $term,
            /*
             * The person, so each group can be gated on its own permission.
             * `deals.view` alone was enough while the five shipped roles were
             * the only roles; S75 (#88) lets a team compose *"deals but not
             * the client directory"*, and one check would hand that person
             * every client name in the team through a search box.
             */
            'groups' => $term === '' ? GlobalSearch::recent() : GlobalSearch::for($term, $request->user()),
            /*
             * Whether the query was too short to run, so the overlay can say
             * *"keep typing"* rather than *"no results"* — which would be a
             * different and wrong claim about the team's data.
             */
            'tooShort' => $term !== '' && mb_strlen($term) < GlobalSearch::MINIMUM_LENGTH,
        ]);
    }
}
