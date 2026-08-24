<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * Where somebody is in the create-deal wizard (S14 · PRD §5.2 · issue #74).
 *
 * Four steps, in the order the Screen Inventory names them: *"type, client,
 * property, template."* PRD §5.2 walks the same path and adds a fifth line —
 * *"Initial Consultation activates"* — which is not a step somebody takes.
 * Instantiating the workflow activates the first stage (#66), so a fifth
 * screen would be a screen that asks nothing.
 *
 * The order is not arbitrary and cannot be shuffled: the deal type decides
 * which participant role the client takes (`DealRoster::expectedRoles()`) and
 * which workflow templates are offered (`deal_type_workflow_template`), so it
 * has to come first.
 */
enum DealDraftStep: string implements HasLabel
{
    use ProvidesOptions;

    case Type = 'type';
    case Client = 'client';
    case Property = 'property';
    case Template = 'template';

    public function label(): string
    {
        return match ($this) {
            self::Type => 'Deal type',
            self::Client => 'Client',
            self::Property => 'Property',
            self::Template => 'Workflow',
        };
    }

    /** The step after this one, or null at the end. */
    public function next(): ?self
    {
        return match ($this) {
            self::Type => self::Client,
            self::Client => self::Property,
            self::Property => self::Template,
            self::Template => null,
        };
    }

    /** 1-based, for "Step 2 of 4". */
    public function position(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }
}
