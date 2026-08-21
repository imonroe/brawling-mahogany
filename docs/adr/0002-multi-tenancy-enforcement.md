---
created: 2026-08-21
project: Brawling Mahogany
type: adr
status: accepted
---

# ADR 0002 — Multi-tenancy enforcement

**Status:** Accepted · **Issue:** [#28](https://github.com/imonroe/brawling-mahogany/issues/28) · **Sources:** PRD §8.2, §4.1, §9 · `CLAUDE.md`

## Context

One database, one schema, many teams. The tenant boundary is the entire
security model of this product: a leak is not a bug report, it is somebody
seeing another agent's client list, their addresses, and their transaction
values.

The implementation lands in Slice 1. The decision has to exist before the first
business table, because every table inherits it.

`CLAUDE.md` is unambiguous about the standard: *"A gap here is a release
blocker, not a follow-up."*

## Decision

**Single database, single schema, `team_id` on every business table**, enforced
in five layers. They are listed in order of reliability, and the order matters:
each layer catches what the layer below it missed.

### 1. A global Eloquent scope that fails closed

A `BelongsToTeam` trait applies a global scope constraining every query to the
current team. The point is the failure mode: a developer who forgets a `where`
gets *no rows*, not *everybody's rows*.

The scope is not removable by convenience. `withoutTeamScope()` exists for
exactly two callers — the super-admin console and the console commands that
operate across teams — and both are audited (layer 5 below).

### 2. Database constraints where they can be expressed

Composite foreign keys on `(team_id, id)` where a child row must belong to the
same team as its parent. A `task` pointing at a `stage` in another team is then
not merely unlikely, it is unrepresentable.

Where Postgres cannot express the constraint, the relationship carries a test
instead.

### 3. Middleware that resolves the team and rejects mismatches

One middleware resolves the current team and binds it into the container. A
route-bound model whose `team_id` does not match is a **404**, not a 403 — a
403 confirms the record exists, which is itself a disclosure.

### 4. A policy on every model

Deny by default (PRD §9). Every controller action is gated. Policies check
capability; the scope already handled visibility.

### 5. A test suite whose only job is proving refusal

`tests/Isolation/` exists from Slice 0 and grows with every entity. Its job is
to assert that a person in team A gets 403 or 404 for team B's records — for
every route, not a sample.

Slice 0 ships the first test in that suite:
`ModelTenancyConventionTest` fails when a model is added that is neither
tenant-scoped nor explicitly recorded as team-agnostic with a reason. It makes
the decision unskippable before there is anything to leak.

## How the current team is resolved

**Session first, route second, and they must agree.**

- A signed-in person has a current team in the session, set at login and
  changed only through the team switcher (S09).
- A team-scoped route may also name a team. When the session and the route
  disagree, the request is rejected rather than reconciled — silently switching
  a person's team on a link click is how people act in the wrong context.

### The contexts with no session, which is where this usually breaks

This product is queue-heavy from Slice 3, and a queued job has no session.

- **Queued jobs** carry `team_id` explicitly in their payload and re-enter the
  team context on handling. A job that touches team-scoped data without a team
  context throws. Nothing infers a team from "the last one seen".
- **Scheduled commands** iterate teams explicitly. There is no ambient team.
- **Webhooks** (SES bounces, provider callbacks) resolve the team from the
  record the payload names — a `message_deliveries` row, an `extractions` row —
  and never from a request parameter.
- **The client status page** (`/s/{token}`) resolves the team from the token,
  which is single-use and short-lived (PRD §9). It never reads a session.

## How the super administrator bypasses it

The super admin console (`/admin`) runs unscoped, and its own surface is
visually distinct so nobody confuses it for the tenant app (IA §5.5).

Impersonation (PRD §4.1 F1.5) is the sharper case:

- It requires a **logged reason** at the moment it starts.
- It writes to the append-only audit log: who, which team, which person, when,
  why, and when it ended.
- The impersonated session is **visually unmistakable** — the shell renders a
  persistent banner (`ImpersonationBanner`, built in Slice 0) whenever
  `auth.impersonating` is present.
- Impersonation grants the impersonated person's permissions, never the super
  admin's. A support session must not be able to do things the customer cannot.

## What a scope violation does in production

**It throws.** Loudly, to Sentry, with the team ids involved and no PII.

The alternative — returning an empty result — is worse in exactly the way that
matters: a silent empty list looks like "no deals yet" to the person reading it
and like a working feature to the developer who wrote it. A cross-tenant query
is a bug in every case, and a bug that hides is a bug that ships.

## Consequences

- Every business model carries the trait, and every business table carries the
  column. `productDefaults()` (ADR 0001) makes that the default rather than a
  thing to remember.
- Unscoped access is possible but never accidental: it has a name, two callers,
  and an audit trail.
- The isolation suite grows with every entity. That is a real, recurring cost,
  and it is the cheapest insurance in the project.
- Queue and schedule contexts need an explicit team, which makes job payloads
  slightly heavier and job bugs much louder.

## Not decided here

- Whether `people` is team-scoped, shared across teams, or duplicated per team
  (IA §13, open question 3; issue #18). The enforcement model works for all
  three; the choice affects the `team_memberships` design and belongs with
  Slice 1.
- The exact shape of `withoutTeamScope()`'s audit record, which lands with the
  audit log itself.
