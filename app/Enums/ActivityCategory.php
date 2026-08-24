<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * The team activity feed's filter (Screen Inventory S12, issue #81).
 *
 * A filter over `event_type`, grouped by its prefix. Event types are open —
 * every slice adds a few — so the filter groups by the half before the dot
 * rather than listing the twenty-odd values — so a new `stage.reopened` lands
 * in the right group without this file changing. A new *prefix* does not:
 * `task` had to be added here when S17 (#71) started emitting `task.completed`,
 * because a prefix no category claims is what the test below fails on.
 *
 * `tests/Unit/ActivityCategoryTest.php` reads every `eventType:` literal in
 * `app/` and fails when one carries a prefix no category claims — because a
 * category set that silently drops a new event type is a filter that lies.
 *
 * IA §11: what this filters is **Activity**, never History, Log, or Feed.
 * "Contact Log" below is the one exception the vocabulary allows, because
 * IA §2 gives `activity_events (source: manual)` exactly that UI label.
 */
enum ActivityCategory: string implements HasLabel
{
    use ProvidesOptions;

    case All = 'all';
    case ContactLog = 'contact_log';
    case Deals = 'deals';
    case People = 'people';
    case Properties = 'properties';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All',
            self::ContactLog => 'Contact Log',
            self::Deals => 'Deals',
            self::People => 'People',
            self::Properties => 'Properties',
        };
    }

    /**
     * The `event_type` prefixes this category claims.
     *
     * Empty for `All`, which is the absence of a filter rather than a group.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return match ($this) {
            self::All => [],
            self::ContactLog => ['contact'],
            // A workflow, its stages, the gates on them, the moments they
            // announce, the tasks under them, and who is on the deal are all
            // one story to somebody scanning a feed.
            //
            // `task` was added with S17 (#71), and the docblock above used to
            // claim a new `task.completed` would *"land in the right group
            // without this file changing"*. It would not have: grouping by
            // prefix only helps once the prefix is claimed, and an unclaimed
            // one fails `ActivityCategoryTest` — which is the test working,
            // and the comment having promised something it could not do.
            self::Deals => ['workflow', 'stage', 'gate', 'milestone', 'participant', 'task'],
            self::People => ['person'],
            self::Properties => ['property'],
        };
    }

    /**
     * Every prefix any category claims, which is every prefix the product
     * emits once the test above passes.
     *
     * @return list<string>
     */
    public static function everyPrefix(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(fn (self $category): array => $category->prefixes(), self::cases()),
        )));
    }

    /**
     * The empty state's copy (IA §10: say what belongs here).
     */
    public function emptyMessage(): string
    {
        return match ($this) {
            self::All => 'Nothing has happened yet. Every advance, every logged call, and every person added shows up here.',
            self::ContactLog => 'No contact logged yet. Log a call, a text, or a showing and it appears here.',
            self::Deals => 'Nothing on a deal yet. Advancing a stage, completing a task, or adding a participant shows up here.',
            self::People => 'Nobody added yet. Adding or importing a person shows up here.',
            self::Properties => 'No property activity yet. Adding a property or linking one to a deal shows up here.',
        };
    }
}
