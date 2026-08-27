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

**Status.** Slices 0–2 have landed: the Laravel + Inertia + Vue skeleton and
CI (Slice 0); tenancy — teams, memberships, roles, auth, the people directory,
activity timeline, audit log, super admin console, cross-tenant isolation
(Slice 1); and the workflow engine — deals, template/instance split, gate
evaluation, `AdvanceWorkflow`, deal chrome/overview/timeline/index, tasks,
offers, private file storage, and the templates/roles UI (Slice 2). Only
`#87` (seeded template packs) remains in that epic, blocked on `#11` (the
content, not the mechanism).

**Slice 3 (automation) is in progress.** Message templates and automations
(S44–S46) landed, then the send path itself (#92, #93, #96) landed together
with its safety rails rather than after — a trigger raises `action_instances`
inside the advance's own transaction, the queue job dispatches after commit,
and F5.9's rails (kill switch, rate ceiling, sandbox) are enforced in the
worker immediately before the mailer. Then the client-facing half (#97): one
branded email layout every mailable extends, the milestone notification as a
*frame* around an ordinary automation rather than a second mailable, and S91's
internal alert. Remaining: SES and delivery tracking (#94, #95), documents and
their guardrails (#98–#100, #104), and the mobile layer (#101–#103). #19 (web
push on a real iPhone) is not code — don't try to close it with a commit.

**Mail is configured** (#12): SES over SMTP, verified domain
`monroedigitalconsulting.com`, everything leaving as
`goldieflow@monroedigitalconsulting.com`. Two things that are *not* settled —
whether the account has SES **production access** (a verified domain and a
production account are different grants, and in the sandbox a message to a real
client is rejected rather than delivered), and the dedicated sending subdomain
PRD §8.5 asks for, which #15's naming decision still gates. See
[`Environment and secrets.md`](docs/Environment%20and%20secrets.md) §2. Both of
those are console and DNS work, so **#12 cannot be closed by a commit** any more
than #19 can — the application half of it is done.

Before making architectural decisions or writing code, read
[`docs/Product Requirements Document.md`](docs/Product%20Requirements%20Document.md)
(the PRD) — the source of truth for scope, data model, release plan, and open
questions. It's a living draft (currently v0.5); check its `status`/`version`
frontmatter and Decision Log (§15) before assuming a detail is settled.

## Documentation map

| Doc | Purpose |
|---|---|
| [`Product Requirements Document.md`](docs/Product%20Requirements%20Document.md) | Scope, personas, features, data model, architecture, release slices, compliance, open questions/decisions |
| [`Information Architecture.md`](docs/Information%20Architecture.md) | **The naming authority.** Code names, internal labels, and client-facing labels for every concept |
| [`Screen Inventory.md`](docs/Screen%20Inventory.md) | The full screen list, mapped to PRD feature IDs |
| [`resources/help/*.md`](resources/help) | **The user manual** (S92). Separate from `docs/` on purpose — different reader, different register. A behavior change needs a pass over both |
| [`Build Plan.md`](docs/Build%20Plan.md) | Build order, critical path, map to the GitHub issue backlog |
| [`Design System.md`](docs/Design%20System.md) / [`Design references.md`](docs/Design%20references.md) | Visual/UI direction |
| [`Frontend conventions.md`](docs/Frontend%20conventions.md) | Where things live in `resources/js`, component governance, formatters, content rules |
| [`Testing.md`](docs/Testing.md) | The four test suites and the conventions every slice inherits |
| [`Environment and secrets.md`](docs/Environment%20and%20secrets.md) | Which secrets exist per environment, and how they rotate |
| [`Deployment.md`](docs/Deployment.md) | Staging and production, backups, restore drill |
| [`adr/`](docs/adr) | Architecture decisions: persistence conventions, multi-tenancy enforcement, no email-only flows |
| [`Rough data model.canvas`](docs/Rough%20data%20model.canvas) | First-pass data model (superseded in detail by PRD §6) |
| [`The basic idea.md`](docs/The%20basic%20idea.md), [`Conversation with Emily and Heather.md`](docs/Conversation%20with%20Emily%20and%20Heather.md) | Origin material — useful for *why*, not current spec |

**When documents disagree, Information Architecture wins on naming, and the PRD's Decision Log (§15) wins on scope.** The PRD still contains some pre-rename terminology in places (e.g. "Project"/"Milestone" in the old sense) — don't propagate that into code or new docs.

## Terminology (do not mix these up)

| Current term | Superseded term | Meaning |
|---|---|---|
| **Deal** | ~~Project~~ | The transaction (a sale, purchase, or rental placement) |
| **Stage** | ~~Milestone~~ (old, broad sense) | A *period* within a workflow — start/end dates, holds tasks and gates |
| **Milestone** | — (narrowed meaning) | A *moment*: the notable completion of a stage. Not a table — it's `stages.is_milestone` + `stages.milestone_label` |
| **Gate** | — | A condition that must clear before a stage/deal can advance |
| **Workflow** vs **Workflow Template** | — | Runtime instance vs. reusable definition — keep the layers separate |
| **Team** | — | The tenant boundary |
| **Status Page** | ~~Client Portal~~ | The read-only, magic-link client-facing view |
| **Status Viewer** | ~~Portal User~~ | The client's access role |

Full three-vocabulary table (code / internal label / client-facing label) is in [`Information Architecture.md`](docs/Information%20Architecture.md) §2.

## Architecture principles to carry into implementation

These come from PRD §8. Each is a real invariant, held by a test where one is
named — treat that test as the authority, not this summary.

- **Template vs. instance split.** Definition layer (`workflow_templates`, `stage_templates`, `gate_templates`, `task_templates`) and runtime layer (`workflows`, `stages`, `gates`, `tasks`) are separate. Instantiating a template snapshots it — later template edits must never rewrite an in-flight deal.
- **Single mutation path for workflow state.** All advancement goes through `App\Support\Workflow\AdvanceWorkflow` — evaluates gates, applies the transition in a transaction, dispatches triggered actions, writes timeline/audit entries. No controller mutates workflow state directly. Held by `tests/Unit/SingleMutationPathTest.php` (reads all of `app/`, `routes/`, `database/`) and `HasStateMachine`'s `saving` hook (refuses an illegal transition however it was written) — a source-reading guard alone was walked past in review, so both layers exist.
- **Gate evaluation is data-driven.** One evaluator class per `gate_type` (manual confirmation, required tasks complete, document present, field populated, action completed, date reached, approval). Adding a gate type means adding a class, not touching advancement logic.
- **Multi-tenancy: single database, single schema, `team_id` on every business table.** Enforced in layers — global Eloquent scope, composite FKs, middleware, policies, cross-tenant isolation suite. A gap here is a release blocker. See [`docs/adr/0002`](docs/adr/0002-multi-tenancy-enforcement.md). Thirteen models legitimately carry no `team_id`, each with a reason in `tests/Isolation/ModelTenancyConventionTest.php` — **that test's list is the authority; any number quoted in this file will go stale.**
- **A lookup is archived, never deleted.** No destroy route; in-use count shown before the choice, not after; archiving reversible; count scoped to the asking team; system rows get no controls (not disabled ones). Pattern set by deal types (S76), followed by roles and templates. Reasoning in [`Frontend conventions.md`](docs/Frontend%20conventions.md) §4.
  - **The count is scoped even when the row is not** — shared rows (system roles, pack templates) need a per-team count, or one team learns another team's numbers (`WorkflowTemplate::inUseCount()`).
  - **An in-use count isn't always a "be careful" warning** — for a snapshotted template (S41) it's reassurance that editing won't touch the deals already running on it.
- **A link out, never a copy of what's on the other end.** PRD §10: MLS data is licensed, so `external_links` is a label + URL with no column for title/price/photo/description — adding one is a licensing decision, not a feature. URLs are held to an http/https allowlist on the way in and on save (`App\Support\Links\SafeUrl`), since a stored `javascript:` URL is stored XSS the moment it's an `href`.
- **A URL out of the environment is still rendered.** `BUG_REPORT_URL` (#176) lands in an `iframe src`, so it goes through the same `SafeUrl` http/https allowlist a typed link does — "only an operator can set it" describes who makes the typo, not what it does. And `allow-scripts allow-same-origin` is **not a sandbox** when the framed document is on an origin we answer on (it reaches `window.parent` and reads the session), so `BugReportForm` refuses one: checking **both** `APP_URL` and the host actually serving the request, comparing host **and port**, and treating a port-less origin as standing for both 80 and 443 (HSTS upgrades `http://<app host>` and lands it same-origin).
- **A static is not "once per process" in a web SAPI.** PHP tears user-land statics down at every request boundary (this image runs `frankenphp run` with no worker directive), so a per-process latch holds only inside the Pest process — where it was never needed. Cooldowns belong in the cache, and the test travels past the TTL, which is the one black-box observation separating the two.
- **A diagnostic keyed `reason` reaches the log as `[redacted]`.** `Redactor::SENSITIVE_KEY_PARTS` holds `reason` and `ScrubPii` is tapped onto every channel. Log an enumerated **`reason_code`** (`ALLOWED_KEY_PATTERNS` passes `_code$`) and assert through `Redactor::context()` — `Log::spy()` intercepts above Monolog and cannot see the redaction, so every test passes while the operator gets nothing.
- **A derived name is derived, and a typed one wins.** `deals.name` (typed, sticky) vs `deals.generated_name` (derived from facts). `App\Support\Deals\NameDeal` is the only writer of `generated_name`; `Deal::displayName()` picks which a screen sees. Every fact it derives from needs a refresh call site — currently seven: `PropertyDeals::link/unlink/promote`, `SaveProperty::update`, `DealRoster::add/replace/remove`. Adding an eighth fact means adding an eighth call site (a missed one is why buy-side deals with no subject property could render "Untitled deal").
- **An event's subject is not the same question as which deal it belongs on.** `activity_events` carries `subject_type`/`subject_id` (what happened) and `deal_id` (where a team looks for it) separately — a contact logged against a person is subjected to the person with the deal as context. `RecordActivity` fills `deal_id` from the subject when the subject *is* a deal; anything else passes `deal:` explicitly.
- **A shared table's key is a shared namespace.** `roles` has no global scope (system roles carry no `team_id`), so a derived key can collide with a system row — `Str::slug('Team Owner', '_')` is exactly `team_owner`. The name is refused at the controller, and `TeamMembership::hasRole()`/`scopeHoldingSystemRole()` require a null `team_id` to match a system row. Before deriving an identifier into a shared namespace, check what's already in it.
- **A polymorphic child is reached by nothing.** No FK points at `documents.documentable_id` or `external_links.linkable_id`, so nothing cascades on delete. `HasExternalLinks`/`HasDocuments` sweep on the **parent's own `deleting` hook** (a rule in one caller is a rule the next caller lacks). Files also live on disk, which the row-level sweep doesn't touch — `records:purge` deletes the bytes and sweeps the team's disk directory separately.
- **A shared read filtered per screen is filtered once, then never again.** `ActivityFeed::query()`'s subject-type filtering must be an **allowlist** (`subjectPermissions()`), not an exclusion list — an exclusion list fails open the moment a second screen reuses the query with a different policy gate. `ActivityFeedIsolationTest` reads every `subject:` literal in `app/` and fails when one has no rule.
- **A staging table needs its own sweep.** `records:purge` only finds rows by `deleted_at`, so a table that ends by neglect (not an explicit delete) needs its own entry in `PurgeSoftDeletedRecords`. The column to sweep on is chosen per table: `contact_imports` on `created_at` (an upload is a single sitting), `deal_drafts` on `updated_at` (a wizard is genuinely resumed days later).
- **A presentation table is not a vocabulary.** IA §8's five stage states (`lib/states.ts`, throws on an unknown one) and Design System §7.4's seven-row stage-rail marker table answer different questions. **Overridden** is a marker, not a state — a stage that completed over an overridden gate *is* `complete`; `hasOverride` draws a different marker over the same badge. The marker applies only to a **completed** stage (an active stage can still be genuinely blocked by other gates) and never to a **skipped** one (IA §7: conflating Skip with Override is legally material).
- **A cache is only true at the moment something refreshed it.** `stages.state` is written only by an advance attempt, so it can go stale between attempts. Both screens that draw a current stage read the same function, `StageReadiness::stageState()` — the active stage renders live via `App\Support\Workflow\DescribeBlockers` (writes nothing), every other stage renders from the cached record (its gates are history, not a live question).
- **An eager-load is a claim that a row needs the relation — verify with a fixture, not a query count.** A query-count budget (`DealsIndexBudgetTest`) is blind to a fixed cost paid on every page; the guard that actually catches a broken `with()` is two **same-sized** fixtures differing only in whether the relation is populated. Before adding a `with()`, name the cell that reads it.
- **Reading is not advancing.** `AdvanceWorkflow` determines "what's blocking this stage" by attempting the advance, which writes `stages.state` and refreshes gate caches — never call it from a read path. Hub screens read through `App\Support\Workflow\DescribeBlockers`, which composes the same (pure) evaluators and writes nothing.
- **One header for a deal, built once.** Every deal tab renders `App\Support\Deals\DealHeader::for($deal)` via the `dealHeader` prop, drawn by `resources/js/layouts/DealLayout.vue`. Adding a deal tab means adding it to `DEAL_TAB_PAGES` in `resources/js/app.ts` and to `DealHeader`.
- **A cascade that means *context* has to be stepped around.** `teamScopedForeign()` always cascades, which is wrong when the FK means context rather than ownership — `activity_events.deal_id` is that case. The purge detaches those rows (by an allowlist of subject types) before the parent goes. See [`docs/adr/0002`](docs/adr/0002-multi-tenancy-enforcement.md).
- **An override is four artefacts, not a flag:** the flag, an immutable audit entry (who/when/which gate/why), a timeline marker, and an auto-created follow-up task — all written inside `AdvanceWorkflow::override()`. The follow-up task is deliberately **not** `is_required` (it would be counted by the very gate the override is meant to clear). Overriding never advances — the modal reopens onto the refreshed checklist so Advance is a second, deliberate press. Never write `stages.skipped_reason` here — Override and Skip are legally distinct (IA §7).
- **A row nothing can reach is a rule nobody is following.** If a gate type, state, or flag has exactly one way to be satisfied, verify that path is actually reachable from a route/controller/page — not just evaluated. (Both `required_tasks_complete` and `ManualConfirmationEvaluator` shipped this way; `AdvanceWorkflow::confirm()`/`unconfirm()` closed the second one.) `DocumentPresentEvaluator` and `ApprovalEvaluator` are the ones still owed this check.
- **A check that cannot fail is worse than no check.** `scheduler` inherited the image's own `HEALTHCHECK` (Caddy's admin API on `:2019`) because, unlike `app` and `worker`, it never overrode it — so it read `unhealthy` while dispatching on time, and a real outage would have looked identical to the steady state. The reverse trap sits in the replacement: `CMD-SHELL` runs the test as a `sh -c` process whose own `/proc` entry contains the pattern, so a literal `grep "schedule:work" /proc/*/cmdline` matches itself and always passes. `schedule:[w]ork` is the fix, and the bracket is load-bearing. **Prove a healthcheck can go red before trusting that it is green.**
- **Completing is not editing.** `POST`/`DELETE` on `deals/{deal}/tasks/{task}/completion`, separate from the `PATCH` that edits the task — only completion writes an activity event and feeds a gate. `App\Support\Deals\DealTasks` owns the table. Completion is idempotent on the *column*; the *event* still records once per actual completion.
- **A shared row may not point at a private one, and a composite FK won't say so on its own.** `message_templates` is strictly team-scoped; `action_definitions` is nullable-team (can be system-shared). Postgres FKs are MATCH SIMPLE, so a null `team_id` satisfies a composite FK without checking anything — a `CHECK (team_id IS NOT NULL OR message_template_id IS NULL)` is what actually prevents a system automation from naming a team's private template. Also: `ON DELETE SET NULL` on a composite key nulls **every** referencing column unless the list is named explicitly.
- **Two ways to put a human in the loop is one way.** F5.4's manual-send prompt and F5.7's approval queue are the same moment from two ends — `action_definitions` refuses both-on with a CHECK constraint, and S44's UI offers one three-way choice rather than two independent booleans.
- **Escaping is decided by where a value lands, not where it came from.** `MergeFields::resolve()` returns raw values; `RenderMessage` is the only escaper — HTML-escaped into `body_html`, untouched into `body_text`, CR/LF-stripped into `subject` (a mail header). The template body itself isn't escaped (author's own HTML), but the preview renders it in a sandboxed iframe.
- **A validator scanning for well-formed tokens is blind to malformed ones.** `MergeFields::extract()` is loose about what's between `{{ }}` and checks well-formedness after, but a dropped brace (`{{ client_name }`) matches nothing and needs its own check — `MergeFields::strayBraceRuns()` removes every matched pair and flags what's left over as unbalanced.
- **Both ends of a paired invariant need the rule.** An automation's action type and its template's channel must agree — checked in both `SaveAutomationRequest` and `ActionDefinition::booted()`/`MessageTemplate::booted()`, since editing either side alone can reach the broken state. A guard inside a `booted()` hook must query the model directly, not through the team-scoped global scope, or it silently no-ops for exactly the callers it exists to catch.
- **An email is the first thing this product renders without a browser.** `App\Support\Formatting\Format` mirrors `resources/js/lib/formatters.ts` server-side, kept to only the rules a message needs. Its test copies `formatters.test.ts`'s worked examples verbatim so a one-sided change fails in the PR that made it.
- **Automation is the highest-blast-radius feature** — an email to the wrong client can't be recalled. PRD §4.5's approval-queue and safety-rail behavior (F5.7, F5.9) are launch blockers, not enhancements. The findings below are what building the send path (#92, #93, #96) taught:
  - **An idempotency key you generate is not the one the provider hands back.** A provider call can time out *after* accepting the message, so a key derived from the provider's response can't dedupe that case. `action_instances.message_key` is ours, claimed via a conditional `UPDATE … WHERE message_key IS NULL` before the mailer is ever called.
  - **Rows go inside the advance's transaction; jobs dispatch after it commits.** A row outside the transaction can end up in an approval queue for an advance that rolled back; a job dispatched inside can be picked up before the commit lands. `AdvanceWorkflow::dispatchRaised()` is the boundary.
  - **A rendered body is a cache of the deal at raise time.** `action_instances.payload` holds pre-rendered words (F5.10 needs "ready to review" to mean something), so a `{{ token }}` typed into an edited field during approval has nothing left to substitute it — `ApproveMessage` refuses tokens in an edited field rather than re-substituting at approval time.
  - **Cancellation, not existence, distinguishes "already sent" from "never sent."** Reopen-dedupe excludes **cancelled** rows (a skipped stage cancelled its queue; nothing went out) but counts **failed** ones (it'll fail again for the same reason).
  - **A default nobody can leave is not a default.** `teams.approval_required_until` (F5.7's 30-day hold) has no DB default and must be set explicitly — it lives on `Team::booted()` now because `ProvisionTeam` isn't the only door that creates a team (admin console, factories, future signup).
  - **"Not mine" and "broken" are different refusals.** `ApproveMessage::cancel()` on a `pending` (already-dispatched) row is the ordinary case, not a race — a worker arriving after cancellation must stand down silently (`SendDecision::standDown()` writes nothing), not overwrite the cancellation reason with a rail error.
  - **A trigger wired to one implementation of a thing is wired to none of it.** `gate_cleared` only fires where `gates.is_met` actually transitions false→true, inside `evaluateGates()` — which only runs on Advance, not on every underlying change (e.g. a task completing doesn't itself re-evaluate). An override raises nothing because no evaluator reads `gates.overridden` at all.
  - **"Nobody owns this row" and "someone owns it right now" are indistinguishable by staleness alone**, because the crashed-worker case and the live-sibling-past-its-visibility-timeout case present identically. A worker **always stands down** on a `pending` row with a claimed key; outcomes are decided elsewhere — a read (S47's queue list) or later by `automations:reap-unconfirmed`, never by a second worker's own judgment.
  - **Type against `CarbonInterface`, not `Illuminate\Support\Carbon`.** This project's dates hydrate as `CarbonImmutable`; an `instanceof Carbon` check is false for every row and fails silently closed.
  - **A gate belongs in `ExecuteAction::handle()` ahead of the `match`, not inside one branch.** `SendRails`'s ownership check only covered `send_email`; `create_task` had none, so a cancelled automation could still create the task.
  - **A rail's own refusal is a write, so check ownership of the row before writing a reason.** `SendRails::decide()` must confirm the row is still `pending`/unclaimed before stamping an `error` — otherwise it overwrites a cancellation reason or writes onto an already-delivered message.
  - **A rail with no UI is a rule nobody can pull.** F5.9's kill switch needed its own screen (`/settings/sending`) — a panel buried elsewhere isn't reachable fast enough during an incident.
  - **An alert hung off one failure path is hung off none of them.** S91 fired from `ExecuteAction::fail()` and never fired for the outage it was written about — a transport exception is caught in `send()` and re-thrown, never reaching `fail()`. `automations:alert-on-failures` reads `state` instead: a row is `failed` however it got there, so a branch a later slice adds cannot bypass it. Ask what the failure *is*, not where it is announced.
  - **A high-water mark must point at a boundary, not at a row.** `executed_at` is `timestamp(0)`, so a burst shares a second — a mark set to a reported row's timestamp silences every sibling that landed in that second after the `SELECT`, permanently. The sweep picks its own boundary and reports `[mark, boundary)`. **A frozen clock cannot see this defect**, which is why it survived a review round green.
  - **A boundary at `now()` walks over rows that were never visible to it.** `executed_at` is stamped in PHP and becomes visible at COMMIT, and `onOneServer` pins the *scheduler*, not the writers — so the boundary sits a minute behind the sweep. Gross clock skew still defeats it, and the code says so rather than implying otherwise.
  - **A durability promise cannot live in a cache**, and the branch you did not think of as a caller is the one written without the rule. The mark is a column on `teams`; the empty-window branch must *anchor* it once, because a null column falls back to a floor relative to `now()` that slides forward every sweep. `withoutOverlapping()`'s default mutex expiry is exactly `COLD_START_HOURS` — a margin of zero against a framework default is not a margin.
  - **`COALESCE(a, b)` to widen a claim widens what can move it.** Keying the window on `COALESCE(executed_at, updated_at)` let any save drag a row back in front of the mark. For a state the product cannot produce, silence beats an email every five minutes.
  - **A headline that asserts is wrong for one caller.** *"An automated message did not go out"* is false over the reaper's *"it may have reached the recipient"*, and false again for a `create_task` that involved no message. Derive the words from the action type; let the row's own `error` say what happened.
  - **A guard's *candidate filter* is as much a hazard as its pattern list.** `SingleMutationPathTest` missed `action_instances.state` because its file filter only opened files mentioning `Stage`/`Workflow`/`Gate` — `ExecuteAction` mentions none of them. Adding a guarded column means adding its model/table to the filter, not just the pattern.
- **Email is a surface the product does not control, and Design System §12 is a separate universe** — tables, inline styles, literal hex, a real plain-text half. `EmailPalette` copies §12.1 and a test holds it to the document. What building it taught (#97):
  - **The address, the display name and the reply answer to different masters — and all three have to move together.** The address is the one verified identity: SES rejects an unverified `From` at the API, and one the message is not DKIM-aligned with fails **DMARC** (not SPF, which is evaluated against the envelope MAIL FROM, not this header — getting the mechanism wrong is how somebody later concludes the rule is negotiable). The **name** is what a person reads, so it is the team's: *"Bosart Group via Goldieflow"*. And the **reply** must reach the team, because a From line naming the agency over a reply that lands in the product's mailbox is worse than not doing it — the old line said *Goldieflow* and at least matched where replies went. Shipping the first two without the third is the defect review caught. `SendingIdentity` holds all three, and every tenant string reaching a header goes through `RenderMessage::headerSafe()` — a claim worth re-checking rather than repeating, since the invitation's **subject** interpolated `$team->name` raw while the From name beside it was stripped. A rule true of every case but one is the shape people stop checking.
  - **`APP_NAME` is an infrastructure identifier, and a display string pinned to it inherits that.** It is slugged into the session cookie and the cache, Redis and Horizon prefixes, which is why the rename note leaves it at the codename — so teams were sending *"Bosart Group via Brawling Mahogany"* to their sellers. `config('app.product_name')` (`APP_PRODUCT_NAME`) is the name a person reads and the only one safe to change. **Before pinning a display string to an existing config value, check what else derives from that value.**
  - **`config('app.name')` has no infrastructure reader — every prefix calls `env('APP_NAME')` directly.** So the config key is purely a display value (as Laravel documents it) and resolves to `APP_PRODUCT_NAME`; the env var stays the codename and the session cookie and three prefixes never move. Splitting only `app.product_name` off fixed the code we own and left every **vendor** view — `Illuminate\Notifications`' email, `Illuminate\Mail`'s components, Fortify's 2FA issuer — still rendering the codename. **Before adding a key beside an existing one, check whether the existing one can simply be corrected.**
  - **A pin that cannot pin.** `phpunit.xml`'s `<env>` skips a name already in `getenv()`, Laravel's `Env` reads `$_SERVER` first, and `compose.yaml`'s `env_file: .env` puts a developer's whole `.env` into the environment `make check` runs in — so the pin was green in CI and ten tests red locally. `TestCase::setUp()` sets the config directly, and a test asserts the pin is in force: a pin with no test is a hope.
  - **A default chained to `APP_NAME` inherits a value nobody may change**, and that chain is written in more than one file. `.env.example` was corrected while `config/mail.php` still read `env('MAIL_FROM_NAME', env('APP_NAME', …))`, so every message the product itself writes kept signing as the codename for a round after the fix. `tests/Unit/ProductNameSeparationTest.php` reads the files, because `phpunit.xml` pins the value and no rendered-message test can see it.
  - **A test that asserts `config(X)` cannot see a wrong value in `X`.** Both display-name tests read the config they were testing, so they were true of any value — including the codename that was actually going out. A test about *which* config key feeds a slot has to assert the literal.
  - **Validate with the parser that will consume it.** `emily(work)@bosart.test` passes Laravel's `email` **and** `email:rfc` (an RFC 5322 comment is legal) and throws in `Symfony\Component\Mime\Address` — inside a worker, after `message_key` is claimed, so the row fails permanently and the team is told the *transport* rejected it. `App\Rules\SendableEmailAddress` runs the real parser at save time; `SendingIdentity::address()` skips an unparseable stored candidate rather than throwing. The first version of that skip constructed *Laravel's* `Address`, which validates nothing — the same mistake, inside its own fix.
  - **An owner who has left is not a reply address.** `oldest('id')` picks the *founding* membership, so without `active()` a client's reply goes to whoever set the team up however long ago they left — silently, since the address still exists. Both other owner queries in `app/` filter revocation, and one is F5.9's sandbox redirect target: disagree and the rail and the reply chain name different people for the same team.
  - **A fallback justified for one reader is not justified for another.** `Person::displayNameWithin()` ends at the sign-in address, and its docblock argues that is safe — for an *audit entry*, read by people already in the team. The invitation put it in front of a stranger with no account. PRD §5.1 step 1 reaches it every time: a platform operator has no membership in a team created four lines earlier.
  - **`people.email` is a credential; `team_memberships.email` is what a team recorded.** The invitation put the first in `Reply-To` while taking the display name from the second — two halves of one header reading different tables, and telling a stranger which address signs in.
  - **A fallback chain every link of which is empty for the ordinary case is not a fallback.** `teams.sending_identity_email` is nullable and nothing sets it — not the migration, not `ProvisionTeam`, not the factory — so the reply chain added in round 1 resolved to nothing for every team that existed. A team owner is the last link. And because a name in an inbox is a promise somebody will answer, `SendingIdentity` is **one object returning both halves**: there is no way to get the From without the Reply-To, and with no reply address the team's name is dropped rather than claimed.
  - **A tenant's colour is a fill with a computed foreground, never text.** §2.7 gives a team's accent to headings; a reader in dark mode gets a deep brand on near-black, and a team picks a deep colour *because* it looks right on white. In email the accent is only the header band and the button, both of which bring their own ground. S72 *warns* about low contrast because somebody is standing there; email *computes* because nobody is — which is how §15.6 settles.
  - **A raster asset cannot participate in the token layer** (§2.6). A logo gets a plate that stays light in both schemes, and it is **embedded** rather than linked: the bytes are on a private disk and a client has no session to fetch them with.
  - **A dark-mode block without `!important` parses cleanly and does nothing**, because every rule it overrides is an inline style. Omitting it is worse than omitting the block — it looks handled.
  - **A reader with no writer is as dead as a row nothing can reach.** `teams.logo_path` shipped in Slice 1 with nothing able to set it, and read as finished from either end until a layout needed it.
  - **The second front door is the one you cut for a layout.** S87 is a frame around an ordinary `stage_completion` automation, not a `MilestoneNotificationMail` — a second mailable would be a second path to a client's inbox past F5.7's queue and F5.9's rails.
  - **A frame drawn live around a body drawn earlier is two moments in one email.** The announcement is snapshotted beside the words at raise time, because what an approver reads on S48 *is* the payload — and **putting a value in the payload is not showing it to them**, so S48 draws the frame too. Branding is deliberately the opposite: deal content is snapshotted, identity is live.
  - **A test that renders nothing proves nothing about rendering.** `Mail::fake()` never executes a view, so `tests/Feature/Mail/` puts the real `array` transport back and reads the MIME. Same trap one layer along: `Illuminate\Http\Testing\File::getMimeType()` answers from the *filename*, so an upload test written with `fake()` never reaches a bytes-decided allowlist.
- **Documents are the highest-risk surface, and the guardrails ship before the button** (#98, #99, #100, #104). Slice 2 closed #63's residual window by restricting *context* — images only, against a property only — because *"a photographed check is an image, exactly what a photo gallery accepts."* The general path exists only because `SensitiveContent` reads the bytes. What building it taught:
  - **An optimisation that changes a value's default is a change to every caller that took the default.** Batching the uploader lookup into S50 left `uploadedBy` null on S52 — the only screen that renders it.
  - **Narrowing and widening the same pattern trades one direction for the other.** The SSN check oscillated over three rounds: match hyphens only (misses every extracted form), widen the separator (refuses a tax proration line), route through collapsed text (refuses every three-two-four numeric column). The shape alone is not the signal. A punctuated SSN and a *labelled* spaced one are; so is a **labelled** routing number, where a bare checksum passes one nine-digit run in ten and refused parcel numbers, MLS references and the wire fraud advisory brokerages are required to circulate. **A guardrail people learn to route around protects nothing.**
  - **`gzinflate`/`gzuncompress` without `$max_length` is an uncatchable OOM.** A 1MB PDF fatals a 128MB process, `@` suppresses the warning and not the death, so no `catch` downstream ever runs. Any decompression reachable from an upload endpoint is bounded.
  - **A test that cannot tell the fix from the defect is not a test.** The bomb above produces `not_scanned` either way, and `memory_get_peak_usage()` is monotonic across a run, so both in-process assertions passed against the defect. Reproducing the condition needed a subprocess with production's `memory_limit`.
  - **A partial read has more than one door.** `MAX_CHARACTERS`, the stream budget and the OOXML part cap all end a read early, and a confidence check that knew only the first labelled a 500-stream PDF `clean` with its statement page unread.
  - **A scanner that cannot read must not report "clean".** `ReadableText::from()` returns **null**, not `''`, for an image or a text-free PDF, so `documents.scan_state` keeps `clean` and `not_scanned` apart and every screen says which. A badge reading "clean" over a photograph of a cheque would be believed, which is worse than saying nothing — and the help article says the same thing in the user's words rather than letting the check imply a guarantee.
  - **A refusal is a response, not an error.** It carries what was refused, why, and **where to put it instead** (#99: *"that is the part that makes this acceptable rather than infuriating"*), so it is read off the session into a dialog rather than flashed as a toast or squeezed into a field error. Nothing in it names the file — a filename is often the most descriptive thing about a document.
  - **A compliance control is a panel, not a description line.** Screen Inventory says S51 and S53 *"cannot be quietly softened later for being annoying"*, so the warning sits above the control it governs and names all five refused categories: the failure mode is somebody believing their file is the exception.
  - **A policy keyed on the wrong subject is a list you cannot open.** `DocumentPolicy` keyed every document on `properties.*`, true while documents were only a property's photographs; S21 attached them to deals, and a role with `deals.view` and not `properties.view` got a tab listing files that then refused to download. The permission follows what the document hangs off — matched on **class names**, since no morph map is enforced and a string literal would fail silently open to the default.
  - **A `nullable` field the form did not send is absent from `$validated`, not null in it.** `$validated['caption']` was a 500 on every upload without a caption — the ordinary case — and every earlier test happened to send one.
  - **Report a number, do not imply a limit.** S50's storage figure is `SUM(size_bytes)` over live rows and nothing else: there is no plan tier to exceed, and a progress bar toward an invented ceiling is the kind of lie somebody later builds a billing assumption on.
  - **One path to the bytes.** The viewer's preview and the download both go through the subject's own audited route, so a rendered preview is an access with an entry behind it (PRD §9) and the authorization lives in one place rather than two that can drift. Never a presigned URL: an entry written when a link is *minted* records an intention, not a read.
- **No user flow depends on email alone.** Every email-initiated flow needs a second way to start/answer it (in-app, artifact handoff, or an operator console command) — email is a channel we don't control. See [`docs/adr/0003`](docs/adr/0003-no-email-only-flows.md). New mailables and mail-sending notifications are catalogued in `App\Support\Mail\EmailIndependence`; `tests/Unit/EmailIndependenceTest.php` fails the build when one has no resolvable second door.

## Data handling and security

- PII (client financial info, uploaded documents) is the single highest-risk surface. Certain document categories (executed contracts, earnest money instruments, lending packets, bank statements, government IDs) are **refused outright**, not just flagged — see PRD §4.6 and §10.
- Anything routed to a third-party AI/LLM provider must be redacted first, logged with model/version/cost, and never write into a live record (`key_dates`, `tasks`) without explicit human confirmation. See PRD §4.10 and §8.4.
- No PII in logs, ever. Audit log is append-only and must cover auth, permission changes, gate overrides, document access, extraction reviews, and super-admin impersonation.
- This product is explicitly **not** the system of record for executed contracts/signatures (that's the customer's existing e-signature platform) and does not ingest MLS/IDX listing data — only links to it. Don't build features that assume otherwise without checking PRD §10 (Compliance and Legal Considerations) first.

## Basic principles for working in this project

- The app runs in a Docker container, the database in a separate one, described by `docker-compose.yml`.
- Laravel + ShadCN + Vue + Inertia + Tailwind.
- Tests are required for anything built, from the start, and run in GitHub Actions.
- Local/CI should stay as close to production as reasonably possible; use compose includes to override, not divergent configs.
- All environment variables and secrets live in a gitignored `.env` at the root, passed into containers transparently.
- Branching: `main` is tagged releases only. Feature branches target `dev`. A PR merges `dev` into `main` to cut a release.
- Reuse components; prefer pre-built ones over rolling your own.
- Documentation is part of the development process — every PR that changes behavior updates the relevant docs (user-facing help, developer docs, PRD, ADRs, Design System, this file).

## Working in the codebase

### The commands

| Command | What it does |
|---|---|
| `make setup` | Boots the whole stack on a clean machine |
| `make check` | Everything CI runs, in the container |
| `composer check` | Pint, PHPStan, Pest |
| `npm run check` | Wayfinder, ESLint, Prettier, `vue-tsc`, Vitest |
| `php artisan migrate:fresh --seed` | A working demo team. Sign in as `emily@example.test` / `password`; `ian@example.test` is the super administrator |
| `php artisan platform:promote <email>` | Grant platform administrator to an existing account — the **first-run bootstrap** for `/admin`. `--demote` reverses it (`--demote-last` to skip the only-administrator warning). Audited |
| `php artisan db:seed --class='Database\Seeders\PerformanceFixtureSeeder'` | G8's volumes in a database you can open (PRD §9): 25 active deals, 500 past clients, 2,000 activity events. Sign in as `perf@example.test` / `password`. Not part of `migrate:fresh --seed` |
| `php artisan automations:reap-unconfirmed` | Records the outcome of sends handed to a transport that never confirmed. Hourly, `--hours=6` default. Never resends |
| `php artisan automations:dispatch-due` | Queues due automation instances with nothing coming for them (future `scheduled_for`, or stranded by a web process that died between commit and dispatch). Every minute |
| `php artisan records:purge` | The 30-day retention purge (PRD §9): team-scoped rows, deleted accounts, expired exports, abandoned import uploads. Nightly; safe to run by hand |
| `php artisan invitation:link <email>` | Print the accept link for an outstanding invitation (ADR 0003, no mail transport needed). `--team=<slug>` if invited to more than one. Rotates the token. Audited |
| `php artisan auth:reset-link <email>` | Print a single-use password reset link (ADR 0003). Audited |

`composer check` and `npm run check` are exactly what the pipeline runs. If one passes locally and fails in CI, that's a bug in the scripts, not something to work around.

### Where things go

Frontend structure, layout resolution, and the formatter list are in [`Frontend conventions.md`](docs/Frontend%20conventions.md). Two rules worth repeating:

- **Nothing formats a date, name, address, or amount itself.** Call `resources/js/lib/formatters.ts`.
- **Nothing decides its own state colour or label.** Call `resources/js/lib/states.ts` (throws on an unknown state). `lib/activity.ts` does the same for timeline event types but falls back to `state-neutral` instead of throwing (Design System §7.3); `tests/js/activityEventTypes.test.ts` catches an `eventType:` with no icon.
- **`cn()` is bare `twMerge`, which files `text-13` in the text-*colour* group.** So `cn('… text-13 text-muted-foreground')` silently drops the size and the element renders at the body's 14px. Use an array class binding wherever a custom size and a colour appear together.

### Component governance (Design System §13.2)

1. Check shadcn-vue first — it's probably there.
2. Composes from 2–3 shadcn parts? Belongs in `components/app/`.
3. Only then consider a third-party library (maintained, tree-shakeable).
4. **Never hand-edit `components/ui/`.** Extend via a `cva` variant or a wrapper in `components/app/` — `CODEOWNERS` guards the directory.
5. **No raw colours in components** — a needed colour with no token gets a new token.
6. A pattern used three times gets promoted into `components/app/` with a name.
7. New state → Design System §2.4 first, then `lib/states.ts`, then the badge.
8. Both light and dark values, always.
9. A tone is background + foreground + icon, moved together or not at all.

Rules 5, 7, and 8 are enforced by `tests/js/tokenDiscipline.test.ts` and `tests/js/tokens.test.ts`.

### The banned words (IA §11)

One concept, one word, in code, routes, and UI:

Deal (not Project) · Stage (not Phase or Step) · Milestone, narrow sense only
· Gate (Requirement allowed **only** in the deal view) · Task (not To-do or
Item) · Automation (not Action or Trigger) · Template (not Blueprint) · Pack
(not Bundle) · Participant (not Contact or Party) · Vendor (not Service
provider) · Dates & Deadlines (not Key dates, in UI) · Status Page (not
Portal) · Keep in Touch (not Nurture or Drip) · Team (not Organization or
Workspace) · Extract (not Scan, Parse, or AI).

Two carry a distinction the short form loses:

- **Person, not User** — "User" implies a login. `App\Models\Person` is the authenticatable (`password` nullable; null never authenticates). Name/email/phone live on `TeamMembership`, not `Person` — `people` holds only credentials, `TeamMembership::fullName()` is what a screen shows. A credential-less contact gets its own per-team row.
- **Activity, not History or Log** — "Audit" is the separate append-only security log, with different retention and readers.

**Advance** is the only verb for moving a workflow forward — never Progress, Move, Next, or Complete. **Override** and **Skip** are different actions with different audit consequences and must never be conflated in a label.

`tests/Unit/CodeDisciplineTest.php` fails the build when a superseded table name appears in code.

### Writing anything team-scoped

Four things, each caught by a test if forgotten:

1. **`use BelongsToTeam`** on the model, `$table->productDefaults()` on the migration (`tests/Isolation/ModelTenancyConventionTest.php`).
2. **Never put `team_id` in `#[Fillable]`** — the trait fills it from the resolved team. A factory needing a specific team uses `Database\Factories\Concerns\ForcesAttributes`.
3. **Authorize every controller action** — `$this->authorize()`, a FormRequest's `authorize()`, or `can:` middleware (`tests/Feature/AuthorizationCoverageTest.php`).
4. **A queued job carries its team** — `use RunsForTeam`, dispatch with `->forTeam($id)`, work inside `$this->withinTeam(...)`.

`App\Support\Activity\RecordActivity` (`activity_events`) and `App\Support\Audit\AuditLogger` (`audit_log`) are the only writers of their tables. The audit log redacts known-sensitive attributes before writing; the table's triggers refuse UPDATE/DELETE/TRUNCATE.

**A table with no `team_id` sits outside every mechanism keyed on one** — including the retention purge, which discovers its tables the same way. Slice 2 moved contact details onto `team_memberships` for exactly this reason rather than adding a special-case guard. The thirteen models still carrying no `team_id` hold credentials or reference data, never customer data — read [`docs/adr/0002`](docs/adr/0002-multi-tenancy-enforcement.md) ("The hole the layers do not cover") before adding a fourteenth.

### Testing

Four suites — Unit, Feature, Isolation, Performance — in [`Testing.md`](docs/Testing.md). Tests run against real Postgres; mail, notifications, and storage are faked, and a stray HTTP request fails the test.

Some project rules are held by tests rather than memory: enums checked against PRD/IA tables, log calls checked for interpolated values, every model checked for a tenancy decision, token pairs checked for contrast in both themes. When one fails, fix the code or the document — not the test.

## Adversarial Review

Any time you make a PR, subscribe to the PR and make sure that all tests are passing. Then use a sub-agent to do an adversarial review of the PR. The sub-agent should make any notes necessary in the PR, and you should respond by making any corrections which make sense. After updates, conduct the adversarial review again. Repeat up to five times, or until there's no feedback left to address. If it's still not done after five rounds, flag for followup. If there's no more feedback, merge the PR into `dev`. All reviews happen in the GitHub PR, and stay subscribed until it's merged.
