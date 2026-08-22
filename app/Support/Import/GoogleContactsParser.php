<?php

declare(strict_types=1);

namespace App\Support\Import;

use Illuminate\Support\Arr;

/**
 * Google People API connections (PRD F2.8).
 *
 * Google's export is JSON from `people.connections.list`, and its shape is
 * arrays of typed values — a person has several names, several email
 * addresses, several phone numbers, each optionally flagged primary. The rule
 * here is: the primary one, or the first, and never a merge of several.
 *
 * The OAuth exchange lives in App\Http\Controllers\People\GoogleContactsController;
 * this class only reads what came back, which keeps it testable without a
 * network.
 */
final class GoogleContactsParser implements ContactParser
{
    /**
     * @return list<string>
     */
    public function columns(string $contents): array
    {
        return ['names', 'emailAddresses', 'phoneNumbers'];
    }

    /**
     * @return array<string, string>
     */
    public function suggestMapping(string $contents): array
    {
        return [];
    }

    /**
     * @param  array<string, string>  $mapping
     * @return array{contacts: list<ParsedContact>, failures: list<ImportFailure>}
     */
    public function parse(string $contents, array $mapping = []): array
    {
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return ['contacts' => [], 'failures' => [new ImportFailure(0, 'The response from Google could not be read.')]];
        }

        $connections = $decoded['connections'] ?? $decoded;

        if (! is_array($connections)) {
            return ['contacts' => [], 'failures' => [new ImportFailure(0, 'The response from Google had no contacts in it.')]];
        }

        $contacts = [];
        $failures = [];
        $number = 0;

        foreach ($connections as $connection) {
            $number++;

            if (! is_array($connection)) {
                $failures[] = new ImportFailure($number, 'This contact could not be read.');

                continue;
            }

            $name = $this->preferred($connection, 'names');
            $first = is_array($name) ? trim((string) ($name['givenName'] ?? '')) : '';
            $last = is_array($name) ? trim((string) ($name['familyName'] ?? '')) : '';

            if ($first === '' && is_array($name) && isset($name['displayName'])) {
                $parts = preg_split('/\s+/', trim((string) $name['displayName'])) ?: [];
                $first = array_shift($parts) ?? '';
                $last = $last === '' && count($parts) > 0 ? implode(' ', $parts) : $last;
            }

            if ($first === '') {
                $failures[] = new ImportFailure($number, 'This contact has no name.');

                continue;
            }

            $email = Arr::get($this->preferred($connection, 'emailAddresses') ?? [], 'value');
            $phone = Arr::get($this->preferred($connection, 'phoneNumbers') ?? [], 'value');

            $contacts[] = new ParsedContact(
                row: $number,
                firstName: $first,
                lastName: $last === '' ? null : $last,
                email: is_string($email) && $email !== '' ? $email : null,
                phone: is_string($phone) && $phone !== '' ? $phone : null,
            );
        }

        return ['contacts' => $contacts, 'failures' => $failures];
    }

    /**
     * The value Google marked primary, or the first one.
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>|null
     */
    private function preferred(array $connection, string $key): ?array
    {
        $values = $connection[$key] ?? null;

        if (! is_array($values) || $values === []) {
            return null;
        }

        foreach ($values as $value) {
            if (is_array($value) && (bool) Arr::get($value, 'metadata.primary', false)) {
                return $value;
            }
        }

        $first = $values[array_key_first($values)];

        return is_array($first) ? $first : null;
    }
}
