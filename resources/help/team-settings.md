---
title: Team settings
summary: Members, invitations, deal types, and the audit log.
section: setup
order: 3
---

**Settings** holds your own account at the top and your team's below it. You
see the team sections you have permission for.

## Members

Who is on the team, what roles they hold, and when they joined. From here you
invite people, change roles, and revoke access.

**Inviting somebody** sends them a link. If your install has no mail set up —
a fresh development environment, say — the accept link can be issued directly
from this screen, and there is a console command for it too. No flow in this
app depends on email alone, because email is a channel nobody controls.

**Revoking** takes away access and keeps the history. Their name stays on the
deals they worked and the contacts they logged, because removing somebody from
a team should not rewrite what happened.

## Deal types

The kinds of transaction your team runs. Three ship — seller representation,
buyer representation, rental placement — and you can add your own.

Deal types are **archived, never deleted**, and the screen shows how many
deals use one before you archive it.

## Roles

See [Roles and permissions](/help/roles-and-permissions).

## The audit log

The security record: sign-ins, permission changes, requirement overrides,
document access, impersonation.

It is **append-only**. Nobody can edit it, including whoever runs the
platform — the database itself refuses. That is what makes it worth having.

It is not the same as **Activity**, which is the working record of the
business. Different records, different readers, different retention.

## Exporting and deleting

A team's data can be exported. Deleted records are kept for thirty days and
then permanently removed — files included.
