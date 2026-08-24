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
claim was confirmed by measurement rather than estimate — and **the deal timeline
(S16)**, the stage rail Screen Inventory calls *"the one interaction with no
obvious precedent to copy"*, which is where the difference between a state and a
presentation of one had to be settled. Its remaining screens — tasks, offers, and
the templates UI — are still open under epic #3.

Before making architectural decisions or writing code, read [`docs/Product Requirements Document.md`](docs/Product%20Requirements%20Document.md) (the PRD), which is the source of truth for scope, data model, release plan, and open questions. It is a living draft (currently v0.5) — check its `status` and `version` frontmatter and its Decision Log (§15) before assuming a detail is settled.

## Documentation map

| Doc | Purpose |
|---|---|
| [`Product Requirements Document.md`](docs/Product%20Requirements%20Document.md) | Scope, personas, features, data model, architecture, release slices, compliance, open questions/decisions |
| [`Information Architecture.md`](docs/Information%20Architecture.md) | **The naming authority.** Code names, internal labels, and client-facing labels for every concept |
| [`Screen Inventory.md`](docs/Screen%20Inventory.md) | The full screen list, mapped to PRD feature IDs |
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
- **Multi-tenancy: single database, single schema, `team_id` on every business table.** Enforce it in layers — a global Eloquent scope (fails closed if a `where` is forgotten), composite FKs where possible, middleware, policies, and a dedicated cross-tenant isolation test suite. A gap here is a release blocker, not a follow-up. **Built in Slice 1** — see [`docs/adr/0002`](docs/adr/0002-multi-tenancy-enforcement.md) for where each layer lives. Six models legitimately carry no `team_id`, and each is recorded with a reason in `tests/Isolation/ModelTenancyConventionTest.php`. Adding a seventh means adding a reason there, which is the point.
- **A lookup is archived, never deleted.** Deal types (S76) is the first of these and sets the pattern for roles (S75), template packs, and every other lookup screen: no destroy route at all, the in-use count shown *before* the choice rather than reported after it, archiving reversible, the count scoped to the asking team, and system rows given no controls rather than disabled ones. The reasoning is in [`docs/Frontend conventions.md`](docs/Frontend%20conventions.md) §4.
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

- **Automation is the highest-blast-radius feature.** An email to the wrong client can't be recalled. Anything touching `action_definitions`/message sending needs the approval-queue and safety-rail behavior from PRD §4.5 (F5.7, F5.9) treated as launch blockers, not enhancements.
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
- Keeping the documentation up to date is critical.  For every PR, make sure that any documentation which needs to be updated, gets updated.  Documentation is part of the development process here, so it's imperative that we keep it as accurate as possible.

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
already reach. The twelve models that still carry no `team_id` hold credentials
or reference data and no customer data at all, which is the property that makes
them safe. Before adding a thirteenth, read
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

