<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a buyer thinks of each candidate, and in what order (F3.5 · S20 · #62).
 *
 * `deal_properties` landed with #61 carrying the half that names the deal.
 * This is the other half of PRD §6.2's drafted `link_role`/`interest_status`
 * pair — the half that only means anything on a buyer-side deal, where twelve
 * houses are toured before an offer is made on one.
 *
 * ## `is_subject` is already `link_role`
 *
 * PRD §6.2 drafted `link_role (subject/candidate)`. #61 narrowed it to a
 * boolean and the amendment there argues why: subject/candidate is binary —
 * every link that is not the subject is a candidate — and a boolean is what
 * `deal_properties_one_subject` can enforce. A string column would have needed
 * an application check to say the same thing, which is the trade this schema
 * makes the other way round everywhere else.
 *
 * ## Nullable, with no default
 *
 * A seller-side deal's subject property has no buyer opinion attached to it,
 * and defaulting every row to "Interested" would put a meaningless badge on
 * every seller deal in the product. Null means *nobody has said*, which is a
 * different fact from *interested*, and F3.5 is explicitly buyer-side only.
 *
 * `StoreDealPropertyRequest` refuses an interest on a deal whose type is not
 * buy-side, so the column cannot quietly fill up with values no screen shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_properties', function (Blueprint $table): void {
            // PRD §6.3. Four values, held against the document by
            // `DocumentedVocabularyTest`.
            $table->string('interest_status')->nullable();

            /*
             * The agent's ranking, which is the feature #62 asks for by name:
             * *"`sort_order` exists so an agent can rank candidates."* Nine
             * houses need an order more than they need a fifth adjective.
             */
            $table->unsignedSmallInteger('sort_order')->default(0);

            // S20 lists one deal's properties, subject first, then by rank.
            $table->index(['deal_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('deal_properties', function (Blueprint $table): void {
            $table->dropIndex(['deal_id', 'sort_order']);
            $table->dropColumn(['interest_status', 'sort_order']);
        });
    }
};
