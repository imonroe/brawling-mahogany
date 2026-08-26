<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Property;
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
            ->with('uploader')
            ->latest('created_at')
            /*
             * `created_at` is `timestamp(0)`, so a bulk upload shares a second
             * and Postgres returns heap order within it. The same tiebreaker
             * S47's queue lists already paid for.
             */
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Documents/Index', [
            'documents' => collect($documents->items())->map($this->row(...))->values()->all(),
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
            'storageUsed' => (int) Document::query()->sum('size_bytes'),
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
        $ids = Document::query()
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
     * @return array<string, mixed>
     */
    private function row(Document $document): array
    {
        $subject = $this->subject($document);

        return [
            'id' => $document->getKey(),
            'name' => $document->original_name,
            'caption' => $document->caption,
            'category' => $document->category->value,
            'categoryLabel' => $document->category->label(),
            'visibility' => $document->visibility->value,
            'sizeBytes' => $document->size_bytes,
            'uploadedAt' => $document->created_at?->toIso8601String(),
            'uploadedBy' => $document->uploader?->displayNameWithin($document->team),
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
