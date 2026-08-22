<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * One contact, however it arrived (PRD §4.2 F2.8).
 *
 * CSV, vCard, and Google Contacts each have their own shape and their own
 * surprises. Normalising to this one struct at the parser boundary is what
 * lets the duplicate resolution, the preview, and the commit be written once.
 */
final readonly class ParsedContact
{
    public function __construct(
        public int $row,
        public string $firstName,
        public ?string $lastName = null,
        public ?string $email = null,
        public ?string $phone = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'row' => $this->row,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            row: (int) ($data['row'] ?? 0),
            firstName: (string) ($data['first_name'] ?? ''),
            lastName: isset($data['last_name']) ? (string) $data['last_name'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
        );
    }
}
