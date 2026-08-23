<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which surface a permission is a capability *of* (issue #142).
 *
 * The product has three of them, and they are not tiers of the same thing —
 * they are separate applications that happen to share a database.
 *
 * Slice 1 asked "does this membership let somebody act in the team?" by asking
 * whether their roles carried **any** permission at all. That worked only
 * while every permission in the catalogue happened to be a team-app one. The
 * moment Slice 4 gives Status Viewer `status_page.view` (#110), every client
 * in every directory becomes somebody with "access", removing one becomes an
 * access operation needing `team.members.manage`, and tidying up the directory
 * stops working for the person whose job it is.
 *
 * So the question is asked of the **permission**, not of the role.
 *
 * ## Why here and not a flag on `roles`
 *
 * `roles` is customer data: PRD F2.3 lets a team owner compose their own, and
 * #88 gives them a screen for it. A security-relevant flag on a customer-
 * writable row needs a default, and either default is wrong for half the roles
 * somebody will compose — a `false` default silently recreates the exact bug
 * this issue is about (a role with real permissions that appears on no members
 * screen and therefore cannot be revoked), and a `true` default hands app
 * access to the first client-facing role anybody builds.
 *
 * The permission catalogue is **product** data: flat, finite, seeded in code
 * (`App\Support\Permissions`), and never written by a customer. Classifying it
 * is a decision the build can force — `Permissions::catalogue()`'s shape has no
 * entry without a surface — and one no customer can get wrong. A composed role
 * then inherits its answer from what it is made of, which is the property that
 * makes "a list of role keys to maintain" unnecessary in the first place.
 */
enum PermissionSurface: string
{
    /**
     * The team application: deals, people, properties, settings.
     *
     * Holding one of these is what "is on the team" means. It is the test
     * `TeamMembership::carriesAccess()` applies, the test the members screen
     * (S74) applies, and the test the People index's Team segment (S30)
     * applies — one rule, three callers, none of them holding its own list.
     */
    case Team = 'team';

    /**
     * The client-facing status page (Slice 4, #110).
     *
     * Read-only, reached by magic link, no sign-in into the team app. A person
     * holding nothing but these is a **contact** as far as every team screen is
     * concerned, and removing them from the directory stays an ordinary
     * directory edit rather than becoming a revocation.
     */
    case Client = 'client';

    /**
     * The super admin console, which runs above the tenant boundary.
     *
     * Not team access either: a platform administrator's power over a team is
     * the console and audited impersonation, not a seat on it. Keeping this
     * out of `Team` preserves what the members screen already did by naming
     * its two keys — Super Administrator never appeared there, and
     * `Role::assignableWithinTeam()` makes sure a team cannot hand it out.
     */
    case Platform = 'platform';
}
