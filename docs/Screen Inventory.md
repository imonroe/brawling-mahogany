---
created: 2026-08-20
modified: 2026-08-22
project: Goldieflow
type: reference
status: draft
version: 1.0
tags:
  - monroe-digital
  - design
  - screens
  - goldieflow
---

# Screen Inventory

> [!info] What this document is for
> Every screen the product needs, what it costs to design, and which slice it belongs to. It doubles as a route map, since on Inertia every screen is a page component behind a named route.
>
> Vocabulary comes from [[Information Architecture]]. Feature IDs point at [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document]]. Visual references are in [[Design references]].

> [!note] Terminology
> This document uses the [[Information Architecture]] vocabulary: **Deal** (not Project) and **Stage** (not Milestone). PRD v0.3 now matches. Feature IDs are stable across all versions.

> [!success] Built in slice 1
> S01–S06 and S09, S30–S33, S72, S74, S77, S79, and S81–S85. Two of them differ from the row below, and the rows say so: S31's route parameter is the **membership**, and S84 is a page rather than a modal.

> [!success] Built in slice 3 so far
> **S44, S45 and S46** — the automation definition layer. Message templates with
> a channel, a recipient *rule* rather than an address, merge fields validated at
> save time, a live preview against a real deal, and a test send that can reach
> nobody but its author; and automations on a stage template, with the triggers
> and action types F5.2 and F5.3 name.
>
> **S47, S48 and S49** — the runtime, which arrived with F5.9's rails attached
> rather than shortly afterwards. A trigger raises an instance, the words are
> rendered at that moment (F5.10's pre-fill), a person reads it, and only then
> does a worker hand it to a transport — where the kill switch, the rate ceiling
> and the sandbox are asked one last time, in the order the code argues.
>
> S45 and S46 were renamed from *email* to *message* templates, and their routes
> with them. The note in section G argues it. S47 and S49's routes moved too, and
> S48 stopped being a modal — the note in section H says why.

> [!success] Built in slice 2 so far
> S76 (deal types), S35–S37 (properties), S19 and S25 (deal people), S20 (deal
> properties), S14 and S28 (create deal and attach workflow), **S15 (the deal
> overview)**, and **S12** and **S26** (the team activity feed and the two-click
> contact log).
>
> S15 brought the deal chrome with it — the §8.4 `DealHeader` and the tab row are
> now shared by every deal tab, and S19 and S20 were retrofitted onto them in the
> same change. Three departures from the S15 row below are recorded in the notes at
> the end of this document.
>
> S12 took a sidebar row that [[Information Architecture]] §5.1 did not have — a
> screen at a route with nothing pointing at it is a screen nobody opens — and that
> document now carries it.

---

## How to read this

**Users:** `TC` is Heather in her coordinator role, `Agent` is Emily, `Team` means both, `Client` is the seller or buyer, `Admin` is Ian as super administrator.

**Key states** lists the variants that need designing beyond the happy path. This column is the one that gets skipped and then invented at 11pm during implementation.

**Effort** is design effort only, not build.

| Effort | Meaning | Full design pass | With a component library |
|---|---|---|---|
| **S** | Assembles from existing patterns | ~0.5 day | ~1 hour |
| **M** | New composition of known parts | ~1.5 days | ~0.5 day |
| **L** | Novel interaction needing real thought | ~4 days | ~2 days |

---

## A. Authentication and system pages

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S01 | Login | `/login` | Team | Error, rate limited, locked | F1.3 | 1 | S |
| S02 | Two-factor challenge | `/two-factor` | Team | Invalid code, recovery code | NFR | 1 | S |
| S03 | Forgot and reset password | `/forgot-password`, `/reset-password` | Team | Sent, expired token, success | F1.3 | 1 | S |
| S04 | Accept invitation | `/invitations/{token}` | Team | Expired, already accepted, set password | F1.3 | 1 | S |
| S05 | System pages (403, 404, 500, maintenance) | various | All | Four variants, tenant and admin themed | NFR | 1 | S |

> [!note] S03 and S04 each carry a second door that is not a screen
> [[adr/0003-no-email-only-flows|ADR 0003]]: no flow depends on email alone. S04's alternatives are in-app — the invitation appears on S09 and in S06's banner, and the link can be issued from S74 or S83 — plus `php artisan invitation:link` for an install with no screens yet. S03's is `php artisan auth:reset-link`, deliberately console-only: a page that mints reset links for other accounts is an account-takeover button however carefully it is gated. Neither adds a row here, because neither is a screen — but both are part of the flow, and a redesign of S03 or S04 that forgets them re-creates the dead end they close.

## B. Application shell

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S06 | App shell and sidebar | global | Team | Collapsed, mobile bottom bar, permission-hidden sections, impersonation banner, **pending-invitation banner** (ADR 0003), **Report a bug** modal when the environment supplies a form (#176) | F1.2 | 1 | **L** |
| S07 | Global search overlay | global | Team | Empty, no results, grouped results, recent | F9.3 | 2 | M |
| S08 | Notification panel | global | Team | Empty, unread, grouped, mark all read | F5.3 | 3 | M |
| S92 | Help | `/help`, `/help/{article}` | Team | Contents, one article, planned feature, unknown article (404) | — | 2 | S |
| S09 | Team switcher | global | Team | Single team (hidden), multiple, no access, **invitation waiting** (accept in-app, no link needed — ADR 0003) | F1.4 | 1 | S |

> [!note] S06 is the highest-leverage screen in the inventory
> Every other internal screen inherits it. Getting the density, the type scale, and the mobile collapse right here means the other 70 screens mostly assemble themselves. Getting it wrong means 70 screens inherit the mistake.

## C. Dashboard and work

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S10 | Team dashboard | `/dashboard` | Team | Empty (new team), 1-5 deals, **25 deals**, all blocked, nothing due | F9.1, G8 | 2 | **L** |
| S11 | My Work queue | `/work` | TC | Empty, overdue grouping, nothing assigned, 50+ tasks | F9.2 | 2 | M |
| S12 | Team activity feed | `/activity` | Team | Empty, filtered, loading more | F9.4 | 2 | S |

> [!note] S12's three states, and where each one lives
> **Empty** and **filtered** share one `EmptyState`, but not one sentence: the copy is `ActivityCategory::emptyMessage()`, so a filtered-to-nothing Properties tab says something different from a brand new team, and the filtered variant offers "Show everything" rather than leaving somebody to work out which chip did it.
>
> **Loading more** appends rather than replaces. `events` is an Inertia merge prop keyed on `id`, so Load more is a partial reload carrying the next cursor — and changing the filter, which is an ordinary visit rather than a partial one, resets the list instead. The pagination is a **cursor**, not a page number: the feed is the one list in the product whose first row changes while you read it, and offset pagination under an insert shows one row twice and drops another.
>
> A row's icon and tint come from `resources/js/lib/activity.ts`, held against the event types `app/` actually writes by `tests/js/activityEventTypes.test.ts`.

## D. Deals

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S13 | Deals index | `/deals` | Team | Empty, 25 rows, filtered, saved view, bulk select, closed deals | F9.1 | 2 | **L** |
| S14 | Create deal | `/deals/create` | Team | Multi-step: type, client, property, template. Validation, resume | F3.1 | 2 | M |
| S15 | Deal overview | `/deals/{deal}` | Team | Active, blocked, closed, no workflow attached, no property | F3.7 | 2 | **L** |
| S16 | Deal timeline | `/deals/{deal}/timeline` | Team | 5 stages, 20 stages, blocked, overridden, skipped, multiple concurrent workflows | F4.6-F4.8 | 2 | **L** |
| S17 | Deal tasks | `/deals/{deal}/tasks` | TC | Empty, grouped by stage, overdue, unassigned. **Built.** The screen the product is sold on, and the one that gives `required_tasks_complete` a way to clear other than an override | F4.10 | 2 | M |
| S18 | Deal dates and deadlines | `/deals/{deal}/dates` | Team | Empty, derived dates, cascade preview, past due, extracted-pending. **Built** (#107). The cascade preview is a dry run of the real calculation and not a second one — `SaveKeyDate::preview()` and `SaveKeyDate::edit()` both call `KeyDateGraph::cascadeFrom()`, so what the list promises is what the save does | F8.2 | 4 | M |
| S19 | Deal people | `/deals/{deal}/people` | Team | Empty, roles grouped, missing required role | F3.3 | 2 | S |
| S20 | Deal properties | `/deals/{deal}/properties` | Agent | Subject only, candidates list, interest statuses, none yet | F3.4-F3.5 | 2 | M |
| S21 | Deal documents | `/deals/{deal}/documents` | TC | **Built** (#98). Empty, categorised, upload in progress, refused | F6.1-F6.3 | 3 | M |
| S22 | Deal offers | `/deals/{deal}/offers` | Agent | Empty, hidden by deal type, multiple, countered | F3.6 | 2 | M |
| S23 | Advance stage | modal | Team | All gates met, 1 unmet, several unmet, advisory only, last stage. **Built.** | F4.8 | 2 | **L** |
| S24 | Override gate | modal | Agent | Reason required, confirmation, follow-up task preview. **Built**, reached from S23's blocking rows | F4.9 | 2 | M |
| S25 | Add participant | modal | Team | Search existing, create new, role select, duplicate warning | F3.3 | 2 | S |
| S26 | Log contact | modal | TC | Type select, quick save, attach to deal. **Two-click target** | F2.5 | 2 | S |
| S27 | Add and edit task | modal | TC | New, edit, assign, due date, required flag. **Built.** One modal for both verbs; **Complete** is deliberately not in it, because IA §7 makes it its own act with its own route and its own audit consequence | F4.10 | 2 | S |
| S28 | Attach workflow | modal | Team | Template picker, pack filter, preview stages, already attached | F4.1 | 2 | M |
| S29 | Close deal | modal | Agent | Outcome select, transition to Keep in Touch, fell through | F3.8 | 6 | S |

> [!note] S92 is a new screen, and it is content rather than a feature
> The Screen Inventory did not have a Help screen. #170 added one, and the interesting decisions are all about **where the words live** rather than what the page does.
>
> **Markdown files in `resources/help/`, not rows and not components.** #170 asks for documentation *"which can be updated and improved as we continue development"*, and that phrase decides the storage. A page written as a Vue component is a page only a frontend developer can correct; a page in a database needs an editor screen, a permission, an audit decision and a migration before anybody can fix a typo. A file is a diff — reviewed the way `docs/` is reviewed, and landing in the same pull request as the change it describes, which is the only arrangement under which documentation stays true. `league/commonmark` ships with Laravel, so this adds no dependency.
>
> **`auth` alone — outside `verified`, `two-factor` and `team` as well as outside every policy**, which is the one place this screen departs from every other in the shell. The first cut left it inside the tenant group, and review found the case that matters: PRD §9 holds an un-enrolled Team Owner at the enrolment screen, and *Signing in and your account* is the article explaining enrolment and recovery codes — so the manual locked out the one person who needed that page. `team` and `verified` are the same argument at other moments (invited but not yet in a team; mid-signup), and all three are when a manual is worth more than usual. On top of that, a help section gated on `deals.view` cannot explain what a deal is to the person deciding whether to ask for that permission. Articles name the permission a feature needs instead. Held by a middleware assertion rather than by the comment, because that claim was made in five places and was false in all five.
>
> **Planned features are documented, not omitted.** #170 asks for placeholders and the literal reading is the right one: a manual with a gap teaches nothing, while one that says *"documents arrive in a later release"* answers the question somebody opened it with — and stops that question reaching Emily by phone. Seven articles carry `arrives_with`, which draws a badge on the contents page and a banner on the article, and a test holds the two halves together: everything in *Coming later* must be marked, and nothing outside it may be.
>
> **The prose styles are written from tokens rather than pulled in.** `@tailwindcss/typography` would style the body in one class and would bring its own greys, which Design System §13.2 rule 5 forbids. Hand-writing them against the same tokens is why the manual looks like the app rather than like a README.
>
> Three guards keep it honest as the product moves, and **two of the three shipped broken and were caught by review**, which is the argument for having written them at all. Every internal link is resolved: against the **articles** for `/help/…` and against the route table otherwise, because `/help/{article}` matches any segment and a route-level check waved through a dead link on the first draft. Every article is checked for the IA §11 vocabulary, because a manual is where somebody learns what the words mean — it fired on a paragraph naming banned words in order to disclaim them, and later on *key dates*, which IA §11 bans in the UI in Emily's own phrase. And every `**Section →**` instruction is checked against the sidebar; the first version of that ran its pattern over the *rendered* HTML, where `**` has already become `<strong>`, so it matched nothing on every article and passed. Each now carries a floor on what its scan found, which is the assertion that would have caught it.

> [!note] S26's two clicks are the specification, and they are measured
> Once the modal is open and the person is known, a saved entry is **pick the type, then Log it**. `tests/js/logContactDialog.test.ts` mounts it and counts, because a requirement stated in prose is a requirement that erodes one field at a time.
>
> What that constrains: the type is six 44px tiles rather than a `<select>` (a native picker on a phone is two taps and a scroll wheel); nothing but the type is required, so an empty time means *now* and an absent note means nothing was worth typing; and the person is preselected wherever the entry point knows them.
>
> **Three entry points, and only one of them asks who.** The person record (S31) and each participant row on S19 hand the modal a person — and S19 hands it the deal as well, so the optional attachment costs no click at all. The shell's top-bar button has to ask, and asks *before* the two rather than between them.
>
> A logged contact is subjected to the **person** (F2.5: "against a person and optionally a deal") and carries the deal in `activity_events.deal_id`, which is what puts one entry on the person, the deal, and the feed without writing it twice.

## E. People

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S30 | People index | `/people` | Team | Segments (Clients, Vendors, Team, Leads), empty, 500+ rows, search | F2.4 | 1 | M |
| S31 | Person detail | `/people/{membership}` | Team | Contact log, related deals, no login, past client, vendor fields | F2.1, F2.5 | 1 | M |
| S32 | Create and edit person | modal | Team | New, edit, duplicate email warning, promote to login | F2.1 | 1 | S |
| S33 | Contact import | `/people/import` | Agent | Source pick, field mapping, duplicate preview, partial failure, result summary | F2.8 | 1 | **L** |
| S34 | Vendor directory | `/people?segment=vendors` | Team | Specialty filters, ratings, last used, empty | F2.6 | 2 | M |

## F. Properties

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S35 | Properties index | `/properties` | Team | Empty, grid and list toggle, filtered by status | F3.4 | 2 | M |
| S36 | Property detail | `/properties/{property}` | Agent | No photos, gallery, linked deals, external links | F3.4 | 2 | M |
| S37 | Create and edit property | modal | Team | Address entry, type and status, external links | F3.4 | 2 | S |
| S38 | Photo gallery manager | modal | Agent | Upload, reorder, set primary, captions, empty | F6.5 | 2 | M |

## G. Templates

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S39 | Templates index | `/templates` | Agent | Installed packs, available packs, custom templates, empty | F4.13 | 2 | M |
| S40 | Pack browser and install | `/templates/packs` | Agent | Preview contents, install, already installed, update available | F4.13 | 2 | M |
| S41 | Workflow template editor | `/templates/{template}` | Agent | Stage list, reorder, add, edit, remove, in-use warning, versioning | F4.4 | 2 | **L** |
| S42 | Stage template editor | `/templates/{template}/stages/{stage}` | Agent | Tasks, gates, automations — each addable, editable and reorderable — owner role, milestone toggle, client label | F4.3 | 2 | **L** |
| S43 | Gate editor | modal | Agent | Seven gate types with distinct configs, blocking toggle | F4.8 | 2 | M |
| S44 | Automation editor | modal | Agent | Trigger, type, message template, approval toggle, manual prompt. **Built.** One three-way execution choice rather than an approval toggle *and* a manual toggle — see the note below | F5.1-F5.2 | 3 | **L** |
| S45 | Message template list | `/templates/messages` | Agent | Empty, in use by N automations, unused. **Built.** Renamed from *Email template list* at `/templates/emails` — see the note below | F5.5 | 3 | S |
| S46 | Message template editor | `/templates/messages/{template}` | Agent | Merge field picker, invalid field, live preview, recipient rule, test send. **Built.** | F5.5-F5.6 | 3 | **L** |

> [!note] S40 is a section of S39, and *install* turned out to be *copy*
> The pack browser has no route of its own. Both lists live on `/templates` — a team's own above, the packs below — because the thing a reader is doing there is choosing a process, and a second screen to reach the half they do not own makes the choice a navigation.
>
> **The two lists are two different things, which is why they are not one list.** A team's templates are editable; a pack's are shared by every team and `WorkflowTemplatePolicy::update()` refuses one outright. One list with some rows quietly read-only is exactly the shape Frontend conventions §4.3 warns about, so the pack's only verb is **Use a copy** — a deep copy that drops `template_pack_id`, because a row still naming the pack is a row a future "update your packs" feature would try to reconcile.
>
> That leaves S40's *already installed* and *update available* states with nothing to describe, and they are departures rather than omissions: nothing is installed, so nothing can be out of date. Whether packs should be updatable at all is a question #87 can answer once there is a pack whose contents somebody wants to revise.
>
> **S43 is a section too**, and a thin one for now. The picker reads `GateRegistry::selectableOptions()` — the types the editor can fully specify, which is three: manual confirmation, required tasks complete, and (since #109) date reached, whose whole configuration is the name of the date. The other four need a field this editor has none for, and a gate composed without its configuration is one no evaluator can ever answer — a stage only an override could pass, built in two clicks. A **pack file** is held to the wider `GateRegistry::types()` instead (#87), because a file is written by somebody who can supply the configuration; a gate that arrives that way keeps its Edit, and the picker offers its stored type back so a label can be corrected without letting anybody choose one this editor cannot compose.
>
> [!note] S45 and S46 are *message* templates, not email templates
> Renamed with #90, and the rename is the issue rather than a tidy-up. PRD §7.12 is the correction the issue implements — *"`Email Template` points the wrong way, and should generalise"* — and the v0.2 update puts `push` beside `email` and the deferred `sms` in the channel enum. A route reading `/templates/emails` would be wrong the first time somebody wrote a push template, and a sending subdomain is not the only identifier that is painful to change once things point at it. Epic #4's own child list already calls them message templates.
>
> **S46 has a departure worth naming: two of F5.6's seven merge fields cannot resolve yet.** Key dates are Slice 4 (#109) and the status page link is Slice 4 (#110). They are *registered* rather than omitted, carrying the slice that wires them, so the picker names them and the save-time validator refuses them **by name** — "there is no such field" would send somebody looking for a spelling mistake. Same shape as `GateRegistry::selectableOptions()` one screen over.
>
> **And there is no delete.** A template is archived, because an automation *points at* one — the rule S76 set for every lookup screen. `/templates/{template}` has a destroy and this does not, and the difference is real: instantiation snapshots a workflow template, so a running deal holds no pointer back to it; an automation holds a live pointer here.

> [!note] S44's *approval toggle* and *manual prompt* are one control, not two
> The row above lists them separately, which is how PRD §6.2 names the columns, and the editor offers **one three-way choice**: fires on its own · needs approving first · prompts somebody to do it. F5.4's manual prompt and F5.7's approval queue describe the same moment from two ends — a human in the loop — so two booleans have four states of which two are nonsense, and an automation that did both would ask two people to agree to one email. `action_definitions` carries the invariant as a CHECK constraint, so a route added in a later slice inherits it.
>
> The Screen Inventory's own note on this screen asks for *"a progressive form that narrows, not four independent dropdowns that can be combined into nonsense"*, and every narrowing the form does is refused again on the server: a template on an action that sends nothing, a template on the wrong channel, an archived template, a requirement from another stage, and a trigger nothing can raise yet.

> **#87's seeded packs are not built and are not blocked on any of this.** They are blocked on #11 — Emily's real listing-side checklist — because a seeded pack whose stages somebody invented is worse than an empty templates screen: it teaches a process nobody follows and gets copied before anyone notices.

## H. Automation runtime

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S47 | Message approval queue | `/messages` | Team | Empty, needs review, **held by a rail**, **handed over and never confirmed**, did not go out. **Built.** Failures and held messages are each their own query rather than a filter over recent sends, and every list says when it is truncated. No bulk approve, deliberately — see the note below | F5.7 | 3 | M |
| S48 | Message preview and edit | `/messages/{message}` | Team | Rendered with real merge data, missing field, editing before send. **Built**, as a page rather than a modal — see the note below | F5.6 | 3 | M |
| S49 | Automation failure detail | `/messages/{message}` | Team | Provider error, resolved to nobody, stopped by a person, sandbox redirect, and the delivery history from bounces and complaints. **Built** (#95) | F5.8 | 3 | S |

> [!note] S47 has no bulk approve, and the absence is the feature
> The row above listed *bulk approve* as a key state, and it is not built and
> will not be. Issue #93 names the failure mode in as many words — *"bulk
> approve teaches people to approve without reading"* — and PRD §4.5 makes this
> queue a launch blocker precisely because the thing it guards cannot be
> recalled. Every release is one message, opened, with its words on screen.
> There is no route that takes an array of ids, and a test asserts the route
> list, because a screen that chose not to draw the button would still leave the
> endpoint there.
>
> The route is `/messages` rather than `/messages/pending`: the same screen
> carries what did not go out, and a path naming one of its two halves would be
> wrong the moment somebody's credentials expired. Failures sit **above** the
> queue rather than behind a tab, which is the same argument — PRD §1.1's second
> question is *"has the client been told?"*, and a message that failed answers it
> exactly as badly as one still waiting.
>
> **And they are their own query.** The first build derived them by filtering the
> 25 most-recently-touched rows, which made *"did this go out?"* a question about
> how busy the team had been since: 25 successful sends pushed a failure off the
> screen entirely. Both lists are bounded — a team inside F5.7's window has every
> outbound message here — and both say so when they are, because a list that
> silently shows 200 of 340 is a list somebody believes they have cleared.

> [!note] S48 and S49 are one page, and it is not a modal
> The rows had S48 as a modal preview and S49 as a separate failure page, and
> building them showed they are the same screen asked at two moments. What a
> reviewer needs before releasing a message — the words, the recipients, the deal,
> what is unfilled — is exactly what somebody needs three months later when the
> question is why the client never heard about the inspection. Two screens would
> have been two renderings of one payload, disagreeing within a month about which
> template a message came from.
>
> A page rather than a modal because the body is the point: F5.10 pre-fills a
> message *"ready to review and send"*, and reviewing means reading an email at
> the size an email is. The HTML body renders in a sandboxed iframe, never
> `v-html` — S46's editor made the same choice for the same reason.
>
> **The test send stays on S46**, where the template lives. A test send of a
> *queued instance* would be a second copy of a real client message on a real
> transport, which is the thing this screen exists to make deliberate.

## I. Documents

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S50 | Documents index | `/documents` | Team | **Built** (#98). Empty, categorised, filtered by deal, storage used — the last is **reported, never enforced**: no plan tier exists, so a bar toward an invented limit would be a lie somebody later builds a billing assumption on | F6.1 | 3 | M |
| S51 | Upload dialog | modal | TC | **Built** (#98). **Prominent PII warning** as a panel above the control, category required, drag and drop, progress. **One file at a time**, deliberately: the dialog carries a category *and* a visibility, and a multi-file drop would have to guess how those apply to the rest — guessing wrong on visibility publishes somebody's document | F6.6 | 3 | M |
| S52 | Document viewer | `/documents/{document}` | Team | **Built** (#98). PDF, image, unsupported type, download, visibility toggle. The preview is decided by the stored `mime_type`, which `finfo` derived from the bytes — never the filename; bytes are served only by the subject's own audited route | F6.4 | 3 | M |
| S53 | Upload refused | modal | TC | **Built** (#99). Detected financial instrument, explanation, what to do instead — read off the session rather than flashed as a toast, because a refusal has three things to say and has to stay on screen until they are read | F6.7 | 3 | S |

> [!danger] S51 and S53 carry legal weight, not just UX
> The warning on S51 is a compliance control described in PRD section 10, and S53 is the visible half of the scan in PRD section 8.4. Neither can be quietly softened later for being annoying. Design them to be read, and write the copy with the eventual terms of service in mind.

## J. Mobile and PWA

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S54 | Install prompt and onboarding | in-app | Team | iOS instructions, Android prompt, already installed, dismissed | F12.1 | 3 | S |
| S55 | Push permission flow | in-app | Team | Pre-prompt rationale, granted, denied, blocked at OS level | F12.2 | 3 | S |
| S56 | Offline state | global | Team | Cached read-only, stale banner, reconnecting, action queued | F12.1 | 3 | S |

> [!note] iOS makes S54 mandatory rather than optional
> Web push on iOS only works once the PWA has been added to the home screen, so the install prompt is a prerequisite for notifications rather than a nicety. Budget real copy time for it, because it is asking a user to do something unfamiliar.

## K. Calendar and dates

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S57 | Calendar | `/calendar` | Team | Month, week, agenda, empty, dense day, deadline versus event styling. **Built** (#105) — see the note in the hard-screens table below | F8.1 | 4 | **L** |
| S58 | Event detail and edit | modal | Team | New, edit, link to deal and stage, attendees, recurring. **Built** (#105). Attendees are membership ids on the event rather than a join table: an attendee here is a colleague on a shared calendar, not an invitation anybody replies to, so there is no state per person to store | F8.1 | 4 | S |
| S59 | Dates and deadlines list | `/dates` | Team | Cross-deal, next 14 days, overdue, critical only. **Built** (#107), reading the same `KeyDate` scopes S18 does — a date is pending, confirmed or past due in exactly one place | F8.2 | 4 | M |
| S60 | iCal feed settings | modal | Team | Generate, revoke, copy URL, per-deal versus personal. **Built** (#108). A modal over S57, so the feed list rides in that screen's props rather than on a route that renders a page nobody navigates to; the list is **this person's only**, because a feed URL is a bearer token and a colleague's is not something to draw on a screen. A feed also stops resolving the moment its holder stops holding `calendar.view` (#194) — the key this screen's own parent is gated on, so a URL cannot outlive the screen it came from — and that **gates rather than revokes**, so restoring the role restores the subscription. The consequence is that a gated feed cannot be ended: it is on no list, and for its holder `destroy()` is gated on the same key it just lost — which is #206 | F8.3 | 4 | S |

## L. Client status page

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S61 | Magic link verifying | `/s/{token}` | Client | Verifying, success redirect, failure. **Built** (#110), and the *verifying* state does not exist: redemption is a conditional `UPDATE` inside the same request, so the client is redirected to their session URL before there is anything to render. A spinner over a synchronous claim would be a picture of work, and the two states that remain — success and failure — are both redirects | F7.1 | 4 | S |
| S62 | Status timeline | `/s/{token}` | Client | **Mobile first.** Early stage, mid, complete, nothing happening, team branding, no documents. **Built** (#111) — see the note in the hard-screens table below | F7.2, F7.5 | 4 | **L** |
| S63 | Status documents | `/s/{token}/documents` | Client | Empty, list, download. **Built** (#111). Reached by a link rather than embedded, because IA §5.4 gives this surface one page scrolled and a deal with fourteen documents would be most of it; the section is absent rather than empty when there are none, which is the ordinary case | F7.4 | 4 | S |
| S64 | Expired or invalid link | `/s/expired` | Client | Expired, already used, revoked, request a new one. **Built** (#110). The three refusals get three sentences, because *"already used"* and *"revoked"* mean opposite things to the person reading — one is *press the link again from the newer email*, the other is *your agent ended this*. Requesting a new one posts back and always answers the same way, so the form cannot be used to learn which deals exist | F7.1 | 4 | S |

> [!tip] S62 is where the "nothing is happening" state matters most
> A client checks in during a quiet week and needs to leave reassured rather than worried. That state gets designed last and matters most, and PRD section 9 puts it under WCAG 2.1 AA because this audience skews older than the internal users.

## M. AI extraction (slice 5)

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S65 | Extract from document | modal | TC | Upload, queued, processing, failed, cost warning | F10.1 | 5 | M |
| S66 | Review extracted dates | `/deals/{deal}/extractions/{extraction}` | TC | Source PDF beside proposed dates, confidence levels, edit, reject, conflict with existing, cascade preview | F10.2 | 5 | **L** |
| S67 | Review extracted tasks | `/deals/{deal}/extractions/{extraction}` | Team | Proposed tasks, bulk accept, edit, assign, reject trivia | F10.3 | 5 | M |
| S68 | Extraction history | `/settings/extractions` | Admin | Per-deal audit, model version, cost, what the human changed | F10.4 | 5 | S |

> [!danger] S66 is the highest-risk screen in the product
> A missed inspection deadline has legal consequences, and this screen is the only thing standing between a model's output and a live contingency calendar. It must make an unreviewed date impossible to accept by accident, show its source page so a human can actually check it, and never default to "confirm all." Design it as if someone will click through it while distracted, because they will.

## N. Keep in Touch (slice 6)

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S69 | Past clients | `/keep-in-touch` | Agent | Empty, hundreds of rows, last contacted, sorted by neglect | F11.1 | 6 | M |
| S70 | Suggestions queue | `/keep-in-touch/suggestions` | Agent | Anniversary, local event, dormant, dismissed, actioned | F11.2, F11.4 | 6 | M |
| S71 | Keep in touch schedule | modal | Agent | Cadence, pause, opt out, next touch | F11.3 | 6 | S |

## O. Settings

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S72 | Team profile and branding | `/settings/team` | Agent | Logo upload, colour picker, signature block, live preview of client page | F1.2, F7.5 | 1 | M |
| S72b | Sending safety | `/settings/sending` | Agent | Stop everything, sandbox, hourly and daily limits, and the first-month review window — all four editable, not three and a notice. **Built** in Slice 3 — see the note below | F5.9 | 3 | S |
| S73 | Sending identity | `/settings/sending/identity` | Agent | Unverified, DNS records to add, verifying, verified, failed | F5.9 | 3 | M |
| S74 | Members and invitations | `/settings/members` | Agent | Empty, pending invites, revoke, last owner warning, **link issued** (shown once, replaces the emailed one — ADR 0003), **re-invite adds a role to an active member and replaces a revoked one's whole set** | F1.3 | 1 | M |
| S75 | Roles and permissions | `/settings/roles` | Agent | System roles (locked), custom roles, permission matrix, in-use warning | F2.3 | 2 | **L** |
| S76 | Deal types and lookups | `/settings/deal-types` | Agent | Defaults, custom, in-use warning | F3.1 | 2 | S |
| S77 | My profile and security | `/settings/profile` | Team | Details, password, 2FA enrol, recovery codes, sessions | NFR | 1 | S |
| S78 | Notification preferences | `/settings/notifications` | Team | Per event type, channel, quiet hours | F12.4 | 3 | S |
| S79 | Data export | `/settings/export` | Agent | Request, preparing, ready, expired | NFR | 1 | S |
| S80 | Billing | `/settings/billing` | Agent | Plan, packs owned, seats, invoices, payment method | Slice 7 | 7 | M |

> [!note] The logo upload arrived with S86, not with S72
> Slice 1 shipped the colour picker, the signature block and the preview, and `teams.logo_path` with **no writer** — a column the screen rendered as a value and nothing could set. That was harmless until #97 made *"per-team logo"* a headline state of S86: a layout reading a column nothing can fill is `CLAUDE.md`'s S17 finding pointed the other way round, and reads as finished from either end. The upload is on this screen now, on the private documents disk, served back through an authorized route and **embedded** rather than linked in email.

> [!note] S72b was not in the original inventory, and F5.9 required it
> The rails themselves are `SendRails`, in the queue worker — issue #96 is
> explicit that *"every one of them must hold when a message is sent by a
> scheduled job at 3am with no human present"*, so no screen enforces anything
> here. What this screen does is let somebody **reach** them, which is a
> separate question the feature list did not ask: F5.9 describes the kill switch
> as *"one toggle"*, and a toggle nobody can find is a column.
>
> Its own screen rather than a panel on S72, because this is the one somebody
> opens in a hurry after a client phones, and burying the stop button under a
> colour picker is how it takes forty seconds to find instead of five. It says
> how many messages the switch is currently holding rather than promising that
> it holds them.
>
> **S73 moved to `/settings/sending/identity` to make room**, and the nesting is
> right rather than merely available: verifying a sending domain and deciding
> what may leave the building are the same subject at two depths, and a person
> setting up DNS records has arrived from this screen. Both are still to come
> — S73 is #94 — but the route belonged to something built, and two screens
> sharing one path is a bug waiting for the second one.

## P. Super admin

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S81 | Admin dashboard | `/admin` | Admin | Tenant count, health summary, recent errors | F1.5 | 1 | S |
| S82 | Teams list | `/admin/teams` | Admin | Search, suspended, usage | F1.5 | 1 | S |
| S83 | Team detail and provision | `/admin/teams/{team}` | Admin | Create, edit, suspend, usage detail, **pending invitations and link issued** (ADR 0003) | F1.5 | 1 | M |
| S84 | Start impersonation | `/admin/teams/{team}/impersonate` | Admin | **Reason required**, duration, audit warning | F1.5 | 1 | S |
| S85 | System health and queues | `/admin/health` | Admin | Queue depth, failed jobs, AI spend against cap, SES reputation | NFR | 1 | M |

## Q. Transactional email templates

Real design work, and easy to forget in an inventory. These are what the client actually sees most of the time.

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S86 | Base branded email layout | email | Client | Per-team logo and colours, dark mode clients, plain text fallback. **Built** (#97). Every `Mailable` in `app/Mail` extends `mail.layout` — Fortify's password reset is a framework notification and deliberately outside it, see the layout's own note; the accent is a fill with a computed foreground and never text; the logo is embedded, on a plate | F5.5 | 3 | M |
| S87 | Milestone notification | email | Client | With and without MLS link, with and without status link, long address. **Built** (#97), as a frame rather than a second mailable — see the note below | F5.5 | 3 | M |
| S88 | Deadline reminder | email | Team | Single date, several dates, critical styling. **Built** (#109) as a notification rather than a mailable of its own, so S86's layout and F12.4's quiet hours both apply without a second path. **One digest per person per morning however many dates are in it** — a reminder that arrives five times is a reminder somebody filters. A critical date **today** is the exception and its own notification, because it is the one type that bypasses quiet hours | F8.4 | 4 | S |
| S89 | Magic link | email | Client | Link, expiry note, "you did not request this". ADR 0003 applies: the agent must be able to hand the client a link without the message. **Built** (#110), with two second doors rather than one — **Copy link** on the deal's People tab hands the agent the URL to pass on by whatever channel the client actually answers, and `status-page:link` prints one from the console | F7.1 | 4 | S |
| S90 | Team invitation | email | Team | Inviter name, team name, expiry. **Never the only way in** — see S04, S09, S74, S83 and ADR 0003. **Built** in Slice 1; reframed in S86's layout in Slice 3 | F1.3 | 1 | S |
| S91 | Internal alert | email | Team | Automation failed, bounce, extraction failed. **Built** (#97, with bounces added by #95); an extraction failure is Slice 5 | F5.8 | 3 | S |

> [!note] S87 is a frame, not a second mailable
> The obvious reading of "milestone notification" is a `MilestoneNotificationMail` of its own. That would be a second path to a client's inbox, past F5.7's approval queue and F5.9's three rails — a second front door cut into the feature PRD §4.5 calls the highest-blast-radius in the product, for the sake of a layout.
>
> So a milestone notification is an **ordinary automated message that happens to be about a milestone**: raised by a `stage_completion` automation, queued, approved and railed exactly like every other one, and wearing a frame that opens with the stage's `milestone_label`. Every guarantee is unchanged because nothing about the send changed.
>
> Two consequences worth knowing. The headline appears only on a **completion** — a milestone is *the notable completion of a stage* (IA §2), and a `stage_start` email would open with "Your home is on the market" on the morning the photographer was booked. And the frame offers the MLS link only when the team's own words do not already carry it, because `{{ mls_link }}` is a merge field a template may already use and PRD §5.4's worked example is exactly that email.

---

## Summary

**91 screens.** Higher than the 55 to 70 I estimated before enumerating, and the overage is almost entirely settings, templates, and transactional emails, which are the three groups people forget when estimating from a feature list.

### By effort

| Effort | Count | Full design pass | With a component library |
|---|---|---|---|
| **L** | 15 | 60 days | 30 days |
| **M** | 38 | 57 days | 19 days |
| **S** | 38 | 19 days | 6 days |
| **Total** | **91** | **~136 days** | **~55 days** |

Those numbers are design only, and they exclude build. The gap between them is the entire argument for buying Tailwind Plus, which was the recommendation in [[Design references]]. Roughly 76 of the 91 screens are assembly work if a strong component library and a settled design system are in place, and become bespoke work if they are not.

### By slice

| Slice | Screens | L | Focus |
|---|---|---|---|
| 1. Foundation | 21 | 2 | Auth, shell, people, admin |
| 2. Deals and workflow | 30 | 8 | The heart of the product |
| 3. Automation, documents, mobile | 20 | 2 | |
| 4. Calendar and status page | 11 | 2 | |
| 5. AI extraction | 4 | 1 | Small count, high risk |
| 6. Keep in Touch | 4 | 0 | |
| 7. Commercial | 1 | 0 | |

Slice 2 carries 30 screens and 8 of the 15 hard ones. That is consistent with it being the slice that makes the product exist, and it is worth knowing before it starts rather than during.

### The 15 screens that need real design thinking

Everything else assembles. These do not.

| ID | Screen | Why it is hard |
|---|---|---|
| S06 | App shell | Every other screen inherits its decisions |
| S10 | Team dashboard | 25 deals legible at once, with late and blocked obvious |
| S13 | Deals index | Density at 25 rows without becoming a spreadsheet. **Built.** Twenty rows, not twenty-five — Design System §4.3 now carries the measured budget rather than an estimate, and the line that decides it is the filter bar: hand-rolled `flex-wrap` inputs wrap to two rows and cost eight of them, so S13 uses the `h-8` components. Three departures: the `owner` cell is hidden and #78's assignee filter with it, because `deals` carries no owning-agent column (the same absence recorded for S15 above); the `date` cell is the soonest **open task** due date rather than a key date, since `key_dates` is S18 in slice 4 and a task due date is a real date somebody must act by; and the `select` cell is absent, because bulk select with no bulk action is a checkbox that selects into nothing — #78 asks for it to be *"hidden where it does not apply"*, and until an action exists that is everywhere. Saved views are deferred: `team_memberships.role_fields` could hold them, but it exists for role-contributed profile fields (PRD F2.7) and a saved view is not one |
| S15 | Deal overview | Six kinds of information competing for one screen. **Built.** Three departures, each forced by something the design documents do not cover: the current-stage card and the progress strip repeat **per running workflow**, because PRD §7.5 gives a deal concurrent ones and §9.2's recipe describes a single card; §8.4's one primary **Advance Stage** button appears only when exactly one workflow is running, since a primary action that silently picks one of two is worse than none; and §8.4's `owner` meta pair is absent because `deals` carries no owning-agent column. Dates and Documents are laid out as first-class cards naming the slice that fills them (4 and 3). Offers is hidden entirely, which is IA §5.2 read literally — there is no `offers` table yet, so every deal is empty of them |
| S38 | Photo gallery manager | **Built**, and with it the storage service Slice 3's documents will sit on. F6.4 is one sentence — *"no public buckets, every download authorized and short-lived"* — and it decided most of this. **The key reveals nothing**: `{team}/{ulid}.{ext}`, never `team-3/123-main-st/sellers-bank-statement.pdf`, with the typed name in the database where an authorized controller serves it. **Downloads are a route, not a presigned bucket URL**, which is the one decision worth arguing with: a presigned URL is cheaper and cannot be audited, and PRD §9 makes document access an audited event — an entry written when a link is *minted* records an intention rather than a read. **The bytes are deleted immediately** while the row soft-deletes for §9's thirty days, because a soft-deleted row whose file is still readable by anything holding the key is a deletion that did not delete. #63's **residual window** is closed by its first option and recorded on the issue: images only, against a property only, never a deal, and the F6.6 warning names cheques in those words. And the **sortable library is none** — explicit move controls, recorded in Design System §13.2, with the reorder endpoint taking the whole order at once so S41 and S42 can overturn the interaction without changing the API |
| S22 | Offers | **Built.** PRD §7.9's gap — *"nothing covers offers or the chain of dates governing a live transaction"* — filled as the team's own working record and **not the contract**: PRD §2.2 confirms e-signature is unnecessary in Emily's market and §10 leaves the executed document in CTM, so there is no upload column, no signature column, and a line on the screen saying where the signed version lives. Four decisions. **Accepting one offer rejects the rest in the same transaction**, because a deal with two accepted offers is a deal whose closing-date chain has two answers, and Slice 4 reads exactly that chain — a partial unique index is the backstop. An offer already *withdrawn* is left alone, because withdrawing is something the offeror did and rejecting is the team choosing somebody else. **Countered is a state, not a replacement**: a counter that overwrote the row it answered would lose the negotiation, so it is its own row. **Expiry is derived, never stored** — the rule `Task::state()` follows, and for the same reason. And the tab hides itself per IA §5.2 by asking the deal **side** rather than a `has_offers` column, since the side already answers it and a second place to record the same fact is a second place for it to be wrong; `Other` counts as having offers, because a tab nobody uses costs a tab and a missing one costs somebody the feature |
| S07 | Global search | **Built.** Postgres `like` on indexed name columns rather than a search service, which is what #82 asks for and PRD §8.1 argues for — *"revisit only if the result quality is genuinely inadequate"* is a judgement about real use, not something to pre-empt. Three decisions worth recording. **Every query is an ordinary scoped Eloquent query**: no `withoutTeamScope()`, no raw table access, no union that could shed a scope on the way through — #82 calls search *"the classic place tenancy leaks"*, and `tests/Isolation/GlobalSearchIsolationTest` proves the property with a control case, because a search that returns nothing for everything passes the leak test perfectly. **An empty group is not rendered**, since three headings above one result buries it. And **"nothing matches" is a claim about the team's data**, so a one-letter query gets *"keep typing"* instead — answering it with "no results" would be answering a question nobody asked. Documents are absent rather than empty: they are Slice 3, by filename and category only |
| S34 | Vendor directory | **Built**, as a segment of S30 rather than a resource of its own. PRD §5.9's fourth step is the whole value — *"filtering the directory by specialty surfaces him with his rating and history"* — so the two filters are the screen: specialty by `jsonb` containment, which means a vendor whose *service area* mentions staging is not a stager, and service area by substring. **Last used is derived from `deal_participants` and never stored**, exactly as F2.6 asks: it is the most useful column and the one most likely to be stale if duplicated, so it is a subquery selected once for the page. Two smaller decisions: the specialty options are read from the rows rather than a lookup, because IA §13.3 made specialties free text and the honest list is the one in use; and they are fetched **only on the segment that draws the filter**, because one query on four segments that never render the control is a cost nobody can justify. The filters are ignored rather than refused on other segments, so a stale bookmark cannot empty the Clients tab |
| S10 | Team dashboard | **Built.** §9.3's P4 pattern unchanged — stat row of four, lists on the left, a 352px rail of dates and activity on the right — and **two of the four stats answer a question the product cannot answer exactly, so both say so on the tile**. *Blocked stages* counts `stages.state`, which an advance attempt writes and nothing else refreshes, so a gate satisfied this morning still reads blocked: the tile is captioned *"as of the last advance"*, because the alternative is evaluating every gate on twenty-five deals on every render, which spends PRD §9's whole budget on a number nobody clicks. It is the same trade S16 makes in the other direction, and for a reason that inverts cleanly — that screen shows one expanded card where a stale badge contradicts the pane beside it, and this one shows a count with nothing to contradict. And *"Closing in 14 days"* is **departed from**: `key_dates` is S18, in Slice 4, so the tile counts what is genuinely due — `Deal::withNextDueDate()`, the near-enough S13's column already uses — and is labelled **Due in 14 days**. A heading that claims a closing date the database does not hold is worse than a departure recorded here. The third decision is the panel: Screen Inventory's hard part is *"25 deals legible at once, with late and blocked obvious"*, and one **Needs attention** list above the full one is what stops somebody scanning twenty-five rows for the three that matter — blocked sorts above late, because a blocked deal is one nobody can move and a late one is one somebody can |
| S11 | My Work | **Built.** F9.2's *"every task assigned to me across all deals, ordered by urgency"*, and three decisions the specification leaves to the build. **Grouped by deal, ordered by urgency, and those are not in tension**: `tasks.deal_id` is not nullable precisely because this screen groups by deal, so the rows are sorted once and the groups fall out in that order — the deal holding the most overdue thing is the deal at the top. **The count is shared from the middleware, not from this page**: §10.4 puts it beside the sidebar link on every screen, and a number that is only right on `/work` is wrong everywhere else, which is worse than no number; it costs one query per request, and `PeopleIndexBudgetTest`'s ceiling names it. **Overdue is asked of `Task::state()`** rather than re-derived, so the count and the badges under it cannot disagree — the same rule S17 records, and the reason this screen reads today in the team's calendar rather than the server's |
| S16 | Deal timeline | The novel interaction in the product. **Built.** Design System §7.4's stage rail, and the tab it had been holding open is live. Three decisions the specification leaves to the build, each recorded because it could reasonably have gone the other way: **one rail per workflow, never a merged one** — F4.7 lets two run concurrently and they have independent stage sequences with no shared order, so a merged rail has to invent one, and sorting by date is the obvious invention and is wrong; **Overridden is a marker, not a stage state** — §7.4's marker table has a row IA §8's five-state vocabulary does not, so an overridden stage is `complete` carrying `hasOverride`, and the marker and the badge deliberately disagree; and **the active stage is badged live while every other stage is badged from the record** — `stages.state` is a cache only an advance attempt refreshes, so a stage cached `blocked` whose gate has since been satisfied would badge Blocked over a requirements pane showing nothing in the way. One departure, and one that has since closed: `skipped_reason` renders as "no reason was recorded" rather than being hidden, because F4.12's skip is #70 and a field that only appears once somebody fills it is a field the screen forgets to draw; and the task rows, read-only while completing one was S17's unbuilt endpoint, went live with S17 (#71) — what keeps a checkbox inert now is the reader's permission rather than a missing route, which is the honest reason for it. Five departures from §7.4 are recorded in the Design System's own note on the component |
| S23 | Advance stage | Must explain refusal clearly enough to act on. **Built**, and completed later by the button that should have been the first one on it — **Confirm**. `ManualConfirmationEvaluator` answers by reading `gates.is_met`, and the only writer of that column was the advance's own cache refresh, which reads the evaluator: so the most common gate type in the product could not clear on its own, and the sole way past one was an **Override** — the act IA §7 reserves for a condition that should have been met and was not, carrying an audit entry and a follow-up task each time. The routine path through a gate was the audited exception, and nothing failed, because each half worked. It is the shape S17 found for tasks, one gate type over, and it had a second tell nobody read: `GatePolicy::update` already existed, docblocked *"Ticking a manual gate is ordinary deal work"*, for a permission no route asked for. Three decisions in closing it. **The gate is ticked in place, not on another page** — `linkTarget: gate` now resolves to nothing deliberately rather than for want of a screen, because sending somebody elsewhere to tick one box is the worst reading of PRD §5.4. **It writes `is_met` and never `overridden`**, exactly as the override writes the flag and never `is_met`, so six weeks later the record still separates *the survey came back* from *somebody proceeded without it* — and §12.2's override metric goes back to measuring processes that failed rather than gates people had no other way to clear. And **it is not audited**: PRD §9 names gate overrides in `audit_log` and not the ordinary path, and writing forty ticks a deal into an append-only table would bury the overrides it exists to make findable. The timeline carries it, with the actor. The refusal is explained by splitting the blockers by *what you would do next* rather than listing them: the ones an evaluator named a resolution for get a link, and the ones that cannot clear on their own get an **Override**. Three of the seven gate types are permanently in that second group for Slice 2, so it is the common case and not an edge. The group headings appear only once there is more than one blocker — over a list of one they are furniture. Design System §7.4's *"what happens when you advance"* block is built on the server (`App\Support\Workflow\AdvancePreview`) so that what the reader is promised can be tested; two of its four entries have no data until Slices 3 and 4 and say so by name rather than being omitted, because an absent Emails row reads as "no emails will be sent" and will silently stop being true. The dialog is loaded when it opens rather than served off a page prop — §8.4 puts Advance in the header, so any of the eight tabs can start one, and the whole value of the screen is that the refusal describes this minute |
| S33 | Contact import | Field mapping and duplicate resolution are always harder than they look |
| S31 | Person detail | Built against `{membership}` rather than `{person}`. A person is shared across teams (PRD decision log, 2026-08-22) and the membership is the team-scoped half, so binding to it means the global scope does the isolation — there is no route that could reach somebody this team has never met |
| S84 | Start impersonation | Built as a page, not a modal. A typed reason, a duration, and an unmissable warning are more than a modal should carry, and the screen is reached from a team's detail page rather than from a list |
| S41 | Workflow template editor | Reordering with in-flight deals to protect. **Built**, and the protection turned out to need no protecting: `InstantiateWorkflow` snapshotted at the moment the workflow started, so a running deal holds no pointer back here to break. That inverts the screen's job — the in-use count is shown not as a warning that an edit is dangerous but so somebody changing a template twelve deals came from knows **the twelve will not change with it**, which is the thing a team will otherwise assume the wrong way round and edit a template instead of fixing the deal. The count is `Workflow` scoped, and the unscoped version would have been a leak of exactly the shape the isolation suite exists to catch: a pack template is shared by every team, so counting without the scope tells one team how many deals every other team is running. Reordering has **no drag library** — Design System §13.2's note, decided in S38 — and the endpoint takes the whole order at once because a reorder is one intention, so two adjacent swaps racing produce an order neither person chose. #87 extended all of that: every stage, gate and task is **editable in place** and reorderable, because the four columns a seeded pack needs — a task's `owner_role`, `description`, `is_required` and `due_offset_days` — either had no writer at all or could be set once and never corrected, which is why #11's markup pass was happening in a GitHub comment rather than in the product. Correcting one flag used to mean deleting the task and adding it back, at the end of the list |
| S42 | Stage template editor | Three child types on one screen. **Built** as part of S41's page rather than a route of its own: a stage template is a name, a duration, a milestone flag and three short lists, and a screen per stage would make reordering — the interaction the hard-list names — a navigation. Two decisions. **Every action is authorized against the workflow template, never the stage**, because a policy guarding the parent row while a child route let somebody add a gate to one of its stages is a guard with a door beside it, and `TemplateEditingTest` asserts the refusal on the stage and gate routes rather than only on the template one. And **the gate type picker reads the registry**, so PRD §8.3's *"adding a gate type means adding a class"* extends to the editor: a typo is a validation error rather than a gate no evaluator will ever answer. It reads `selectableOptions()` rather than `types()`, and Slice 4 gave that list a second membership rule — `EDITOR_CONFIGURABLE`, added so `date_reached` could carry the name of the date it waits for. So an evaluator becomes selectable by being added to one of two constants rather than by existing, which is a better rule than *"by existing"* and worth stating as the one it is: a gate type the editor cannot configure is a gate type the editor should not offer |
| S44 | Automation editor | Trigger, action, recipient rule, all interdependent |
| S46 | Email template editor | Merge fields, validation, live preview |
| S57 | Calendar | Events and deadlines are different things sharing a grid. **Built** (#105), and the hard part is exactly where the row said it was. Four decisions. **The calendar library is none** — the same answer S38 gave the drag library, recorded in Design System §15.3, and for a sharper reason here: every scheduler component brings its own DOM and its own palette, and §13.2 rule 5 forbids a raw colour in a component, so the choice was a month grid of cells or a permanent exception to the rule the design system is held to by a test. **A deadline is a day and an event is a span**, so they sort differently within one cell rather than being drawn differently and left in whatever order the merge produced: `CalendarBoard` sorts on `[day, sortsAfterAllDay, sortKey]` with deadlines at `-1`, which puts *the inspection is due* above *11:00 showing* on the day both fall. **Recurrence is expanded on read and never stored as rows**, because an edit to a weekly event would otherwise have to find and rewrite fifty-two of them, and the occurrence a client saw would be a row rather than a rule; `Recurrence::occurrencesBetween()` skips to the first step near the window in closed form so a five-year-old daily event costs the same as a new one, and caps at 1,000 so a malformed rule cannot spin. And **one board feeds both readers** — S57 and S60's `.ics` compose the same merged list, so the feed on somebody's phone cannot disagree with the screen they are looking at |
| S62 | Client status timeline | The only screen a stranger uses unaided. **Built** (#111), and that sentence decided everything. **IA §9's language rules are enforced on the server, in `ClientStatus`, not in the template** — a template holding the rows could reach for `stage.name` in one place and forget, and the failure is silent until a seller reads *"Chase lender"* on their own page. It follows that **a stage with no client announcement is omitted rather than shown under its internal name**: the alternative to a missing row is a wrong one. **§9.6's reassurance paragraph is present in every status rather than being an empty state somebody remembered** — the quiet week is the state this screen exists for, and the version of it that gets designed last is the version that does not exist. Accessibility is a requirement here and not best effort (PRD §9), so the timeline is a real `<ol>`, each step's state is in **words** as well as in position and colour, targets are 44px, and `prefers-reduced-motion` is honoured by there being no motion to honour; #112 audited it. And **the team's accent is a fill with a computed foreground, never text** — the rule §15.6 settled for email, extended here for the same reason: S72 can *warn* an agent picking a colour because somebody is standing there, and this page has to *compute*, because nobody is |
| S66 | Review extracted dates | Highest legal risk in the product |
| S75 | Roles and permissions | Permission matrices are notoriously hard to make legible. **Built**, and the review found the sharp edge of composing keys: `Str::slug('Team Owner', '_')` is exactly `team_owner`, the shipped role's key, and the unique index is over `(team_id, key)` while the shipped rows have no team — so the database permitted the row and every check written as `roles.key = 'team_owner'` treated the counterfeit as the real thing, up to and including the guard that refuses to revoke the last owner. Both halves are fixed: the name is refused, and the membership's own checks ask for a null `team_id`. and made legible by cutting the matrix down rather than by drawing it better: the catalogue offered is the **team surface only**, so the platform console's permissions and the client surface's are absent — a team composing from `platform.administer` would be a customer granting themselves `/admin`, and the refusal is a validation error rather than a quiet filter, because a request naming a key nobody's screen rendered is an attempt worth refusing out loud. Three decisions follow S76's lookup pattern exactly. **A shipped role gets no controls rather than disabled ones**: F2.3 is that a team differs by composing a new role, and a team that renamed Team Member would change what that name means to everybody reading their own audit log six months later. **There is no destroy route at all** — a role appears in every audit entry and every membership that ever held it — and the archive is reversible, with the holder count shown *before* the choice, because archiving a role held by four people takes four people's access with it. And **the key is derived from the name and never typed**: it is what every permission check is written against, so letting a customer choose `team_owner` would let a customer choose what a name means in this product |

### Sequencing recommendation

Design S06 first and alone, then get it in front of Heather before drawing anything else. It sets type scale, density, colour, and the mobile collapse, and 70 screens inherit those decisions. A week spent there saves a month of inconsistency later.

Then S16, because the stage timeline is the one interaction with no obvious precedent to copy. Everything else in the inventory has a reference in [[Design references]]. That one has to be invented, and it is worth learning early whether it works.

---

## What this inventory deliberately excludes

- Trivial confirmation dialogs. Assume a shared pattern, designed once.
- Toasts, tooltips, and inline validation. Design system concerns, not screens.
- Per-screen empty and error states as separate entries. They are captured in the **Key states** column instead.
- Print and PDF views. None are specified. Confirm with Emily whether she needs a printable deal summary, because agents print more than software people expect.
- The marketing site. Different project, different audience, slice 7.
- Native app screens. Post-v1.

---

## Related notes

- [[Information Architecture]]: what everything is named
- [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document]]: what gets built and why
- [[Design references]]: what these screens should look like
- [[Design System]]: tokens, components, and the seven page patterns

## Next actions

- [x] Design S06 (app shell) and review it with Heather before anything else ✅ 2026-08-21 — approved; #31
- [ ] Prototype S16 (deal timeline), the one interaction with no clear precedent 📅 2026-09-07
- [ ] Buy or reject Tailwind Plus, since the estimate swings by ~80 days on it 📅 2026-08-27
- [ ] Ask Emily whether she needs printable deal summaries 📅 2026-08-27
- [ ] Apply the [[Information Architecture]] terminology pass to the PRD 📅 2026-08-27
