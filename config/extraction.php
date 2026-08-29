<?php

declare(strict_types=1);

/*
 * Extraction — PRD §4.10 F10.6, §8.4, §14.3 · issue #113.
 *
 * F10.6: *"Extraction sits behind an interface so the model provider can
 * change without touching the workflow engine."* That promise is only worth
 * anything if swapping providers is a change to `EXTRACTION_DRIVER` and
 * nothing else, so every provider-shaped fact lives here and nothing outside
 * `App\Support\Extraction\Providers` reads it.
 *
 * ## The default is `null`, deliberately
 *
 * PRD §10 lists four things that must exist before F10 ships — a signed DPA, a
 * no-training commitment, a retention position, and disclosure language in the
 * team's own listing agreement (#13). None of them are a code change, and none
 * of them can be checked from here. A default that reached a live provider
 * would mean the *absence* of a decision sends somebody's contract to a third
 * party, so the absence of a decision sends it nowhere: the null driver
 * refuses every call and says why.
 */

return [

    /*
     * Which provider. `null` refuses; `anthropic` calls the API.
     *
     * `app/Support/Extraction/ProviderManager.php` resolves this, and it is
     * the only file that maps a name to a class.
     */
    'driver' => env('EXTRACTION_DRIVER', 'null'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),

        /*
         * The model, and the reason it is an env var rather than a constant:
         * #118's regression harness re-runs the corpus on **every model
         * version change**, and it cannot do that if changing the model is a
         * deploy. PRD §12.3 gives it zero tolerance on critical dates missed,
         * which is a scorecard somebody has to be able to produce for a
         * candidate model before it becomes the default.
         */
        'model' => env('EXTRACTION_MODEL', 'claude-sonnet-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'api_version' => '2023-06-01',
        'max_tokens' => 8000,

        /*
         * Generous, because this runs in a queue worker rather than in front
         * of somebody. Several pages of a scanned contract through a vision
         * model is genuinely slow, and a timeout that fires mid-read costs the
         * money *and* produces nothing.
         */
        'timeout' => (int) env('EXTRACTION_TIMEOUT', 180),
    ],

    /*
     * What a call costs, in **micros** — millionths of a dollar per token.
     *
     * Cents cannot express this. A page of contract is a few thousand tokens
     * and an input token is a small fraction of a cent, so rounding each call
     * to a cent would round most of them to zero and make §14.3's *"track cost
     * per deal from day one"* a column of noughts. `extractions.cost_micros`
     * carries the same unit, and `Money::fromMicros()` is the only thing that
     * turns it into words.
     *
     * Rates are per **million** tokens, which is how every provider quotes
     * them — divide at the point of use, not here, so a published price can be
     * pasted in and checked against the provider's own page.
     */
    'pricing' => [
        'claude-opus-5' => ['input' => 5_000_000, 'output' => 25_000_000],
        'claude-sonnet-5' => ['input' => 3_000_000, 'output' => 15_000_000],
        'claude-haiku-4-5-20251001' => ['input' => 1_000_000, 'output' => 5_000_000],
    ],

    /*
     * PRD §14.3: *"Extraction cost grows with deal volume rather than team
     * count, so a heavy user could be unprofitable at a flat price. Track cost
     * per deal from day one of slice 5 and cap it."*
     *
     * Two ceilings, because they fail differently. The **team** cap is a
     * commercial limit and one team hitting it must not stop anybody else's
     * extraction. The **platform** cap is the one that exists so a defect —
     * a retry loop, a runaway import — cannot spend the company's money
     * overnight, and it stops everything by design.
     *
     * Both are per calendar month in UTC. Not the team's timezone: a
     * platform-wide ceiling cannot roll over at thirty different instants,
     * and a team-local month would make the two numbers incomparable on the
     * one screen that shows them side by side (#54).
     */
    'caps' => [
        'team_monthly_micros' => (int) env('EXTRACTION_TEAM_MONTHLY_CAP', 50_000_000),
        'platform_monthly_micros' => (int) env('EXTRACTION_PLATFORM_MONTHLY_CAP', 500_000_000),

        /*
         * Tell somebody before it stops, not when it stops. Eighty per cent of
         * a month's budget with a week to go is a conversation; a hard stop on
         * the twenty-eighth is an outage.
         */
        'warn_at_percent' => (int) env('EXTRACTION_CAP_WARN_PERCENT', 80),
    ],
];
