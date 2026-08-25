<?php

declare(strict_types=1);

use App\Enums\DocumentCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything a customer uploads (PRD §4.6 F6.4–F6.6, §7.14 · S38 · #63).
 *
 * ## One table, with a category
 *
 * PRD §7.14: *"`Photos` should be the general document table with a
 * category."* A gallery image is a `document` with category `photo` and a
 * `sort_order`, not a second table — which is what lets Slice 3's document
 * module, its categories and its guardrails sit on this rather than beside it.
 *
 * ## The key reveals nothing
 *
 * F6.4 asks that a leaked key say nothing: no
 * `team-3/123-main-st/sellers-bank-statement.pdf`. So `path` is opaque — a
 * team-scoped directory of random identifiers — and the name a person typed
 * lives in `original_name`, which is served from the database by an authorized
 * controller and never by the object store.
 *
 * ## Polymorphic, and narrow on purpose
 *
 * `documentable` is a morph so Slice 3 can hang documents off a deal without
 * another table. **In this slice only a property may have one**, enforced in
 * the application rather than here — see `DocumentCategory` and #63's residual
 * window note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->productDefaults();

            $table->string('documentable_type');
            $table->ulid('documentable_id');

            $table->string('category')->default(DocumentCategory::Photo->value);

            /*
             * Which disk, stored per row rather than read from config at read
             * time. A team's files do not move when somebody changes an
             * environment variable, and a row that does not say where it lives
             * is a row nobody can find after the default changes.
             */
            $table->string('disk');
            $table->string('path');

            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');

            $table->string('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);

            $table->foreignUlid('uploaded_by')->nullable()->constrained('people')->nullOnDelete();

            $table->index(['documentable_type', 'documentable_id', 'sort_order']);
        });

        DB::statement(sprintf(
            "ALTER TABLE documents ADD CONSTRAINT documents_category_check CHECK (category IN ('%s'))",
            implode("','", array_column(DocumentCategory::cases(), 'value')),
        ));

        /*
         * At most one primary per subject — the same backstop
         * `deal_properties` puts under its subject flag. A property with two
         * primary photos is a property whose card has two answers.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX documents_one_primary
                ON documents (documentable_type, documentable_id)
                WHERE is_primary AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
