---
created: 2026-08-21
project: Goldieflow
type: reference
status: draft
version: 1.0
---

# Testing

> [!info] What this document is for
> The test conventions every later slice inherits. Set them badly in week one
> and ninety issues inherit the mistake — which is why they are written down
> before there is much to test.

`CLAUDE.md`: *"Testing is very important. For anything we build, we must have
tests. This should be a basic principle from the very beginning of the build."*

---

## 1. Running them

| Command | What it does |
|---|---|
| `composer test` | Pest, against Postgres |
| `composer check` | Everything CI runs: Pint, PHPStan, Pest |
| `npm run test` | Vitest — formatters, the state map, components, token discipline |
| `npm run check` | Everything CI runs on the front end: Wayfinder, ESLint, Prettier, `vue-tsc`, Vitest |

`composer check` and `npm run check` are the same commands the pipeline runs,
so the local loop and CI cannot disagree.

Inside the container stack: `docker compose exec app composer check`.

---

## 2. The four suites

| Suite | Directory | Covers |
|---|---|---|
| **Unit** | `tests/Unit` | Pure logic: gate evaluators, date offsets, merge-field rendering, the PII scrubber, the enums against the documents |
| **Feature** | `tests/Feature` | HTTP routes through policies and Inertia responses |
| **Isolation** | `tests/Isolation` | Cross-tenant access returns 403 or 404. **A release blocker** (PRD §8.2) |
| **Performance** | `tests/Performance` | Query-count and latency budgets on the screens that carry load |

Everything but Unit runs against a real Postgres, one transaction per test
(`RefreshDatabase`).

> [!note] A query budget is a **comparison**, not a ceiling
> A test asserting "fewer than 20 queries" passes an N+1 on a fixture of three
> rows, which is the size a fixture tends to be. The assertion that catches one
> is the same screen at two sizes returning the *same* number:
> `expect($large)->toBe($small)`.
>
> Two things that budget depends on. **Both fixtures are built before either is
> counted** — building the second inside a running listener counts its inserts
> against the first measurement. And the fixture has to vary the thing at risk:
> `ActivityFeedBudgetTest` gives every row a different actor, because fifty
> rows sharing one would be answered from the identity map and a per-row lookup
> would never show.

### Why Postgres and not SQLite

SQLite would pass tests that production fails. JSONB operators, enum-backed
check constraints, composite foreign keys on `(team_id, id)`, and `date` versus
`timestamp` behaviour are precisely the parts of this schema most worth
testing, and SQLite models none of them faithfully.

`phpunit.xml` therefore points at `brawling_mahogany_test` on the pgsql
connection. Locally that database lives in the compose stack; in CI it is a
service container on the same pinned version.

That database name still carries the old `Brawling Mahogany` codename, as do
the container and volume names. Renaming them is an infrastructure migration
rather than a documentation change, so they were left alone. See `CLAUDE.md`.

---

## 3. Nothing escapes a test run

`Tests\TestCase::setUp()` closes every outbound door:

- `Mail::fake()` and `Notification::fake()` — no message can reach anybody.
- `Storage::fake()` on the default disk — no upload is written outside a temp
  directory.
- `Http::preventStrayRequests()` — a request to a host the test did not
  explicitly fake **fails the test**. SES, the AI provider, and every webhook
  live behind the HTTP client, and a test that reaches one for real can cost
  money or send a message to somebody's client.

### The queue is deliberately not faked by default

Issue #25 asks for the queue to be faked alongside mail and storage. It is not,
and the reason is worth recording.

`QUEUE_CONNECTION=sync` in `phpunit.xml` means a dispatched job **runs**, so a
feature test exercises the job it dispatches rather than asserting that it
would have. With a globally faked queue, a broken job passes every test until
production. The escape risk that faking was meant to address is already closed
at the boundaries above — mail, storage, and HTTP.

A test that genuinely wants to assert dispatch rather than behaviour calls
`$this->fakeQueue()`.

---

## 4. Helpers

| Helper | Use |
|---|---|
| `$this->actingAsPerson($person, $team)` | Act as a person with credentials, inside a team. Passing no team is the "no access" case, and it must stay reachable |
| `$this->teamWithMember()` | A team plus an ordinary Team Member. **The default for a test that just needs somebody signed in** |
| `$this->teamWithOwner()` | A team plus a Team Owner. Note that an un-enrolled owner is redirected to 2FA enrolment (PRD §9), so every page assertion becomes a 302 — use `teamWithMember()` unless the test is *about* an owner |
| `$this->enrollTwoFactor($person)` | Give somebody the enrolment the mandate insists on |
| `$this->withTeam($team)` | Bind a team the way the middleware does, for tests that work against models rather than routes |
| `$this->freezeAt('2026-08-20 15:00')` | Pin the clock. This product is dates, deadlines, and derived offsets — a test that depends on "now" without pinning it fails at midnight |
| `$this->fakeQueue()` | Opt in to a faked queue |
| `expect($value)->toBeSnakeCase()` | IA §8: state and enum values are `snake_case`, always |

---

## 5. Factory house style

- **Every factory produces a valid record with no arguments.** A factory that
  needs three arguments to make sense is a factory nobody uses.
- **States, not argument soup.** `Deal::factory()->closed()`, not
  `Deal::factory()->create(['state' => 'closed', 'closed_at' => …])` repeated in
  forty tests. When the shape of "closed" changes, one state changes with it.
- **Relationships are explicit.** A factory does not silently create a team;
  the test says which team, because tenancy is the thing most worth being
  explicit about.

---

## 6. Tests that hold rules rather than behaviour

Some of this project's rules are mechanical, and checking them beats
remembering them:

| Test | Rule it holds | Source |
|---|---|---|
| `tests/Unit/DocumentedVocabularyTest.php` | The enums match the PRD §6.3 and IA §8 tables, exactly. Reads the markdown | issue #38 |
| `tests/Unit/CodeDisciplineTest.php` | No value is interpolated into a log message; the superseded vocabulary never appears; page components are PascalCase | PRD §9, IA §12, IA §6 |
| `tests/Unit/RedactPiiTest.php` | "No PII in logs, ever" | PRD §9 |
| `tests/Isolation/ModelTenancyConventionTest.php` | Every model is tenant-scoped or explicitly recorded as team-agnostic; every scoped table has the column and the foreign key | PRD §8.2 |
| `tests/Isolation/CrossTenantAccessTest.php` | **The release blocker.** Cross-tenant access is refused, by every vector: direct route, nested route, index, foreign id in a form, signed URL, and a queued job | PRD §8.2, §9, issue #42 |
| `tests/Feature/AuthorizationCoverageTest.php` | Every controller action asks a policy. Reads the route table, so a controller added later is covered the day it lands | PRD §9, issue #46 |
| `tests/js/tokenDiscipline.test.ts` | No raw hex and no Tailwind palette class in a component | Design System §2.1 |
| `tests/js/boundControls.test.ts` | Every `AppSelect` in `resources/js` has a `v-model` or an `@update:model-value`. It is props-and-emit, not `defineModel`, so `:model-value` alone is a control that displays state and can never change it — no type error, no runtime warning, and S28's pack filter shipped exactly that | issue #74 |
| `tests/js/tokens.test.ts` | Every state pair meets 4.5:1 in both themes; every colour token exists in both | Design System §11, §13.2 rule 8 |
| `tests/js/dealHeader.test.ts` | The §8.4 header the eight deal tabs share: IA §5.2's tab list in order, counts only on the tabs that are lists of something, a tab whose slice has not shipped rendered *and* disabled rather than linked, and the single primary **Advance Stage** button shown only when the server named one workflow and the reader holds `workflow.advance` | Design System §8.4, IA §5.2, issue #75 |
| `tests/js/controlSizes.test.ts` | Every button and input size matches the measured control table | Design System §4.2, §7.2, §11 |
| `tests/js/cssDependencies.test.ts` | Every package `app.css` imports is declared *and* installed — run inside the container by `make check`, so a stale dependency volume fails loudly instead of as a blank page | issue #22 |
| `tests/Unit/ProvisionEnvBlockTest.php` | The provisioning script's managed `.env` block wins over each competing spelling in its dataset, refuses a file it cannot read unambiguously, and never rotates `APP_KEY` | issue #36, Deployment §6 |
| `tests/Unit/BranchProtectionTest.php` | `scripts/protect-branches.sh`, `ci.yml`'s job names, and Deployment §7 agree — and every CI job has a `name:`, since an unnamed one cannot be required | issue #24, Deployment §7 |
| `tests/Isolation/TeamAccessConventionTest.php` | One definition of "is this person on the team". Every `SystemRole` carries a recorded decision with a reason and the seeded permission set has to produce it; every permission carries a `PermissionSurface`; the model, the members screen, the People index's Team and Clients segments, the console's team detail, and the team switcher are asserted to give the same answer about the same five memberships; and **two** source scans hold `app/`, each with its sanctioned uses listed by count and reason, comments stripped through the tokeniser and string literals kept: no file may decide team membership by naming role keys, and none may ask whether a role carries *any* permission at all — the spelling the pre-#142 `activeTeams()` used, which names no role and so is invisible to the first scan. Both assert a floor on the files walked, because a scan that matches nothing reads exactly like a clean codebase, and the second's control is synthetic since its sanctioned list is legitimately empty | PRD §4.2 F2.2/F2.3, ADR 0002, issue #142 |
| `tests/Isolation/UnscopedQueryConventionTest.php` | Every `withoutTeamScope()` in `app/` is listed with a reason, and each listed file has the count it says. Both spellings, comments stripped through the tokeniser | ADR 0002 |
| `tests/Unit/SingleMutationPathTest.php` | Nothing but `AdvanceWorkflow` writes workflow state — across `app/`, `routes/` and `database/`, and through every spelling of the write, `DB::table('stages')` included. That now covers **both** gate columns: `overridden` was added when #77 gave it a first writer, and `is_met` when the confirmation route gave it one. They are guarded as a **pair** — IA §8 insists overridden is not a kind of met, and a caller able to write one without the other is exactly where that distinction starts to drift. Slice 3 added `action_instances.state`, and that widening was to the **candidate filter** rather than to the patterns: the column is called `state`, so every shape already matched, but the filter only opened files naming `Stage`, `Workflow` or `Gate` — and `ExecuteAction` names none of them. That column decides whether an email reaches a client. Four sanctioned writers now, each owning one verb. Carries its own dataset of bypasses so the detector cannot be narrowed by accident | PRD §8.3, §4.5, IA §8, issues #68, #77, #92 |
| `tests/Feature/Workflow/StateMachinePersistenceTest.php` | An illegal transition throws on `save()` however the attribute was written — assignment, `setAttribute`, a variable column name, or `forceFill` | issue #65 |
| `tests/Unit/EmailIndependenceTest.php` | Every mailable in `app/Mail`, every notification in `app/Notifications` with a `toMail`, and every mail-sending Fortify feature that is switched on, is catalogued in `EmailIndependence::FLOWS` with a non-email alternative — and every alternative it names resolves against the real route table or the artisan registry | ADR 0003 |
| `tests/Unit/ExternalLinkConventionTest.php` | The models that use `HasExternalLinks` and the class names in `ExternalLink::LINKABLE` are the same set, and every one of them carries a team. A polymorphic pointer has no composite key to refuse a foreign target, so the allowlist *is* the constraint | ADR 0002, issue #61 |
| `tests/Unit/SafeUrlTest.php` | Only `http` and `https` may be stored and rendered as a link. `javascript:` and `data:text/html` parse cleanly and are script execution in the reader's session — Laravel's `url` rule accepts both | PRD §4.3 F3.4, issue #61 |
| `tests/Isolation/DealPropertyIsolationTest.php` | A link row is reachable only through the deal it is on. The tenancy layers answer "whose team"; only `Route::scopeBindings()` answers "whose deal", and the ranking route — which writes by a list of ids — is held with two deals in **one** team, because a cross-tenant version of that test would pass whether or not the deal filter existed | ADR 0002, issue #62 |
| `tests/Unit/ActivityCategoryTest.php` | Every `eventType:` literal in `app/` has a prefix some `ActivityCategory` case claims. An unclaimed one is invisible at runtime — the row still shows under All and simply never shows under any other tab — so the filter would lie silently | issue #81 |
| `tests/js/activityEventTypes.test.ts` | The same event types all have an icon in `lib/activity.ts`. That table deliberately falls back rather than throwing (Design System §7.3 specifies the fallback), so nothing at runtime would ever report a forgotten one | Design System §7.3, issue #81 |
| `tests/js/logContactDialog.test.ts` | S26's **two-click target**, measured: with the person known, a type tile and Log it save an entry, and nothing else is ever required. A requirement stated in prose erodes one field at a time | Screen Inventory S26, PRD F12.3 |
| `tests/Isolation/DealDraftIsolationTest.php` | A wizard draft is the actor's and its team's, and a foreign id sent inside a *step* is refused — there is no draft id in a URL to send, so the steps are the vector. Also holds both halves of the abandonment sweep: a draft nobody came back to is purged, and one touched yesterday is not | ADR 0002, PRD §9, issue #74 |
| `tests/js/gates.test.ts` | Design System §7.4's requirement row decides one thing once, for S15, S16 and S23 alike — and an **overridden** gate is drawn as neither met nor advisory. `StageReadiness` sorts it into the advisory bucket (`blocksAdvance()` is `is_blocking && ! overridden`), so before #77 a row reporting itself non-blocking was always genuinely advisory and S15 drew the Advisory pill from that flag. The first override would have rendered a bypassed requirement as optional | Design System §7.4, IA §8, issues #77, #69 |
| `tests/Feature/Workflow/OverrideGateTest.php` | F4.9 is **four** artefacts and asserts all four: the flag with who and why, an immutable audit entry naming the gate, a distinct `gate.overridden` timeline marker, and the follow-up task. Also that the follow-up is **not** `is_required` — a required task on the stage being left is counted by a `required_tasks_complete` gate on that same stage and would block the very advance the override exists to permit | PRD §4.4 F4.9, §5.5, issue #69 |
| `tests/Isolation/ActivityFeedIsolationTest.php` | The feed across **both** boundaries — the wrong team, and the wrong colleague. Its enumerating case reads every `subject:` argument in `app/` and fails when one resolves to a class `ActivityFeed::subjectPermissions()` does not name, so a subject type added in a later slice is invisible rather than public. That inversion exists because the previous exclusion-list shape failed open twice: the person rule was missing outright, and the dashboard then reused the query behind a different screen gate. Carries a floor on what the scan found, because a pattern that quietly stopped matching would make the assertion pass over an empty list. In Slice 3 the scan became **structural**: `subject:` stopped being only `RecordActivity`'s the moment mailables arrived, and `new Envelope(subject: $subject)` read as an activity subject type twice under two different names as the expression behind it changed. Narrowing the *expression* cannot work — `subject: $subject` and `subject: $deal` are the same shape — so occurrences are classified by the **call** they belong to, and an unrecognised one fails the build rather than being guessed at | ADR 0002, PRD F2.5, issues #81, #82, #88, #92 |
| `tests/Feature/Help/HelpTest.php` | The manual is checked **against the application**, because documentation rots in one specific way — it goes on describing a product that has moved. Every internal link resolves: `/help/…` against the articles, an asset against `public/`, everything else against the **static** route table — a pattern match would let `/deals/anything` through, and a manual links to screens rather than to rows. A relative link is refused by name, because `](tasks.md)` renders to a real 404; a link with a scheme is skipped, because a manual wants outbound links. The article check is the load-bearing half — `/help/{article}` matches any segment, so a route-level check waved through `/help/logging-a-contact`, an article that never existed, on the first draft. Also: the IA §11 vocabulary, since a manual is where somebody learns what the words mean; every `**Section →**` instruction against the sidebar, read out of `navigation.ts` rather than hand-copied — a copy of the thing you check against drifts, and this one had already; and both halves of the placeholder rule — everything in *Coming later* marked, nothing outside it | IA §11, issue #170 |
| `tests/js/helpPages.test.ts` | The two Help pages and `IconButton`'s link branch. The decisions rather than the markup: the contents list comes before the article in the DOM and is moved right by `lg:order`, because reading order is what a keyboard follows; it is dropped below three headings; a summary is clamped rather than truncated, since every one is a sentence written to answer *"is this the article I want"*; and a control that navigates is an anchor, because middle-click and "open in new tab" are what people do to a help icon | Design System §7.2, §11 |
| `tests/Performance/G8TimingTest.php` | The bar Emily set, **measured** rather than assumed: the four screens under budget render against `PerformanceFixtureSeeder`'s real G8 volumes — 25 active deals mid-flight, 500 past clients, 2,000 activity events. Deliberately not the same question as the budgets beside it, which count queries on small fixtures because query count is what survives a shared runner. The numbers are **printed**; the assertion is an order of magnitude above the 400ms target, so a screen at 380ms passes quietly and one at 5s fails. Carries a guard on the guard — a timing test against an empty database is the fastest test in the suite and means nothing | PRD §9, §12.2, issue #89 |
| `tests/Feature/Workflow/ConfirmGateTest.php` | Ticking a manual gate is a **different act on a different column** from overriding one: it writes `is_met`, never `overridden`, and is recorded on the timeline and deliberately *not* in `audit_log` — PRD §9 names gate overrides there and not the ordinary path, and forty ticks a deal would bury what that table exists to make findable. Asserted as a pair, because an implementation that audited everything would pass the timeline half alone. The case that matters most is the end-to-end one: a stage that could not advance, advancing after a confirmation, with `overridden` still false | PRD §4.4 F4.8, IA §8 |
| `tests/Feature/Templates/TemplateEditingTest.php` | **Editing a template never changes a deal already running** — PRD §7.1's "highest-impact correction", asserted rather than asserted-about. Also that a pack's template is uneditable *all the way down*: the refusal is asserted on the stage and gate routes, not only on the template one, because a policy guarding the parent while a child route lets somebody add a gate is a guard with a door beside it. And the in-use count is taken with a second team running the same shared template, since an unscoped count off a `team_id IS NULL` row tells one team how many deals every other team has | PRD §7.1 F4.1, ADR 0002, issues #84–#86 |
| `tests/Feature/Settings/RolesTest.php` | A team may not compose a role that **impersonates a shipped one**. `Str::slug('Team Owner', '_')` is exactly `team_owner`, and the unique index is over `(team_id, key)` while the shipped rows have no team — so both halves are held: the name is refused, and a counterfeit attached by any other route still fails `hasRole()`. Also that the catalogue offered is the team surface only, asserted against `Permissions::teamSurfaceKeys()` rather than a hand-written list, so a permission added to another surface later is covered by the test that exists | PRD §4.2 F2.3, IA §7, issue #88 |
| `tests/Feature/Properties/PropertyPhotosTest.php` | A deletion deletes, at three levels. The bytes go when a photo does while the row keeps PRD §9's window; **the parent takes its documents with it**, which no foreign key does because `documentable_id` is polymorphic; and a purged team leaves nothing on the documents disk, which `records:purge`'s export and import sweeps never touched because uploads live on their own. Upload type is decided by the file's **contents**, not its filename — an allowlist checked against the browser's claim is a denylist with extra steps | PRD §4.6 F6.4, §9, issue #63 |
| `tests/Feature/GlobalSearchTest.php` | Each search group is behind **its own** permission. One `deals.view` check for the whole box was fine while the five shipped roles were the only roles; S75 lets a team compose "deals but not the client directory", and one check handed that person every client name in the team. Written with a control case first, because a search returning nothing for everything passes a leak test perfectly | PRD F9.3, issues #82, #88 |
| `tests/Feature/Deals/DealOverviewTest.php` | Looking at a deal changes nothing. The overview evaluates every gate through `DescribeBlockers` and writes neither `stages.state` nor `gates.is_met` — proven against a fixture the same test then hands to `AdvanceWorkflow`, which *does* mark the stage blocked. Without that second half the assertion passes on any fixture with nothing to write | issue #75 |
| `tests/Feature/Messages/MessageTemplatesTest.php` | F5.6's *"validated at save time"*, and the four things it has to refuse. A token nothing answers to; a field that exists and cannot resolve yet, refused **by name** with its slice; a **malformed** token — `{{ client name }}` — which a strict scan misses because it is not a token at all; and an **unbalanced brace run**, `{{ client_name }` with a brace dropped, which the first version of the rule let through entirely: loosening what sits *between* the braces does nothing when the braces do not pair, so it saved clean, rendered verbatim into a client's inbox, and reported `isComplete() === true` — pre-arming #93's approval gate to release it. Also that **the template's own channel cannot be changed out from under the automations standing on it**: that rule had two callers on the automation side and the template's `PATCH` was a third, written without it. Also the channel narrowing the form (a subject on a channel with none is *prohibited*, not optional) and the recipient rule (PRD F12.2 keeps push internal, so a push template may not be addressed to a client) | PRD §4.5 F5.5, F5.6, issue #90 |
| `tests/Feature/Automations/RaisingAutomationsTest.php` | What a trigger produces, before anything reaches a transport. F4.5's snapshot applied to automations — a template's automations change and the running deal does not — plus the case that is easy to state and easy to get wrong: **an automation that already fired on a stage does not fire again when that stage is reopened, unless the first instance was cancelled.** A skipped stage cancels its queue and nothing went out, so a stage that comes back and is worked properly is still owed its message; an `exists()` over every row silences that forever. Also that a refused advance queues nothing, which is the ordering PRD §8.1 requires asserted from outside | PRD §4.5 F5.1–F5.4, F5.10, F4.5, issue #92 |
| `tests/Feature/Automations/SendingAutomationsTest.php` | F5.9's three rails, every one of them through `ExecuteAction` — which is what a worker calls — and never through a screen, because issue #96 puts them *"in the queue worker, not in the UI"*. The kill switch is read **live** from the row, proven with a deliberately stale `Team`; the ceiling is rolling rather than calendar, counts one team's sends, and counts **only emails** — a created task reaches nobody, and counting them let three of them pause a team's actual client mail. The idempotency case reads the key **off the mailable** rather than off the row afterwards: the row carries a key either way round, and the wrong order is the one that sends a client the same message twice. Two cases pin **standing down** rather than failing — a worker arriving after somebody pressed Stop writes nothing, because marking it `failed` destroyed the reason they typed and flipped the row into the state `alreadyRaised()` counts | PRD §4.5 F5.8, F5.9, issues #92, #96 |
| `tests/Feature/Automations/ApprovalQueueTest.php` | F5.7's queue, which PRD §4.5 calls a launch blocker. A missing merge field blocks approval; an approver may fix the words and may **not** type a merge field into them, because the payload is already-substituted text and a token typed there reaches the client as braces; a manual prompt is marked done without queueing anything; stopping a message keeps the row, because S49 has to answer *"why did the client never hear about this"* months later. Two of the cases assert **absences**: the route *list* contains no bulk approve — a screen that chose not to draw the button would still leave the endpoint there — and `/messages/{id}` answers Method Not Allowed rather than carrying a destroy | PRD §4.5 F5.7, F5.8, F5.10, IA §7, issue #93 |
| `tests/Isolation/ActionInstanceIsolationTest.php` | The highest-consequence table in this suite, and not because of the row: a leak here is an email in a stranger's inbox with a third party's client name and transaction in it. Three of its cases are about the **send** rather than the read — approving another team's message, a ceiling that would let one busy team silence another's client emails, and the sandbox redirect, which is the one rail that can *cause* a cross-tenant send rather than merely fail to prevent one | ADR 0002, PRD §4.5, issues #92, #96 |
| `tests/Feature/Settings/SendSafetyTest.php` | The rails from the screen, which is a different question from whether they hold: `SendingAutomationsTest` proves the worker refuses, this proves somebody can **reach** it. The kill switch stops a message already in flight; the timestamp is not reset by saving an unrelated field, because *"sending has been off since Tuesday"* is what the screen is for; a limit of zero is refused, since zero is `sends_disabled` said a second way; and F5.7's window can be ended and restarted, which it could not be when the screen rendered it read-only | PRD §4.5 F5.7, F5.9, §9, issue #96 |
| `tests/Feature/Automations/DispatchDueAutomationsTest.php` | The minute sweep, and what it **skips** — which matters as much as what it picks up, because it runs for every team at once, forever. It never re-hands a row carrying a `message_key` (the crash window); it stops knocking on a team held by *either* halting rail, not just the kill switch; and it still sweeps a stranded `create_task` for such a team — against **both** rails, since the ceiling branch is one `continue` away from silently not — because a task reaches nobody outside the team. One case counts the queries on an idle run and asserts the number **exactly**: a third query here is a per-minute cost on every deployment forever and should have to be argued for | PRD §4.5 F5.9, issue #92 |
| `tests/Feature/Automations/SendingAutomationsTest.php` (claim cases) | The three that settle what a worker may say about a row it does not own: it **never** narrates an outcome for a claimed row however old the claim looks; the reaper records one from a distance; and the reaper leaves a claim it is not yet sure about. Plus the two that hold the rail ordering — a halt must not overwrite a stopped message's typed reason, or write a rail error onto a delivered one | PRD §4.5, issue #92 |
| `tests/Performance/MessageQueueBudgetTest.php` | S47's budget, and its fixture is built around `CLAUDE.md`'s eager-load finding: **a template per message**, not one shared, because a fixture that cannot grow the relation cannot catch an N+1 on it. Two assertions, and the second exists because the first could not see what it claimed to: `toBe($small)` is a **per-row** budget, blind to fixed cost by construction — two queries were added to this screen and it stayed green while this test's own docblock said it would not. So the absolute count is pinned as well, and a fifth list has to be argued for in a diff rather than absorbed. Every list the screen renders has a fixture, including `held`, which had none and whose eager loads were therefore never executed by the guard | issues #78, #93 |
| `tests/Feature/Messages/MessageRenderingTest.php` | Where a merged value lands decides what happens to it: escaped into `body_html`, **untouched** into `body_text` — escaping there puts `&amp;` into every message to the O'Brien household — and stripped of CR and LF into the subject, which is a mail **header** whose value comes from a name somebody typed into the people directory. Also that the registry and the resolver are the same table: a field registered and never resolved renders as an empty string in a client email | PRD §4.5 F5.6, issue #90 |
| `tests/Feature/Templates/AutomationsTest.php` | S44's *"invalid combinations are impossible to save"*, one refused combination per case — and the two the **database** refuses rather than the form: a shared automation may not name a message template at all (a Postgres foreign key is MATCH SIMPLE, so the composite key is silent on a null `team_id` and the CHECK is what closes it), and `is_manual` with `requires_approval` is a state that cannot exist. The archived and wrong-channel guards are asserted on the **model** as well as the request, because #92's instantiation is a second caller the request never sees | PRD §4.5 F5.1–F5.4, issue #91 |

When one of these fails, the fix is the code or the document — not the test.

### Proving a mechanical test is not vacuous

A test that enumerates something can pass because it found nothing to check.
Both of Slice 1's do the enumerating, so both were checked by breaking the
thing they guard:

- Removing `BelongsToTeam` from one model turns **all sixteen** isolation tests
  red, which is the definition of done issue #42 asked for.
- Deleting one `$this->authorize()` call turns the authorization-coverage test
  red, naming the route and the action.
- The deal overview's *"changes nothing"* case is the shape most at risk of
  passing vacuously, and it is written so it cannot: the fixture carries an
  unmet blocking gate on an active stage, and after the assertions the same
  fixture is handed to `AdvanceWorkflow`, which marks the stage blocked. The
  test is therefore about *which* of the two paths writes, not about whether
  anything ever would. Deleting the last four lines makes it pass on a deal
  with no gates at all.
- Removing `TeamInvitationMail` from `EmailIndependence::FLOWS` turns the
  email-independence test red, and so does pointing one of its alternatives at
  a route name that does not exist — the second is the failure that matters,
  because a catalogue of doors that are not there reads as coverage on every
  review after this one.

  Its first cut scanned only `app/Mail` for `Mailable` subclasses, so a
  notification with a `toMail` was invisible to it — which is the class of
  sender Slice 5's client messages will most likely be, and the whole reason
  Fortify's reset needed a hand-written constant. An enumerating test is only
  as good as what it enumerates: ask what it *cannot see* as well as what it
  finds.

Slice 2's two enumerating tests were checked the same way. Unclaiming the
`property` prefix in one `ActivityCategory` case names all seven property event
types in the failure; deleting one line from `lib/activity.ts` names the event
type it belonged to. Both of those regexes also carry a guard *on the guard* —
a case asserting the scan found `contact.logged`, `stage.advanced`, and more
than ten types in total — because a pattern that quietly stopped matching would
make every other assertion pass over an empty list, which is the same silence
one layer up.

Slice 2's later ones were checked the same way, and one of them changed how the
guard is written. `SingleMutationPathTest` was widened to `gates.is_met` with
four **shape probes** added beside the four that already existed for
`overridden` — because a pattern added without one passes immediately and looks
exactly like a pattern that works. The same round found a fixture asserting the
*old, weaker* behaviour: `PropertyPhotosTest` had claimed that a `.jpg`
carrying a `text/html` content-type was stored as `image/jpeg`, which was true
and was the bug. A test can be green, specific, and describing the defect.

Do the same for the next one. An enumerating test you have never seen fail is a
test you do not know the behaviour of.

S23's budget test was checked the same way, and the first attempt found
nothing. Deleting the controller's inverse-relation loop changed no query
count — because `DescribeBlockers` already hands every gate the stage it came
from, so twenty gates share one object and the evaluators memoise off it. What
*does* break it is deleting that `setRelation`, which turns the same fixture
from 22 queries into 49. A budget test's fixture has to grow the thing whose
absence would actually cost a query; a fixture that grows something already
shared measures nothing and reads as coverage.

Its companion case is the other half of the same problem: *"really did render
the larger workflow"* asserts the payload holds twenty gates and eleven stages,
because "the same number of queries" is equally true of two fixtures that were
never built.

> [!warning] A `0` or a `null` is the answer a broken feature gives too
> The trap this catches repeatedly: asserting a count is `0`, or a value is
> `null`, when that is also what the code returns with the fix missing. Two of
> the assertions in `ActivityFeedIsolationTest` would have been exactly that —
> "team A sees no events" passes on an empty table — so each one first asserts
> the row it must not see actually exists, with `withoutGlobalScopes()`.

---

## 7. Coverage

Reported in CI as information, not as a gate. A coverage percentage on a young
codebase measures how much code exists, not how well it is tested, and a gate
on it produces tests written to satisfy the gate.

---

## 8. What each slice adds

| Slice | Test work that comes with it |
|---|---|
| 1 | ✅ The isolation suite proper, the authorization-coverage test, and the team-context helpers |
| 2 | Gate evaluator unit tests; `AdvanceWorkflow` transaction and dispatch tests; the deal overview's read-only guarantee and its query budget (`tests/Performance/DealOverviewBudgetTest.php`); the dashboard's query budget at 25 deals |
| 3 | Approval-queue tests, and the safety rails: no message leaves without an approved state. Every new mailable needs its ADR 0003 second door catalogued and covered |
| 4 | Derived date cascade tests, and magic-link expiry — including the non-email path to a status page link (ADR 0003) |
| 5 | Extraction provenance: nothing reaches `key_dates` or `tasks` without a confirmed `extracted_fields` row |
