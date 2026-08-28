<?php

declare(strict_types=1);

use App\Enums\ExtractionKind;
use App\Enums\ExtractionState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `extractions` — one row per attempt (PRD §6.2 · issue #115).
 *
 * ## One row per *attempt*, not per document
 *
 * PRD §6.2 says so in as many words, and it is the decision that makes three
 * later questions answerable. A retry after a provider outage is a second row,
 * so #118's *"has a model version change made it worse"* has two results to
 * compare rather than one that overwrote the other. A cost is a fact about a
 * call, so §14.3's per-deal total is a `SUM` rather than a guess. And a failure
 * survives its own retry, so an operator can see that a document took three
 * goes rather than seeing only that it eventually worked.
 *
 * ## Cost is stored in micros, and that is a deviation worth naming
 *
 * ADR 0001 says money is integer cents. This column is millionths of a dollar,
 * because a cent is too coarse: a page of contract costs a fraction of one, and
 * rounding per call would make §14.3's *"track cost per deal from day one"* a
 * column of noughts that adds up to nothing. The column is named `cost_micros`
 * rather than `cost` precisely so nobody reads it as cents by habit, and
 * `config/extraction.php` carries the rates in the same unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extractions', function (Blueprint $table): void {
            $table->productDefaults();

            /*
             * The document is the subject and the deal is where a team looks
             * for it — the same split `activity_events` makes, for the same
             * reason. The deal is not derivable from the document: a document
             * hangs off a property as readily as off a deal, and S66 lives at
             * `/deals/{deal}/extractions/{extraction}`.
             */
            $table->teamScopedForeign('document_id', 'documents');
            $table->teamScopedForeign('deal_id', 'deals');

            $table->string('kind');
            $table->string('state')->default(ExtractionState::Queued->value);

            /*
             * Nullable, and the reason is the whole point of F10.6: these are
             * recorded *after* a provider answers, so a row that never got that
             * far carries nulls rather than a guess at which model would have
             * run. A `blocked` row has no provider because none was called.
             */
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('model_version')->nullable();
            $table->string('prompt_version')->nullable();

            /*
             * F10.4: the raw output is retained. #118's quality question —
             * *"what is the model getting wrong"* — cannot be answered from the
             * parsed proposals, because the interesting cases are the ones the
             * parser dropped.
             */
            $table->config('raw_response');

            /*
             * #114's definition of done: *"the redacted artefact sent to the
             * provider is recorded, so what was disclosed is knowable after the
             * fact."* This is that artefact — literally the text that left the
             * building. It is the *safe* copy by construction, which is what
             * makes storing it defensible where storing the original text would
             * not be.
             */
            $table->text('redacted_text')->nullable();

            /* Counts by rule, never values. See `RedactionReport`. */
            $table->config('redaction_report');

            $table->bigInteger('cost_micros')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            /*
             * What a person reads on S65 when it went wrong, and the enumerated
             * code beside it. Two columns rather than one because they have
             * different readers: `error` is a sentence written for the agent
             * looking at the screen, `error_code` is what an operator greps and
             * what `Redactor::ALLOWED_KEY_PATTERNS` will let through a log.
             */
            $table->text('error')->nullable();
            $table->string('error_code')->nullable();

            $table->foreignUlid('requested_by')->nullable()
                ->constrained('people')->nullOnDelete();

            /*
             * S68 reads per team newest-first, and the **cap query reads the
             * same index**: `SpendLedger::sum()` filters `team_id` and a
             * `created_at` month range, in that order.
             *
             * Keyed on `created_at` rather than `completed_at`, and the
             * ledger's own docblock argues why — a row created on the 31st and
             * completed on the 1st spent last month's budget from the queue's
             * point of view, and a row still `processing` at the boundary would
             * count against neither month. An index on `completed_at` was here
             * for one round, described as the cap query's, and was not the one
             * the cap query uses. An index that disagrees with the query that
             * maintains it is the trap `calendar_feeds` recorded one slice ago.
             */
            $table->index(['team_id', 'created_at']);
            $table->index(['deal_id', 'created_at']);
        });

        DB::statement(sprintf(
            "ALTER TABLE extractions ADD CONSTRAINT extractions_kind_check CHECK (kind IN ('%s'))",
            implode("','", array_column(ExtractionKind::cases(), 'value')),
        ));

        DB::statement(sprintf(
            "ALTER TABLE extractions ADD CONSTRAINT extractions_state_check CHECK (state IN ('%s'))",
            implode("','", array_column(ExtractionState::cases(), 'value')),
        ));

        /*
         * A negative cost is not a refund, it is an arithmetic bug in whatever
         * computed it — and it would quietly buy headroom under the cap that
         * §14.3 exists to enforce.
         */
        DB::statement(
            'ALTER TABLE extractions ADD CONSTRAINT extractions_cost_not_negative_check CHECK (cost_micros >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('extractions');
    }
};
