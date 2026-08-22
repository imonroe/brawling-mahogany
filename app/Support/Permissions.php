<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SystemRole;
use App\Models\Person;
use App\Support\Tenancy\TeamContext;

/**
 * The permission catalogue (PRD §6.2, §9 Authorization).
 *
 * *"Flat, seeded in code."* Every capability the product exposes has a key
 * here, including the ones whose screens do not exist yet — `workflow.advance`
 * and `message.approve` land in Slices 2 and 3, but a role composed today has
 * to be able to hold them, and a permission invented later is a permission
 * nobody's existing roles have.
 *
 * The seeder (Database\Seeders\PermissionSeeder) is idempotent against this
 * list: it inserts what is missing, updates what changed, and deletes what is
 * gone. This file is the authority, not the table.
 */
final class Permissions
{
    public const VIEW_DEALS = 'deals.view';

    public const MANAGE_DEALS = 'deals.manage';

    public const ADVANCE_WORKFLOW = 'workflow.advance';

    public const OVERRIDE_GATE = 'workflow.override';

    public const SKIP_STAGE = 'stage.skip';

    public const VIEW_PEOPLE = 'people.view';

    public const MANAGE_PEOPLE = 'people.manage';

    public const IMPORT_PEOPLE = 'people.import';

    public const VIEW_PROPERTIES = 'properties.view';

    public const VIEW_CALENDAR = 'calendar.view';

    public const MANAGE_NURTURE = 'nurture.manage';

    public const MANAGE_TEMPLATES = 'templates.manage';

    public const APPROVE_MESSAGE = 'message.approve';

    public const VIEW_RESTRICTED_DOCUMENT = 'document.view_restricted';

    public const CONFIRM_EXTRACTION = 'extraction.confirm';

    public const MANAGE_SETTINGS = 'settings.manage';

    public const MANAGE_TEAM_MEMBERS = 'team.members.manage';

    public const MANAGE_ROLES = 'team.roles.manage';

    public const EXPORT_TEAM_DATA = 'team.export';

    public const VIEW_AUDIT_LOG = 'team.audit.view';

    public const IMPERSONATE = 'team.impersonate';

    public const ADMINISTER_PLATFORM = 'platform.administer';

    /**
     * Every permission, with the group and description PRD §6.2 asks for.
     *
     * @return array<string, array{group: string, description: string}>
     */
    public static function catalogue(): array
    {
        return [
            self::VIEW_DEALS => ['group' => 'Deals', 'description' => 'See the team’s deals.'],
            self::MANAGE_DEALS => ['group' => 'Deals', 'description' => 'Create, edit, and close deals.'],
            self::ADVANCE_WORKFLOW => ['group' => 'Deals', 'description' => 'Advance a workflow to its next stage.'],
            self::OVERRIDE_GATE => ['group' => 'Deals', 'description' => 'Override an unmet gate, with a reason and an audit entry.'],
            self::SKIP_STAGE => ['group' => 'Deals', 'description' => 'Mark a stage not applicable.'],

            self::VIEW_PEOPLE => ['group' => 'People', 'description' => 'See the people directory.'],
            self::MANAGE_PEOPLE => ['group' => 'People', 'description' => 'Add and edit people, and log contact.'],
            self::IMPORT_PEOPLE => ['group' => 'People', 'description' => 'Import contacts from a file or Google.'],

            self::VIEW_PROPERTIES => ['group' => 'Properties', 'description' => 'See the team’s properties.'],
            self::VIEW_CALENDAR => ['group' => 'Calendar', 'description' => 'See the team calendar.'],
            self::MANAGE_NURTURE => ['group' => 'Keep in Touch', 'description' => 'Manage post-close schedules and suggestions.'],

            self::MANAGE_TEMPLATES => ['group' => 'Templates', 'description' => 'Create and edit workflow templates and packs.'],
            self::APPROVE_MESSAGE => ['group' => 'Automation', 'description' => 'Approve a message before it reaches a client.'],

            self::VIEW_RESTRICTED_DOCUMENT => ['group' => 'Documents', 'description' => 'Open a document in a restricted category.'],
            self::CONFIRM_EXTRACTION => ['group' => 'Documents', 'description' => 'Confirm an extracted date or task into the record.'],

            self::MANAGE_SETTINGS => ['group' => 'Team', 'description' => 'Edit team profile, branding, and sending identity.'],
            self::MANAGE_TEAM_MEMBERS => ['group' => 'Team', 'description' => 'Invite, revoke, and manage team members.'],
            self::MANAGE_ROLES => ['group' => 'Team', 'description' => 'Compose roles from the permission set.'],
            self::EXPORT_TEAM_DATA => ['group' => 'Team', 'description' => 'Export the team’s own data.'],
            self::VIEW_AUDIT_LOG => ['group' => 'Team', 'description' => 'Read the team’s audit log.'],

            self::IMPERSONATE => ['group' => 'Platform', 'description' => 'Act as a person inside a team, with a logged reason.'],
            self::ADMINISTER_PLATFORM => ['group' => 'Platform', 'description' => 'Reach the super admin console.'],
        ];
    }

    /**
     * Every permission key, in seed order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::catalogue());
    }

    /**
     * The permissions each shipped role holds.
     *
     * PRD §9 is deny by default, so a role's list is exhaustive: anything
     * absent is refused. Status Viewer and Contact hold nothing at all —
     * a Status Viewer reads one status page through a magic link (Slice 4) and
     * has no access to the application, and a Contact has no access of any
     * kind.
     *
     * @return array<string, list<string>>
     */
    public static function forSystemRoles(): array
    {
        $teamMember = [
            self::VIEW_DEALS,
            self::MANAGE_DEALS,
            self::ADVANCE_WORKFLOW,
            self::VIEW_PEOPLE,
            self::MANAGE_PEOPLE,
            self::IMPORT_PEOPLE,
            self::VIEW_PROPERTIES,
            self::VIEW_CALENDAR,
            self::MANAGE_NURTURE,
        ];

        $teamOwner = [
            ...$teamMember,
            // Everything above, plus what separates running the team from
            // working in it.
            self::OVERRIDE_GATE,
            self::SKIP_STAGE,
            self::MANAGE_TEMPLATES,
            self::APPROVE_MESSAGE,
            self::VIEW_RESTRICTED_DOCUMENT,
            self::CONFIRM_EXTRACTION,
            self::MANAGE_SETTINGS,
            self::MANAGE_TEAM_MEMBERS,
            self::MANAGE_ROLES,
            self::EXPORT_TEAM_DATA,
            self::VIEW_AUDIT_LOG,
        ];

        return [
            SystemRole::SuperAdministrator->value => [self::ADMINISTER_PLATFORM, self::IMPERSONATE],
            SystemRole::TeamOwner->value => $teamOwner,
            SystemRole::TeamMember->value => $teamMember,
            SystemRole::StatusViewer->value => [],
            SystemRole::Contact->value => [],
        ];
    }

    /**
     * The permissions a person holds in the resolved team.
     *
     * Returns nothing when no team is resolved — which is the correct answer
     * on the sign-in screen, and the correct answer for a person whose only
     * membership has been revoked.
     *
     * @return list<string>
     */
    public static function grantedTo(?Person $person): array
    {
        if (! $person instanceof Person) {
            return [];
        }

        $team = app(TeamContext::class)->get();

        if ($team === null) {
            return [];
        }

        $membership = $person->membershipIn($team);

        if ($membership === null) {
            return [];
        }

        $membership->loadMissing('roles.permissions');

        return $membership->permissionKeys();
    }
}
