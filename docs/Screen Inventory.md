---
created: 2026-08-20
modified: 2026-08-20
project: Brawling Mahogany
type: reference
status: draft
version: 1.0
tags:
  - monroe-digital
  - design
  - screens
  - brawling-mahogany
---

# Screen Inventory

> [!info] What this document is for
> Every screen the product needs, what it costs to design, and which slice it belongs to. It doubles as a route map, since on Inertia every screen is a page component behind a named route.
>
> Vocabulary comes from [[Information Architecture]]. Feature IDs point at [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document]]. Visual references are in [[Design references]].

> [!note] Terminology
> This document uses the [[Information Architecture]] vocabulary: **Deal** (not Project) and **Stage** (not Milestone). PRD v0.3 now matches. Feature IDs are stable across all versions.

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

## B. Application shell

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S06 | App shell and sidebar | global | Team | Collapsed, mobile bottom bar, permission-hidden sections, impersonation banner | F1.2 | 1 | **L** |
| S07 | Global search overlay | global | Team | Empty, no results, grouped results, recent | F9.3 | 2 | M |
| S08 | Notification panel | global | Team | Empty, unread, grouped, mark all read | F5.3 | 3 | M |
| S09 | Team switcher | global | Team | Single team (hidden), multiple, no access | F1.4 | 1 | S |

> [!note] S06 is the highest-leverage screen in the inventory
> Every other internal screen inherits it. Getting the density, the type scale, and the mobile collapse right here means the other 70 screens mostly assemble themselves. Getting it wrong means 70 screens inherit the mistake.

## C. Dashboard and work

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S10 | Team dashboard | `/dashboard` | Team | Empty (new team), 1-5 deals, **25 deals**, all blocked, nothing due | F9.1, G8 | 2 | **L** |
| S11 | My Work queue | `/work` | TC | Empty, overdue grouping, nothing assigned, 50+ tasks | F9.2 | 2 | M |
| S12 | Team activity feed | `/activity` | Team | Empty, filtered, loading more | F9.4 | 2 | S |

## D. Deals

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S13 | Deals index | `/deals` | Team | Empty, 25 rows, filtered, saved view, bulk select, closed deals | F9.1 | 2 | **L** |
| S14 | Create deal | `/deals/create` | Team | Multi-step: type, client, property, template. Validation, resume | F3.1 | 2 | M |
| S15 | Deal overview | `/deals/{deal}` | Team | Active, blocked, closed, no workflow attached, no property | F3.7 | 2 | **L** |
| S16 | Deal timeline | `/deals/{deal}/timeline` | Team | 5 stages, 20 stages, blocked, overridden, skipped, multiple concurrent workflows | F4.6-F4.8 | 2 | **L** |
| S17 | Deal tasks | `/deals/{deal}/tasks` | TC | Empty, grouped by stage, overdue, unassigned | F4.10 | 2 | M |
| S18 | Deal dates and deadlines | `/deals/{deal}/dates` | Team | Empty, derived dates, cascade preview, past due, extracted-pending | F8.2 | 4 | M |
| S19 | Deal people | `/deals/{deal}/people` | Team | Empty, roles grouped, missing required role | F3.3 | 2 | S |
| S20 | Deal properties | `/deals/{deal}/properties` | Agent | Subject only, candidates list, interest statuses, none yet | F3.4-F3.5 | 2 | M |
| S21 | Deal documents | `/deals/{deal}/documents` | TC | Empty, categorised, upload in progress, refused | F6.1-F6.3 | 3 | M |
| S22 | Deal offers | `/deals/{deal}/offers` | Agent | Empty, hidden by deal type, multiple, countered | F3.6 | 2 | M |
| S23 | Advance stage | modal | Team | All gates met, 1 unmet, several unmet, advisory only, last stage | F4.8 | 2 | **L** |
| S24 | Override gate | modal | Agent | Reason required, confirmation, follow-up task preview | F4.9 | 2 | M |
| S25 | Add participant | modal | Team | Search existing, create new, role select, duplicate warning | F3.3 | 2 | S |
| S26 | Log contact | modal | TC | Type select, quick save, attach to deal. **Two-click target** | F2.5 | 2 | S |
| S27 | Add and edit task | modal | TC | New, edit, assign, due date, required flag | F4.10 | 2 | S |
| S28 | Attach workflow | modal | Team | Template picker, pack filter, preview stages, already attached | F4.1 | 2 | M |
| S29 | Close deal | modal | Agent | Outcome select, transition to Keep in Touch, fell through | F3.8 | 6 | S |

## E. People

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S30 | People index | `/people` | Team | Segments (Clients, Vendors, Team, Leads), empty, 500+ rows, search | F2.4 | 1 | M |
| S31 | Person detail | `/people/{person}` | Team | Contact log, related deals, no login, past client, vendor fields | F2.1, F2.5 | 1 | M |
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
| S41 | Workflow template editor | `/templates/{template}` | Agent | Stage list, reorder, add, remove, in-use warning, versioning | F4.4 | 2 | **L** |
| S42 | Stage template editor | `/templates/{template}/stages/{stage}` | Agent | Tasks, gates, automations, milestone toggle, client label | F4.3 | 2 | **L** |
| S43 | Gate editor | modal | Agent | Seven gate types with distinct configs, blocking toggle | F4.8 | 2 | M |
| S44 | Automation editor | modal | Agent | Trigger, type, message template, approval toggle, manual prompt | F5.1-F5.2 | 3 | **L** |
| S45 | Email template list | `/templates/emails` | Agent | Empty, in use by N automations, unused | F5.5 | 3 | S |
| S46 | Email template editor | `/templates/emails/{template}` | Agent | Merge field picker, invalid field, live preview, recipient rule, test send | F5.5-F5.6 | 3 | **L** |

## H. Automation runtime

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S47 | Message approval queue | `/messages/pending` | Team | Empty, needs review, editing before send, bulk approve | F5.7 | 3 | M |
| S48 | Message preview and test send | modal | Team | Rendered with real merge data, missing field, send to self | F5.6 | 3 | M |
| S49 | Automation failure detail | `/messages/{message}` | Team | Bounced, complained, provider error, retry | F5.8 | 3 | S |

## I. Documents

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S50 | Documents index | `/documents` | Team | Empty, categorised, filtered by deal, storage used | F6.1 | 3 | M |
| S51 | Upload dialog | modal | TC | **Prominent PII warning**, category required, drag and drop, progress, multi-file | F6.6 | 3 | M |
| S52 | Document viewer | `/documents/{document}` | Team | PDF, image, unsupported type, download, visibility toggle | F6.4 | 3 | M |
| S53 | Upload refused | modal | TC | Detected financial instrument, explanation, what to do instead | F6.7 | 3 | S |

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
| S57 | Calendar | `/calendar` | Team | Month, week, agenda, empty, dense day, deadline versus event styling | F8.1 | 4 | **L** |
| S58 | Event detail and edit | modal | Team | New, edit, link to deal and stage, attendees, recurring | F8.1 | 4 | S |
| S59 | Dates and deadlines list | `/dates` | Team | Cross-deal, next 14 days, overdue, critical only | F8.2 | 4 | M |
| S60 | iCal feed settings | modal | Team | Generate, revoke, copy URL, per-deal versus personal | F8.3 | 4 | S |

## L. Client status page

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S61 | Magic link verifying | `/s/{token}` | Client | Verifying, success redirect, failure | F7.1 | 4 | S |
| S62 | Status timeline | `/s/{token}` | Client | **Mobile first.** Early stage, mid, complete, nothing happening, team branding, no documents | F7.2, F7.5 | 4 | **L** |
| S63 | Status documents | `/s/{token}/documents` | Client | Empty, list, download | F7.4 | 4 | S |
| S64 | Expired or invalid link | `/s/expired` | Client | Expired, already used, revoked, request a new one | F7.1 | 4 | S |

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
| S73 | Sending identity | `/settings/sending` | Agent | Unverified, DNS records to add, verifying, verified, failed | F5.9 | 3 | M |
| S74 | Members and invitations | `/settings/members` | Agent | Empty, pending invites, revoke, last owner warning | F1.3 | 1 | M |
| S75 | Roles and permissions | `/settings/roles` | Agent | System roles (locked), custom roles, permission matrix, in-use warning | F2.3 | 2 | **L** |
| S76 | Deal types and lookups | `/settings/deal-types` | Agent | Defaults, custom, in-use warning | F3.1 | 2 | S |
| S77 | My profile and security | `/settings/profile` | Team | Details, password, 2FA enrol, recovery codes, sessions | NFR | 1 | S |
| S78 | Notification preferences | `/settings/notifications` | Team | Per event type, channel, quiet hours | F12.4 | 3 | S |
| S79 | Data export | `/settings/export` | Agent | Request, preparing, ready, expired | NFR | 1 | S |
| S80 | Billing | `/settings/billing` | Agent | Plan, packs owned, seats, invoices, payment method | Slice 7 | 7 | M |

## P. Super admin

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S81 | Admin dashboard | `/admin` | Admin | Tenant count, health summary, recent errors | F1.5 | 1 | S |
| S82 | Teams list | `/admin/teams` | Admin | Search, suspended, usage | F1.5 | 1 | S |
| S83 | Team detail and provision | `/admin/teams/{team}` | Admin | Create, edit, suspend, usage detail | F1.5 | 1 | M |
| S84 | Start impersonation | modal | Admin | **Reason required**, duration, audit warning | F1.5 | 1 | S |
| S85 | System health and queues | `/admin/health` | Admin | Queue depth, failed jobs, AI spend against cap, SES reputation | NFR | 1 | M |

## Q. Transactional email templates

Real design work, and easy to forget in an inventory. These are what the client actually sees most of the time.

| ID | Screen | Route | User | Key states | PRD | Slice | Effort |
|---|---|---|---|---|---|---|---|
| S86 | Base branded email layout | email | Client | Per-team logo and colours, dark mode clients, plain text fallback | F5.5 | 3 | M |
| S87 | Milestone notification | email | Client | With and without MLS link, with and without status link, long address | F5.5 | 3 | M |
| S88 | Deadline reminder | email | Team | Single date, several dates, critical styling | F8.4 | 4 | S |
| S89 | Magic link | email | Client | Link, expiry note, "you did not request this" | F7.1 | 4 | S |
| S90 | Team invitation | email | Team | Inviter name, team name, expiry | F1.3 | 1 | S |
| S91 | Internal alert | email | Team | Automation failed, bounce, extraction failed | F5.8 | 3 | S |

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
| S13 | Deals index | Density at 25 rows without becoming a spreadsheet |
| S15 | Deal overview | Six kinds of information competing for one screen |
| S16 | Deal timeline | The novel interaction in the product |
| S23 | Advance stage | Must explain refusal clearly enough to act on |
| S33 | Contact import | Field mapping and duplicate resolution are always harder than they look |
| S41 | Workflow template editor | Reordering with in-flight deals to protect |
| S42 | Stage template editor | Three child types on one screen |
| S44 | Automation editor | Trigger, action, recipient rule, all interdependent |
| S46 | Email template editor | Merge fields, validation, live preview |
| S57 | Calendar | Events and deadlines are different things sharing a grid |
| S62 | Client status timeline | The only screen a stranger uses unaided |
| S66 | Review extracted dates | Highest legal risk in the product |
| S75 | Roles and permissions | Permission matrices are notoriously hard to make legible |

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

- [ ] Design S06 (app shell) and review it with Heather before anything else 📅 2026-08-31
- [ ] Prototype S16 (deal timeline), the one interaction with no clear precedent 📅 2026-09-07
- [ ] Buy or reject Tailwind Plus, since the estimate swings by ~80 days on it 📅 2026-08-27
- [ ] Ask Emily whether she needs printable deal summaries 📅 2026-08-27
- [ ] Apply the [[Information Architecture]] terminology pass to the PRD 📅 2026-08-27
