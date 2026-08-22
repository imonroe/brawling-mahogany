<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * @phpstan-type ParseResult array{contacts: list<ParsedContact>, failures: list<ImportFailure>}
 */
interface ContactParser
{
    /**
     * @param  array<string, string>  $mapping  source field => canonical field
     * @return array{contacts: list<ParsedContact>, failures: list<ImportFailure>}
     */
    public function parse(string $contents, array $mapping = []): array;

    /**
     * The columns this source offers, for the mapping step.
     *
     * @return list<string>
     */
    public function columns(string $contents): array;

    /**
     * A best guess at the mapping, so the common case needs no work.
     *
     * @return array<string, string>
     */
    public function suggestMapping(string $contents): array;
}
