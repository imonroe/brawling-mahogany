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

The scope is not removable by convenience. `withoutTeamScope()` is spelled out
rather than aliased, so grepping finds every use — and
`tests/Isolation/UnscopedQueryConventionTest.php` greps, failing on a call site
that has not been given a reason. See *Decided since* for the rule that
survived contact with the code.

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
  **Not yet implemented, because nothing needs it yet:** no tenant route in
  Slice 1 names a team — they bind a team-scoped model and let the global
  scope do the isolation — and `/admin`, which does name teams, runs outside
  this middleware. The first tenant route that takes a `{team}` builds this.

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
- Unscoped access is possible but never accidental: it has a name, a short
  list of callers, and an audit trail.
- The isolation suite grows with every entity. That is a real, recurring cost,
  and it is the cheapest insurance in the project.
- Queue and schedule contexts need an explicit team, which makes job payloads
  slightly heavier and job bugs much louder.

## What Slice 1 built

Written after the fact, so the ADR describes the code rather than the plan.

| Layer | Where it lives |
|---|---|
| 1. Global scope | `App\Models\Concerns\BelongsToTeam` and `TeamScope`. `App\Support\Tenancy\TeamContext` holds the one resolved team |
| 2. Database | `productDefaults()`'s foreign key and `(team_id, id)` unique index; `teamScopedForeign()` for composite child keys |
| 3. Middleware | `ResolveCurrentTeam` (session first, route second) and `EnsureTeamContext` |
| 4. Policies | `App\Policies\*`, all using `ChecksTeamPermissions`, all denying by default |
| 5. Tests | `tests/Isolation/` — `CrossTenantAccessTest` for the vectors, `ModelTenancyConventionTest` for the models |

Two things the implementation added that the decision did not name:

- **The write side.** The scope protects reads; `BelongsToTeam` also fills
  `team_id` on create and throws `CrossTenantException` when a save names a
  different team. `team_id` is absent from every model's fillable list, so a
  request body can never choose a tenant.
- **`tests/Feature/AuthorizationCoverageTest.php`**, which reads the route
  table and fails on any controller action that never asks a policy. Layer 4
  said "every controller action is gated"; this is what holds it.

## Decided since

- **`people` is shared across teams** (issue #18, PRD decision log
  2026-08-22). One row per human, with everything a team knows privately about
  them on `team_memberships`. Six models carry no `team_id`, each recorded with
  a reason in the isolation suite: `people` (shared), `teams` (the boundary
  itself), `roles` (the five system roles have no team), `permissions` (flat
  and identical everywhere), `audit_log` (outlives the team it describes), and
  `passkeys` (a credential belongs to a human, not a tenancy — somebody who
  works for two teams signs in once).
- **`withoutTeamScope()` is not a list of callers, it is a rule.** This ADR
  said two callers, then three. The code had thirteen, and the commit that
  raised the count was editing a different paragraph of this file at the time.
  Counting in prose does not work, so the count moved to
  `tests/Isolation/UnscopedQueryConventionTest.php`, which fails on a call
  site that has not been given a reason.

  The rule the reasons have to satisfy is narrower than a list and holds as
  the code grows. An unscoped query may ask **about the actor** — which teams
  am I in, am I the last owner anywhere, is 2FA mandatory for me — because
  those questions span teams by nature and scoping them is what makes them
  wrong. (Slice 1 shipped that exact bug: `guardLastOwnerAnywhere()` asked a
  cross-team question through the scope and got zero every time.) Or it may
  run in **a context with no tenant**: the super-admin console, a console
  command iterating teams explicitly, the invitation-accept route whose hashed
  token is what establishes a team at all.

  It may never read *tenant data* — somebody's deals, their people, their
  documents. No reason written in the allow-list makes that acceptable.

  Worth naming the busiest one, because it surprises: `Person::membershipIn()`
  runs unscoped on every `authorize()` call, through
  `ChecksTeamPermissions`. It is asking which membership the actor holds in a
  team it was handed, which is the first kind, and it is not separately
  audited — auditing every authorization check would drown the log the audit
  requirements exist to keep readable.

## The hole the layers do not cover

Five layers protect team-scoped rows. `people` is **not** team-scoped — it is
shared, deliberately — and the layers have nothing to say about it.

Slice 1's review found both halves of what that costs:

- **Writes**, closed at the model. A team could attach a membership to any
  address and then rewrite that row — including one carrying somebody's
  credentials, redirecting their password reset while the password itself
  looked untouched. `Person::identityIsEditableBy()` permits an identity edit
  only when the person has no credentials and no other team holds a live
  membership.

  Where that check *runs* is the part worth recording, because the first
  attempt got it wrong. It went in at the two call sites the review had named,
  and the third — the contact import's merge path — went on rewriting account
  holders' names and numbers for another round. It is now an `updating` hook in
  `Person::booted()`, so a write does not have to remember to ask: every path
  into the table passes it, including Slice 2's. Offending fields are reverted
  rather than thrown on; a stale form or a merge row is an ordinary event, not
  a 500. `tests/Isolation/SharedPersonRecordTest.php` holds it, exercising the
  form, the import, and the self-edit that must still be allowed.
- **Reads**, open and filed as issue #140. Adding somebody by an existing
  address shows the team what another team supplied. That is the shared-record
  decision working as designed, and it is still a cross-tenant disclosure.
- **Retention**, closed in round 4. `records:purge` discovers its tables by
  looking for `BelongsToTeam`, so the one table that cannot carry the trait was
  the one table the 30-day window never closed on — the table holding password
  hashes and two-factor secrets. `people` is now purged explicitly, outside the
  per-team loop. The same shape as the other two: a mechanism built around
  `team_id`, and the shared table sitting outside it.

The lesson for the next shared table: **a table without `team_id` is outside
every layer in this document.** Five of the six exceptions are reference data
or the boundary itself. `people` is the one that holds customer data, and it
needs its own reasoning rather than the protection the others inherit.

## Not decided here

- Whether `people` keeps shared identity fields or moves them to
  `team_memberships` (issue #140). The enforcement model works either way; the
  disclosure above does not survive the second option.
- The exact retention of `audit_log` beyond "it survives a tenant purge"
  (issue #57). The rows are written; how long they are kept is a policy
  question the first customer contract will settle.
