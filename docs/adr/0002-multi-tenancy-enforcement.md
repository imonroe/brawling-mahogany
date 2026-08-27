---
created: 2026-08-21
project: Goldieflow
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
| 3. Middleware | `ResolveCurrentTeam` (session first, route second) and `EnsureTeamContext`, both ordered ahead of `SubstituteBindings` in `bootstrap/app.php` |
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

- **Layer 3 has to run before route model binding** (issue #156). Appending
  the tenancy middleware to the web group is not enough to place them:
  `SubstituteBindings` is in Laravel's middleware priority list and the
  appended middleware were not, so the binding ran first. It queried a
  team-scoped table with no team established, layer 1 threw
  `MissingTeamContextException` exactly as designed, and every screen that
  binds a team-scoped model answered 500 — `/people/{membership}`,
  `/properties/{property}`, and the redirect a store lands on — while the
  index beside it, binding nothing, was fine.

  So the order is declared rather than assumed:
  `prependToPriorityList()` puts `HandleImpersonation`, `ResolveCurrentTeam`
  and `EnsureTeamContext` ahead of `SubstituteBindings`, in that order — who
  the person is, which team they are standing in, and a refusal when the
  answer is none, all settled before a binding may touch a scoped table.

  **The suite could not see it, and that is the part worth remembering.**
  `TestCase::withTeam()` sets the context in the container before the request
  is made, so a feature test has a team whatever order the middleware are in.
  Only a session-backed request asks `ResolveCurrentTeam` for the answer.
  `tests/Feature/Tenancy/TeamResolutionTest.php` now holds three that do,
  giving the request nothing but a session — which is all a browser has.

- **`people` is the login, and nothing else** (issues #18 and #140, PRD
  decision log 2026-08-22). Slice 1 shared one row per human across teams with
  their contact details on it; Slice 2 moved those details to
  `team_memberships` and left the row holding credentials. See *The hole the
  layers do not cover* for why the reversal, and what it generalises to.

  Thirteen models now carry no `team_id`, each recorded with a reason in the
  isolation suite: `people` (a login is one per human), `teams` (the boundary
  itself), `roles` (the five system roles have no team), `permissions` (flat
  and identical everywhere), `audit_log` (outlives the team it describes),
  `passkeys` (a credential belongs to a human, not a tenancy), the six
  definition-layer tables added in Slice 2 — `deal_types`, `template_packs`,
  and the four `*_template` tables — where a null `team_id` means a system row
  every team can see, and `action_definitions` in Slice 3, which mirrors its
  stage template's team for the same reason. **None of the thirteen holds
  customer data**, which is the property that makes them safe and the one
  `people` used to break.

  `action_definitions` is the first of them that **points at a team-scoped
  table**, and that is a new shape for this paragraph rather than another
  instance of it. A shared automation naming one team's `message_template`
  would send that team's words, with their signature, from every other team on
  the platform — so being outside the scope is not free here, and two database
  constraints pay for it: a composite foreign key over
  `(team_id, message_template_id)`, and a `CHECK` that a row with no team may
  not name a template at all. The second is the load-bearing one, because a
  Postgres foreign key is **MATCH SIMPLE** and a null `team_id` satisfies one
  without checking anything. A later exemption that points at scoped data has
  to show the same working.
- **Which teams a person may resolve is one question with one answer**
  (issue #142). Layer 3 asks `Person::activeTeams()` before it will resolve a
  team from a session, so "is this person on the team" is a tenancy decision
  and not only a UI one: it is what stands between somebody a team merely
  *knows* — a client, a vendor, an opposing agent, all of whom hold a
  `team_memberships` row by design — and the tenant itself.

  Slice 1 answered it two ways. `carriesAccess()` and `activeTeams()` asked
  whether any role carried any permission; `/settings/members`, the People
  index's Team segment, and the console's team detail each named
  `['team_owner', 'team_member']`. The lists agreed by coincidence, and the
  coincidence had two expiry dates: a team composing its own role (PRD F2.3),
  and Status Viewer gaining its first permission in #110 — which would have
  made every client a member, and quietly turned removing one from the
  directory into an access operation needing `team.members.manage`.

  The answer now comes from the **permission**, not the role:
  `App\Enums\PermissionSurface` says whether a permission is a capability of
  the team app, the client status page, or the platform console, and team
  access means holding at least one on the team surface. A role — including
  one a customer composed — inherits its answer from what it is made of, which
  is why there is no list of keys to keep in step.

  Not a `grants_team_access` column on `roles`, for the reason this ADR gives
  about `people`: **the layers key on the tables, and `roles` is a table a
  customer writes.** A security-relevant flag there needs a default, and both
  defaults are wrong. The permission catalogue is product data — flat, finite,
  seeded in code — so classifying it is a decision the build can force.
  `tests/Isolation/TeamAccessConventionTest.php` is that build failure: a role
  with no recorded decision, a permission with no surface, or a new file
  deciding team membership by naming role keys.
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

## The hole the layers do not cover — closed, and how

Five layers protect team-scoped rows. `people` was **not** team-scoped — it was
shared, deliberately — and the layers had nothing to say about it.

Slice 1's reviews found three separate costs, and the pattern is the finding:
**every mechanism in this codebase keys on `team_id`, so a table without one is
outside all of them at once.** Not just the five enforcement layers.

- **Writes.** A team could attach a membership to any address and then rewrite
  that row — including one carrying somebody's credentials, redirecting their
  password reset while the password itself looked untouched. Closed by an
  `updating` hook on the model, after enforcing it at individual call sites
  missed one for a whole review round.
- **Retention.** `records:purge` discovers its tables by looking for
  `BelongsToTeam`, so the one table holding password hashes and two-factor
  secrets was the one the 30-day window never closed on. Closed by purging
  `people` explicitly.
- **Reads.** Adding somebody by an existing address showed the team what
  another team supplied. **Not closable by a guard**, because it was the
  shared-record decision working exactly as designed.

### What Slice 2 did instead

Moved the data (issue #140). Contact details — name, email, phone — now live on
`team_memberships`, which is team-scoped and therefore inside all five layers
and the purge. `people` keeps only what makes a login work.

The reasoning is worth keeping, because it generalises past this table.
Sharing bought one thing: a stager working for two teams being one record with
one phone number. That benefit only existed *while the fields were shared*, and
those fields were exactly what leaked. Once every team-visible field is on the
membership, each team holds its own view regardless — so the sharing bought
nothing and still cost the disclosure. **A trade-off with no remaining benefit
is not a trade-off.**

Two things fell out of the move that are worth noticing:

- The write-side machinery was **deleted**, not kept as defence in depth. There
  is no shared column left to protect, and a guard on a property that already
  holds is a guard that will one day be trusted for the wrong reason.
- `people.email` narrowed from "an address a team has for somebody" to "the
  address this account signs in with". A null used to mean a contact with no
  email; it now means a person with no login, which is most of the directory.

### The rule for the next shared table

**A table without `team_id` is outside every mechanism in this codebase that
keys on one** — the scope, the composite keys, the middleware, the policies,
the isolation suite, *and* the retention purge.

`ModelTenancyConventionTest` enumerates the models that carry none and records
a reason for each; **that list is the authority, and any count written here
will go stale**. Most are reference data or the tenant boundary itself.
`people` is a stronger case than it used to be: it holds credentials, which are
genuinely one-per-human, and nothing else. If a future table wants to be
shared, the question to answer is not "can we guard it" but "what does sharing
buy, and is that still true once the team-visible fields are somewhere else".

### The deliberately cross-tenant table

`suppressed_addresses` (Slice 3, issue #95) is a different case again, and the
only one so far. The others are *shared* — a row every team may read, like a
seeded deal type. This one is **cross-tenant**: what one team learns, every
other team is bound by.

The fact recorded is a fact about an address rather than about a team. A
mailbox that does not exist does not exist for anybody, and SES measures
bounce and complaint rates **per account** (PRD §12.2 — bounce under 2%,
complaint under 0.1%), so one team writing repeatedly to a dead address is
spending every other team's deliverability. A per-team list would have each new
team rediscover the same bad address at the account's expense.

Issue #95 asks for it to be *"built explicitly rather than falling out of a
scope gap"*, and the distinction is the whole of it: a scope gap is something
nobody decided. What makes this defensible rather than merely convenient is
three things, and dropping any one of them turns it into the `people`
disclosure again:

- **Nothing team-facing reads the row.** `SuppressedAddress::suppresses()`
  returns a reason and nothing else. `discovered_by_team_id` is for the
  platform console, which is already cross-tenant by design.
- **A team is told about the address, never about another team.** A refused
  send says *"this address can no longer be written to"* and why —
  `SuppressionReason::explanation()` — and two teams sharing a client learn
  nothing about each other's correspondence from it.
- **It holds no customer data beyond the address itself**, which is the fact
  being recorded. There is no name, no deal, no message.

It also outlives a team purge on purpose (issue #57): the address is still dead
after the team that discovered it has gone, and a purge that resurrected it
would hand the account's reputation straight back to the same bounce. Having no
`team_id` is what makes that true **by construction** rather than by an
exception in `PurgeSoftDeletedRecords` — which is the shape to prefer, because
an exception in a sweep is a rule the next person to edit the sweep has to know
about.

**And it is the one soft-deleting table nothing ever hard-deletes**, which is a
deliberate exception to PRD §9's *"soft delete, then hard delete after thirty
days"* and to CLAUDE.md's *"a staging table needs its own sweep"*. The soft
delete here is not a staging state: `mail:suppression --lift` removes an
address from the list, and the row stays so that the audit entry written about
the lift has something to resolve to — an entry whose `auditable_id` points at
nothing cannot answer *"who decided this address was fine"*, which is the only
reason it is written. `records:purge` discovers its tables by `team_id`, so it
never reaches this one, and that is the intended behaviour rather than an
oversight. The rows are an address, a reason and two timestamps; there is no
customer data in them to age out.

The same soft delete is why `Suppression::record()` looks `withTrashed()` and
**restores** rather than inserting: the unique index covers trashed rows, so a
plain insert after a lift would be ignored and an address that had just
hard-bounced would stay writable. And why the restore is gated on the event
postdating the lift — SNS retries for up to 23 days, so a replayed copy of the
very notification an operator lifted in response to would otherwise reverse
their decision, silently.

The one place the tenancy is lifted to reach it is
`ApplyDeliveryEvent`: an SNS notification arrives carrying a message id and no
team, so the **find** is unscoped and the **write** is not. The rows come back
already keyed to one team, and everything after runs inside
`TeamContext::runFor()`. `UnscopedQueryConventionTest` records it as kind 2 — a
context with no tenant — and it is the clearest example of that kind in the
product.

### Writing a screen for a shared table

`people` was the case where sharing was wrong and the fix was to stop sharing.
`deal_types` is the other case: a null `team_id` genuinely means *"a system
default every team gets"*, so the table stays shared and the layers stay
absent. `template_packs` and the four `*_template` tables have the same shape,
and their screens are still unwritten — so the lesson from S76 (issue #58)
belongs here rather than in that screen's docblock.

**Every layer that is absent has to be replaced by hand, and each one was
first replaced by something slightly different from what it replaces.**
Adversarial review found all four:

| Layer normally responsible | What replaced it | What went wrong first |
|---|---|---|
| The global scope, producing a 404 | `resolveRouteBinding()` on the model | Nothing did, so the policy answered **403** for "exists, not yours" and 404 for "does not exist" — a working existence oracle over every row on the platform. Layer 3 already forbids this by name |
| A composite foreign key | A guard on the model's `creating`/`updating` | Ran on `saving`, which fires **before** `BelongsToTeam` fills `team_id` |
| A partial unique index | A validation rule | Filtered `deleted_at` but not `archived_at`, and folded case with PHP's `mb_strtolower()` against an index built on Postgres `lower()` — two functions that disagree on real input |
| A scoped count | **A query against a scoped model**, not a hand-written `where` | Was written unscoped, and would have told one team how many deals every other team is running. The fix is not to add a `where`: the count is over `deals`, which *does* carry `BelongsToTeam`, so asking `Deal::query()` gets the scope for free. Reach for the model that has the layer rather than re-implementing it against the one that does not |

### Choosing what a new table points at

S76's lesson applied one step earlier. `deal_participants` (#60) had to
reference a human, and PRD §6.2 said `person_id`. Since #140 that would have
been the wrong half of the pair, for a reason worth reusing: **`people`
carries no `team_id`, so a `person_id` column cannot be half of a composite
key** — the database would accept a participant pointing at another team's
person, exactly as `tasks.assignee_id` does and exactly as
`InstantiateWorkflow::assignableWithin()` now has to compensate for by hand.

`team_memberships` carries `team_id`, so `teamScopedForeign()` makes the
cross-tenant participant unrepresentable and nothing needs writing by hand at
all.

So when a new table needs to reference something, the question is not only
*"which model means the right thing"* but **"which model carries the layer"**.
Where those disagree, prefer the one that carries it and check whether the
other really means something different — here it did not: a membership is a
person as this team knows them, which is precisely what a participant is.

**Slice 2 currently answers "which human" two ways**, and the next table
should not have to choose between them. `tasks.assignee_id` points at `people`
and `deal_participants.team_membership_id` points at `team_memberships`. The
membership is the one to copy: `tasks.assignee_id` predates #140 and is the
hole `InstantiateWorkflow::assignableWithin()` exists to plug, so it is the
precedent that should move rather than the one to follow. Until it does, read
it as debt rather than as a pattern.

That last row generalises past counting: **a shared table's screen still
touches scoped tables, and those keep every layer.** Only the checks that are
genuinely about the shared row have to be written by hand, and the smaller that
set is kept, the fewer of these four mistakes there are to make.

The rule that falls out: **when a hand-written check stands in for a database
constraint, read the constraint and match it predicate for predicate**,
including which function folds the case and which side of the wire it runs on.
A test that only exercises ASCII, or only the unarchived case, will agree with
a rule that matches neither.

And: **a 404 for a foreign row, a 403 for a shared one.** They are not
inconsistent. The actor can genuinely see a system row — it is on their screen
and in their picker — so refusing with a 403 discloses nothing. A foreign row
they were never shown must not be confirmed to exist.

### The pointer that has no table: polymorphic relations (#61)

`external_links` (S36, S37) points at whatever carries links — a property
today, a deal in #62. Layer 2 cannot reach it, and the reason is worth stating
rather than assuming: a composite foreign key over `(team_id, linkable_id)`
needs **one** table to reference, and the whole point of a polymorphic column
is that there is not one. Postgres has nothing to check the pair against.

So this is the third shape of the same gap. The first was a shared lookup
(`deal_types`, where a null `team_id` means everybody's). The second was a
table with no `team_id` at all (`people`, closed in #140 by moving the data).
This one is different again: the child carries `team_id` and the *parent* is
unknowable at schema time.

What stands there instead, and in this order:

1. **`team_id` is still NOT NULL on the child.** Layers 1, 3, 4 and 5 all key
   on it, and so does `records:purge`'s table discovery. A table without one
   is outside every mechanism the product has, which is the lesson `people`
   taught.

   **But `team_id` is only half of what the purge needs, and the other half is
   the parent's job.** `PurgeSoftDeletedRecords` finds a row by its
   `deleted_at`, and relies on `ON DELETE CASCADE` to reach children that have
   none — which is exactly the mechanism a polymorphic pointer does not have.
   So a link left live when its property was soft-deleted was swept by
   nothing: the property was hard-deleted at day thirty, no cascade reached
   the link, and the row stayed forever, pointing at an id that no longer
   existed. Past PRD §9's *"then hard delete"*, and invisible.

   The fix is a `deleting` hook on `HasExternalLinks`, not on the controller
   that happened to notice — the same sentence this section makes about the
   tenancy guard, for the same reason. **The rule: a polymorphic child is
   deleted by its parent's model, in the parent's own transaction, matching
   the parent's softness.** Nothing else will.
2. **An allowlist of what may be pointed at.** `ExternalLink::LINKABLE` is a
   list of class names, and every entry must itself be team-scoped — because
   the guard below reads the target's `team_id`, and a target without one has
   no answer to give. `tests/Unit/ExternalLinkConventionTest.php` holds the
   allowlist against the models that use `HasExternalLinks`, so the two lists
   cannot drift apart in either direction.
3. **A model guard on `creating` and `updating`.** It loads the target
   *through the global scope* — deliberately, rather than lifting it to
   produce a more precise error message. A scoped query already gives the
   right answer, because another team's property is invisible and comes back
   null; lifting the scope would have made this the one place in `app/`
   reading tenant data unscoped, which `UnscopedQueryConventionTest` refuses
   and this document does not sanction.
4. **`tests/Isolation/PropertyIsolationTest.php`**, which pins both directions
   — a link created against a foreign target, and a link *updated* to point at
   one. A create-only guard is a guard somebody edits their way past.

Note what the guard does not cover, because the honest sentence is worth more
than the reassuring one: it runs on model events, so `saveQuietly()`, a
query-builder `update()`, and a raw insert all skip it. That is the same seam
`Deal::guardDealType()` and `HasStateMachine` document, and the same answer
applies — do not mass-update the column.

**The rule for the next polymorphic table:** carry `team_id`, allowlist the
types, guard on the model with the scope *on*, and write the isolation test
for the update path as well as the insert. If any of those four feels like
too much for what the table is worth, the table probably wants a plain
`teamScopedForeign()` and a second column instead.

### A record that is one person's, inside a team (#74)

`deal_drafts` is the one table here whose rows are **not** shared by the team
that owns them. Every other `team_id` in this schema means "everybody in this
team may see this"; a wizard draft adds "and only the person who started it".

The reason is not privacy, it is loss. Two agents creating deals at the same
time are doing two different things, and a resume that landed in a colleague's
half-typed address would destroy their work rather than share it. So
`DealDraftPolicy` asks `created_by_person_id === $person->getKey()` on top of
the usual team check, and the wizard resolves the draft **from the actor** —
there is no draft id in any URL, which is what makes the policy a second line
rather than the only one.

**This is not a precedent for narrowing other tables.** A note, a document, a
deal is the team's by design, and PRD §4.2's whole argument for a shared
workspace depends on that. What makes a draft different is that it is a *form
in progress* rather than a record — and the moment it becomes a record, it
becomes the team's like everything else.

### A `teamScopedForeign` that means *context* still cascades (#81)

`teamScopedForeign()` always writes `cascadeOnDelete()`, and that is the right
default: a stage belongs to its workflow, a gate to its stage, and a parent
that goes should take its children with it.

**But the macro cannot tell ownership from context.** `activity_events.deal_id`
is the first column here that means the second thing. An event whose subject
*is* the deal — a stage advanced, a workflow attached — is the deal's own
record and should go when the deal is finally purged. An event whose subject is
a **person**, with the deal only as context, is not: PRD F2.5 logs a contact
*"against a person and optionally a deal"*, the person is still in the
directory, and the call still happened. Left to the cascade, a client's contact
history quietly lost entries thirty days after an unrelated deal was purged.

`nullOnDelete` cannot express it either. The key is composite over
`(team_id, deal_id)`, so Postgres would null `team_id` along with it — and
`team_id` is `NOT NULL` precisely so layer 1 can never be evaded.

So the rule, for the next column of this shape:

> **A `teamScopedForeign` that expresses context rather than ownership still
> cascades, and the purge has to step around it.** Detach the rows that only
> reference the parent *before* the parent is deleted, in the same command that
> deletes it.

`PurgeSoftDeletedRecords::detachActivityFromExpiringDeals()` is the
implementation. Two properties of it are load-bearing and were both got wrong
first:

- **It runs before the delete, not after.** After is too late — the rows are
  gone.
- **It names the subject types it detaches, rather than the ones it spares.**
  The first version excluded deal subjects and detached everything else, which
  fails *open*: a subject type added later is detached by default, so a
  workflow's own events survived their deal as orphans — and, because
  `ActivityFeed` treats a null `deal_id` as "not deal context", surfaced to
  readers without `deals.view`. An allowlist fails closed: a new subject type
  cascades until somebody decides otherwise.

## Not decided here

- The exact retention of `audit_log` beyond "it survives a tenant purge"
  (issue #57). The rows are written; how long they are kept is a policy
  question the first customer contract will settle.
