<?php

declare(strict_types=1);

namespace App\Support\Help;

/**
 * One page of the manual (S92 · issue #170).
 *
 * A value object rather than a model: the manual is repository content, not
 * customer data. It has no `team_id`, is identical for every install, and is
 * edited in a pull request — which is the point. A database-backed help
 * system needs an editor, an audit trail and a migration to change a sentence;
 * a Markdown file needs a diff.
 */
final readonly class HelpArticle
{
    /**
     * @param  list<array{level: int, text: string, id: string}>  $headings
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $summary,
        public string $section,
        public int $order,
        public string $html,
        public array $headings,
        /**
         * Marked **Coming later** rather than omitted.
         *
         * #170 asks for placeholders, and the reason to honour that literally
         * is that a manual with a gap teaches nothing, while a manual that
         * says *"documents arrive in a later release"* answers the question
         * somebody opened it with. It also stops the same question reaching
         * Emily by phone.
         */
        public ?string $arrivesWith = null,
    ) {}

    public function isPlanned(): bool
    {
        return $this->arrivesWith !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCard(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'arrivesWith' => $this->arrivesWith,
        ];
    }
}
