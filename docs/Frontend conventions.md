---
created: 2026-08-21
project: Brawling Mahogany
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
| everything else | `AppLayout` |

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

> [!note] One known disagreement with shadcn's defaults
> Design System §5.2 specifies the dialog scrim as `--foreground` at 45%.
> shadcn's generated overlay uses `bg-black/80`. Rule 4 says the generated file
> is not edited, so the correction belongs in a `components/app/` dialog
> wrapper when the first real dialog is built (Slice 2). Recorded here so it is
> a decision rather than a discrepancy somebody finds later.

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
