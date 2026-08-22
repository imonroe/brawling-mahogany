<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ContactImportState;
use App\Jobs\Concerns\RunsForTeam;
use App\Models\ContactImport;
use App\Models\TeamMembership;
use App\Support\Import\ContactParserFactory;
use App\Support\Import\ImportFailure;
use App\Support\Import\ParsedContact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Parse an uploaded contact list and work out what it would do (S33).
 *
 * Queued because *"a 2,000-row Google Contacts import cannot run in a web
 * request"*, and it ends in `awaiting_review` rather than writing anything:
 * S33 requires that somebody sees what will merge and what will be created,
 * and can change it, **before** any of it happens.
 *
 * The job carries its team explicitly (RunsForTeam). Issue #49: *"Never import
 * into another team."*
 */
class ParseContactImport implements ShouldQueue
{
    use Queueable, RunsForTeam;

    public function __construct(public readonly string $importId) {}

    public function handle(ContactParserFactory $parsers): void
    {
        $this->withinTeam(function () use ($parsers): void {
            $import = ContactImport::query()->findOrFail($this->importId);

            $import->forceFill(['state' => ContactImportState::Parsing])->save();

            try {
                $contents = Storage::get((string) $import->disk_path) ?? '';
                $parser = $parsers->for($import->source);

                $mapping = $import->column_mapping ?: $parser->suggestMapping($contents);
                $result = $parser->parse($contents, $mapping);

                $import->forceFill([
                    'state' => ContactImportState::AwaitingReview,
                    'column_mapping' => $mapping,
                    'preview' => $this->preview($result['contacts']),
                    'failures' => array_map(
                        fn (ImportFailure $failure): array => $failure->toArray(),
                        $result['failures'],
                    ),
                ])->save();
            } catch (Throwable $exception) {
                $import->forceFill([
                    'state' => ContactImportState::Failed,
                    // The class, never the message: a parse error can quote
                    // the row it choked on, and PRD §9 allows no PII in logs.
                    'failures' => [['row' => 0, 'reason' => $exception::class]],
                ])->save();

                throw $exception;
            }
        });
    }

    /**
     * Decide, for every parsed row, whether it would merge or create.
     *
     * The lookup is against **this team's** memberships, which is what makes
     * the answer honest: a person already in another team's directory is still
     * a new person to this one, and the shared `people` row is attached rather
     * than duplicated at commit time.
     *
     * @param  list<ParsedContact>  $contacts
     * @return list<array<string, mixed>>
     */
    private function preview(array $contacts): array
    {
        $emails = array_values(array_filter(array_map(
            fn (ParsedContact $contact): ?string => $contact->email === null ? null : mb_strtolower($contact->email),
            $contacts,
        )));

        $existing = $emails === [] ? [] : TeamMembership::query()
            ->join('people', 'people.id', '=', 'team_memberships.person_id')
            // Lower-cased on both sides: an address is the same address
            // whatever the export capitalised it as.
            ->whereIn(DB::raw('lower(people.email)'), $emails)
            ->pluck('people.email')
            ->map(fn (string $email): string => mb_strtolower($email))
            ->all();

        $existing = array_flip($existing);
        $seen = [];
        $rows = [];

        foreach ($contacts as $contact) {
            $key = $contact->email === null ? null : mb_strtolower($contact->email);

            $action = match (true) {
                // A file that lists the same address twice is the commonest
                // messy-CSV case, and importing it twice is the commonest bug.
                $key !== null && isset($seen[$key]) => 'skip',
                $key !== null && isset($existing[$key]) => 'merge',
                default => 'create',
            };

            if ($key !== null) {
                $seen[$key] = true;
            }

            $rows[] = [...$contact->toArray(), 'action' => $action];
        }

        return $rows;
    }
}
