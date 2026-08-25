<?php

declare(strict_types=1);

use App\Support\Help\HelpLibrary;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * S92 — the manual (issue #170).
 *
 * Most of this file is not about rendering. Documentation rots in one specific
 * way — it goes on describing a product that has moved — and the only defence
 * that survives contact with a year of development is a test that reads the
 * prose and checks it against the application. So the cases that matter here
 * are the ones that fail when the *app* changes and nobody remembered the
 * manual.
 */
beforeEach(function (): void {
    [$this->team, $this->member] = $this->teamWithMember();
    $this->actingAsPerson($this->member, $this->team);
});

it('renders the contents, grouped in the order somebody meets the product', function (): void {
    $response = $this->get('/help')->assertOk();

    $sections = $response->viewData('page')['props']['sections'];

    // Getting started first, planned features last — the order somebody needs
    // them rather than alphabetical or by how much work each took.
    expect(collect($sections)->pluck('key')->all())
        ->toBe(['getting-started', 'deals', 'people', 'setup', 'coming-later']);

    expect(collect($sections)->flatMap(fn (array $s): array => $s['articles'])->count())
        ->toBeGreaterThanOrEqual(15);
});

it('renders one article, with its headings for the contents list', function (): void {
    $response = $this->get('/help/stages-and-requirements')->assertOk();

    $article = $response->viewData('page')['props']['article'];

    expect($article['title'])->toBe('Stages and requirements')
        ->and($article['html'])->toContain('<h2 id="advancing">')
        ->and(collect($article['headings'])->pluck('id'))->toContain('advancing');
});

it('walks the manual in order', function (): void {
    // `Next` is genuinely the next thing to learn, not the next thing
    // alphabetically — which is the whole reason the ordering is explicit.
    $response = $this->get('/help/welcome')->assertOk();

    expect($response->viewData('page')['props']['previous'])->toBeNull()
        ->and($response->viewData('page')['props']['next']['slug'])->toBe('signing-in');
});

it('answers 404 for an article that does not exist', function (): void {
    $this->get('/help/no-such-article')->assertNotFound();
});

it('is readable by somebody with no permissions at all', function (): void {
    /*
     * The manual asks only that you are signed in. A help section gated on
     * `deals.view` cannot explain what a deal is to the person deciding
     * whether to ask for that permission, and a Contact given a login has as
     * much reason to read *Signing in* as an owner does.
     *
     * Asserted with a membership holding **no** roles, which is the emptiest
     * a signed-in person can be.
     */
    $bare = App\Models\Person::factory()->create();

    app(App\Support\Tenancy\TeamContext::class)->runFor($this->team, function () use ($bare): void {
        App\Models\TeamMembership::query()->create([
            'team_id' => $this->team->getKey(),
            'person_id' => $bare->getKey(),
            'first_name' => 'Nobody',
            'joined_at' => now(),
        ]);
    });

    $this->actingAsPerson($bare, $this->team);

    $this->get('/help')->assertOk();
    $this->get('/help/welcome')->assertOk();
});

it('reaches the owner stranded at two-factor enrolment', function (): void {
    /*
     * The case that moved these routes out of the tenant group.
     *
     * PRD §9 makes 2FA mandatory for a Team Owner, so an un-enrolled owner is
     * held at the enrolment screen — and *Signing in and your account* is the
     * article explaining enrolment, recovery codes, and what to do when the
     * phone is the thing you lost. Inside `two-factor`, the manual locked out
     * the one person who most needed that page.
     *
     * The control matters: the same person is asserted to be redirected away
     * from an ordinary screen, so a passing case here cannot be the mandate
     * quietly not applying.
     */
    [$team, $owner] = $this->teamWithOwner();

    $this->actingAsPerson($owner, $team);

    $this->get('/dashboard')->assertRedirect(route('security.edit'));

    $this->get('/help')->assertOk();
    $this->get('/help/signing-in')->assertOk();
});

it('is gated on being signed in and on nothing else', function (): void {
    /*
     * The claim this PR makes in five places, asserted where it actually
     * lives — the route's middleware — rather than through a request.
     *
     * All three halves are also asserted behaviourally: two-factor above, and
     * the teamless case in `HelpWithoutTeamTest` — its own file precisely
     * because `TeamContext` is an in-memory singleton, so the case is only
     * honest where nothing has resolved a team.
     *
     * `verified`, `two-factor` and `team` are each excluded for a case
     * somebody is genuinely in: mid-signup, held at 2FA enrolment, or invited
     * and not yet in a team. All three are moments when a manual is worth
     * more than usual, and all three would have been locked out.
     */
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array($route->getName(), ['help.index', 'help.show'], true));

    expect($routes)->toHaveCount(2);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        // No message argument: Pest reads extra arguments to `toContain` as
        // further values to look for, not as a failure message.
        expect($middleware)->toContain('auth')
            ->and($middleware)->not->toContain('two-factor')
            ->and($middleware)->not->toContain('team')
            ->and($middleware)->not->toContain('verified');
    }
});

it('keeps the manual behind the sign-in screen', function (): void {
    auth()->logout();

    $this->get('/help')->assertRedirect('/login');
});

it('strips raw HTML rather than rendering it', function (): void {
    /*
     * Repository content today, so this is not an injection boundary — and it
     * is configured as one because the audience for these files is people
     * editing prose, and the output reaches the browser through `v-html`.
     *
     * Asserted against a real file written for the test rather than against
     * the converter in isolation, because the question is what the *route*
     * returns.
     *
     * **The file has to be written before this test's first help request.**
     * `HelpLibrary` is a container singleton, so the directory listing is
     * memoised for the rest of the test once anything has read it — a `get`
     * placed above the `File::put` makes this 404 rather than fail loudly.
     */
    $path = resource_path('help/zz-probe.md');

    File::put($path, "---\ntitle: Probe\nsummary: Probe.\nsection: getting-started\norder: 98\n---\n\n"
        ."<script>alert(1)</script>\n\nOrdinary text.\n");

    try {
        $html = $this->get('/help/zz-probe')->assertOk()
            ->viewData('page')['props']['article']['html'];

        expect($html)->not->toContain('<script')
            ->and($html)->toContain('Ordinary text.');
    } finally {
        File::delete($path);
    }
});

it('never links to a route the application does not have', function (): void {
    /*
     * **The guard that keeps the manual true.**
     *
     * Documentation rots by going on describing a product that has moved, and
     * an internal link is the part that rots first and most visibly — a reader
     * following one lands on a 404 and learns that the manual cannot be
     * trusted, which is worse than the missing sentence.
     *
     * So every `](/...)` in every article is resolved against the real route
     * table. This fails on the day a route is renamed, in the pull request
     * that renames it, which is the only moment anybody can cheaply fix it.
     */
    /*
     * **Static routes only**, deliberately.
     *
     * The first version rewrote `{param}` to `[^/]+` before matching, which
     * made `/deals/anything-at-all` resolve — the same hole that let
     * `/help/logging-a-contact` through, one route pattern over. A manual has
     * no business linking to a row: it links to screens, and every screen is a
     * static URI. So a link carrying an id now fails rather than resolving
     * against the pattern that would have held it.
     */
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('GET', $route->methods(), true))
        ->map(fn ($route): string => '/'.ltrim((string) $route->uri(), '/'))
        ->reject(fn (string $uri): bool => str_contains($uri, '{'));

    $articles = array_keys(app(HelpLibrary::class)->all());

    $broken = [];
    $checked = 0;
    $intoTheManual = 0;

    foreach (File::files(resource_path('help')) as $file) {
        /*
         * Every link, not only the absolute ones.
         *
         * The first version matched `](/…` alone, which is blind to
         * `[tasks](tasks.md)` — the reflex spelling when you are editing a
         * directory of Markdown files, and one that renders to a genuinely
         * dead href: the route constrains `{article}` to `[a-z0-9-]+`, so the
         * dot makes `/help/tasks.md` a 404. A guard whose premise is that
         * links rot first cannot be blind to the spelling an author reaches
         * for.
         */
        preg_match_all('/\]\(([^)\s]+)/', (string) File::get($file->getPathname()), $matches);

        foreach ($matches[1] as $link) {
            /*
             * A link out is not this test's business.
             *
             * The widened pattern is what makes the relative case visible, and
             * it also catches `https://`, `mailto:` and the `](` inside an
             * image. Refusing those would fail the build on three legitimate
             * spellings and — worse — call an absolute URL *relative*, sending
             * the author looking for a path they did not write. A manual wants
             * outbound links and screenshots; the rule here is only that an
             * internal link resolves.
             */
            if (Str::contains($link, ['://', 'mailto:', 'tel:'])) {
                continue;
            }

            $checked++;

            // A fragment is a position on the page, and a query string is not
            // part of what the router matches.
            $link = Str::before(Str::before($link, '#'), '?');

            if ($link === '') {
                continue;
            }

            /*
             * A relative link renders to a dead href rather than to a missing
             * one: `{article}` is constrained to `[a-z0-9-]+`, so the dot in
             * `](tasks.md)` makes `/help/tasks.md` a 404. It is also the
             * reflex spelling in a directory of Markdown files, which is why
             * it is named in the message rather than lumped in with the rest.
             */
            if (! Str::startsWith($link, '/')) {
                $broken[] = $file->getFilename().': '.$link
                    .' (relative — an internal link is an absolute path)';

                continue;
            }

            /*
             * A link **into the manual** is checked against the articles, not
             * against the route.
             *
             * `/help/{article}` matches any single segment, so a route-level
             * check waves through `/help/anything-at-all` — and it did: the
             * first draft of these files linked to `/help/logging-a-contact`,
             * an article that does not exist, and this test passed over it. A
             * guard blind to the most likely broken link in the files it
             * guards is not a guard.
             */
            if (Str::startsWith($link, '/help/')) {
                $intoTheManual++;

                if (! in_array(Str::after($link, '/help/'), $articles, true)) {
                    $broken[] = $file->getFilename().': '.$link.' (no such article)';
                }

                continue;
            }

            /*
             * A screenshot is a link too. A manual is the one document set
             * that wants images, and the spelling is `](`, so an absolute path
             * naming a real file under `public/` resolves as surely as a route
             * does — and one naming a file that is not there is exactly the
             * rot this test exists to catch.
             */
            if (File::exists(public_path(ltrim($link, '/')))) {
                continue;
            }

            if (! $routes->contains($link)) {
                $broken[] = $file->getFilename().': '.$link;
            }
        }
    }

    /*
     * The scan has to be finding links: a pattern that quietly stopped
     * matching would make the assertion below pass over an empty list.
     *
     * Counted in two halves because the totals are not interchangeable. Every
     * link in the manual today points *into the manual*, so the app-route
     * branch executes zero times — a single total would look like coverage the
     * route half does not have. What protects that half meanwhile is the
     * static-only rule above, not this floor. (A third line asserting
     * `$checked - $intoTheManual >= 0` stood here for a round and was
     * arithmetic: the second counter only ever increments after the first.)
     */
    expect($checked)->toBeGreaterThanOrEqual(4)
        ->and($intoTheManual)->toBeGreaterThanOrEqual(4);

    expect($broken)->toBe([], 'These help articles link somewhere that does not exist: '
        .implode(', ', $broken));
});

it('gives every article the frontmatter the index needs', function (): void {
    /*
     * A file missing `section` lands in Getting started by default and a file
     * missing `order` sorts last — neither throws, because a typo in prose
     * should not take a screen down. That safety is exactly why it needs a
     * test: without one, a mis-sectioned article is invisible rather than
     * broken.
     */
    $articles = app(HelpLibrary::class)->all();

    expect($articles)->not->toBeEmpty();

    $sections = collect(HelpLibrary::SECTIONS)->pluck('key');

    foreach ($articles as $slug => $article) {
        expect($article->title)->not->toBe('', "{$slug} has no title")
            ->and($article->summary)->not->toBe('', "{$slug} has no summary")
            ->and($sections->contains($article->section))->toBeTrue(
                "{$slug} names section '{$article->section}', which is not one of the five",
            )
            ->and($article->order)->toBeLessThan(99, "{$slug} has no order");

        // The slug is the URL, so it has to survive being one.
        expect(Str::slug($slug))->toBe($slug, "{$slug} is not URL-safe");
    }
});

it('marks every planned feature as planned, on both screens', function (): void {
    /*
     * #170 asks for placeholders. The risk with a placeholder is not that it
     * is missing — it is that it reads as documentation for something that
     * exists, and somebody goes looking for a screen that is not there.
     *
     * So everything in the Coming later section carries `arrives_with`, which
     * is what draws the badge on the index and the banner on the article.
     */
    $articles = app(HelpLibrary::class)->all();

    $planned = collect($articles)->filter(
        fn ($article): bool => $article->section === 'coming-later',
    );

    expect($planned)->not->toBeEmpty();

    foreach ($planned as $slug => $article) {
        expect($article->isPlanned())->toBeTrue(
            "{$slug} is in Coming later but is not marked with `arrives_with`, "
            .'so it renders as though the feature exists',
        );
    }

    // And nothing outside that section is marked planned, which would be the
    // same confusion in the other direction.
    $misplaced = collect($articles)->filter(
        fn ($article): bool => $article->isPlanned() && $article->section !== 'coming-later',
    );

    expect($misplaced->keys()->all())->toBe([]);
});

it('uses the product’s own vocabulary', function (): void {
    /*
     * IA §11 is *"one concept, one word"*, and it binds user-facing text.
     * A manual is the most user-facing text there is — it is where somebody
     * learns what the words mean — so a manual that says *"project"* or
     * *"to-do"* teaches a vocabulary the app does not use, and every screen
     * afterwards reads as slightly wrong.
     *
     * Only the unambiguous ones are checked. *Step* and *Item* are ordinary
     * English that IA §11 bans as **synonyms for Stage and Task**, and a scan
     * for them would fire on "the next step in the wizard", which is not the
     * mistake.
     */
    $banned = [
        'project' => 'Deal',
        'milestones' => 'Stage (or Milestone in the narrow sense only)',
        'blueprint' => 'Template',
        'to-do' => 'Task',
        'nurture' => 'Keep in Touch',
        'drip' => 'Keep in Touch',
        'portal' => 'Status Page',
        'workspace' => 'Team',
        'organization' => 'Team',
        'service provider' => 'Vendor',
        /*
         * IA §11 line 450 bans this **in the UI** specifically — *"Dates &
         * Deadlines, not Key dates"*, and it is Emily's exact phrase. The
         * manual is UI text, so it is bound by it, and the first draft said
         * *"key dates"* four times.
         */
        'key date' => 'Dates & Deadlines',
    ];

    $offences = [];

    foreach (File::files(resource_path('help')) as $file) {
        $contents = mb_strtolower((string) File::get($file->getPathname()));

        foreach ($banned as $word => $instead) {
            if (str_contains($contents, $word)) {
                $offences[] = sprintf('%s says "%s" — use %s', $file->getFilename(), $word, $instead);
            }
        }
    }

    expect($offences)->toBe([], implode('; ', $offences));
});

it('never promises a screen the reader cannot reach', function (): void {
    /*
     * The other half of the placeholder rule.
     *
     * An article outside *Coming later* describes something that exists, so
     * anything it tells the reader to open has to be openable. The phrase this
     * catches is a heading or sentence naming a nav destination — the manual's
     * most common instruction is *"Deals → New deal"*, and the arrow is what
     * makes it a promise.
     */
    $planned = collect(app(HelpLibrary::class)->all())
        ->filter(fn ($article): bool => $article->isPlanned())
        ->keys();

    /*
     * Read out of the sidebar rather than typed here.
     *
     * The first version was a hand-copied list, and it had already drifted
     * before it shipped — it omitted Dashboard and carried Help, which is a
     * top-bar icon. A copy of the thing you are checking against answers a
     * different question than the failure message claims, and a sidebar rename
     * leaves it green while every arrow in the manual points at a label that no
     * longer exists. Same shape as `subjectPermissions()` in
     * `ActivityFeedIsolationTest`: derive it, so the guard fails in the pull
     * request that renames the nav.
     */
    $navigation = (string) File::get(resource_path('js/lib/navigation.ts'));

    /*
     * Comments stripped first, the way `routeTargets.test.ts` does and for the
     * same reason. A commented-out entry — `// removed 2026: { label:
     * 'Reports', … }` — would otherwise legitimise an arrow pointing at a
     * screen that no longer exists, which is a silent pass rather than a loud
     * failure and so the worse direction for this guard to be wrong in.
     *
     * Line comments go **first**: with the block pass leading, a block-comment
     * opener written inside a `//` comment pairs with the next real closer and
     * swallows the entries between them. That fails loudly on the floor below
     * rather than passing, but it fails while blaming an article.
     */
    $navigation = (string) preg_replace(['#//[^\n]*#', '#/\*.*?\*/#s'], '', $navigation);

    preg_match_all("/label: '([^']+)'/", $navigation, $labels);

    $navigable = $labels[1];

    expect($navigable)->toContain('Deals');
    expect(count($navigable))->toBeGreaterThanOrEqual(8);

    $offences = [];
    $checked = 0;

    /*
     * Read from the **Markdown source**, not from `$article->html`.
     *
     * The first version of this ran `/\*\*(...) →/` over the rendered HTML,
     * where `**Deals → New deal**` has already become
     * `<strong>Deals → New deal</strong>`. It matched **nothing**, on every
     * article, and passed — which is the same silence the link guard was
     * caught in. Mutation-tested: appending `**Dashboards → Reports**` to an
     * article now fails this.
     */
    foreach (File::files(resource_path('help')) as $file) {
        if (in_array(Str::before($file->getFilename(), '.md'), $planned->all(), true)) {
            continue;
        }

        /*
         * Only the **first** segment of a chain is checked: `**Settings →
         * Roles → New role**` asks about Settings, because Roles and New role
         * are inside a screen rather than in the sidebar and there is nothing
         * to check them against. The promise this guard makes is that the
         * reader can find the door, not every handle behind it.
         *
         * `**Destination → …**` is the shape, and the shape is load-bearing:
         * `**Deals** → New deal` (bold on the first word only) and a lowercase
         * destination both escape this. Mutation-tested, and written down here
         * rather than widened, because a looser pattern starts matching prose.
         */
        preg_match_all(
            '/\*\*([A-Z][A-Za-z ]+?)\s*→/u',
            (string) File::get($file->getPathname()),
            $matches,
        );

        foreach ($matches[1] as $destination) {
            $checked++;

            if (! in_array(trim($destination), $navigable, true)) {
                $offences[] = sprintf(
                    '%s sends the reader to "%s", which is not in the sidebar',
                    $file->getFilename(),
                    trim($destination),
                );
            }
        }
    }

    // The scan has to be finding instructions, or it is asserting over an
    // empty list — which is exactly how the first version passed.
    expect($checked)->toBeGreaterThanOrEqual(4);

    expect($offences)->toBe([], implode('; ', $offences));
});

it('gives the contents list ids that match the headings', function (): void {
    /*
     * The two were derived separately — one from the Markdown, one from the
     * rendered HTML — and disagreed the moment a heading held a character
     * CommonMark escapes. `## Dates & Deadlines` produced `dates-deadlines`
     * in the contents list and `dates-amp-deadlines` on the heading, because
     * `strip_tags` leaves `&amp;` for `Str::slug` to read as a word. A
     * contents link that scrolls nowhere is the most obvious defect a manual
     * can have.
     *
     * Also asserts the duplicate case, which two identical headings in one
     * article would otherwise collapse onto the first.
     */
    $article = app(HelpLibrary::class)->parse('anchors', "---\ntitle: Anchors\nsummary: Probe.\n"
        ."section: getting-started\norder: 97\n---\n\n"
        ."## Dates & Deadlines\n\nOne.\n\n## What it will do\n\nTwo.\n\n## What it will do\n\nThree.\n"
        // The collision the first numbering scheme could not see: suffixing
        // once with the occurrence count gives `foo-2`, `foo`, `foo-2`, and the
        // third heading then anchors onto the first — a contents link that
        // scrolls to the wrong section rather than to none.
        ."## Foo 2\n\nFour.\n\n## Foo\n\nFive.\n\n## Foo\n\nSix.\n");

    // No message argument: Pest reads a second argument to `toContain`
    // as another needle, not as a failure message.
    foreach ($article->headings as $heading) {
        expect($article->html)->toContain('<h2 id="'.$heading['id'].'">');
    }

    $ids = collect($article->headings)->pluck('id');

    expect($ids->all())->toBe([
        'dates-deadlines',
        'what-it-will-do',
        'what-it-will-do-2',
        'foo-2',
        'foo',
        'foo-3',
    ])->and($ids->unique()->count())->toBe($ids->count());
});
