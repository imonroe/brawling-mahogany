<?php

declare(strict_types=1);

/*
 * Branch protection is the one piece of the pipeline that cannot be committed
 * — it lives in repository settings — so `scripts/protect-branches.sh` names
 * the five required checks as a literal list. A literal list of job names is
 * exactly the kind of claim that rots: rename a job in ci.yml and the rule
 * silently requires a check that will never report, which does not fail
 * loudly. It blocks every pull request forever instead.
 *
 * So the two are checked against each other here, and the epic's exit
 * criterion ("a PR to `dev` runs Pest, PHPStan and Pint and blocks on
 * failure") has something holding it up other than memory.
 */

function ciJobNames(): array
{
    $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)->not->toBeFalse();

    preg_match_all('/^    name: (.+)$/m', $workflow, $matches);

    return array_map(trim(...), $matches[1]);
}

function scriptCheckNames(): array
{
    $script = file_get_contents(base_path('scripts/protect-branches.sh'));

    expect($script)->not->toBeFalse();

    // CHECKS=('Tests' 'Static analysis' ...)
    preg_match("/^CHECKS=\((.+)\)$/m", $script, $matches);

    expect($matches)->toHaveCount(2, 'scripts/protect-branches.sh must declare a CHECKS array.');

    preg_match_all("/'([^']+)'/", $matches[1], $names);

    return $names[1];
}

it('requires exactly the checks that CI actually reports', function (): void {
    $ci = ciJobNames();
    $script = scriptCheckNames();

    // Sorted, because the order in the rule does not matter but the set does.
    sort($ci);
    sort($script);

    expect($script)->toBe(
        $ci,
        'scripts/protect-branches.sh and .github/workflows/ci.yml disagree about '
        .'the job names. A required check that never reports blocks every pull '
        .'request; a job missing from the list blocks nothing.',
    );
});

it('guards every CI job, so none of them is advisory', function (): void {
    // The epic makes "blocks on failure" an exit criterion for all of them,
    // not for whichever ones somebody remembered.
    expect(ciJobNames())->toHaveCount(
        5,
        'The CI job count changed. That is fine — add the new job to CHECKS in '
        .'scripts/protect-branches.sh and to docs/Deployment.md §7, then update '
        .'this number. It is pinned so a new job cannot quietly stay advisory.',
    );
});

function branchProtectionSection(): string
{
    $deployment = file_get_contents(base_path('docs/Deployment.md'));

    expect($deployment)->not->toBeFalse();

    /*
     * Just §7, not the whole file. Searching the document for 'Tests' or
     * 'Restore' finds ordinary English somewhere in ten kilobytes of runbook,
     * so a whole-file search passes while §7 documents a check that no longer
     * exists — which is the failure this test is for.
     */
    preg_match('/^## 7\. Branch protection$(.*?)(?=^## |\z)/ms', $deployment, $matches);

    expect($matches)->toHaveCount(2, 'docs/Deployment.md has no "## 7. Branch protection" section.');

    return $matches[1];
}

it('keeps the checks documented where an operator will look for them', function (): void {
    $section = branchProtectionSection();

    foreach (ciJobNames() as $job) {
        // Asserted on the boolean rather than the haystack, so a failure prints
        // the message rather than the whole section.
        expect(str_contains($section, $job))->toBeTrue(
            "docs/Deployment.md §7 lists the required checks and is missing '{$job}'.",
        );
    }
});
