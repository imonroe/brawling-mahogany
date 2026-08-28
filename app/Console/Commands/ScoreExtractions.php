<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ExtractionKind;
use App\Support\Extraction\ProviderFailed;
use App\Support\Extraction\ProviderManager;
use App\Support\Extraction\PromptRegistry;
use App\Support\Extraction\ReadProposals;
use App\Support\Extraction\Redaction\Redactor;
use App\Support\Extraction\Scoring\CorpusCase;
use App\Support\Extraction\Scoring\Scorecard;
use Illuminate\Console\Command;

/**
 * The accuracy regression harness (#118 · #14 · PRD §12.3).
 *
 * #118: the corpus *"becomes a permanent regression suite … re-run it on every
 * prompt version change and every model version change … This runs outside
 * normal CI — it costs money per run and needs the provider — but it must be
 * **one documented command, not a manual afternoon**."*
 *
 * This is that command.
 *
 * ## Why it is not a test
 *
 * Everything in `tests/` runs on every push, and `Http::preventStrayRequests()`
 * fails any test that reaches a real host. That is exactly right for a suite
 * and exactly wrong for this: scoring twenty contracts costs real money against
 * a real provider, and a CI run that did it on every commit would be both
 * expensive and, at $2 a contract, a bill nobody agreed to.
 *
 * So it is a command somebody runs deliberately, and the scorecard it prints is
 * the artefact — recorded against `prompt_version` so #118's *"has a model
 * version change made it worse"* has two numbers to compare.
 *
 * ## What it does not touch
 *
 * No `extractions` rows, no team, no database at all. The corpus is text on
 * disk and the ground truth is JSON beside it. That keeps the harness runnable
 * against a candidate model without a tenant to hang the work off, and it keeps
 * a measurement run out of the cost figures a team sees on S68 — a scorecard
 * run should not show up as a deal's spend.
 */
class ScoreExtractions extends Command
{
    protected $signature = 'extraction:score
        {--corpus=tests/Corpus/contracts : Directory holding the corpus}
        {--only= : Score one case by slug}
        {--limit=0 : Stop after this many cases}
        {--dry-run : Read the corpus and report what would be scored, calling nothing}
        {--json= : Also write the scorecard as JSON to this path}';

    protected $description = 'Score extraction against the hand-checked corpus (#118). Costs money — it calls the provider.';

    public function handle(
        ProviderManager $providers,
        PromptRegistry $prompts,
        Redactor $redactor,
        ReadProposals $reader,
    ): int {
        $directory = base_path((string) $this->option('corpus'));

        $cases = CorpusCase::load($directory, (string) ($this->option('only') ?: ''));

        if ($cases === []) {
            $this->components->error("No corpus cases found in {$directory}.");
            $this->line('See tests/Corpus/README.md for the format.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $cases = array_slice($cases, 0, $limit);
        }

        if ($this->option('dry-run')) {
            return $this->describe($cases, $prompts);
        }

        if (! $providers->isAvailable()) {
            $this->components->error('No extraction provider is configured, so there is nothing to score.');
            $this->line('Set EXTRACTION_DRIVER and the provider’s key, then run this again.');

            return self::FAILURE;
        }

        $provider = $providers->provider();
        $prompt = $prompts->for(ExtractionKind::Contract);
        $scorecard = new Scorecard($provider->name(), $provider->model(), $prompt->version());

        foreach ($cases as $case) {
            $this->components->task($case->slug, function () use (
                $case,
                $provider,
                $prompt,
                $redactor,
                $reader,
                $scorecard,
            ): bool {
                try {
                    /*
                     * Through the **same** redactor the pipeline uses, not the
                     * raw text. #114 asks the corpus to measure redaction for
                     * *"damage to extractable content"* as well as for misses,
                     * and it can only do that if what is scored is what would
                     * actually be sent. A harness that skipped redaction would
                     * report accuracy the shipped path cannot achieve.
                     */
                    $redacted = $redactor->redact($case->text);
                    $result = $provider->extract($redacted, $prompt);
                    $proposals = $reader->from($result->raw, ExtractionKind::Contract);

                    $scorecard->record($case, $proposals, $result->costMicros, $redacted->report);

                    return true;
                } catch (ProviderFailed $failure) {
                    $scorecard->recordFailure($case, $failure->reasonCode);

                    return false;
                }
            });
        }

        $this->render($scorecard);

        if (is_string($this->option('json')) && $this->option('json') !== '') {
            file_put_contents(
                (string) $this->option('json'),
                json_encode($scorecard->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            );

            $this->line('Scorecard written to '.$this->option('json'));
        }

        /*
         * **A non-zero exit on a missed critical date.**
         *
         * PRD §12.3 gives that metric zero tolerance, and #118 spells out the
         * consequence: *"A model upgrade that regresses this does not ship,
         * however much better it looks elsewhere."* A harness that printed a
         * red number and exited 0 would leave that judgement to whoever
         * happened to read the output.
         */
        return $scorecard->passes() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<CorpusCase>  $cases
     */
    private function describe(array $cases, PromptRegistry $prompts): int
    {
        $this->components->info(count($cases).' cases, calling nothing.');

        $this->table(
            ['Case', 'Dates', 'Critical', 'Traits'],
            array_map(static fn (CorpusCase $case): array => [
                $case->slug,
                count($case->dates),
                $case->criticalCount(),
                implode(', ', $case->traits),
            ], $cases),
        );

        $this->line('Prompt version: '.$prompts->for(ExtractionKind::Contract)->version());

        return self::SUCCESS;
    }

    private function render(Scorecard $card): void
    {
        $this->newLine();
        $this->components->info('Scorecard — '.$card->model.' · prompt '.$card->promptVersion);

        foreach ($card->lines() as [$metric, $value, $target, $verdict]) {
            $this->components->twoColumnDetail(
                $metric.' <fg=gray>('.$target.')</>',
                $verdict === 'pass'
                    ? '<fg=green>'.$value.'</>'
                    : '<fg=red>'.$value.'</>',
            );
        }

        $this->newLine();

        foreach ($card->misses() as $miss) {
            $this->components->error($miss);
        }
    }
}
