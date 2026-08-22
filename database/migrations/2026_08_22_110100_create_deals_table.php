<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deals (PRD §4.3 F3.1, F3.2, F3.8, §6.2 · IA §8, §10 · issue #59).
 *
 * **Deal, never Project.** IA §12 and the PRD decision log renamed it, and
 * `CLAUDE.md` is explicit that superseded terminology must not reach code even
 * where an older doc passage still shows it. Emily and Heather never said
 * "project"; `tests/Unit/CodeDisciplineTest.php` fails the build if it appears.
 *
 * ## Two names, and the reason there are two
 *
 * `generated_name` is derived — subject property address, falling back to a
 * client surname (IA §10) — and it goes on updating as the deal acquires the
 * facts it is derived from. `name` is what somebody typed, and it wins on
 * every screen the moment it is set.
 *
 * Storing only one would lose something either way. One derived column alone
 * means a manual name is overwritten the day a property is attached; one typed
 * column alone means a deal created before its property is named "Untitled"
 * for ever. Issue #59 asks for both explicitly: *"editing the name does not
 * stop `generated_name` from updating when the property changes."*
 *
 * ## Closing is not the end
 *
 * F3.8, and PRD §7.15 on what the rough data model got wrong: it *"had no
 * place for anything after closing."* `closed` hands the deal to `nurture` and
 * the participants stay attached, which is what Slice 6 picks up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table): void {
            $table->productDefaults();

            /*
             * A plain foreign key, and one of the few places in this schema
             * that is right.
             *
             * ADR 0002 layer 2 wants `(team_id, deal_type_id)` referencing
             * `(team_id, id)`, so a deal cannot borrow another team's type.
             * That constraint cannot be written here, because the legitimate
             * case breaks it: a **system** deal type has `team_id = null`, and
             * a composite key from a NOT NULL `deals.team_id` can never match
             * `(null, id)`. Expressing "my team's type or the shared one" is
             * beyond a foreign key.
             *
             * The ADR names this exact situation — *"where Postgres cannot
             * express the constraint, the relationship carries a test
             * instead"* — and `tests/Isolation/CrossTenantAccessTest.php`
             * carries it: a deal may not be created against another team's
             * private type.
             *
             * Restricted rather than cascading: deleting a type that deals
             * point at should fail loudly. Archiving is what somebody actually
             * means (see `deal_types`), and a cascade here would silently take
             * the deals with it.
             */
            $table->foreignUlid('deal_type_id')->constrained('deal_types')->restrictOnDelete();

            $table->string('name')->nullable();
            $table->string('generated_name')->nullable();

            $table->string('state')->default('active');

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Integer cents (ADR 0001). Somebody's house is not a float.
            $table->money('transaction_value', nullable: true);

            $table->text('notes')->nullable();

            // The deals index (S13) filters by state and sorts by recency, at
            // 25 concurrent deals per team and hundreds of closed ones.
            $table->index(['team_id', 'state', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
