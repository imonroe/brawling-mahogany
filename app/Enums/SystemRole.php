<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;

/**
 * The five access roles (PRD §4.2 F2.2), assigned per team.
 *
 * Note the rename IA §12 records: Portal User is now **Status Viewer**.
 *
 * These are the roles the product ships and depends on by key — the 2FA
 * mandate names two of them, the super admin console names one. A team's own
 * custom roles (F2.3) are rows in `roles` with a `team_id` and no entry here,
 * because nothing in the code may depend on a key a customer invented.
 */
enum SystemRole: string implements HasLabel
{
    use ProvidesOptions;

    case SuperAdministrator = 'super_administrator';
    case TeamOwner = 'team_owner';
    case TeamMember = 'team_member';
    case StatusViewer = 'status_viewer';
    case Contact = 'contact';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Super Administrator',
            self::TeamOwner => 'Team Owner',
            self::TeamMember => 'Team Member',
            self::StatusViewer => 'Status Viewer',
            self::Contact => 'Contact',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdministrator => 'Platform operator. Cross-team lookup and impersonation, every use audited.',
            self::TeamOwner => 'Runs the team: billing, members, roles, templates, and settings.',
            self::TeamMember => 'Works deals day to day.',
            self::StatusViewer => 'A client reading their own status page. No login into the app.',
            self::Contact => 'Known to the team, with no access of any kind.',
        };
    }

    /**
     * PRD §9: *"2FA available, mandatory for Team Owner and Super
     * Administrator."* That word does the work — see
     * App\Http\Middleware\RequireTwoFactorAuthentication.
     */
    public function requiresTwoFactor(): bool
    {
        return $this === self::TeamOwner || $this === self::SuperAdministrator;
    }
}
