<?php

declare(strict_types=1);

namespace App\Support\Templates;

/**
 * What an import did, in the words the command prints and the tests assert.
 *
 * A return value rather than console output, because {@see ImportPack} has two
 * callers that are not a console: the seeder that ships packs on deploy, and
 * the test suite. A class that wrote its own output would make the seeder
 * chatty and the tests blind.
 *
 * `notes` is the half worth having. An import that silently drops an
 * association nobody can see is the failure this codebase keeps naming —
 * *"narrowing a list in silence is not the same as narrowing it"* — so every
 * decision the importer makes on its own, and every stanza it declined to
 * honour, arrives here as a sentence somebody reads.
 */
final readonly class ImportReport
{
    /**
     * @param  list<string>  $templates  workflow template names written
     * @param  list<string>  $notes  what the importer decided on its own
     */
    public function __construct(
        public string $pack,
        public array $templates,
        public array $notes = [],
    ) {}

    public function summary(): string
    {
        $templates = count($this->templates);

        return "{$this->pack}: ".$templates.' workflow '
            .($templates === 1 ? 'template' : 'templates').'.';
    }
}
