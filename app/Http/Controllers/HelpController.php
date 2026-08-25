<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Help\HelpLibrary;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S92 — the manual (issue #170).
 *
 * ## Behind `auth`, and behind nothing else
 *
 * Every other screen in the shell asks a policy first, and sits inside
 * `verified`, `two-factor` and `team` besides. This one asks only that
 * somebody is signed in, and every exclusion is a case somebody is in.
 *
 * A manual gated on `deals.view` cannot explain what deals are to the person
 * deciding whether to ask for that permission, and a Contact who has been
 * given a login has as much reason to read *Signing in* as an owner does.
 * More sharply: PRD §9 holds an un-enrolled Team Owner at the enrolment
 * screen, and *Signing in and your account* is the article explaining
 * enrolment and recovery codes — inside `two-factor`, the manual locked out
 * the one person who needed that page. `team` and `verified` are the same
 * argument at other moments.
 *
 * `routes/web.php` puts these two outside the tenant group for that reason,
 * and `HelpTest` asserts the middleware rather than trusting this paragraph —
 * the claim was made in five places and was false in all five before review
 * caught it.
 *
 * `AuthorizationCoverageTest` reads the route table and expects every action
 * to authorize, so both actions here are listed in its own exemptions with
 * this reason rather than being quietly skipped.
 *
 * ## Everything is shown to everybody
 *
 * The manual does not hide an article because the reader lacks the permission
 * it describes. The UI hides what you cannot use — §7.3, and the right rule
 * for a screen — but documentation that disappears based on your role leaves
 * you unable to find out what you are missing or what to ask for. Articles
 * name the permission a feature needs instead, in a line the reader can act
 * on.
 */
class HelpController extends Controller
{
    public function index(HelpLibrary $library): Response
    {
        return Inertia::render('Help/Index', [
            'sections' => $library->sections(),
        ]);
    }

    public function show(string $article, HelpLibrary $library): Response
    {
        $found = $library->find($article);

        abort_if($found === null, 404);

        return Inertia::render('Help/Show', [
            'article' => [
                'slug' => $found->slug,
                'title' => $found->title,
                'summary' => $found->summary,
                'section' => $found->section,
                'arrivesWith' => $found->arrivesWith,
                'html' => $found->html,
                'headings' => $found->headings,
            ],
            ...$library->neighbours($found),
        ]);
    }
}
