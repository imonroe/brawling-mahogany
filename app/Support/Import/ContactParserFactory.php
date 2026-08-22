<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Enums\ContactImportSource;

/** One place that knows which parser reads which source. */
final class ContactParserFactory
{
    public function for(ContactImportSource $source): ContactParser
    {
        return match ($source) {
            ContactImportSource::Csv => new CsvContactParser,
            ContactImportSource::VCard => new VCardContactParser,
            ContactImportSource::GoogleContacts => new GoogleContactsParser,
        };
    }
}
