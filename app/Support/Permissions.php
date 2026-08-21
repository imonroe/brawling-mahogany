<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The permission keys the interface asks about.
 *
 * PRD §6.2 seeds permissions in code, flat, with a group and a description.
 * The roles that hold them, and the team-scoped role assignment, arrive with
 * tenancy in Slice 1 (epic #2). Until then `grantedTo()` returns every key
 * for an authenticated person, so the navigation's permission *mechanism* is
 * exercised — a section the user cannot use is hidden, never disabled
 * (IA §5.1) — without pretending a role system exists.
 */
final class Permissions
{
    public const MANAGE_TEMPLATES = 'templates.manage';

    public const MANAGE_SETTINGS = 'settings.manage';

    public const MANAGE_TEAM_MEMBERS = 'team.members.manage';

    public const VIEW_DEALS = 'deals.view';

    public const MANAGE_DEALS = 'deals.manage';

    public const VIEW_PEOPLE = 'people.view';

    public const MANAGE_PEOPLE = 'people.manage';

    public const VIEW_PROPERTIES = 'properties.view';

    public const VIEW_CALENDAR = 'calendar.view';

    public const MANAGE_NURTURE = 'nurture.manage';

    /**
     * Every permission key, in seed order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::VIEW_DEALS,
            self::MANAGE_DEALS,
            self::VIEW_PEOPLE,
            self::MANAGE_PEOPLE,
            self::VIEW_PROPERTIES,
            self::VIEW_CALENDAR,
            self::MANAGE_NURTURE,
            self::MANAGE_TEMPLATES,
            self::MANAGE_SETTINGS,
            self::MANAGE_TEAM_MEMBERS,
        ];
    }

    /**
     * The permissions a person currently holds.
     *
     * @return list<string>
     */
    public static function grantedTo(?User $user): array
    {
        return $user === null ? [] : self::all();
    }
}
