<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PermissionSurface;
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

    /**
     * Added in Slice 2 with S35–S37 (issue #61).
     *
     * The catalogue had `properties.view` and nothing else, which described a
     * product where properties appeared from somewhere and nobody could type
     * one in. Editing a property is not editing a deal — a house outlives the
     * transactions it appears in, and the Read Only role PRD §4.2 F2.2 exists
     * for should be able to open the directory without being able to change
     * what is in it. So it is its own key, the way People has view/manage and
     * Deals has view/manage.
     *
     * `PermissionSeeder` and `SystemRoleSeeder` are idempotent against this
     * file, so an install that already exists picks the key up on its next
     * deploy rather than needing a migration.
     */
    public const MANAGE_PROPERTIES = 'properties.manage';

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
     * The client's own status page (Slice 4, S61–S64, issue #110).
     *
     * Catalogued ahead of its screen, which is what this file's docblock says
     * the catalogue is for. It is here now because of what it *is* rather than
     * what it does yet: the first permission on a surface that is not the team
     * app, and therefore the case `PermissionSurface` exists to answer.
     *
     * **Which role holds it is #110's decision, not this one.** Status Viewer
     * still holds nothing, so nothing about a client changes today. What
     * changes is that when #110 hands it to them, the answer is already
     * decided and already tested: a Status Viewer is still not on the team,
     * still not on `/settings/members`, and still removable from the directory
     * by somebody without `team.members.manage`.
     */
    public const VIEW_STATUS_PAGE = 'status_page.view';

    /**
     * Every permission, with the group and description PRD §6.2 asks for, and
     * the surface it is a capability of (issue #142).
     *
     * The surface is not decoration and not a synonym for the group: `group`
     * is how the roles UI (#88) arranges checkboxes, and `surface` is what
     * decides whether holding the permission makes somebody a member of the
     * team. There is no entry without one — the array shape says so, and
     * `tests/Isolation/TeamAccessConventionTest.php` says so at runtime — so a
     * permission added in Slice 4 cannot quietly land on the wrong side of the
     * question the way `status_page.view` was about to.
     *
     * @return array<string, array{group: string, surface: PermissionSurface, description: string}>
     */
    public static function catalogue(): array
    {
        /**
         * Most of the catalogue is the team app, so the exceptions are what
         * the reader should have to notice. Spelling out `PermissionSurface::Team`
         * twenty-one times would bury the two entries that are not it.
         *
         * @return array{group: string, surface: PermissionSurface, description: string}
         */
        $team = fn (string $group, string $description): array => [
            'group' => $group,
            'surface' => PermissionSurface::Team,
            'description' => $description,
        ];

        return [
            self::VIEW_DEALS => $team('Deals', 'See the team’s deals.'),
            self::MANAGE_DEALS => $team('Deals', 'Create, edit, and close deals.'),
            self::ADVANCE_WORKFLOW => $team('Deals', 'Advance a workflow to its next stage.'),
            self::OVERRIDE_GATE => $team('Deals', 'Override an unmet gate, with a reason and an audit entry.'),
            self::SKIP_STAGE => $team('Deals', 'Mark a stage not applicable.'),

            self::VIEW_PEOPLE => $team('People', 'See the people directory.'),
            self::MANAGE_PEOPLE => $team('People', 'Add and edit people, and log contact.'),
            self::IMPORT_PEOPLE => $team('People', 'Import contacts from a file or Google.'),

            self::VIEW_PROPERTIES => $team('Properties', 'See the team’s properties.'),
            self::MANAGE_PROPERTIES => $team('Properties', 'Add and edit properties, and link them to deals.'),
            self::VIEW_CALENDAR => $team('Calendar', 'See the team calendar.'),
            self::MANAGE_NURTURE => $team('Keep in Touch', 'Manage post-close schedules and suggestions.'),

            self::MANAGE_TEMPLATES => $team('Templates', 'Create and edit workflow templates and packs.'),
            self::APPROVE_MESSAGE => $team('Automation', 'Approve a message before it reaches a client.'),

            self::VIEW_RESTRICTED_DOCUMENT => $team('Documents', 'Open a document in a restricted category.'),
            self::CONFIRM_EXTRACTION => $team('Documents', 'Confirm an extracted date or task into the record.'),

            self::MANAGE_SETTINGS => $team('Team', 'Edit team profile, branding, and sending identity.'),
            self::MANAGE_TEAM_MEMBERS => $team('Team', 'Invite, revoke, and manage team members.'),
            self::MANAGE_ROLES => $team('Team', 'Compose roles from the permission set.'),
            self::EXPORT_TEAM_DATA => $team('Team', 'Export the team’s own data.'),
            self::VIEW_AUDIT_LOG => $team('Team', 'Read the team’s audit log.'),

            /*
             * The client surface, and the reason `surface` exists at all.
             *
             * Reading your own status page is not being on the team, so this
             * one is deliberately *not* $team(). Slice 4 (#110) will hand it
             * to Status Viewer; the members screen, the People index's Team
             * segment, and TeamMembership::carriesAccess() will all go on
             * saying no, because all three ask the surface rather than
             * counting permissions.
             */
            self::VIEW_STATUS_PAGE => [
                'group' => 'Status Page',
                'surface' => PermissionSurface::Client,
                'description' => 'Read a deal’s status page through a magic link.',
            ],

            /*
             * The console, above the tenant boundary (ADR 0002). Not team
             * access either: a platform administrator reaches a team through
             * audited impersonation, not through a seat on it, and
             * Role::assignableWithinTeam() keeps a team from handing it out.
             */
            self::IMPERSONATE => [
                'group' => 'Platform',
                'surface' => PermissionSurface::Platform,
                'description' => 'Act as a person inside a team, with a logged reason.',
            ],
            self::ADMINISTER_PLATFORM => [
                'group' => 'Platform',
                'surface' => PermissionSurface::Platform,
                'description' => 'Reach the super admin console.',
            ],
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
     * Every permission on one surface (issue #142).
     *
     * @return list<string>
     */
    public static function onSurface(PermissionSurface $surface): array
    {
        return array_keys(array_filter(
            self::catalogue(),
            fn (array $entry): bool => $entry['surface'] === $surface,
        ));
    }

    /**
     * The keys that mean "works here" rather than "is known here".
     *
     * This is the one definition of team access in the product. Everything
     * asks it through one of two doors: `TeamMembership::carriesAccess()` for
     * a loaded row, and `TeamMembership::scopeCarryingAccess()` in SQL — which
     * the members screen (S74), the People index's Team and Clients segments
     * (S30), the console's team detail, and the team switcher all go through.
     * None of them keeps a list of role keys of its own.
     *
     * That is the whole point of #142: three of those queries did, and a team
     * composing its own role (PRD F2.3) got somebody who could act in the
     * team, appeared on none of those lists, and therefore could not be
     * revoked from the screen that revokes people.
     *
     * @return list<string>
     */
    public static function teamSurfaceKeys(): array
    {
        return self::onSurface(PermissionSurface::Team);
    }

    /**
     * Does holding these permissions put somebody on the team?
     *
     * @param  list<string>  $permissionKeys
     */
    public static function grantTeamAccess(array $permissionKeys): bool
    {
        return array_intersect($permissionKeys, self::teamSurfaceKeys()) !== [];
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
     * **A role's list here no longer decides whether it is a team role.** That
     * used to follow from the list being empty, which is why Status Viewer
     * gaining `status_page.view` in #110 would have turned every client into a
     * member (#142). What decides it is the *surface* of what the list holds,
     * so #110 may hand Status Viewer the key without touching anything else,
     * and `tests/Isolation/TeamAccessConventionTest.php` records the decision
     * for every role in this list.
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
            self::MANAGE_PROPERTIES,
            self::VIEW_CALENDAR,
            self::MANAGE_NURTURE,
            /*
             * Moved down from the owner's list in Slice 5 (#116), and the
             * reason is who does the job.
             *
             * It was placed with the owner's permissions before the screen
             * existed, on the sound instinct that accepting a model's date
             * into a contingency calendar has legal consequence. Screen
             * Inventory then settles who is standing there: S66's user column
             * is **TC** — Heather — and PRD §5.3 walks her through uploading
             * the contract and confirming eleven dates. A default that put the
             * one control on the person who is not at the screen would make
             * the flagship feature unusable by the person it was specified
             * for, and the workaround would be making every coordinator an
             * owner, which is worse for everything else.
             *
             * What it keeps is the ability to say otherwise. This stays a
             * permission of its own rather than folding into `deals.manage`,
             * so a team that wants confirmation to sit with one person composes
             * a role that says so (S75). Starting an extraction is separately
             * `deals.manage`, so a read-only role still cannot spend the
             * team's money on one.
             */
            self::CONFIRM_EXTRACTION,
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
            /*
             * Slice 4 (#110) hands them the one permission on their own
             * surface, which is what `VIEW_STATUS_PAGE`'s docblock said this
             * issue would decide.
             *
             * **Nothing about team membership changes.** `PermissionSurface::
             * Client` is what decides that, not the count of permissions, so a
             * Status Viewer is still not on `/settings/members`, still not in
             * the People index's Team segment, and still removable by somebody
             * without `team.members.manage`. `TeamAccessConventionTest` holds
             * that, and it held it before this line existed — which is the
             * whole reason the surface column was added ahead of the role
             * needing it.
             */
            SystemRole::StatusViewer->value => [self::VIEW_STATUS_PAGE],
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
