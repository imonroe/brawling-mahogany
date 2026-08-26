<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Property;
use App\Models\Stage;
use App\Models\TeamMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * S50 — every document the team holds, in one place (PRD §4.6 F6.1 · #98).
 *
 * The deal tab answers *"what is on this deal"*; this answers *"where is that
 * disclosure"*, which is a different question asked from a standing start. So
 * it is filtered rather than grouped — by category, by visibility, by deal.
 *
 * ## Storage used is a fact, not a quota
 *
 * Screen Inventory lists it as a state, and it is deliberately **reported
 * rather than enforced**. There is no plan tier to exceed and no behaviour
 * that changes at a threshold, so a progress bar toward an invented limit
 * would be a lie about how the product works. `SUM(size_bytes)` answers *"are
 * we accumulating something"* without implying a consequence that does not
 * exist.
 *
 * It counts **live** rows only, which the global scope gives for free: a
 * soft-deleted document is inside PRD §9's thirty-day window with its bytes
 * already gone — `DocumentStorage::remove()` deletes the file immediately —
 * so counting it would report storage nobody is using.
 */
class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $filters = $request->validate([
            'category' => ['nullable', Rule::enum(DocumentCategory::class)],
            'visibility' => ['nullable', Rule::enum(DocumentVisibility::class)],
            'deal' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $documents = $this->filtered($filters)
            ->where($this->readable(...))
            ->latest('created_at')
            /*
             * `created_at` is `timestamp(0)`, so a bulk upload shares a second
             * and Postgres returns heap order within it. The same tiebreaker
             * S47's queue lists already paid for.
             */
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        /*
         * Resolved in two queries rather than one per row. `subject()` did a
         * `find()` per document, so a full page of 25 was 25 extra round trips
         * — round 1 of review measured the page at 95 queries. A `MorphTo`
         * eager load groups by type and fetches each set once.
         */
        $subjects = $this->subjectsFor($documents->items());
        $uploaders = $this->uploadersFor($documents->items());

        return Inertia::render('Documents/Index', [
            'documents' => collect($documents->items())
                ->map(fn (Document $document): array => $this->row($document, $subjects, $uploaders))
                ->values()
                ->all(),
            'total' => $documents->total(),
            'filters' => [
                'category' => $filters['category'] ?? null,
                'visibility' => $filters['visibility'] ?? null,
                'deal' => $filters['deal'] ?? null,
                'q' => $filters['q'] ?? null,
            ],
            'categories' => DocumentCategory::options(),
            'visibilities' => DocumentVisibility::options(),
            /*
             * Only deals that actually have documents. A picker offering every
             * deal the team has ever run would be mostly empty answers, and
             * the question this screen gets asked is "which deal was that on".
             */
            'deals' => $this->dealsWithDocuments(),
            /*
             * Scoped by the same rule as the rows. An unscoped total is a side
             * channel: it reports the size of documents the same request has
             * just refused to name, which is the leak in a quieter voice.
             */
            'storageUsed' => (int) Document::query()->where($this->readable(...))->sum('size_bytes'),
        ]);
    }

    /**
     * S52 — one document, and what can be done about it (F6.4 · #98).
     *
     * ## The preview is decided by the stored type, and refuses to guess
     *
     * `mime_type` is derived from the bytes by `finfo` at upload, so it is
     * true of the file rather than a claim the browser made — which is what
     * makes it safe to decide a preview from. An image previews, a PDF
     * previews in an object frame, and everything else says plainly that it
     * cannot be shown here and offers the download. **Unsupported is a state
     * the screen has**, not a blank box somebody stares at.
     *
     * The preview loads through `deals.documents.show`, which authorizes and
     * audits every read (PRD §9), so a rendered preview is an access with an
     * entry behind it exactly like a download.
     */
    public function show(Document $document, DocumentStorage $storage): Response
    {
        $this->authorize('view', $document);

        $subject = $this->subject($document);

        return Inertia::render('Documents/Show', [
            'document' => [
                ...$this->row($document),
                'mimeType' => $document->mime_type,
                'missing' => ! $storage->exists($document),
            ],
            'downloadUrl' => $this->downloadUrl($document),
            'subjectUrl' => $subject['url'],
            'visibilities' => DocumentVisibility::options(),
            'can' => ['update' => request()->user()?->can('update', $document) ?? false],
        ]);
    }

    /**
     * F6.3's toggle, and the only thing on this screen that writes.
     *
     * Audited, because making a document client-visible is a **disclosure
     * decision**: it is the moment somebody outside the team can read a
     * seller's inspection report, and PRD §9 wants the record of who decided
     * that. The reverse direction is audited too — "who un-shared this, and
     * when" is the same question asked after the fact.
     */
    public function updateVisibility(
        Request $request,
        Document $document,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->authorize('update', $document);

        $validated = $request->validate([
            'visibility' => ['required', Rule::enum(DocumentVisibility::class)],
        ]);

        $document->forceFill(['visibility' => $validated['visibility']])->save();

        $audit->recordChange('document.visibility_changed', $document);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Visibility updated.')]);

        return back(fallback: route('documents.show', $document));
    }

    /**
     * The audited read, which lives on the deal route because that is where
     * the authorization is nested.
     *
     * A property's photograph goes through the gallery's own download, for the
     * same reason: each route authorizes the subject it hangs off, and a
     * second path to the bytes would be a second place the rule could drift.
     */
    private function downloadUrl(Document $document): ?string
    {
        if ($document->documentable_type === (new Deal)->getMorphClass()) {
            return '/deals/'.$document->documentable_id.'/documents/'.$document->getKey();
        }

        if ($document->documentable_type === (new Property)->getMorphClass()) {
            return '/properties/'.$document->documentable_id.'/photos/'.$document->getKey();
        }

        return null;
    }

    /**
     * Only the subjects this person may see (round 1 of review, blocker 3).
     *
     * `viewAny` is deliberately the **wider** of the two subject permissions,
     * and the justification written beside it was *"each row is still
     * authorized on its way out"*. It was not: `index()` mapped rows straight
     * out of the query, so a role holding `deals.view` without
     * `properties.view` was shown a property document's filename, size,
     * uploader — and the property's address in `subjectLabel`.
     *
     * A claim in a docblock is not a mechanism. This is the mechanism, and it
     * is in the **query** rather than a filter over the results: filtering
     * after pagination would report a total the page does not contain, and
     * "25 documents" over 19 rows is its own kind of leak.
     *
     * `whereRaw('false')` rather than an empty result set by luck: a person
     * with neither permission cannot reach `viewAny` at all, so this arm is
     * unreachable today — and an unreachable arm that returned *everything*
     * is the failure this method exists to prevent.
     *
     * @param  Builder<Document>  $query
     */
    private function readable(Builder $query): void
    {
        $person = request()->user();

        $subjects = [];

        if ($person?->can('viewDeals', Document::class) ?? false) {
            $subjects[] = (new Deal)->getMorphClass();
            $subjects[] = (new Stage)->getMorphClass();
        }

        if ($person?->can('viewProperties', Document::class) ?? false) {
            $subjects[] = (new Property)->getMorphClass();
        }

        if ($subjects === []) {
            $query->whereRaw('false');

            return;
        }

        $query->whereIn('documentable_type', $subjects);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Document>
     */
    private function filtered(array $filters): Builder
    {
        return Document::query()
            ->when(
                is_string($filters['category'] ?? null),
                fn (Builder $query) => $query->where('category', $filters['category']),
            )
            ->when(
                is_string($filters['visibility'] ?? null),
                fn (Builder $query) => $query->where('visibility', $filters['visibility']),
            )
            ->when(
                is_string($filters['deal'] ?? null),
                fn (Builder $query) => $query
                    ->where('documentable_type', (new Deal)->getMorphClass())
                    ->where('documentable_id', $filters['deal']),
            )
            ->when(
                is_string($filters['q'] ?? null) && trim((string) $filters['q']) !== '',
                function (Builder $query) use ($filters): void {
                    /*
                     * The filename and the caption — everything a person can
                     * actually recall about a file. **Not the contents**:
                     * nothing indexes them, and a search that silently covered
                     * less than it appeared to would teach somebody the
                     * document is not there.
                     */
                    $term = '%'.trim((string) $filters['q']).'%';

                    $query->where(function (Builder $inner) use ($term): void {
                        $inner->where('original_name', 'ilike', $term)
                            ->orWhere('caption', 'ilike', $term);
                    });
                },
            );
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function dealsWithDocuments(): array
    {
        /*
         * Scoped like the rows. Round 2 of review proved the leak with a
         * fixture: a role holding only `properties.view` got a deal's
         * `displayName()` — its address — in the filter picker while the row
         * itself was correctly withheld. A filter is a read.
         */
        $ids = Document::query()
            ->where($this->readable(...))
            ->where('documentable_type', (new Deal)->getMorphClass())
            ->distinct()
            ->pluck('documentable_id')
            ->all();

        $options = [];

        foreach (Deal::query()->whereIn('id', $ids)->get() as $deal) {
            $options[] = [
                'id' => (string) $deal->getKey(),
                'label' => $deal->displayName(),
            ];
        }

        /*
         * Sorted in PHP because `displayName()` is derived — IA §10's typed
         * name wins over the generated one, and no column holds the answer.
         * `usort` rather than a collection so the list stays a list.
         */
        usort($options, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * Every subject the page needs, one query per morph type.
     *
     * @param  array<int, Document>  $documents
     * @return array<string, array{label: string, url: string|null}>
     */
    private function subjectsFor(array $documents): array
    {
        $resolved = [];

        foreach ([Deal::class, Property::class] as $model) {
            $type = (new $model)->getMorphClass();

            $ids = [];

            foreach ($documents as $document) {
                if ($document->documentable_type === $type) {
                    $ids[] = $document->documentable_id;
                }
            }

            if ($ids === []) {
                continue;
            }

            foreach ($model::query()->whereIn('id', array_unique($ids))->get() as $subject) {
                $resolved[$type.':'.$subject->getKey()] = [
                    'label' => $subject->displayName(),
                    'url' => $model === Deal::class
                        ? '/deals/'.$subject->getKey().'/documents'
                        : '/properties/'.$subject->getKey(),
                ];
            }
        }

        return $resolved;
    }

    /**
     * Who uploaded each document, in one query rather than two per row.
     *
     * `Person::displayNameWithin()` calls `membershipIn()`, which always
     * queries — it is not relation-aware, and changing that would touch every
     * caller in the product. So the batch lives here, where the N is.
     *
     * IA §11: a name is something a **team** recorded, so it comes off the
     * membership rather than off `people`. Every document on this page belongs
     * to the resolved team, which is what makes one query enough.
     *
     * @param  array<int, Document>  $documents
     * @return array<string, string>
     */
    private function uploadersFor(array $documents): array
    {
        $ids = [];

        foreach ($documents as $document) {
            if (is_string($document->uploaded_by)) {
                $ids[] = $document->uploaded_by;
            }
        }

        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (
            TeamMembership::query()
                ->whereIn('person_id', array_unique($ids))
                ->whereNull('revoked_at')
                ->get() as $membership
        ) {
            $names[(string) $membership->person_id] = $membership->fullName();
        }

        return $names;
    }

    /**
     * @param  array<string, array{label: string, url: string|null}>  $subjects
     * @param  array<string, string>  $uploaders
     * @return array<string, mixed>
     */
    private function row(Document $document, array $subjects = [], array $uploaders = []): array
    {
        $subject = $subjects[$document->documentable_type.':'.$document->documentable_id]
            ?? $this->subject($document);

        return [
            'id' => $document->getKey(),
            'name' => $document->original_name,
            'caption' => $document->caption,
            'category' => $document->category->value,
            'categoryLabel' => $document->category->label(),
            'visibility' => $document->visibility->value,
            'sizeBytes' => $document->size_bytes,
            'uploadedAt' => $document->created_at?->toIso8601String(),
            'uploadedBy' => $uploaders[(string) $document->uploaded_by] ?? null,
            'scanState' => $document->scan_state,
            /*
             * Where it hangs, and the way back to it. A row with no route to
             * its own subject is why this screen filters by deal rather than
             * only listing.
             */
            'subjectLabel' => $subject['label'],
            'subjectUrl' => $subject['url'],
        ];
    }

    /**
     * @return array{label: string, url: string|null}
     */
    private function subject(Document $document): array
    {
        if ($document->documentable_type === (new Deal)->getMorphClass()) {
            $deal = Deal::query()->find($document->documentable_id);

            return [
                'label' => $deal?->displayName() ?? 'A deal that is no longer here',
                'url' => $deal === null ? null : '/deals/'.$deal->getKey().'/documents',
            ];
        }

        if ($document->documentable_type === (new Property)->getMorphClass()) {
            $property = Property::query()->find($document->documentable_id);

            return [
                'label' => $property?->displayName() ?? 'A property that is no longer here',
                'url' => $property === null ? null : '/properties/'.$property->getKey(),
            ];
        }

        return ['label' => 'Elsewhere', 'url' => null];
    }
}
