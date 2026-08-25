---
created: 2026-08-20
modified: 2026-08-22
project: Goldieflow
type: reference
status: draft
version: 1.0
tags:
  - monroe-digital
  - ia
  - naming
  - design
  - goldieflow
---

# Information Architecture

> [!info] What this document is for
> This is the **naming authority** for Goldieflow. When a table, a route, a button, a status badge, or a client-facing sentence needs a word, it comes from here. When this document and any other document disagree, this one wins and the other gets corrected.
>
> Companion documents: [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document]] (what gets built), [[Screen Inventory]] (which screens exist), [[Design references]] (what they should look like).

> [!warning] Two renames landed here that supersede PRD v0.2
> - **Project is now Deal**, everywhere: database, routes, and UI.
> - **Milestone is now Stage**, and *Milestone* is reassigned to a new, narrower meaning.
>
> Full mapping in [[#12. Migration from PRD v0.2]]. The PRD still uses the old terms and needs a terminology pass.

---

## 1. Naming principles

Six rules. Everything else in this document is these rules applied.

1. **Use the words the users already use.** Emily and Heather never once said "project." They said deal, listing, under contract, dates and deadlines. Matching their vocabulary removes a translation step from every conversation and every screen.
2. **One concept, one word, forever.** If a thing is a Stage, it is never a phase, a step, or a milestone in a tooltip. Synonyms in an interface read as different concepts.
3. **A word means one thing.** If "milestone" describes both a two-week period and a single moment, it describes neither. Split the concepts and name both.
4. **Internal vocabulary and client vocabulary are separate layers.** The client never sees a gate, a workflow, or an override. Translation happens at the boundary, deliberately, not by accident.
5. **Nouns name things, verbs name actions, and neither borrows from the other.** "Advance" is always the verb for moving a deal forward. It is never a noun, and no other verb ever means that.
6. **Code names and display labels may differ in form, never in meaning.** `key_dates` displays as "Dates & Deadlines." That is formatting. `projects` displaying as "Deals" would be two vocabularies, and that is forbidden.

---

## 2. The three vocabularies

Every concept has up to three names: what the code calls it, what the team sees, and what the client sees. A dash means the concept is never exposed at that layer.

| Concept | Code / database | Internal UI label | Client-facing label |
|---|---|---|---|
| The transaction | `deals` | Deal | Your Sale / Your Purchase |
| Kind of transaction | `deal_types` | Deal Type | (not shown) |
| Running procedure | `workflows` | Workflow | (not shown) |
| Reusable procedure | `workflow_templates` | Template | (not shown) |
| Bundle of templates | `template_packs` | Pack | (not shown) |
| A period within a workflow | `stages` | Stage | Step |
| A notable moment | `stages.is_milestone` | Milestone | (shown as an event on the timeline) |
| Condition on advancing | `gates` | Gate / Requirement | (not shown) |
| Work someone owes | `tasks` | Task | (not shown) |
| A person | `people` | Person | (not shown) |
| Team access record | `team_memberships` | Team Access | (not shown) |
| An access role | `roles` | Role | (not shown) |
| A capability | `permissions` | Permission | (not shown) |
| Which app a permission belongs to | `PermissionSurface` | Surface | (not shown) |
| Someone's part in a deal | `deal_participants` | Participant | (not shown) |
| A building | `properties` | Property | Property |
| A deadline | `key_dates` | Dates & Deadlines | Important Dates |
| Scheduled thing | `events` | Event | (varies: Inspection, Open House) |
| A file | `documents` | Document | Document |
| Automated behaviour | `action_definitions` | Automation | (not shown) |
| A fired automation | `action_instances` | (internal only) | (not shown) |
| Email content | `message_templates` | Email Template | (not shown) |
| History | `activity_events` | Activity | Updates |
| Logged interaction | `activity_events` (source: manual) | Contact Log | (not shown) |
| Post-close relationship | `nurture_schedules` | Keep in Touch | (not shown) |
| Suggested outreach | `touchpoint_prompts` | Suggestion | (not shown) |
| PDF reading | `extractions` | Extract | (not shown) |
| A proposed value | `extracted_fields` | Suggested Date / Suggested Task | (not shown) |
| A vendor | `people` + vendor fields | Vendor | (not shown) |
| The client's page | (route `/s/{token}`) | Status Page | Your [Sale / Purchase] |

> [!note] "On the team" is a property of the Permission, not of the Role
> A **Role** is a named bundle of Permissions; a Team Access record is a person holding roles in one team. Whether that makes them *on the team* — someone the Team segment lists, `/settings/members` can revoke, and the switcher offers a team to — is decided by the **Surface** of the permissions those roles carry: the team app, the client Status Page, or the platform console. So a Status Viewer with a Status-Page permission is still a Contact everywhere the team app asks, and a role a team composes itself needs no entry on any list to be recognised. Never write a rule about team membership as a list of role keys; the code has one definition of it (`TeamMembership::carriesAccess()`), and `tests/Isolation/TeamAccessConventionTest.php` fails the build on a second one. See PRD §4.2 F2.2 and issue #142.

> [!note] Why "Requirement" appears alongside "Gate"
> `gates` is the right word in code and in the template editor, where you are configuring conditions. In the deal view, where Heather is looking at what is blocking her, "2 requirements not met" reads better than "2 gates not met." Both are permitted, but only in their own context, and never in the same screen.

---

## 3. Stage and Milestone: the distinction

The rough data model called everything a Milestone. But the thing it described has a start date, an end date, contained tasks, and gates, which makes it a period, not a point. Emily and Heather talk in periods: pre-listing, signed listing, under contract, during inspection.

**A Stage is a period.** You are in it for days or weeks. It holds tasks, gates, and automations. It has planned and actual start and end dates. A workflow is an ordered list of stages.

**A Milestone is a moment.** It marks the completion of a stage that is significant enough to be worth telling somebody about. Not every stage is a milestone.

| | Stage | Milestone |
|---|---|---|
| Nature | Duration | Point in time |
| Example | "Under Contract" | "Property Listed" |
| Holds tasks | Yes | No |
| Holds gates | Yes | No |
| Has dates | Start and end | One |
| Client sees it | As a step on the timeline | As an event |
| Triggers client email | Sometimes | Usually |

**Implementation:** a milestone is not a separate table. It is `stages.is_milestone` plus `stages.milestone_label`, the client-facing name for the moment. "Listing Preparation" is a stage and not a milestone. "Property Listed" is both: the stage completes, and completing it is the moment.

This costs one boolean and one string, and it buys a vocabulary that describes what is actually happening.

---

## 4. Object hierarchy

```
Team
├── Team Access (people whose roles carry team-app permissions)
├── People
│   ├── Clients (lead → active → past client)
│   ├── Vendors
│   └── Team Members
├── Properties
├── Templates
│   └── Pack
│       └── Template
│           └── Stage Template
│               ├── Task Template
│               ├── Gate Template
│               └── Automation
├── Email Templates
├── Deals
│   ├── Participants (people, in a deal role)
│   ├── Properties (subject or candidate)
│   ├── Workflows
│   │   └── Stages
│   │       ├── Tasks
│   │       ├── Gates
│   │       └── Automations fired
│   ├── Dates & Deadlines
│   ├── Offers
│   ├── Events
│   ├── Documents
│   └── Activity
└── Keep in Touch
    ├── Past Clients
    └── Suggestions
```

**Read the hierarchy as ownership, not navigation.** A Property is owned by the Team and referenced by Deals, which is why it appears at both levels. Documents belong to a Deal but are also browsable team-wide.

---

## 5. Navigation architecture

### 5.1 Internal app: persistent left sidebar

| Section | Route | Contents |
|---|---|---|
| **Dashboard** | `/dashboard` | Active deals, what is blocked, what is late |
| **My Work** | `/work` | Every task assigned to me, across deals |
| **Deals** | `/deals` | The deal list, filters, saved views |
| **People** | `/people` | Segmented: Clients, Vendors, Team, Leads |
| **Properties** | `/properties` | Property directory |
| **Calendar** | `/calendar` | Events and deadlines |
| **Keep in Touch** | `/keep-in-touch` | Past clients and suggestions |
| **Activity** | `/activity` | Everything the team has done, filterable (S12) |
| **Templates** | `/templates` | Packs, workflow templates, email templates |
| **Settings** | `/settings` | Team, members, roles, integrations, profile |

Sidebar order is deliberate: the two screens Heather opens every morning sit at the top, and configuration sits at the bottom where it is found once and rarely revisited.

**Permission scoping:** Templates and Settings are visible only to holders of the relevant permission. A section the user cannot use is hidden, never shown disabled.

> [!note] Activity is in the sidebar, and it is not the Audit Log
> Added with S12 (#81). [[Screen Inventory]] always routed it at `/activity`, and a screen at a route with nothing pointing at it is a screen nobody opens — so it takes a sidebar row, second-to-last in its group, because "what has everyone been doing" is a question asked at the end of a day rather than the start of one.
>
> It reads `activity_events` and it is called **Activity** (§11: never Feed, History, or Log). The **Audit Log** is a different table with different retention, a different permission (`team.audit.view`), and a different reader; it stays in Settings. Two records, two screens, two words.

### 5.2 Inside a deal: horizontal tabs

Opening a deal is entering a context. Tabs, not sidebar items.

| Tab | Route | Notes |
|---|---|---|
| Overview | `/deals/{deal}` | The hub. Default landing. |
| Timeline | `/deals/{deal}/timeline` | Stages, current position, activity |
| Tasks | `/deals/{deal}/tasks` | This deal's tasks |
| Dates | `/deals/{deal}/dates` | Dates and deadlines |
| People | `/deals/{deal}/people` | Participants |
| Properties | `/deals/{deal}/properties` | Subject and candidates |
| Documents | `/deals/{deal}/documents` | |
| Offers | `/deals/{deal}/offers` | Hidden when empty and the deal type has no offers |

### 5.3 Mobile (PWA)

The sidebar collapses to a bottom tab bar carrying the four most-used destinations: **Dashboard, My Work, Deals, Calendar**. Everything else lives behind a "More" sheet. Deal tabs become a horizontally scrollable strip.

### 5.4 Client status page

No navigation. One page, scrolled. Adding navigation to a page a client visits four times is adding a decision they did not ask to make.

### 5.5 Super admin

Separate route namespace at `/admin`, visually distinct from the tenant app so nobody ever confuses the two. Sections: Teams, People, System Health, Audit Log.

---

## 6. Route conventions

| Rule | Example |
|---|---|
| Plural resource collections | `/deals`, `/people`, `/properties` |
| ULID identifiers, never sequential integers | `/deals/01J8XZ...` |
| Nested resources only one level deep | `/deals/{deal}/documents`, never `/deals/{deal}/workflows/{workflow}/stages/{stage}` |
| Deeper things get query parameters | `/deals/{deal}/timeline?stage=01J8...` |
| Verbs only for non-CRUD actions, as POST | `/deals/{deal}/advance`, `/deals/{deal}/close` |
| Settings namespaced by subject | `/settings/team`, `/settings/members`, `/settings/roles` |
| Admin namespaced separately | `/admin/teams` |
| Client status page short and opaque | `/s/{token}` |
| Kebab-case in paths, never snake or camel | `/keep-in-touch` |

**Inertia page components** mirror routes in PascalCase: `/deals` renders `Pages/Deals/Index.vue`, `/deals/{deal}/documents` renders `Pages/Deals/Documents.vue`.

---

## 7. Action vocabulary

One verb per action, used identically everywhere it appears. The right column is not stylistic preference; those words are banned because they create ambiguity.

| Verb | Means | Never use instead |
|---|---|---|
| **Create** | Bring a new top-level record into existence | New (as a verb), Make, Start |
| **Add** | Attach something to a parent | Create, Insert, Attach |
| **Edit** | Change an existing record | Update, Modify, Change |
| **Remove** | Detach from a parent, record survives | Delete, Unlink |
| **Delete** | Destroy permanently | Remove, Erase, Trash |
| **Archive** | Retire from active view, fully retained | Delete, Hide, Close |
| **Advance** | Move a workflow to its next stage | Progress, Move, Next, Complete |
| **Override** | Force past an unmet gate, with a reason | Bypass, Force, Skip, Ignore |
| **Skip** | Mark a stage not applicable to this deal | Ignore, Dismiss, Cancel |
| **Complete** | Finish a task | Done, Close, Check off, Finish |
| **Reopen** | Undo a completion — a task that is not done after all, or a stage the team has to go back to | Uncomplete, Undo, Revert, Restart |
| **Assign** | Give a task an owner | Delegate, Allocate, Give |
| **Log** | Record something that already happened | Add note, Record, Track |
| **Send** | Dispatch a message immediately | Fire, Trigger, Push, Blast |
| **Approve** | Release a queued message for sending | Confirm, OK, Accept, Allow |
| **Invite** | Bring a person into a team with access | Add user, Create user |
| **Install** | Add a template pack to a team | Enable, Import, Get |
| **Duplicate** | Copy a template for editing | Clone, Copy, Fork |
| **Extract** | Read structured data out of a document | Scan, Parse, Import, Analyze |
| **Confirm** | Accept an extracted value into a live record | Approve, Accept, Save |
| **Close** | End a deal at completion | Complete, Finish, Archive |

> [!warning] Override and Skip are different, and the difference matters legally
> **Override** means the gate should have been met and was not, and you are proceeding anyway. It demands a reason, writes an audit entry, and creates a follow-up task. **Skip** means the stage does not apply to this deal at all. Conflating them in a label destroys the audit trail's meaning.

---

## 8. State vocabulary

Code uses `snake_case`. UI uses Title Case. Client-facing uses plain language.

### Deal

| Code | UI | Client-facing |
|---|---|---|
| `active` | Active | In Progress |
| `closed` | Closed | Complete |
| `nurture` | Past Client | (not shown) |
| `fell_through` | Fell Through | (not shown) |
| `cancelled` | Cancelled | (not shown) |

### Workflow

`not_started` → Not Started · `active` → Active · `on_hold` → On Hold · `completed` → Completed · `cancelled` → Cancelled

### Stage

| Code | UI | Badge colour | Client-facing |
|---|---|---|---|
| `pending` | Upcoming | Neutral | Coming Up |
| `active` | In Progress | Blue | In Progress |
| `blocked` | Blocked | Amber | (shown as In Progress) |
| `complete` | Complete | Green | Done |
| `skipped` | Skipped | Neutral | (hidden) |

**Note:** `blocked` is never surfaced to the client. A client seeing "Blocked" reads it as "something has gone wrong," when it usually means a checkbox is unticked.

### Task

`open` → Open · `completed` → Completed · `overdue` → Overdue (derived, not stored)

### Gate

`met` → Met · `unmet` → Not Met · `overridden` → Overridden

### Person lifecycle

`lead` → Lead · `active` → Client · `past_client` → Past Client · `archived` → Archived

> [!warning] This describes a **contact**, not a colleague
> Every value here is a stage of a client relationship, so somebody on the team
> has no honest answer in it. `team_memberships.status` is therefore
> **nullable**, and null is not "unknown" — it is *this person has no place on
> the client lifecycle*. It is what a colleague holds, and what a former
> colleague holds until the team says what they are now.
>
> That column used to be `NOT NULL`, so `AcceptInvitation` wrote `active` for
> want of something to write, and `active` reads as *Client* — a team's own
> assistant badged as their client (#162). The fix that hid the badge for
> anybody carrying access only moved the problem: revoke that access and the
> row fell back to the same value nobody had chosen. The question
> *"was `active` typed or defaulted?"* has no answer in a column that cannot be
> empty.
>
> **Colleague means team access *and* not revoked.**
> `TeamMembership::carriesAccess()` is the one definition of team access (§2's
> note above, and issue #142) and deliberately says nothing about revocation —
> a revoked Team Owner's membership is still an access membership, which is why
> removing somebody revokes rather than deletes. `isColleague()` adds the
> revocation, and `scopeNotColleagues()` is the same question in SQL, so the
> **Leads** and **Clients** segments of S30 filter on exactly what the badge
> beside the row draws. They asked two different questions for one round, and a
> former colleague recorded as a past client was then visible on no segment but
> All.
>
> **What accepting an invitation does to the lifecycle**, since that is the one
> place the product writes it without a human choosing:
>
> - Somebody who is **on the team afterwards** holds null. That is the invited
>   role granting team access, *or* a live membership that already carried it —
>   the roles are a union on a live row, so a Team Member given a status page is
>   still a Team Member, and asking only the invitation wrote `active` onto a
>   colleague where nobody could see or correct it.
> - Anybody else **keeps the lifecycle they had**. Clearing it for `Contact` and
>   `Status Viewer` erased a classification the team had typed.
> - With none to keep, they get `active` — *Client*. This is what the product
>   did before any of this and is deliberately unchanged, but it is a guess, and
>   for the `Contact` role — *"known to the team, with no access of any kind"*,
>   which is a lender or an inspector as readily as a client — it is the wrong
>   one. `SavePerson::create` answers the same question with `Lead`.
>
> A professional contact has **no lifecycle**: they are in or out, engaged
> across many deals, never promoted from one state to another. So the answer is
> not a fifth state here — it is a badge drawn from the vocabulary that already
> describes them, `ParticipantRole` (§13.3's flag and specialties, and PRD §6.3's
> per-deal roles). Issue #167 is where that lands; until it does, these two
> paths guess, visibly and correctably, on S32.
>
> An **archived** role grants nothing, on both sides of that question: a
> membership whose only role is archived carries no access, so it is not a
> colleague, so its lifecycle is its own.
>
> A screen draws three independent facts, each when it is true: the roles the
> team calls them by (whenever the membership carries access, revoked or not,
> so S30 agrees with `/settings/members` and the console), the lifecycle
> (whenever it is not null), and **Revoked**. S32 offers the lifecycle for a
> contact, refuses it for a colleague, and makes it optional for a former one.

### Automation / message

`pending` → Scheduled · `awaiting_approval` → Needs Review · `sent` → Sent · `failed` → Failed · `cancelled` → Cancelled

### Extracted field

`pending` → Needs Review · `confirmed` → Confirmed · `edited` → Edited · `rejected` → Rejected

---

## 9. Client-facing language rules

The client is a homeowner, not a user of software. Three rules, all traceable to what Emily and Heather said.

**No jargon, ever.** The client sees the `milestone_label`, never the internal stage name.

| Internal | Client-facing |
|---|---|
| Pre-Listing Preparation | Getting your home ready |
| Signed Listing Agreement | We're officially working together |
| Property Listed | Your home is on the market |
| Under Contract | You have an accepted offer |
| Inspection Objection Deadline | Inspection review period |
| Clear to Close | Everything's approved |
| Closed | Sold |

**No instructions directed at the client.** Heather's point, and it shapes the whole surface: chasing a client through a checklist reads as less professional than a phone call, not more. The status page states facts and never issues assignments. "Your inspection is scheduled for Thursday" is correct. "Action needed: confirm inspection time" is not.

**No alarming words.** Blocked, failed, overdue, and error never reach the client. If something is late, the agent handles it by phone.

---

## 10. Content and formatting conventions

| Element | Convention | Example |
|---|---|---|
| Person names | First Last in display, sortable by last | Emily Bosart |
| Deal names | Subject property street address, falling back to client surname | 123 Main St · Bosart Purchase |
| Addresses | Street on line one, City, ST ZIP on line two | |
| Dates, internal | Weekday, short month, day | Thu, Aug 20 |
| Dates, client-facing | Full month and day, no year unless it differs | Thursday, August 20 |
| Relative dates | Only within 7 days, then absolute | in 3 days · Aug 30 |
| Times | 12-hour with lowercase meridiem, team timezone | 2:30pm |
| Currency | Whole dollars, thousands separated, no cents above $1,000 | $485,000 |
| Counts | Numeral plus noun, pluralised | 3 deals · 1 task |
| Empty states | State what goes here, then the action | "No deals yet. Create your first deal." |
| Destructive confirmation | Name the object and the consequence | "Delete 123 Main St? This removes 14 tasks and cannot be undone." |
| Errors | What happened, then what to do | "Couldn't send. Check the sending address in Settings." |
| Loading | Skeletons for known layouts, spinners only for unknown duration | |

**Sentence case for everything the user reads.** Title Case is reserved for navigation labels, tabs, and status badges.

---

## 11. Glossary: use this, not that

| Use | Not | Why |
|---|---|---|
| Deal | Project, Transaction, File, Matter | What Emily and Heather actually say |
| Stage | Milestone, Phase, Step, Status | A period, and Milestone now means something else |
| Milestone | Key event, Checkpoint | A moment worth reporting |
| Gate | Condition, Requirement (except in the deal view), Blocker, Rule | |
| Task | To-do, Item, Action item, Checklist item | Action is taken by Automation |
| Automation | Action, Trigger, Rule, Workflow step | Workflow means the running procedure |
| Template | Workflow template (in UI), Blueprint, Preset | |
| Pack | Bundle, Library, Kit, Module | |
| Participant | Contact, Party, Member, Stakeholder | Member means team access |
| Person | User, Contact, Record, Lead | User means someone with login |
| Vendor | Service provider, Contractor, Supplier | Shorter, and what agents say |
| Dates & Deadlines | Key dates (in UI), Important dates, Milestones | Emily's exact phrase |
| Activity | History, Log, Feed, Audit | Audit means the security log |
| Status Page | Portal, Client portal, Dashboard | Portal overpromises a read-only page |
| Keep in Touch | Nurture, Drip, Campaign, CRM | Marketing jargon Emily would not use |
| Team | Organization, Account, Workspace, Company | |
| Extract | Scan, Parse, Analyze, Read, AI | |

---

## 12. Migration from PRD v0.2

PRD v0.2 predates this document and still uses the old terms. Apply this mapping when the PRD gets its terminology pass.

| PRD v0.2 | Now | Notes |
|---|---|---|
| `projects` / Project | `deals` / Deal | Straight rename |
| `project_types` | `deal_types` | Straight rename |
| `project_participants` | `deal_participants` | Straight rename |
| `project_property` | `deal_property` | Straight rename |
| `milestones` / Milestone | `stages` / Stage | Rename, and the word Milestone is reassigned |
| `milestone_templates` | `stage_templates` | Straight rename |
| (did not exist) | `stages.is_milestone`, `stages.milestone_label` | New. See section 3. |
| Client Portal | Status Page | Reflects the v0.2 reduction to read-only |
| Portal User (role) | Status Viewer | |
| Nurture (UI) | Keep in Touch | Code name `nurture_schedules` is retained |
| Action / Action definition (UI) | Automation | Code names retained |
| Key Dates (UI) | Dates & Deadlines | Code name `key_dates` retained |

**Feature IDs in the PRD (F1.1, F4.8, and so on) do not change.** They are stable references and [[Screen Inventory]] points at them.

---

## 13. Open naming questions

1. **The product name.** Settled as a working name on 2026-08-22: **Goldieflow**, after Emily's Great Dane, with `goldieflow.com` secured. Still open is whether it is the *launch* name. Confirm before the marketing site goes up and before the sending subdomain is chosen, because sending reputation is painful to move once built. Two pieces of prior art to clear first: an operating scheduling SaaS called Goldie (beauty and wellness, so a different industry) and a live/pending `GOLDIE VAULT` SaaS trademark covering chain-of-title tracking. Neither blocks a working name; both warrant a real USPTO clearance search before launch.
2. **Workflow versus Template in the UI.** Both appear in the sidebar area and in a deal. Watch for confusion during the first usability pass with Heather. If it appears, "Playbook" for the template is the fallback.
3. ~~**Vendor as a status or a flag.**~~ **Settled in slice 1: a flag.** `team_memberships.is_vendor`, with its own segment on the People index — a stager can be a past client and a vendor at the same time, and one status column cannot say both. The vendor fields (specialty tags, typical cost, service area, rating, history) live on the membership too, because what one team paid a stager is not another team's business.
4. **Deal naming for buyer-side deals with no subject property yet.** Falls back to client surname, which produces "Bosart Purchase." Acceptable, but check with Heather once she has ten of them on one screen.

---

## Related notes

- [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document]]: what gets built
- [[Screen Inventory]]: which screens exist and what they cost
- [[Design references]]: what they should look like
- [[Design System]]: tokens, components, and page patterns
- [[Conversation with Emily and Heather]]: the source of most of this vocabulary
