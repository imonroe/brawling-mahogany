<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * vCard 2.1/3.0/4.0, as exported by Contacts apps (PRD F2.8).
 *
 * No mapping step: a vCard's fields are named by the format, which is the one
 * thing it has over CSV. The parts that still need care are line folding
 * (RFC 6350 wraps long lines with a leading space) and the `N` property's
 * five semicolon-separated components, of which only two matter here.
 */
final class VCardContactParser implements ContactParser
{
    /**
     * @return list<string>
     */
    public function columns(string $contents): array
    {
        return ['FN', 'N', 'EMAIL', 'TEL'];
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
        $contacts = [];
        $failures = [];
        $number = 0;

        foreach ($this->cards($contents) as $card) {
            $number++;

            $properties = $this->properties($card);

            $first = null;
            $last = null;

            if (isset($properties['N'])) {
                // N is Family;Given;Additional;Prefix;Suffix.
                $components = explode(';', $properties['N']);
                $last = $this->clean($components[0]);
                $first = $this->clean($components[1] ?? '');
            }

            if (($first === null || $first === '') && isset($properties['FN'])) {
                $parts = array_values(array_filter(preg_split('/\s+/', trim($properties['FN'])) ?: []));
                $first = $parts === [] ? null : array_shift($parts);
                $last ??= $parts === [] ? null : implode(' ', $parts);
            }

            if ($first === null || $first === '') {
                $failures[] = new ImportFailure($number, 'This card has no name.');

                continue;
            }

            $email = $properties['EMAIL'] ?? null;

            if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failures[] = new ImportFailure($number, 'The email address is not valid.');

                continue;
            }

            $contacts[] = new ParsedContact(
                row: $number,
                firstName: $first,
                lastName: $last === '' ? null : $last,
                email: $email,
                phone: $properties['TEL'] ?? null,
            );
        }

        if ($contacts === [] && $failures === []) {
            $failures[] = new ImportFailure(0, 'No vCards found in this file.');
        }

        return ['contacts' => $contacts, 'failures' => $failures];
    }

    /**
     * @return list<string>
     */
    private function cards(string $contents): array
    {
        $normalised = $this->unfold($contents);

        preg_match_all('/BEGIN:VCARD(.*?)END:VCARD/is', $normalised, $matches);

        return $matches[1];
    }

    /**
     * @return array<string, string>
     */
    private function properties(string $card): array
    {
        $properties = [];

        foreach (preg_split('/\r?\n/', $card) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);

            // TEL;TYPE=CELL and EMAIL;TYPE=INTERNET — the parameters are not
            // interesting, only the first value of each property is kept.
            $name = strtoupper(explode(';', $name)[0]);

            if (! isset($properties[$name]) && trim($value) !== '') {
                $properties[$name] = trim($value);
            }
        }

        return $properties;
    }

    private function unfold(string $contents): string
    {
        // RFC 6350 §3.2: a line break followed by a space or tab continues the
        // previous line. Unfolding first means every property is one line.
        return (string) preg_replace('/\r?\n[ \t]/', '', $contents);
    }

    private function clean(string $value): string
    {
        return trim(str_replace('\\,', ',', $value));
    }
}
