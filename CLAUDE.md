# CLAUDE.md

Guidance for Claude (and any AI assistant) when working in this repository.

## What this project is

**Goldieflow** (working name) is a multi-tenant web application that runs the *process* side of a residential real estate practice: workflows, gated stages, tasks, and automated client communication, for small independent teams. See [README.md](README.md) for the full pitch.

> **On the name.** `Goldieflow` replaced the `Brawling Mahogany` codename on
> 2026-08-22, and `goldieflow.com` is secured. The rename was **documentation
> only**. Infrastructure identifiers deliberately still carry the old codename —
> container, volume and network names in the `compose*.yaml` files (and so the
> `brawling-mahogany_*` Docker volumes), the `brawling_mahogany_test` database
> in `phpunit.xml`, `/srv/brawling-mahogany` in `scripts/provision-staging.sh`,
> the values in `.env.example`, and the `imonroe/brawling-mahogany` repository
> itself. **Do not "fix" these.** Renaming them orphans local Docker volumes and
> staging state, so it is a deliberate migration, not a find-and-replace.
> Obsidian vault paths inside `[[wikilinks]]` also still read
> `brawling mahogany` and must stay that way or the links break.

**Slices 0 and 1 have landed, and Slice 2's engine with them.** Slice 0 is the Laravel + Inertia + Vue application skeleton, the container stack, the CI pipeline, and the design system foundations. Slice 1 is tenancy: teams, memberships, the five access roles and their permission catalogue, authentication, the people directory, contact import, the activity timeline, the append-only audit log, the super admin console, and the cross-tenant isolation suite. Slice 2 so far is the workflow engine — deals, the template/instance split, gate evaluation, `AdvanceWorkflow` — plus the deal types screen (S76), deal participants (S19, S25), properties with their polymorphic external links (S35, S36, S37), the deal's own properties tab with subject promotion and buyer interest (S20), the create-deal wizard with attach-workflow (S14, S28), **the deal overview (S15)** — which brought with it the deal chrome every tab now wears and the first HTTP route in front of `AdvanceWorkflow` — the team activity feed with the two-click contact log (S12, S26), **the advance and override modals (S23, S24)**, which gave the engine a way past a gate that cannot clear on its own, and **the deals index (S13)** — the screen that finally rendered
`DealRow` outside the gallery, and where Design System §4.3's twenty-row density
claim was confirmed by measurement rather than estimate — **the deal timeline
(S16)**, the stage rail Screen Inventory calls *"the one interaction with no
obvious precedent to copy"*, which is where the difference between a state and a
presentation of one had to be settled — and **tasks (S17, S27)**, the feature
both practitioners named independently as the thing their tools lack, and the
screen that gave `required_tasks_complete` a way to clear that is not an
override. Since then: skip and reopen (S23's siblings, #70), notes (#72), **My
Work** (S11, #80), **the team dashboard** (S10, #79) and the p95 budget that
holds it (#89), **global search and the vendor directory** (S07, S34, #82,
#83), **offers** (S22, #73), **private file storage and the photo gallery**
(S38, #63) — which is the service Slice 3's documents will sit on — and
**the templates and roles UI** (S39–S43, S75, #84–#86, #88). What is left in
epic #3 is **#87, the seeded template packs**, and it is blocked on #11 rather
than on code: the mechanism is built, and a pack whose stages somebody invented
would teach a process nobody follows.

**Slice 3 has started, with the half that cannot send anything.** Message
templates and the automations that reference them (S44–S46, #90, #91): a
template carries a **channel** and a recipient *rule* rather than an address,
its merge fields are validated at save time, and its preview renders the
**draft** against a real deal of the team's. Automations hang off
`stage_templates` with the triggers and action types F5.2 and F5.3 name.
**And then the half that does** (#92, #93, #96), which landed **together** so
that the first thing able to email a client arrived with its safety rails
attached rather than shortly afterwards. A trigger raises `action_instances`
inside the advance's own transaction and the queue dispatch happens after it
commits; the words are rendered at raise time, which is what F5.10's *"ready to
review and send"* requires; F5.7's queue (S47–S49) stands in front of the
transport; and F5.9's three rails are asked in `ExecuteAction`, in the worker,
in the statement immediately before the mailer. `ActionCompletedEvaluator` is
wired with them — the second of Slice 2's three deferred gates.

What is left in the epic: branded email and SES (#94, #95, #97), documents and
their guardrails (#98–#100, #104), and the mobile layer (#101–#103). #12 (SES
production access) and #19 (web push on a real iPhone) are not code.

Before making architectural decisions or writing code, read [`docs/Product Requirements Document.md`](docs/Product%20Requirements%20Document.md) (the PRD), which is the source of truth for scope, data model, release plan, and open questions. It is a living draft (currently v0.5) — check its `status` and `version` frontmatter and its Decision Log (§15) before assuming a detail is settled.

## Documentation map

| Doc | Purpose |
|---|---|
| [`Product Requirements Document.md`](docs/Product%20Requirements%20Document.md) | Scope, personas, features, data model, architecture, release slices, compliance, open questions/decisions |
| [`Information Architecture.md`](docs/Information%20Architecture.md) | **The naming authority.** Code names, internal labels, and client-facing labels for every concept |
| [`Screen Inventory.md`](docs/Screen%20Inventory.md) | The full screen list, mapped to PRD feature IDs |
| [`resources/help/*.md`](resources/help) | **The user manual** (S92). Not part of `docs/` and deliberately so: `docs/` is the engineering record and this is what a person reads inside the app — different reader, different register. A change to what somebody *does* needs a pass over both |
| [`Build Plan.md`](docs/Build%20Plan.md) | The build order, the critical path, and the map to the GitHub issue backlog |
| [`Design System.md`](docs/Design%20System.md) / [`Design references.md`](docs/Design%20references.md) | Visual/UI direction |
| [`Frontend conventions.md`](docs/Frontend%20conventions.md) | Where things live in `resources/js`, the component governance rules, the formatters, and the content rules |
| [`Testing.md`](docs/Testing.md) | The four test suites, the conventions every slice inherits, and the tests that hold project rules |
| [`Environment and secrets.md`](docs/Environment%20and%20secrets.md) | Which secrets exist per environment, and how they are rotated |
| [`Deployment.md`](docs/Deployment.md) | Staging and production, backups, and the restore drill |
| [`adr/`](docs/adr) | Architecture decisions: persistence conventions, multi-tenancy enforcement, no email-only flows |
| [`Rough data model.canvas`](docs/Rough%20data%20model.canvas) | First-pass data model (superseded in detail by PRD §6, but useful for visual orientation) |
| [`The basic idea.md`](docs/The%20basic%20idea.md), [`Conversation with Emily and Heather.md`](docs/Conversation%20with%20Emily%20and%20Heather.md) | Origin material — useful for *why*, not for current spec |

**When documents disagree, Information Architecture wins on naming, and the PRD's Decision Log (§15) wins on scope.** The PRD itself notes it still contains some pre-rename terminology in places (e.g. "Project"/"Milestone" used in the old sense) — don't propagate that into code or new docs.

## Terminology (do not mix these up)

This project renamed core concepts partway through planning. Always use the current terms below in code, routes, and UI — never the superseded ones, even if you see them in older doc passages.

| Current term | Superseded term | Meaning |
|---|---|---|
| **Deal** | ~~Project~~ | The transaction (a sale, purchase, or rental placement) |
| **Stage** | ~~Milestone~~ (old, broad sense) | A *period* within a workflow — has start/end dates, holds tasks and gates |
| **Milestone** | — (narrowed meaning) | A *moment*: the notable completion of a stage, worth telling the client about. Not a separate table — it's `stages.is_milestone` + `stages.milestone_label` |
| **Gate** | — | A condition that must clear before a stage/deal can advance |
| **Workflow** vs **Workflow Template** | — | Runtime instance vs. reusable definition — always keep template and instance layers separate (see below) |
| **Team** | — | The tenant boundary |
| **Status Page** | ~~Client Portal~~ | The read-only, magic-link client-facing view |
| **Status Viewer** | ~~Portal User~~ | The client's access role |

Full three-vocabulary table (code / internal label / client-facing label) is in [`Information Architecture.md`](docs/Information%20Architecture.md) §2.

## Architecture principles to carry into implementation

These come from PRD §8 and should guide the eventual build:

- **Template vs. instance split.** Every process entity has a definition layer (`workflow_templates`, `stage_templates`, `gate_templates`, `task_templates`) and a separate runtime layer (`workflows`, `stages`, `gates`, `tasks`). Instantiating a template snapshots it — later template edits must never rewrite an in-flight deal.
- **Single mutation path for workflow state.** All stage/workflow advancement goes through one service (`App\Support\Workflow\AdvanceWorkflow`) that evaluates gates, applies the transition in a transaction, dispatches triggered actions to the queue, and writes timeline/audit entries. No controller mutates workflow state directly. **Built in Slice 2**, and held by two tests rather than by memory: `tests/Unit/SingleMutationPathTest.php` reads the source of everything in `app/`, `routes/` and `database/`, and `HasStateMachine`'s `saving` hook refuses an illegal transition however the attribute was written — a source-reading guard alone was walked past three ways in review.
- **Gate evaluation is data-driven.** One small evaluator per gate type (manual confirmation, required tasks complete, document present, field populated, action completed, date reached, approval), resolved by `gate_type`. Adding a gate type means adding a class, not touching advancement logic.
- **Multi-tenancy: single database, single schema, `team_id` on every business table.** Enforce it in layers — a global Eloquent scope (fails closed if a `where` is forgotten), composite FKs where possible, middleware, policies, and a dedicated cross-tenant isolation test suite. A gap here is a release blocker, not a follow-up. **Built in Slice 1** — see [`docs/adr/0002`](docs/adr/0002-multi-tenancy-enforcement.md) for where each layer lives. Thirteen models legitimately carry no `team_id`, and each is recorded with a reason in `tests/Isolation/ModelTenancyConventionTest.php`. Adding a fourteenth means adding a reason there, which is the point. (This said *six* for several slices after the list had grown past it, and *twelve* for one PR after `ActionDefinition` joined — twice now, and the second time in the sentence warning about it. **The list in the test is the authority; every number in this file is a copy that will go stale.**)
- **A lookup is archived, never deleted.** Deal types (S76) is the first of these and set the pattern roles (S75, #88) then followed, and template packs and every other lookup screen after them: no destroy route at all, the in-use count shown *before* the choice rather than reported after it, archiving reversible, the count scoped to the asking team, and system rows given no controls rather than disabled ones. The reasoning is in [`docs/Frontend conventions.md`](docs/Frontend%20conventions.md) §4.

  **The count is scoped even when the row is not.** Roles and workflow
  templates both have a *shared* half — the five system roles and the pack
  templates carry no `team_id` at all — so a count taken off them without the
  scope tells one team how many people or how many running deals **every other
  team** has. `WorkflowTemplate::inUseCount()` records that as the mistake it
  nearly was. A shared row's count is a per-team question, always.

  **And an in-use count does not always mean "careful".** On S41 it means the
  opposite: instantiation snapshotted, so the twelve deals running on a
  template will *not* change when it is edited, and the number exists to say
  so. A team that believes editing a template fixes a live deal will edit the
  template instead of fixing the deal.
- **A link out, never a copy of what is on the other end.** PRD §10: MLS
  listing data is licensed, and *"v1 stores links only, never ingested listing
  content."* `external_links` is a label and a URL and deliberately has no
  column for a title, a price, a photo, or a description — adding one for
  convenience is a licensing decision, not a feature. **Built in Slice 2**
  (#61), replacing the per-site `zillow_url` columns PRD §7.13 rejected. The
  URL is held to an http/https allowlist on the way in *and* on save
  (`App\Support\Links\SafeUrl`), because a stored `javascript:` URL is
  stored XSS the moment it is an `href`.

- **A derived name is derived, and a typed one wins.** `deals` carries both
  `name` and `generated_name` for one reason: the derived half goes on tracking
  the facts — the subject property's street, the client's surname, the deal
  type's side (IA §10) — and the typed half survives every one of those passes.
  `App\Support\Deals\NameDeal` is the only thing that writes
  `generated_name`, and it never touches `name`; `Deal::displayName()` decides
  which a screen sees. Built across #61 and #62.

  **Every fact it derives from has to trigger a refresh, and the buy side is
  where forgetting one shows.** `PropertyDeals` (link, unlink, promote) and
  `SaveProperty` (a subject's street changing) cover the property half;
  `DealRoster` (add, replace, remove) covers the client half. That last one was
  missing for a round, and it did not matter until #62 stopped making a
  buyer's first house the subject — at which point a buy-side deal had nothing
  *but* the surname to be named from, and rendered "Untitled deal" with a
  named Buyer sitting on it.

  Seven call sites, then: `link`, `unlink`, `promote`, `SaveProperty::update`,
  `add`, `replace`, `remove`. Adding an eighth fact means adding an eighth.

- **An event's subject is not the same question as which deal it belongs on.**
  `activity_events` carries both: `subject_type`/`subject_id` is *what this
  happened to*, and `deal_id` is *where a team looks for it*. S26 is where they
  come apart — PRD F2.5 logs a contact **against a person and optionally a
  deal**, so the subject is the person and the deal is context — and a stage
  advance is the mirror image, subjected to the workflow while belonging to the
  deal.

  `RecordActivity` fills `deal_id` from the subject when the subject **is** a
  deal, so the seven call sites that already pass one need no change and the
  eighth cannot be written without one. The four that subject an event to a
  workflow pass `deal:` explicitly. Adding an event type with a deal behind it
  means answering which of those two it is.

- **A shared table's key is a shared namespace, and a derived key can collide
  into it.** `roles` has no global scope — the five shipped roles carry no
  `team_id` — so the unique index is over `(team_id, key)` and a team's own row
  may take a key a system row already has. S75 derives a role's key from its
  name, and `Str::slug('Team Owner', '_')` is exactly `team_owner`.

  Every check written as `roles.key = 'team_owner'` then treated the
  counterfeit as the real thing, and the sharpest consequence was
  `RevokeMembership` counting it among the "other owners" — revoke the only
  genuine owner and the team is locked out of its own settings. The two halves
  of the fix are both needed: the **name is refused** at the controller, and
  `TeamMembership::hasRole()` and `scopeHoldingSystemRole()` ask for a null
  `team_id`, which holds however a row got there.

  Before deriving an identifier into a table anything shares, ask what already
  lives in that namespace.

- **A polymorphic child is reached by nothing.** No foreign key points at
  `documents.documentable_id` or `external_links.linkable_id`, so nothing
  cascades — and `records:purge` finds a row by its `deleted_at`, which a
  document whose *parent* was deleted does not have. Deleting a property left
  its photos as live rows pointing at nothing and their bytes on the disk
  permanently, past F6.4's promise that a deletion deletes.

  `HasExternalLinks` and `HasDocuments` both put the sweep on the **parent's
  own `deleting` hook** rather than in the controller, for the reason ADR 0002
  already records: a rule written into one caller is a rule the second caller
  is written without, and Slice 3 makes deals documentable. And **a file lives
  on a disk the row-level sweep never touches** — `records:purge` deletes a
  purged team's `documents` rows, so the bytes have to be deleted beside them
  and the disk's team directory swept as well.

- **A shared read filtered per screen is filtered once, then never again.**
  `ActivityFeed::query()` is the widest read in the product, and its filtering
  lived half in the query and half in the *screen's* policy gate: `/activity`
  asks `people.view`, and that covered person-subjected events for exactly as
  long as the feed had one caller. S10's dashboard panel reuses the same query
  behind a `deals.view` gate, so a composed *"deals but not the client
  directory"* role read a client's name and a free-text contact note on the
  screen they land on, with a link to a person page answering 403.

  **And an exclusion list fails open.** The filter was `!=` per surface with a
  docblock warning that *"a subject type with no rule is visible to everyone
  who can open the feed"* — which came true twice over. It is an allowlist now:
  `subjectPermissions()` names every subject type with the permission it needs,
  a type not named is excluded, and `ActivityFeedIsolationTest` reads every
  `subject:` in `app/` and fails the build when one has no rule. Same shape as
  the purge cascade in ADR 0002, and the same lesson.

- **A staging table needs its own sweep.** `records:purge` finds a row by its
  `deleted_at`, so everything somebody *deleted* is covered — and a table whose
  rows end by **neglect** rather than by an action is reached by nothing.
  `contact_imports` (S33) and `deal_drafts` (S14) both carry their own sweep in
  `PurgeSoftDeletedRecords` for that reason. **What "abandoned" means differs
  per table, so each sweep picks its own column.** An import is swept on
  `created_at`: the CSV is the risk, an import is a single sitting, and an
  upload that old is over however the row was touched. A draft is swept on
  `updated_at`, because a wizard genuinely is resumed days later and
  `created_at` would delete work in progress. #61 shipped this hole for
  `external_links` and had it found in review; the rule is the generalisation,
  and choosing the column deliberately is half of it.

- **A presentation table is not a vocabulary.** Design System §7.4's stage-rail
  marker table has seven rows; IA §8's stage vocabulary has five states, and
  `lib/states.ts` throws on a sixth rather than render an unstyled badge. Both
  are right, because they answer different questions — and the row that exists
  in one and not the other is **Overridden**.

  A stage that completed over an overridden gate *is* `complete`. How it got
  there is a second fact, carried as `hasOverride` and drawn as a different
  marker over the same badge. IA §8 insists an override is not a kind of Met;
  this is that insistence one level up, so it is not a kind of *state* either
  and does not belong in the table that enumerates them. The counts follow the
  same rule and §7.4 says so outright: *"cleared", not "met"* — the count is met
  **plus** overridden, because "1 of 1 met" over a row badged Overridden says
  the opposite of what happened.

  Before adding a state to render something, ask whether it is a state or a
  presentation of one. Built in Slice 2 (#76).

  The presentation has its own boundary, too: the override marker applies only
  to a **completed** stage. Overriding does not advance, so an active stage can
  carry a waived gate while two others still block it — and marking that one
  Overridden would replace the live "something is still in your way" with a
  historical note, on the one row the reader is there to act on. A **skipped**
  stage is excluded for a different and sharper reason: IA §7 calls conflating
  Skip with Override legally material, so the one row that earns the shield is a
  stage somebody advanced *through* by waiving a condition.

- **A cache is only true at the moment something refreshed it.** `stages.state`
  is written by an advance attempt and by nothing else, so a stage cached
  `blocked` whose gate somebody has since satisfied goes on badging Blocked
  until the next attempt. On a hub that is a stale badge; on S16's expanded card
  it is incoherent *within one card*, because the requirements pane beside the
  badge shows nothing in the way.

  So S16 badges the **active** stage from `DescribeBlockers` — the live answer,
  which writes nothing — and every other stage from the record. **Both screens
  that draw a current stage read the same function**, `StageReadiness::stageState()`:
  S15 and S16 derived it separately for one round and disagreed on the ordinary
  stage straight after an advance, which is cached `active` with its gate unmet.
  One screen said In Progress directly above its own "1 requirement to clear". That split is
  not squeamishness about the cache: a complete stage's gates are what happened,
  not a question still open, and re-evaluating twenty of them per render to
  re-derive a fact that cannot change is work with no reader. The same split
  decides which gates each row carries.

- **An eager-load is a claim that a row needs the relation, and the check is
  the row.** S13's deals index eager-loaded `propertyLinks` with a nested
  `property`, selecting a `state` column `properties` does not have — it has
  `state_code`; `state` is what `deals` calls its lifecycle. So `/deals`
  answered *SQLSTATE[42703]* for any team whose deals had a property linked:
  a 500 on the primary list screen, for the ordinary case of a deal with a
  subject property.

  **Five review rounds went past it, and the reason generalises: a relation
  nothing renders is a relation nothing thinks to seed.** Every fixture on that
  screen seeded participants, workflows, stages and tasks — the four cells the
  row draws — so the broken select list never executed. It read as a wasted
  query right up until something had a property on it.

  A query-count budget will not find this either. `DealsIndexBudgetTest`
  asserts 25 deals cost what 2 deals cost, which is the right shape for an N+1
  and blind to a fixed cost paid once per page. The guard that works holds two
  **same-sized** fixtures differing only in whether the relation is populated.
  Before adding a `with()`, name the cell that reads it; if there isn't one,
  that is the finding.

- **Reading is not advancing, and the read path writes nothing.** `AdvanceWorkflow`
  answers *"what is blocking this stage"* by **attempting the advance**, which
  writes `stages.state = blocked` and refreshes the `gates.is_met` cache. A hub
  screen must not mutate the record it is describing merely by being looked at,
  so S15 reads through `App\Support\Workflow\DescribeBlockers`, which
  composes the same evaluators and writes nothing. That is possible only
  because all seven evaluators are **pure**; an evaluator that saved anything
  would turn every render of every deal overview into a write, and
  `SingleMutationPathTest` would not catch it, because that test guards writes
  to workflow *state*. `DealOverviewTest`'s *"changes nothing"* case pins it,
  and pins it against a fixture an advance attempt really would mark blocked —
  otherwise the assertion passes for the wrong reason. Built in Slice 2 (#75).

- **One header for a deal, built once.** Every deal tab renders the same
  Design System §8.4 header, from the same payload:
  `App\Support\Deals\DealHeader::for($deal)` under a `dealHeader` prop, and
  `resources/js/layouts/DealLayout.vue` draws it. A tab that builds its own
  would disagree with its neighbours about the client's name or the counts
  within a month — S19 and S20 each carried their own slightly different `deal`
  prop before #75 folded them into this one. Adding S16–S22 means adding the
  page to `DEAL_TAB_PAGES` in `resources/js/app.ts` and a tab in `DealHeader`.

- **A cascade that means *context* has to be stepped around.**
  `teamScopedForeign()` always cascades, which is right when the reference
  means ownership and wrong when it means context. `activity_events.deal_id`
  is the second kind: a contact logged against a *person* names a deal for
  context, and letting the cascade reach it lost a client's contact history
  thirty days after an unrelated deal was purged. The purge detaches those
  rows before the parent goes, by an **allowlist of subject types** — an
  exclusion list fails open, and did, in review. See
  [`docs/adr/0002`](docs/adr/0002-multi-tenancy-enforcement.md), *"A
  `teamScopedForeign` that means context still cascades"*.
- **An override is four artefacts, and the fourth is the one that gets
  dropped.** PRD F4.9 is not "set a flag": it is the flag, an immutable audit
  entry naming **who, when, which gate, and why**, a distinct timeline marker,
  and an **auto-created follow-up task** — because an override *defers* an
  obligation and does not delete one. All four live inside
  `AdvanceWorkflow::override()`, beside `handle()`, for the reason the single
  mutation path exists at all: a controller that wrote the flag and remembered
  three of the four would look like it worked. `SingleMutationPathTest` did
  **not** catch that when #77 was written — a probe confirmed it, a controller
  calling `Gate::query()->update(['overridden' => true])` passing while the
  same controller writing `stages.state` failed — so the guard was widened to
  the override flag and the `gates` table rather than the hazard being written
  down. Built in Slice 2 (#77, #69).

  Two things about it are load-bearing and neither is obvious. **The follow-up
  task is not `is_required`**: `is_required` feeds `required_tasks_complete`,
  which counts the required tasks on the stage the person is about to *leave*,
  so a required follow-up would be counted by a tasks gate on that same stage
  and would block the very advance the override exists to permit. And
  **overriding never advances** — overriding one of three blocking gates must
  not move the deal past the other two, so the modal reopens onto the
  refreshed checklist and Advance is a second, deliberate press.

  IA §7 calls conflating **Override** with **Skip** legally material, and
  F4.12's skip is still #70's work. Nothing here writes
  `stages.skipped_reason`.

- **A row nothing can reach is a rule nobody is following.** The engine
  instantiated tasks from `task_templates` for two slices, the
  `required_tasks_complete` evaluator counted them, and no route, controller or
  page touched a task. So the only way past that gate was an **override** — the
  act IA §7 reserves for *"the condition should have been met and was not"*,
  with an audit entry and a follow-up task each time. The routine path through
  a gate was the audited exception, and nothing failed: every test passed,
  because each half worked.

  S17 (#71) closed it, and the shape generalises past tasks. When a gate type,
  a state or a flag has exactly one way to be satisfied, check that the way is
  the one somebody would actually take. `DocumentPresentEvaluator` is the next
  one (#104), and `ApprovalEvaluator` after it.

  **`ManualConfirmationEvaluator` was the same hole, and it survived S17 by
  being invisible in a second way.** It answers by reading `gates.is_met`, and
  the only writer of that column was `AdvanceWorkflow`'s own cache refresh —
  which reads the evaluator. So the most common gate type in the product could
  not clear on its own either, and it had a *tell* that the tasks one did not:
  `GatePolicy::update` already existed, carrying the docblock *"Ticking a
  manual gate is ordinary deal work"*, asked for by no route. **A policy method
  nothing calls is the same finding as a gate nothing can clear**, one layer
  up. `AuthorizationCoverageTest` reads routes and asks whether each
  authorizes; nothing asks the question the other way round.

  `AdvanceWorkflow::confirm()` and `unconfirm()` close it, beside `override()`
  and for the same reason the four artefacts live there: `is_met` and
  `overridden` are the distinction IA §8 insists on, so the file that writes
  one has to be the file that writes the other. `SingleMutationPathTest` now
  guards `is_met` as well, which is #77's widening repeated rather than its
  hazard written down again.

- **Completing is not editing, and the routes say so.** `POST` and `DELETE` on
  `deals/{deal}/tasks/{task}/completion`, beside the `PATCH` that edits the
  task. A boolean inside the edit would make *"I fixed a typo in the title"*
  and *"the work is done"* the same request — and only one of them writes an
  activity event, is counted by a gate, and is done fifty times a deal from a
  checkbox rather than a form. `App\Support\Deals\DealTasks` owns the table
  for the reason `DealRoster` and `PropertyDeals` own theirs: a controller that
  wrote `completed_at` and forgot the event would look like it worked.

  **Completion is idempotent, and that is about the event rather than the
  column.** Writing `completed_at` twice changes nothing; recording the work
  twice reports it twice and attributes it to whoever was second.

- **A shared row may not point at a private one, and the foreign key will not
  say so.** `action_definitions` mirrors its stage template's nullable
  `team_id`; `message_templates` is strictly team-scoped, because a message
  template is *a team's own words to their own clients* with their signature
  under it, which is the closest thing in the definition layer to customer
  data. So a **system** automation naming a team's template would send that
  team's words from every other team on the platform.

  The composite foreign key over `(team_id, message_template_id)` looks like it
  closes that and does not: Postgres foreign keys are **MATCH SIMPLE**, so a
  null `team_id` satisfies one without checking anything — which is exactly the
  shared row. A `CHECK (team_id IS NOT NULL OR message_template_id IS NULL)` is
  what actually closes it. Before relying on a composite key to constrain a
  nullable column, ask what it does when that column is null.

  The same migration carries the other half of that lesson: `ON DELETE SET
  NULL` on a composite key nulls **every** referencing column unless the column
  list is named, so a bare one would have cleared `team_id` too and quietly
  promoted one team's automation into a shared one.

- **Two ways to put a human in the loop is one way.** F5.4's manual prompt and
  F5.7's approval queue describe the same moment from two ends, so
  `action_definitions` keeps both columns PRD §6.2 names and refuses the
  combination with a CHECK constraint — two booleans have four states and two
  of them ask two people to agree to one email. S44 offers a single three-way
  choice, which is the Screen Inventory's *"a progressive form that narrows,
  not four independent dropdowns that can be combined into nonsense"* made
  structural rather than encouraged.

- **Escaping is decided by where a value lands, not by where it came from.**
  `MergeFields::resolve()` returns raw values on purpose and `RenderMessage` is
  the only thing that escapes them: into `body_html` escaped, into `body_text`
  **untouched** — escaping there puts `&amp;` into every plain-text message to
  the O'Brien household — and into `subject` stripped of CR and LF, because a
  subject is a mail **header** and the value is a name somebody typed into the
  people directory. Substitution is one `preg_replace_callback` pass rather
  than a replace per token, because a per-token loop walks over what it has
  already written.

  The template body itself is not escaped — it is the author's own outbound
  HTML — but the **preview renders it in a sandboxed iframe**, because trusting
  an author with their own email is not the same as trusting it inside a
  colleague's session.

- **A validator that scans for well-formed tokens is blind to the dangerous
  ones, and loosening it halfway is worse than not loosening it.**
  `{{ client name }}` is not a merge field, so a scan that extracted valid
  tokens and checked those against the registry would see nothing wrong and let
  the braces through into a client's inbox. `MergeFields::extract()` is
  therefore loose about anything between double braces, and well-formedness is
  checked afterwards.

  That covered *what sits between* the braces and not the braces themselves, so
  `{{ client_name }` — one brace dropped, the likeliest typo of the lot —
  matched nothing, saved with no errors, rendered verbatim, and came back
  `isComplete() === true`, which is the flag #93's approval gate is built on.
  `MergeFields::strayBraceRuns()` removes every matched pair and reports what
  is left, because what is left is by definition unbalanced. When a check is
  loosened to catch a malformed case, ask which half of the shape it actually
  loosened.

- **Both ends of a pair need the rule, and the count of callers is never two.**
  An automation's action type and its template's channel have to agree.
  `SaveAutomationRequest` refuses a mismatch, `ActionDefinition::booted()`
  refuses it again for callers no request reaches — and editing the
  **template's** channel reached the same broken state from the other side with
  a 302, because `ActionDefinition::saving` never fires when no automation row
  is written. The invariant lives on `MessageTemplate::booted()` too now.

  A guard reading a team-scoped model is a second version of the same trap:
  `MessageTemplate::query()` inside that hook answered *"is this visible to
  whoever is in context"* rather than *"what is this row pointing at"*, and
  returned null — read as "nothing to check" — for exactly the callers the hook
  exists for.

- **An email is the first thing this product renders without a browser.**
  Frontend conventions §3 says nothing formats a date or an address itself
  because ninety-one screens would disagree within a month, and until Slice 3
  one file held every rule. `App\Support\Formatting\Format` is its server-side
  mirror, kept to **only the rules a message needs** so the surface that can
  drift is the smallest one that does the job — and its test copies
  `tests/js/formatters.test.ts`'s worked examples verbatim, which is what makes
  a one-sided change fail in the pull request that made it.

- **Automation is the highest-blast-radius feature.** An email to the wrong client can't be recalled. Anything touching `action_definitions`/message sending needs the approval-queue and safety-rail behavior from PRD §4.5 (F5.7, F5.9) treated as launch blockers, not enhancements. **Built in Slice 3** (#92, #93, #96), and the five findings below are what building it taught.

- **An idempotency key you generate is not the one the provider hands back.**
  `action_instances` carries both, and the distinction is the whole guarantee.
  A provider call can time out **after** the provider has accepted the message,
  so the id they would have returned is exactly the thing a timed-out send does
  not have — a retry keyed on `provider_message_id` sails through on precisely
  the send that already reached a client. `message_key` is ours, claimed with a
  conditional `UPDATE … WHERE message_key IS NULL` before the mailer is called
  at all, which makes the database decide which of two workers owns the send.

  The crash window that ordering leaves is the safe one: a `pending` row
  carrying a key, which every path refuses. The other ordering leaves a row
  that looks unsent and is not.

- **The rows go inside the transaction; the jobs go after it.** Both halves are
  load-bearing and they pull opposite ways. A row written outside is a message
  in a team's approval queue for an advance that rolled back. A job dispatched
  inside is one a worker may pick up before the commit lands, or after a
  rollback the queue never hears about — and this particular job emails a
  client. `AdvanceWorkflow` named this seam in its own docblock two slices
  before anything could use it; `dispatchRaised()` is the whole of the second
  half.

- **A cache is only true at the moment something refreshed it — and a
  *substituted* body is a cache of the deal.** `action_instances.payload` holds
  words already rendered, because F5.10's *"ready to review and send"* cannot be
  satisfied by a render that happens at send time. Which means a `{{ token }}`
  typed into S48's editor by an approver has **nothing left to replace it** and
  reaches the client as braces — registered or not, because the substitution
  pass is over. `ApproveMessage` refuses tokens in an edited field rather than
  re-substituting: substituting would fix the values at approval time on one
  message out of two raised from the same words.

  The first version filtered the carried-over `unresolved`/`unknown` lists and
  never looked at what had just been typed. A test written for the other
  direction found it.

- **"Already happened" and "never happened" are different, and cancellation is
  what separates them.** `AdvanceWorkflow::reopen()` recorded the contract
  before there was a table for it: *"an action that already fired stays
  fired… keyed by the stage and the action rather than by a count of
  advances."* The dedupe that implements it excludes **cancelled** rows,
  because a skipped stage cancels its queue and nothing went out — so a stage
  that comes back and is worked properly is still owed its message. A `failed`
  row does count: it will fail again for the same reason, and raising a second
  one only puts a second identical failure on the timeline. An `exists()` over
  every row silences the reopen case forever.

- **A default nobody can leave is not a default, and a rule set in one caller
  is set nowhere.** F5.7 holds every outbound email for a team's first 30 days,
  and `teams.approval_required_until` carried the migration comment *"set on
  team creation"* — which nothing did. `ProvisionTeam` writes four columns and
  this was not one of them, the column has no database default, and there was
  no hook. So the rail PRD §4.5 calls a launch blocker was live for every team
  that already existed and dead for every team it was written for, exactly
  backwards and completely silent.

  It lives on `Team::booted()` now, because `ProvisionTeam` is not the only
  door: `/admin` provisions teams, the factory builds them, and a later slice
  adds signup. **And the screen can turn it off** — it rendered read-only while
  `automation.md` promised otherwise, which is S17's finding one control over.

- **"This message is not mine" and "this message is broken" are different
  answers.** `SendRails` returned a plain refusal for both, and `ExecuteAction`
  marked every refusal `failed` with a timeline entry. But
  `ApproveMessage::cancel()` deliberately allows stopping a **pending**
  instance, and a pending instance is one already dispatched — so *"queued →
  somebody presses Stop → the worker arrives"* is the ordinary sequence, not a
  race. It destroyed the reason a person typed, wrote *"an automated message
  did not go out: this message is Cancelled"* onto the deal, and flipped the
  row from `cancelled` to `failed`, where `alreadyRaised()` counts it — so a
  skipped stage that was later reopened silently never re-raised its message.
  One collapsed distinction defeating the contract two files over.

  `SendDecision::standDown()` writes nothing at all, the same treatment a
  worker that loses the `message_key` claim already got. Before adding a
  refusal, ask whose problem it is.

- **A trigger wired to one implementation of a thing is wired to none of it.**
  `gate_cleared` fired from `AdvanceWorkflow::confirm()` — a person ticking a
  manual gate — and from nowhere else, while S44 offered the trigger for all
  seven gate types and `SaveAutomationRequest` accepted any of them. A team
  building *"when the required tasks are done, email the buyer"* got an
  automation that saved cleanly, showed as active, and never fired. It is now
  raised where `is_met` actually transitions, in `evaluateGates()`, in the
  false→true direction only; an override raises nothing, because IA §8 insists
  overridden is not a kind of met.

- **A rail with no hand on it is a column.** F5.9's kill switch, its rate
  ceiling and its sandbox all live in `SendRails`, in the worker, because issue
  #96 requires them to hold *"when a message is sent by a scheduled job at 3am
  with no human present"*. None of that gives anybody a way to **pull** the
  switch, and F5.9 describes it as *"one toggle"* — so `/settings/sending`
  exists, and it is S17's finding recurring one feature over: a row nothing can
  reach is a rule nobody is following.

  Its own screen rather than a panel on S72, because it is the one somebody
  opens in a hurry after a client phones. It says how many messages the switch
  is currently holding rather than promising that it holds them.

- **A guard that never read the file is not a guard.**
  `SingleMutationPathTest` could not see `action_instances.state` — not because
  its patterns missed the shape, but because its *candidate filter* only opened
  files mentioning `Stage`, `Workflow` or `Gate`, and `ExecuteAction` mentions
  none of them. That column decides whether an email reaches a client: writing
  `pending` releases a message past F5.7's queue, and writing `sent` tells a
  team a client heard something they did not. Both are one array key, and both
  look exactly like housekeeping. This is #68's own `DB::table('stages')` hole,
  recurring at the filter rather than at the pattern — **adding a guarded
  column means adding its model and its table to the filter**, and the file
  says so; it was the sentence that got followed for the pattern and not for
  the filter.
- **No user flow depends on email alone.** Every flow the product initiates by
  email carries a second way to start or answer it that does not involve email
  — the recipient answering in-app, somebody who already controls the flow
  handing the artifact over, or an operator issuing it from the console. Email
  is a channel we do not control, and Slice 1's invitation was unanswerable on
  any install without a mail transport. Built in Slice 2 — see
  [`docs/adr/0003`](docs/adr/0003-no-email-only-flows.md). New mailables **and
  notifications that send mail** are catalogued in
  `App\Support\Mail\EmailIndependence`, and
  `tests/Unit/EmailIndependenceTest.php` fails the build when one has no second
  door, or names one that does not resolve.

## Data handling and security

- PII (client financial info, uploaded documents) is the single highest-risk surface. Certain document categories (executed contracts, earnest money instruments, lending packets, bank statements, government IDs) are **refused outright**, not just flagged — see PRD §4.6 and §10.
- Anything routed to a third-party AI/LLM provider must be redacted first, logged with model/version/cost, and never write into a live record (`key_dates`, `tasks`) without explicit human confirmation. See PRD §4.10 and §8.4.
- No PII in logs, ever. Audit log is append-only and must cover auth, permission changes, gate overrides, document access, extraction reviews, and super-admin impersonation.
- This product is explicitly **not** the system of record for executed contracts/signatures (that's the customer's existing e-signature platform) and does not ingest MLS/IDX listing data — only links to it. Don't build features that assume otherwise without checking PRD §10 (Compliance and Legal Considerations) first.

## Basic principles for working in this project

- The application we are going to build is going to run in a Docker container. The database which it talks to will be in a separate container. We'll need a docker-compose.yml file to describe the stack.
- The application we are going to build is going to be written in Laravel, using ShadCN, Vue, Inertia, and Tailwind.
- Testing is very important. For anything we build, we must have tests. This should be a basic principle from the very beginning of the build. Tests should run in github actions, so we'll need a pipeline for that.
- The version we run locally and in CI/CD should be as reasonably close to production as possible, and if we need to override things in the docker-compose file, we should do so with includes to simplify setup and deployments.
- All environment variables and secrets should be stored in a gitignored .env file in the root of the project. They should be passed into the container which uses them transparently.
- Branching strategy. The `main` branch is for tagged releases only. Feature branches should target the `dev` branch for merging. When we have accumulated enough work in the `dev` branch to cut a tagged release, we'll do a PR to merge `dev` into `main`. That will keep things clean and give us a target for deployments.
- Try to re-use components when possible to try to keep everything DRY.  Prefer pre-built components to rolling your own when practical.
- Keeping the documentation up to date is critical.  For every PR, make sure that any documentation which needs to be updated, gets updated.  Documentation is part of the development process here, so it's imperative that we keep it as accurate as possible.  This includes: user-facing documentation and help files, developer documenation, PRD, ADRs, Design system, and your own documentation, such as CLAUDE.md, etc.  

## Working in the codebase

### The commands

| Command | What it does |
|---|---|
| `make setup` | Boots the whole stack on a clean machine |
| `make check` | Everything CI runs, in the container |
| `composer check` | Pint, PHPStan, Pest |
| `npm run check` | Wayfinder, ESLint, Prettier, `vue-tsc`, Vitest |
| `php artisan migrate:fresh --seed` | A working demo team. Sign in as `emily@example.test` / `password`; `ian@example.test` is the super administrator |
| `php artisan platform:promote <email>` | Grant platform administrator to an existing account — the **first-run bootstrap**. `/admin` provisions teams and invites their owners; this is how the first person gets into `/admin`. `--demote` reverses it (`--demote-last` to skip the only-administrator warning). Audited |
| `php artisan db:seed --class='Database\Seeders\PerformanceFixtureSeeder'` | G8's volumes in a database you can open (PRD §9): 25 active deals mid-flight, 500 past clients, 2,000 activity events. Sign in as `perf@example.test` / `password`. Deliberately **not** part of `migrate:fresh --seed` — a developer wanting a demo team does not want 2,000 events on every schema change |
| `php artisan automations:dispatch-due` | Queue automation instances that are due and have nothing coming for them (#92). Scheduled every minute. It catches two things: a message with a future `scheduled_for`, and one stranded by a web process that died between committing an advance and dispatching its job — without which the message simply never goes and nothing anywhere says so |
| `php artisan records:purge` | The 30-day retention purge (PRD §9): team-scoped rows, deleted accounts, expired exports, and abandoned import uploads. Scheduled nightly; safe to run by hand |
| `php artisan invitation:link <email>` | Print the accept link for an outstanding invitation, with no mail transport and no session (ADR 0003). `--team=<slug>` when the address is invited to more than one. Rotates the token, so it replaces any link already sent. Audited |
| `php artisan auth:reset-link <email>` | Print a single-use password reset link for an existing account (ADR 0003). Starts a reset; only the account holder can finish one. Audited |

`composer check` and `npm run check` are exactly what the pipeline runs. If one
passes locally and fails in CI, that is a bug in the scripts, not something to
work around.

### Where things go

Frontend structure, the layout resolution table, and the formatter list are in
[`docs/Frontend conventions.md`](docs/Frontend%20conventions.md). Two rules are
worth repeating here because they are the ones most easily broken by accident:

- **Nothing formats a date, a name, an address, or an amount itself.** It calls
  `resources/js/lib/formatters.ts`. IA §10 fixes every rule; ninety-one screens
  formatting independently will disagree within a month.
- **Nothing decides its own state colour or label.** It calls
  `resources/js/lib/states.ts`, which throws on an unknown state rather than
  rendering an unstyled badge. Its sibling `lib/activity.ts` does the same job
  for timeline event types — and *does not* throw, because Design System §7.3
  specifies the fallback ("everything else `state-neutral`"). What replaces the
  throw is `tests/js/activityEventTypes.test.ts`, which reads every
  `eventType:` literal out of `app/` and fails when one has no icon.

### Component governance (Design System §13.2)

1. Need a component? **Check shadcn-vue first.** It is probably there.
2. Not there? Can it compose from two or three shadcn parts? Then it belongs in
   `components/app/`.
3. Only then consider a third-party library, and only if it is maintained and
   tree-shakeable.
4. **Never hand-edit `components/ui/`.** Extend through a `cva` variant or a
   wrapper in `components/app/`, so re-running the shadcn CLI stays safe.
   `CODEOWNERS` guards the directory.
5. **No raw colours in components.** No `bg-blue-500`, no `#3B5C8F`, ever. If a
   colour is needed and no token expresses it, the answer is a new token.
6. A pattern used three times gets promoted into `components/app/` with a name.
7. New state? Add it to Design System §2.4 first, then `lib/states.ts`, then
   build the badge.
8. **Both light and dark values, always**, even though dark ships after v1.
9. A tone is three properties — background, foreground, and any icon move
   together or not at all.

Rules 5, 7, and 8 are enforced by tests (`tests/js/tokenDiscipline.test.ts`,
`tests/js/tokens.test.ts`), not by review alone.

### The banned words (IA §11)

One concept, one word. The left column is the only word for the thing, in code,
in routes, and in the UI.

Deal (not Project) · Stage (not Phase or Step) · Milestone, in the narrow
sense only · Gate (Requirement is allowed **only** in the deal view) · Task
(not To-do or Item) · Automation (not Action or Trigger) · Template (not
Blueprint) · Pack (not Bundle) · Participant (not Contact or Party) · Vendor
(not Service provider) · Dates & Deadlines (not Key dates, in the UI) ·
Status Page (not Portal) · Keep in Touch (not Nurture or Drip) · Team (not
Organization or Workspace) · Extract (not Scan, Parse, or AI).

Two of these carry a distinction that the short form loses, and both are load-bearing:

- **Person, not User** — *because* "User" means specifically somebody with a
  login. The table is `people` and the model is `App\Models\Person`, which is
  also the authenticatable. `password` is nullable, and a null password never
  authenticates. (Slice 0 shipped an `App\Models\User` from the Laravel
  skeleton; Slice 1 renamed it, as ADR 0001 said it would.)

  **A Person is a login; a `TeamMembership` is a person as a team knows them.**
  Slice 2 moved name, email, and phone onto the membership (issue #140), so
  `people` holds credentials and nothing a team types. Anything you want to
  *show* — a name, a number, an address — comes from the membership, and
  `TeamMembership::fullName()` is how. PRD F2.1's *"one record per human"* now
  means one record per human **with a login**; a credential-less contact gets
  its own row per team, because there is nothing left for a shared one to
  share.
- **Activity, not History or Log** — *because* "Audit" means the append-only
  security log. The two are different records with different retention and
  different readers, and merging the words merges the concepts.

**Advance** is the only verb for moving a workflow forward — never Progress,
Move, Next, or Complete. **Override** and **Skip** are different actions with
different audit consequences and must never be conflated in a label.

`tests/Unit/CodeDisciplineTest.php` fails the build when a superseded table name
appears in code.

### Writing anything team-scoped

Four things, and forgetting any of them is caught by a test rather than by
review:

1. **`use BelongsToTeam`** on the model, and `$table->productDefaults()` on the
   migration. `tests/Isolation/ModelTenancyConventionTest.php` fails otherwise.
2. **Never put `team_id` in `#[Fillable]`.** A request body must not choose a
   tenant; the trait fills it from the resolved team. A factory that needs a
   specific team uses `Database\Factories\Concerns\ForcesAttributes`.
3. **Authorize every controller action** — `$this->authorize()`, a FormRequest
   with `authorize()`, or `can:` middleware.
   `tests/Feature/AuthorizationCoverageTest.php` reads the route table and
   fails on any action that never asks.
4. **A queued job carries its team**: `use RunsForTeam`, dispatch with
   `->forTeam($id)`, and do the work inside `$this->withinTeam(...)`. A job
   with no team context throws rather than running unscoped.

Two services own their tables and nothing else writes to them:
`App\Support\Activity\RecordActivity` for `activity_events`, and
`App\Support\Audit\AuditLogger` for `audit_log`. The audit log redacts
known-sensitive attributes before writing, and the table's own triggers refuse
an UPDATE, a DELETE, or a TRUNCATE.

**A table with no `team_id` is outside every mechanism that keys on one.** Not
just the five enforcement layers — the retention purge discovers its tables the
same way, so `people` was never purged, and the identity-write rule had to move
onto the model because no scope was going to hold it.

Slice 2 settled it by **moving the data rather than adding a guard**: contact
details went onto `team_memberships`, where all five layers and the purge
already reach. The thirteen models that still carry no `team_id` hold
credentials or reference data and no customer data at all, which is the
property that makes them safe. Before adding a fourteenth, read
[`docs/adr/0002`](docs/adr/0002-multi-tenancy-enforcement.md), *"The hole the
layers do not cover"* — the question is not "can we guard it" but "what does
sharing buy once the team-visible fields live somewhere else".

### Testing

Four suites — Unit, Feature, Isolation, Performance — described in
[`docs/Testing.md`](docs/Testing.md). Tests run against a real Postgres, and
nothing escapes a run: mail, notifications, and storage are faked, and a stray
HTTP request fails the test.

Some project rules are held by tests rather than by memory: the enums are
checked against the PRD and IA tables, log calls are checked for interpolated
values, every model is checked for a tenancy decision, and the token pairs are
checked for contrast in both themes. When one fails, fix the code or the
document — not the test.

## Adversarial Review

Any time you make a PR, subscribe to the PR and make sure that all tests are passing.  Then, you should use a sub-agent to do an adversarial review of the PR.  The sub-agent should make any notes necessary in the PR, and you should respond by making any corrections which make sense.  After you have made updates, conduct the adversarial review again.  Do this loop up to five times, or until there is no feedback left to address.  If, after five rounds of review, it's still not done, then flag me for followup.  If you find that there is no more feedback to address, you may merge the PR into the `dev` branch.  All the reviews should be done in the Github PR, and you should stay subscribed to the PR until it's merged.

