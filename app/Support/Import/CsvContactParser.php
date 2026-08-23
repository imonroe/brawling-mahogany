<?php

declare(strict_types=1);

namespace App\Support\Import;

use Illuminate\Support\Str;

/**
 * CSV with column mapping (PRD F2.8 · Screen Inventory S33).
 *
 * The inventory warns that *"field mapping and duplicate resolution are always
 * harder than they look"*, and the mapping is where that shows: a Google
 * export says "Given Name", an Apple export says "First Name", and a
 * spreadsheet somebody typed says "first". `suggestMapping()` handles the
 * common shapes so the mapping screen starts correct and only needs
 * correcting.
 */
final class CsvContactParser implements ContactParser
{
    /**
     * Canonical field => the header names seen in the wild, normalised.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        'first_name' => ['firstname', 'first', 'givenname', 'given', 'forename'],
        'last_name' => ['lastname', 'last', 'familyname', 'family', 'surname'],
        'email' => ['email', 'emailaddress', 'email1value', 'primaryemail', 'mail'],
        'phone' => ['phone', 'phonenumber', 'mobile', 'mobilephone', 'phone1value', 'telephone', 'cell'],
        'full_name' => ['name', 'fullname', 'displayname', 'contactname'],
    ];

    /**
     * @return list<string>
     */
    public function columns(string $contents): array
    {
        $rows = $this->rows($contents);

        return $rows === [] ? [] : array_map(trim(...), $rows[0]);
    }

    /**
     * @return array<string, string>
     */
    public function suggestMapping(string $contents): array
    {
        $mapping = [];

        foreach ($this->columns($contents) as $column) {
            $normalised = $this->normalise($column);

            foreach (self::ALIASES as $field => $aliases) {
                if (in_array($normalised, $aliases, true) && ! in_array($field, $mapping, true)) {
                    $mapping[$column] = $field;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  array<string, string>  $mapping
     * @return array{contacts: list<ParsedContact>, failures: list<ImportFailure>}
     */
    public function parse(string $contents, array $mapping = []): array
    {
        $rows = $this->rows($contents);

        if ($rows === []) {
            return ['contacts' => [], 'failures' => [new ImportFailure(0, 'The file is empty.')]];
        }

        $headers = array_map(trim(...), array_shift($rows));
        $mapping = $mapping === [] ? $this->suggestMapping($contents) : $mapping;

        $index = [];

        foreach ($headers as $position => $header) {
            if (isset($mapping[$header])) {
                $index[$mapping[$header]] = $position;
            }
        }

        if (! isset($index['first_name']) && ! isset($index['full_name'])) {
            return [
                'contacts' => [],
                'failures' => [new ImportFailure(1, 'No column is mapped to a name. Map one and try again.')],
            ];
        }

        $contacts = [];
        $failures = [];

        foreach ($rows as $offset => $row) {
            // Header is row 1, so the first data row is row 2 — the number a
            // person sees in their spreadsheet.
            $number = $offset + 2;

            if ($this->isBlank($row)) {
                continue;
            }

            $contact = $this->contactFrom($row, $index, $number);

            if ($contact === null) {
                $failures[] = new ImportFailure($number, 'No name in this row.');

                continue;
            }

            if ($contact->email !== null && ! filter_var($contact->email, FILTER_VALIDATE_EMAIL)) {
                $failures[] = new ImportFailure($number, 'The email address is not valid.');

                continue;
            }

            $contacts[] = $contact;
        }

        return ['contacts' => $contacts, 'failures' => $failures];
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $index
     */
    private function contactFrom(array $row, array $index, int $number): ?ParsedContact
    {
        $value = function (string $field) use ($row, $index): ?string {
            $position = $index[$field] ?? null;

            if ($position === null) {
                return null;
            }

            $value = trim((string) ($row[$position] ?? ''));

            return $value === '' ? null : $value;
        };

        $first = $value('first_name');
        $last = $value('last_name');

        if ($first === null) {
            // A single "Name" column: split on the first space, the same way
            // the users-to-people migration did.
            $full = $value('full_name');

            if ($full === null) {
                return null;
            }

            [$first, $last] = $this->splitName($full, $last);
        }

        return new ParsedContact(
            row: $number,
            firstName: $first,
            lastName: $last,
            email: $value('email'),
            phone: $value('phone'),
        );
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitName(string $full, ?string $existingLast): array
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];

        $first = array_shift($parts) ?? '';
        $last = $existingLast ?? (count($parts) > 0 ? implode(' ', $parts) : null);

        return [$first, $last];
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $contents): array
    {
        $contents = $this->stripByteOrderMark($contents);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, $contents);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = array_map(fn (mixed $cell): string => (string) $cell, $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<string>  $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stripByteOrderMark(string $contents): string
    {
        // Excel writes one, and it silently becomes part of the first header,
        // which then matches no alias and maps nothing.
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }

    private function normalise(string $header): string
    {
        return Str::of($header)->lower()->replaceMatches('/[^a-z0-9]/', '')->value();
    }
}
