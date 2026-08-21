---
created: 2026-08-21
project: Brawling Mahogany
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

### Why Postgres and not SQLite

SQLite would pass tests that production fails. JSONB operators, enum-backed
check constraints, composite foreign keys on `(team_id, id)`, and `date` versus
`timestamp` behaviour are precisely the parts of this schema most worth
testing, and SQLite models none of them faithfully.

`phpunit.xml` therefore points at `brawling_mahogany_test` on the pgsql
connection. Locally that database lives in the compose stack; in CI it is a
service container on the same pinned version.

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
| `$this->actingAsPerson()` | Act as a person with credentials. Gains a team argument when tenancy lands in Slice 1 |
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
| `tests/Isolation/ModelTenancyConventionTest.php` | Every model is tenant-scoped or explicitly recorded as team-agnostic | PRD §8.2 |
| `tests/js/tokenDiscipline.test.ts` | No raw hex and no Tailwind palette class in a component | Design System §2.1 |
| `tests/js/tokens.test.ts` | Every state pair meets 4.5:1 in both themes; every colour token exists in both | Design System §11, §13.2 rule 8 |
| `tests/js/controlSizes.test.ts` | Every button and input size matches the measured control table | Design System §4.2, §7.2, §11 |
| `tests/Unit/BranchProtectionTest.php` | `scripts/protect-branches.sh`, `ci.yml`'s job names, and Deployment §7 agree — and every CI job has a `name:`, since an unnamed one cannot be required | issue #24, Deployment §7 |

When one of these fails, the fix is the code or the document — not the test.

---

## 7. Coverage

Reported in CI as information, not as a gate. A coverage percentage on a young
codebase measures how much code exists, not how well it is tested, and a gate
on it produces tests written to satisfy the gate.

---

## 8. What each slice adds

| Slice | Test work that comes with it |
|---|---|
| 1 | The isolation suite proper: every route, both directions. Team-context helpers |
| 2 | Gate evaluator unit tests; `AdvanceWorkflow` transaction and dispatch tests; the dashboard's query budget at 25 deals |
| 3 | Approval-queue tests, and the safety rails: no message leaves without an approved state |
| 4 | Derived date cascade tests, and magic-link expiry |
| 5 | Extraction provenance: nothing reaches `key_dates` or `tasks` without a confirmed `extracted_fields` row |
