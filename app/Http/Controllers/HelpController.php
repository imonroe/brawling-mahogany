<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Help\HelpLibrary;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S92 — the manual (issue #170).
 *
 * ## Behind authentication, and behind nothing else
 *
 * Every other screen in the shell asks a policy first. This one asks only that
 * somebody is signed in, and the absence is deliberate: a manual gated on
 * `deals.view` cannot explain what deals are to the person deciding whether to
 * ask for that permission, and a Contact who has been given a login has as
 * much reason to read *Signing in* as an owner does.
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
