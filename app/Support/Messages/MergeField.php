<?php

declare(strict_types=1);

namespace App\Support\Messages;

/**
 * One merge field, as the editor and the validator both see it (F5.6).
 *
 * Metadata only. What a field *resolves to* lives in {@see MergeFields::resolve()}
 * so the substitution for a whole message is one readable pass rather than
 * twelve closures — and `tests/Unit/Messages/MergeFieldsTest.php` holds the two
 * halves together by asserting that every registered field resolves and every
 * resolved value is registered.
 */
final readonly class MergeField
{
    public function __construct(
        public string $token,
        public string $label,
        public string $group,
        public string $description,
        /**
         * Whether the value may contain line breaks.
         *
         * The HTML render needs to know: an escaped signature block with three
         * lines renders as one run-on line without `<br>`, and inserting
         * `<br>` into every value would corrupt the ones that are addresses on
         * a single line.
         */
        public bool $multiline = false,
        /**
         * Why this field cannot resolve yet, or null when it can.
         *
         * F5.6 names key dates and the status page link, and neither exists
         * before Slice 4. They are registered rather than omitted so the
         * editor can say *which slice* rather than "unknown field" — and the
         * validator refuses them by name, because an unresolved merge field in
         * a client email is exactly what "validated at save time" exists to
         * prevent.
         */
        public ?string $availableFrom = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->availableFrom === null;
    }

    /** What an author types into the body. */
    public function placeholder(): string
    {
        return '{{ '.$this->token.' }}';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'label' => $this->label,
            'group' => $this->group,
            'description' => $this->description,
            'placeholder' => $this->placeholder(),
            'isAvailable' => $this->isAvailable(),
            'availableFrom' => $this->availableFrom,
        ];
    }
}
