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
| `tests/Unit/SingleMutationPathTest.php` | Nothing but `AdvanceWorkflow` writes workflow state — across `app/`, `routes/` and `database/`, and through every spelling of the write, `DB::table('stages')` included. Carries its own dataset of bypasses so the detector cannot be narrowed by accident | PRD §8.3, issue #68 |
| `tests/Feature/Workflow/StateMachinePersistenceTest.php` | An illegal transition throws on `save()` however the attribute was written — assignment, `setAttribute`, a variable column name, or `forceFill` | issue #65 |
| `tests/Unit/EmailIndependenceTest.php` | Every mailable in `app/Mail`, every notification in `app/Notifications` with a `toMail`, and every mail-sending Fortify feature that is switched on, is catalogued in `EmailIndependence::FLOWS` with a non-email alternative — and every alternative it names resolves against the real route table or the artisan registry | ADR 0003 |
| `tests/Unit/ExternalLinkConventionTest.php` | The models that use `HasExternalLinks` and the class names in `ExternalLink::LINKABLE` are the same set, and every one of them carries a team. A polymorphic pointer has no composite key to refuse a foreign target, so the allowlist *is* the constraint | ADR 0002, issue #61 |
| `tests/Unit/SafeUrlTest.php` | Only `http` and `https` may be stored and rendered as a link. `javascript:` and `data:text/html` parse cleanly and are script execution in the reader's session — Laravel's `url` rule accepts both | PRD §4.3 F3.4, issue #61 |
| `tests/Isolation/DealPropertyIsolationTest.php` | A link row is reachable only through the deal it is on. The tenancy layers answer "whose team"; only `Route::scopeBindings()` answers "whose deal", and the ranking route — which writes by a list of ids — is held with two deals in **one** team, because a cross-tenant version of that test would pass whether or not the deal filter existed | ADR 0002, issue #62 |
| `tests/Unit/ActivityCategoryTest.php` | Every `eventType:` literal in `app/` has a prefix some `ActivityCategory` case claims. An unclaimed one is invisible at runtime — the row still shows under All and simply never shows under any other tab — so the filter would lie silently | issue #81 |
| `tests/js/activityEventTypes.test.ts` | The same event types all have an icon in `lib/activity.ts`. That table deliberately falls back rather than throwing (Design System §7.3 specifies the fallback), so nothing at runtime would ever report a forgotten one | Design System §7.3, issue #81 |
| `tests/js/logContactDialog.test.ts` | S26's **two-click target**, measured: with the person known, a type tile and Log it save an entry, and nothing else is ever required. A requirement stated in prose erodes one field at a time | Screen Inventory S26, PRD F12.3 |
| `tests/Isolation/DealDraftIsolationTest.php` | A wizard draft is the actor's and its team's, and a foreign id sent inside a *step* is refused — there is no draft id in a URL to send, so the steps are the vector. Also holds both halves of the abandonment sweep: a draft nobody came back to is purged, and one touched yesterday is not | ADR 0002, PRD §9, issue #74 |
| `tests/Feature/Deals/DealOverviewTest.php` | Looking at a deal changes nothing. The overview evaluates every gate through `DescribeBlockers` and writes neither `stages.state` nor `gates.is_met` — proven against a fixture the same test then hands to `AdvanceWorkflow`, which *does* mark the stage blocked. Without that second half the assertion passes on any fixture with nothing to write | issue #75 |

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

Do the same for the next one. An enumerating test you have never seen fail is a
test you do not know the behaviour of.

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
