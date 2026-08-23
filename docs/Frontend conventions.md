---
created: 2026-08-21
project: Goldieflow
type: reference
status: draft
version: 1.0
---

# Frontend conventions

> [!info] What this document is for
> Where things live in `resources/js`, what is built once in `lib/`, and the
> content rules that keep ninety-one screens sounding like one product.
>
> [[Design System]] owns how things look. [[Information Architecture]] owns what
> they are called. This document is how those two land in code.

---

## 1. Structure

```
resources/js/
├── components/
│   ├── ui/          shadcn output. Never hand-edited.
│   ├── app/         our composites (Design System §7)
│   └── forms/       domain field wrappers
├── layouts/         AppLayout, AuthLayout, ClientLayout, AdminLayout, SettingsLayout
├── pages/           mirrors routes in PascalCase: Deals/Index.vue, Deals/Documents.vue
├── composables/
└── lib/             formatters, the state map, navigation, utils
```

Page components mirror routes in PascalCase (IA §6): `/deals` renders
`Pages/Deals/Index.vue`, `/deals/{deal}/documents` renders
`Pages/Deals/Documents.vue`. `tests/Unit/CodeDisciplineTest.php` fails when a
page is named otherwise.

### Which layout a page gets

Resolved centrally in `app.ts` by the page's own path, so no page picks its own
chrome by accident:

| Page path | Layout |
|---|---|
| `Auth/*` | `AuthLayout` |
| `Settings/*` | `AppLayout` + `SettingsLayout` |
| `Admin/*` | `AdminLayout` — visually distinct, so the two are never confused |
| `Status/*` | none; the page composes `ClientLayout` itself with the team's branding |
| `System/*` | none; system pages carry their own frame and render for signed-out people |
| `Deals/Overview`, `Deals/People`, `Deals/Properties` | `AppLayout` + `DealLayout` |
| everything else | `AppLayout` |

The deal row is a **list of page names, not a prefix**: `Deals/Index` is the
list of deals and `Deals/Create` is the wizard, and neither is *inside* a deal.
The list lives in `DEAL_TAB_PAGES` in `app.ts`; adding S16–S22 means adding a
name there and a tab in `components/app/DealHeader.vue`.

`DealLayout` owns the Design System §8.4 header, the tab row, and the answer to
an Advance pressed from any of them. It reads a `dealHeader` prop, which every
deal-tab controller supplies from `App\Support\Deals\DealHeader::for()` —
one payload, so two tabs cannot disagree about the client's name or the counts.
Per §9.2 the header is full-bleed and **each page owns its own `p-6`**.

---

## 2. Component governance

The rules from Design System §13.2, in the order they get applied:

1. **Need a component? Check shadcn-vue first.** It is probably there.
2. **Not there? Can it compose from two or three shadcn parts?** Then it
   belongs in `components/app/`.
3. **Only then a third-party library**, and only if it is maintained and
   tree-shakeable.
4. **Never hand-edit `components/ui/`.** Extend through a `cva` variant or a
   wrapper in `components/app/`. Re-running the shadcn CLI must stay safe.
   `CODEOWNERS` guards the directory.
5. **No raw colours in components.** Semantic tokens only.
   `tests/js/tokenDiscipline.test.ts` fails the build on a hex value or a
   Tailwind palette class in `app/`, `forms/`, `layouts/`, or `pages/`.
6. **A pattern used three times gets promoted** into `components/app/` with a
   name.
7. **New state? Add it to Design System §2.4 first**, then `lib/states.ts`,
   then build the badge.
8. **Both light and dark values, always**, even though dark ships after v1.
   `tests/js/tokens.test.ts` fails when a colour token exists in one block and
   not the other.
9. **A tone is three properties.** Background, foreground, and any icon move
   together or not at all.

> [!note] The gallery is not served in production, but it is built
> `routes/web.php` registers `/design-system` only outside production, so the
> page cannot be reached there. Inertia resolves pages with a glob, so the
> component is still compiled — as its own lazily-loaded chunk that nothing
> requests. That costs build time and a file in `public/build`, not bytes on a
> customer's connection. If the gallery grows heavy, give it its own Vite
> entry rather than trying to prune the glob.

### Where shadcn's defaults disagree with the Design System

Rule 4 forbids editing generated files, so each disagreement is corrected
somewhere else and recorded here rather than left to be discovered.

| Disagreement | Where it is corrected |
|---|---|
| Overlay scrim is `bg-black/80`; §5.2 specifies `--foreground` at 45% | `app.css`, at the token layer, on `[data-slot='dialog-overlay']` and `[data-slot='sheet-overlay']` |
| `text-white` on the destructive Button and Badge variants; `--destructive-foreground` is the token | Recorded in `tests/js/tokenDiscipline.test.ts`'s allowlist. Visually identical, so it is corrected at the call site when a destructive control is next built rather than by editing generated source |
| `Input` is `h-9`; §4.2 measures the form control at `h-10 px-3` | `components/app/AppInput.vue` |
| `Button` `default` is `h-9 px-4` with a 500 label; §7.2 measures Primary at `h-9 px-3.5` / 600, Ghost at `h-8 px-2.5`, and adds a Compact size shadcn has no equivalent for | `components/app/AppButton.vue` |

`tests/js/tokenDiscipline.test.ts` scans `components/ui/` against that
allowlist, so a *new* palette class in generated source fails the build even
though the existing ones are tolerated.

### The control sizes

`components/app/controlVariants.ts` carries the §4.2 and §7.2 measurements as
a `cva` set — the sanctioned way to differ from generated source (rule 4) —
and `AppButton`, `AppInput` and `AppSelect` apply them. **Use those, not
`ui/button` and `ui/input` directly**, on anything designed against the Design
System.

| | Design System | shadcn's default |
|---|---|---|
| Primary | `h-9 px-3.5`, label 14/**600** | `h-9 px-4`, 500 |
| Ghost | `h-8 px-2.5` | `h-8 px-3` (`size="sm"`, the nearest neighbour — shadcn has no ghost *size*, and `variant="ghost"` defaults to `h-9 px-4`) |
| Compact | `h-7 px-2.5`, 12/600 | no equivalent |
| Disabled primary | `bg-muted` | faded fill |
| Form control | `h-10 px-3`, 14px above `md` | `h-9` |
| Filter | `h-8 px-3`, 12px above `md` | no equivalent |

Both input sizes are 16px below `md` and their measured size above it —
`md:text-sm` for the form control, `md:text-xs` for the filter — because iOS
Safari zooms the page when a field under 16px takes focus, and a filter in a
mobile Sheet is still typed into. The type belongs to each **size** and never
to the base string: a base `text-base` is displaced by a size's `text-xs`, but
a base `md:text-sm` is a different tailwind-merge group and survives, which is
how the filter silently became 14px on the one breakpoint it is used at.

Every size — buttons *and* inputs — is `min-h-11` below `md`, because §11's
44px minimum has no exceptions and §4.3 is explicit that the compact desktop
density is a power-user affordance rather than a house style.

**`AppSelect` is the third of these, and it exists because the rule was broken
four times.** A native `<select>` styled by hand — `h-8 rounded-md border
bg-background px-2.5 text-xs` — had been transcribed into the properties
directory, the contact import, the audit log and deal properties, and every
copy was 32px on a phone. It shares `appInputVariants` with `AppInput` so the
two cannot drift, and it maps the empty option to `null`, because `''` is how
a native select says *unanswered* and `null` is what that means to the server.
Prefer it to `ui/select` for a short list of words; the shadcn listbox is for
a picker with search or rich rows. Both behaviours are pinned in
`tests/js/controlSizes.test.ts`.

Several screens still hand-roll a `<select>` at the **form-control** size, and
nothing scans for it — a follow-up, not a claim that the pattern is finished.

Two behaviours worth knowing rather than discovering: `variant="ghost"` sizes
itself as a ghost button (32px) without being told twice, and a disabled
`AppButton` renders a real `<button disabled>` even when given an `href` —
an `<a aria-disabled>` is still clickable, and `disabled:pointer-events-none`
never matches an anchor.

`tests/js/controlSizes.test.ts` pins every size in both tables — including the
filter, whose absence from it is exactly why the 14px regression above got as
far as it did — along with those two behaviours, the icon's
`pointer-events-none`, and the disabled hover tone. They render in the gallery
so they can be judged next to each other.

The starter kit's own auth and settings screens still use shadcn's sizes.
They are Slice 1 work and will move to these components when they are designed
against the spec; nothing new should be built on the upstream defaults.

---

## 3. What `lib/` owns

### `lib/formatters.ts`

IA §10 specifies formatting exactly. If each screen formats its own dates, the
ninety-one screens disagree within a month — so nothing in `components/` or
`pages/` formats anything itself.

| Function | Rule | Example |
|---|---|---|
| `formatPersonName` | First Last | Emily Bosart |
| `personSortKey` | Sortable by last name | bosart, emily |
| `formatDealName` | Subject property street address, falling back to client surname | 123 Main St · Bosart Purchase |
| `formatAddress` | Street on line one, City, ST ZIP on line two | |
| `formatDate` | Weekday, short month, day | Thu, Aug 20 |
| `formatDateForClient` | Full month and day, year only when it differs | Thursday, August 20 |
| `formatRelativeDate` | Relative **only within 7 days**, then absolute | in 3 days · Aug 30 |
| `formatTime` | 12-hour, lowercase meridiem, **team timezone** | 2:30pm |
| `formatCurrency` | Whole dollars above $1,000, cents below | $485,000 · $250.50 |
| `formatCount` | Numeral plus noun, pluralised | 3 deals · 1 task |
| `formatDateTime` | Date and time together, for a timeline row | Thu, Aug 20 at 2:30pm |

**Timezone.** Storage is UTC; display is the team's timezone (PRD §9). Call
`setTeamTimeZone()` once at boot. `calendarDaysBetween()` compares wall-calendar
days in that timezone, so "tomorrow" means the next date on the calendar rather
than 24 hours from now — which is what a contingency deadline actually means.

> [!note] Why `Intl` rather than date-fns
> Design System §7.5 lists date-fns. `Intl.DateTimeFormat` is built in and
> handles a named IANA timezone without a second package, which is the one hard
> requirement here. If a later slice needs date arithmetic beyond
> `calendarDaysBetween`, adopt date-fns then — one library, used everywhere,
> rather than two doing overlapping jobs.

### `lib/states.ts`

The IA §8 state vocabulary and the Design System §2.4 tone for each state, in
one table. Code is `snake_case`, UI is Title Case, and the mapping lives here
rather than in each component.

- `resolveState(domain, code)` **throws** on an unknown state. An unstyled
  badge carrying a raw `snake_case` string is worse than a loud failure,
  because it ships.
- `clientStateLabel()` and `clientStageName()` are the client-facing
  translation layer. `blocked` never reaches a client — it renders as "In
  Progress" — and a skipped stage is hidden entirely (IA §9). The client sees
  a stage's `milestone_label`, never its internal name.

### `lib/states.ts` is bound to the documents

`tests/js/statesMatchTheDocs.test.ts` reads IA §8 and Design System §2.4 out
of `docs/` and asserts the table matches both — the code values, the labels,
and the tone. `tests/Unit/DocumentedVocabularyTest.php` does the same for the
PHP enums against IA §8 and PRD §6.3.

Changing a state means changing the document and the code together. That is
rule 7 made mechanical.

**Two domains are not state machines, and read from a different document.**
`property` and `propertyInterest` carry PRD §6.3 lookups rather than IA §8
vocabularies,
because neither transitions — a team sets what is true about a house, and a
buyer changes their mind about one. So the test reads their labels out of PRD
§6.3 and only their tones out of Design System §2.4.
The distinction is load-bearing rather than pedantic: PRD §7.11 rules that
"Undergoing improvements" and "Staged" are **workflow positions**, and they
belong to a stage. A property status that grew a workflow position would be
this table quietly becoming a second, worse stage vocabulary — and the same
line keeps "Viewing scheduled" and "Offer made" out of `propertyInterest`,
where both are facts the product already holds somewhere better.

### `lib/activity.ts`

Design System §7.3's tint-by-event-type table, plus the icon each event type
carries — for `ActivityItem`, which three screens render already (S12, S31, and
the deal timeline that follows).

- `activityDescriptor({ eventType, contactType })` returns `{ icon, tone }`. On
  a `contact.logged` row the icon comes from PRD §6.3's contact type instead: a
  phone and an envelope are legible at a glance in a way "Phone call" and
  "Email" at 14px are not.
- **It does not throw the way `resolveState` does**, and the difference is not
  an oversight. §7.3 *specifies* the fallback — "everything else
  `state-neutral`" — so an unmapped event type renders correctly rather than
  wrongly, and a throw would take a whole feed down over one row a later slice
  added.
- What that costs is silence, and `tests/js/activityEventTypes.test.ts` pays
  it: it reads every `eventType:` literal out of `app/` and fails when one has
  no entry here. `tests/Unit/ActivityCategoryTest.php` does the same for the
  feed's filter, which groups by the prefix before the dot.

### `lib/navigation.ts`

The sidebar's contents and order (IA §5.1), the four mobile tabs, and the
"More" sheet's contents (IA §5.3). A section the person lacks permission for is
**hidden, never shown disabled**.

---

## 4. Content rules

**Sentence case for everything the user reads.** Title Case is reserved for
navigation labels, tabs, and status badges (IA §10).

**One verb per action**, from the IA §7 table. The two that matter most:

- **Advance** moves a workflow to its next stage. Never Progress, Move, Next,
  or Complete.
- **Override** forces past an unmet gate, with a reason and an audit entry.
  **Skip** marks a stage not applicable. They are different buttons with
  different colours, and conflating them destroys the audit trail's meaning.

**Empty states say what belongs here, then offer the action**: "No deals yet.
Create your first deal." Never a bare "No results."

**Destructive confirmations name the object and the consequence**: "Delete 123
Main St? This removes 14 tasks and cannot be undone."

**Errors say what happened, then what to do**: "Couldn't send. Check the
sending address in Settings." Not "An error occurred."

**A lookup is archived, never deleted, and the warning comes before the
choice.** Deal types (S76) is the first of these; roles (S75), template packs
(S41), and every other lookup screen follow it. The rule and the reason:

- A lookup is a value other rows *point at*. Deleting "Rental Placement" would
  orphan every rental deal that ever used it, and the type is what decides
  which workflow templates are offered and whether the Offers tab exists at
  all (IA §5.2). So there is **no destroy route** — not a destroy route that
  refuses, which is a route somebody can reach by guessing a verb.
- The count is shown *before* the choice, not reported after it. "4 deals keep
  this type and no new deal can use it" is a decision somebody can make; "Are
  you sure?" tells them nothing they did not already know.
- **Archiving must be reversible.** A screen that archived with no way back
  would have talked somebody out of a delete and handed them the same problem.
- The count is scoped to the asking team. Lookups with a null `team_id` are
  shared rows, so an unscoped count answers "how many does *everybody* have"
  and shows that number to one team. What holds it is
  `tests/Isolation/DealTypeIsolationTest.php`, asserting through the route
  that one team's deals never reach another team's count.
- **System rows get no controls at all, not disabled ones** (IA §5.1). They
  belong to every team; a greyed-out button only invites the question.
- **The count is one query for the page, not one per row**, and a budget test
  holds it (`tests/Performance/DealTypesBudgetTest.php`). This is a screen
  whose entire job is a count per row, so it is the shape most likely to grow
  an N+1 — including through the tidy-looking version where each row asks the
  policy, since `ChecksTeamPermissions` re-queries the membership every call.
- **A validation rule that stands in for a database constraint has to match
  it on every predicate, and fold case in the database.** Both of this table's
  unique indexes are partial on `deleted_at IS NULL AND archived_at IS NULL`
  and are over `lower(name)`; a rule that filtered only `deleted_at`, or that
  folded its bind with PHP's `mb_strtolower()`, matched neither. PHP and
  Postgres genuinely disagree — `ΑΣ` folds to `ας` in one and `ασ` in the
  other — so the comparison belongs in SQL: `lower(name) = lower(?)`.

**Consequential inputs carry their consequence beneath them.** The override
reason field is followed by "This is written to the permanent audit log with
your name and the time. It cannot be edited or deleted."

### The banned words

From IA §11. The left column is the only word for the thing.

| Use | Never |
|---|---|
| Deal | Project, Transaction, File, Matter |
| Stage | Milestone (old sense), Phase, Step, Status |
| Milestone | Key event, Checkpoint |
| Gate | Condition, Blocker, Rule (Requirement is allowed **only** in the deal view) |
| Task | To-do, Item, Action item, Checklist item |
| Automation | Action, Trigger, Rule, Workflow step |
| Template | Blueprint, Preset |
| Pack | Bundle, Library, Kit, Module |
| Participant | Contact, Party, Member, Stakeholder |
| Person | User, Contact, Record, Lead |
| Vendor | Service provider, Contractor, Supplier |
| Dates & Deadlines | Key dates (in UI), Important dates, Milestones |
| Activity | History, Log, Feed, Audit |
| Status Page | Portal, Client portal, Dashboard |
| Keep in Touch | Nurture, Drip, Campaign, CRM |
| Team | Organization, Account, Workspace, Company |
| Extract | Scan, Parse, Analyze, Read, AI |

---

## 5. Accessibility, held from the start

- Every badge carries a word. Colour never carries meaning alone.
- Every icon-only control carries a label — `IconButton` requires one.
- Touch targets are 44px minimum on mobile, without exception.
- Focus is always visible.
- `prefers-reduced-motion` is honoured globally in `app.css`: motion reduces to
  opacity, and feedback is never removed entirely.
- The client status page is held to WCAG 2.1 AA (PRD §9). Its base type is
  16px, its density is comfortable, and none of the internal app's density
  rules apply to it.

---

## Related

- [[Design System]] §2 tokens, §7 component contracts, §13 governance
- [[Information Architecture]] §5 navigation, §7 actions, §8 states, §10 content
- [Testing](Testing.md) — the tests that hold these rules
