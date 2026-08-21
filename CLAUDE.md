# CLAUDE.md

Guidance for Claude (and any AI assistant) when working in this repository.

## What this project is

**Brawling Mahogany** (working codename) is a multi-tenant web application that runs the *process* side of a residential real estate practice: workflows, gated stages, tasks, and automated client communication, for small independent teams. See [README.md](README.md) for the full pitch.

**Slice 0 has landed:** the Laravel + Inertia + Vue application skeleton, the container stack, the CI pipeline, and the design system foundations. Product features begin at Slice 1. Before making architectural decisions or writing code, read [`docs/Product Requirements Document.md`](docs/Product%20Requirements%20Document.md) (the PRD), which is the source of truth for scope, data model, release plan, and open questions. It is a living draft (currently v0.3) — check its `status` and `version` frontmatter and its Decision Log (§15) before assuming a detail is settled.

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
| [`adr/`](docs/adr) | Architecture decisions: persistence conventions, multi-tenancy enforcement |
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
- **Single mutation path for workflow state.** All stage/workflow advancement goes through one service (`AdvanceWorkflow` in the PRD's proposal) that evaluates gates, applies the transition in a transaction, dispatches triggered actions to the queue, and writes timeline/audit entries. No controller mutates workflow state directly.
- **Gate evaluation is data-driven.** One small evaluator per gate type (manual confirmation, required tasks complete, document present, field populated, action completed, date reached, approval), resolved by `gate_type`. Adding a gate type means adding a class, not touching advancement logic.
- **Multi-tenancy: single database, single schema, `team_id` on every business table.** Enforce it in layers — a global Eloquent scope (fails closed if a `where` is forgotten), composite FKs where possible, middleware, policies, and a dedicated cross-tenant isolation test suite. A gap here is a release blocker, not a follow-up.
- **Automation is the highest-blast-radius feature.** An email to the wrong client can't be recalled. Anything touching `action_definitions`/message sending needs the approval-queue and safety-rail behavior from PRD §4.5 (F5.7, F5.9) treated as launch blockers, not enhancements.

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
  rendering an unstyled badge.

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
  login. `App\Models\User` is correctly named; a client in the people
  directory is a Person, and calling them a user would imply an account they
  do not have.
- **Activity, not History or Log** — *because* "Audit" means the append-only
  security log. The two are different records with different retention and
  different readers, and merging the words merges the concepts.

**Advance** is the only verb for moving a workflow forward — never Progress,
Move, Next, or Complete. **Override** and **Skip** are different actions with
different audit consequences and must never be conflated in a label.

`tests/Unit/CodeDisciplineTest.php` fails the build when a superseded table name
appears in code.

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

