---
created: 2026-08-19
modified: 2026-08-19
project: Brawling Mahogany
type: reference
tags:
  - monroe-digital
  - design
  - ui-ux
  - brawling-mahogany
---

# Design references

Curated inspiration and reference sources for the [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document|Brawling Mahogany PRD]], organized by UI surface rather than by website, because the three surfaces have almost nothing in common.

> [!tip] How to use this note
> Do not browse for inspiration in the abstract. Pick one surface, open the sources listed under it, and capture only the specific screens named in the **What to capture** checklist. Save screenshots to a folder per surface. Vague browsing produces vague designs.

---

## The three surfaces

| Surface | Primary user | Device | Design character |
|---|---|---|---|
| **Internal workspace** | Assistant, then Emily | Desktop | Dense, fast, keyboard-friendly, information-rich |
| **Milestone timeline** | Both internal users | Desktop, some mobile | Status-legible at a glance, obvious about what is blocked |
| **Client portal** | Seller or buyer | Phone first | Sparse, calm, jargon-free, reassuring |

A design that serves all three equally well serves none of them. Design them separately and let them share only the brand.

---

## 1. Internal workspace

Emily's assistant needs twelve deals on one screen with the late ones obvious. The right references are project management and CRM tools, not real estate tools.

### Galleries

| Source | URL | Notes |
|---|---|---|
| **Mobbin** | https://mobbin.com | The big one. Hundreds of thousands of screens from real shipped apps, mobile and web, browsable by app and by flow. Paid, with limited free browsing. If you buy one subscription for this project, buy this. |
| **Refero** | https://refero.design | Tightly curated web app UI organized by pattern: tables, filters, settings, empty states. Smaller than Mobbin and higher signal per screen. |
| **SaaSFrame** | https://www.saasframe.io/categories/dashboard | Real SaaS product screens with a dedicated dashboard category. |
| **SaaSUI** | https://www.saasui.design/best-saas-dashboard-ui-inspiration | Real screens plus written comparisons of what works. |
| **Really Good UX** | https://reallygoodux.io | Pattern-level teardowns with commentary on *why* something works. Slower to browse, more useful per screen. |

### Products to sign up for and screenshot

| Product | Why |
|---|---|
| **Linear** | The gold standard for a dense work queue and keyboard-first interaction. Study the issue list density, the filter bar, and command-K. |
| **Height** | Similar territory, different opinions. Useful contrast. |
| **Pipedrive** | Pipeline stages map closely to milestones. Also a good example of how *not* to overload a stage card. |
| **Stripe dashboard** | The best example anywhere of surfacing "what do I need to do next" without shouting. |
| **Front** | Shared inbox for a small team, which is close to the assistant's mental model. |

### What to capture

- [ ] A dense list view showing 15+ records with status, owner, and date all legible
- [ ] The filter and saved-view control pattern
- [ ] An "assigned to me across everything" work queue
- [ ] Overdue and at-risk visual treatment (color, badge, or icon, and how loud it is)
- [ ] Empty state for a brand-new account with zero records
- [ ] Bulk-select and bulk-action affordance
- [ ] Command palette or quick-add pattern
- [ ] A settings and team-management section, for the F1.2 control panel
- [ ] A role and permission editor, for F2.3

---

## 2. Milestone timeline and gated advancement

This is the novel part of the UI and the hardest to get right. Deal-stage pipelines are the obvious analogy. The better one is **CI/CD**, because a build pipeline is structurally identical to the problem in [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document#4.4 Workflow Engine|PRD 4.4]]: ordered steps, gates that must pass, a clear visual for "blocked and here is exactly why," and a documented override.

### Products to study

| Product | What to steal |
|---|---|
| **GitHub Actions** run view | Step list with per-step pass, fail, skipped, and running states. Expand a failed step to see the reason inline. |
| **Vercel** deployment detail | Clean vertical stepper with timing per step. Excellent "why did this fail" affordance. |
| **CircleCI** | Approval gates in a pipeline, which is nearly exactly your override flow. |
| **Stripe** account setup checklist | Onboarding checklist with teeth. Your gate list is structurally a checklist that blocks something. |
| **Notion** getting-started checklist | Softer version of the same pattern, good for the client-facing variant. |
| **Linear** cycle view | Progress across a bounded time window with a clear notion of scope and slippage. |

### Consumer progress patterns worth borrowing

Crude, universally understood, and therefore valuable for the client-facing variant of the same timeline.

- Package and food delivery trackers (DoorDash, Domino's, any carrier)
- Multi-step checkout progress bars

### What to capture

- [ ] Vertical stepper with mixed states: complete, active, blocked, skipped, upcoming
- [ ] Blocked state that names the specific unmet condition and links to the fix
- [ ] An approval or override affordance, including how it demands a reason
- [ ] Visual marker for a step that was overridden or force-passed after the fact
- [ ] Planned versus actual date display on the same row
- [ ] Horizontal compact variant, for a project row inside a list
- [ ] Simplified client-facing variant of the same timeline
- [ ] How a long timeline (15+ steps) collapses without losing the current position

> [!warning] The specific hard problem
> Your milestones carry two sets of children: **tasks** (work someone owes) and **gates** (conditions on advancement). Those are different things and most reference UIs only have one of them. Watch specifically for how CI tools distinguish "this step is running" from "this step is waiting on approval," because that distinction is the closest analog.

---

## 3. Client portal

The seller uses this maybe four times, on a phone, while anxious about the largest transaction of their life. Completely different design problem, and the references are consumer.

### Closest analogs

| Source | Why it fits |
|---|---|
| **Better.com**, **Rocket Mortgage** progress trackers | The single closest analog in existence. A nervous consumer, a long multi-step transaction, real contingency deadlines, and a need for reassurance over data. Same emotional territory, same information problem. |
| **HoneyBook** (https://www.honeybook.com) | Client-facing portal for service businesses. Has already solved the per-team branding problem in [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document#4.7 Client Portal\|PRD F7.5]]. |
| **Copilot** (https://copilot.com) | Purpose-built client portal SaaS. Study the client-side information architecture. |
| **Bonsai**, **SuiteDash** | More of the same, different opinions on how much to show the client. |
| **Delivery tracking** (DoorDash, Domino's) | The pure "where is my thing right now" progress bar. Everyone on earth already understands it. |

### Galleries

| Source | URL | Notes |
|---|---|---|
| **Page Flows** | https://pageflows.com | Records full user flows as **video** rather than static screens. The right format for studying a first-time magic-link login, which is a flow rather than a screen. |
| **Mobbin** | https://mobbin.com | Mobile section, filtered by onboarding and authentication flows. |
| **Screenlane** | https://screenlane.com | Mobile screens, lighter weight. |

### What to capture

- [ ] Magic-link or passwordless login, start to finish, including the email itself
- [ ] Mobile progress timeline that fits above the fold on a phone
- [ ] How jargon gets translated for a layperson (compare internal labels to client labels)
- [ ] "Nothing is happening right now and that is fine" state, which is the hardest one to design and the most common
- [ ] Document list and download on mobile
- [ ] Contact-the-team affordance that does not invite a support ticket
- [ ] Per-tenant branding treatment: how much of the vendor shows through
- [ ] Deadline or key-date display that informs without alarming

> [!note] Accessibility is not optional here
> Per [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document#9. Non-Functional Requirements|PRD section 9]], the portal targets WCAG 2.1 AA. The client population skews older than the internal users. Capture examples with genuinely large touch targets and real contrast, not fashionable low-contrast grey on white.

---

## 4. Direct competitors

Their marketing sites are full of annotated product screenshots and demo videos. That is unpaid competitive research sitting in the open.

| Competitor | URL | Tier | Priority |
|---|---|---|---|
| **Trackxi** | https://trackxi.com | Small team and solo TC | **Highest.** Closest to what we are building, aimed at exactly our customer. |
| **Open To Close** | https://opentoclose.com | Small team and solo TC | High. Trackxi positions directly against it. |
| **Shaker** | https://shaker.io | Small team | Medium |
| **SkySlope** | https://skyslope.com/transaction-management/ | Brokerage | Medium. Mostly to see what we deliberately do not build. |
| **Dotloop** | https://www.dotloop.com | Brokerage | Medium |
| **Brivity** | https://www.brivity.com | Team and brokerage | Medium |
| **Lone Wolf** | https://www.lwolf.com | Brokerage | Low |
| **Follow Up Boss** | https://www.followupboss.com | Agent CRM | Low. Contact-centric, which is the model we are deliberately rejecting. |

### What to capture

- [ ] Every product screenshot on the marketing site, saved with the vendor name
- [ ] Full demo video walkthroughs, with the workflow builder timestamped
- [ ] Pricing page, including what is gated behind which tier
- [ ] Feature list, for the [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document#2.2 Non-Goals for v1|non-goals]] gut check
- [ ] Onboarding flow if a free trial exists

> [!tip] Request demos
> A 30-minute sales call gets a guided tour of a competitor's entire workflow builder, narrated by someone motivated to explain it well. That is worth more than any gallery. Be honest about who you are if asked.

---

## 5. Implementation shortcuts

Inspiration is cheap. Implementation eats the evenings. Given a solo developer on Laravel, Inertia, Vue 3, and Tailwind per [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document#8.1 Stack|PRD 8.1]]:

| Resource | URL | Why |
|---|---|---|
| **Tailwind Plus** (formerly Tailwind UI) | https://tailwindcss.com/plus | Production-quality application shells, tables, forms, and stacked lists. All Tailwind, all copy-paste. For a one-developer build on this exact stack, probably the highest-leverage purchase available. |
| **shadcn-vue** | https://www.shadcn-vue.com | Composable Vue 3 primitives with sane defaults. Own the code rather than the dependency. |
| **Preline** | https://preline.co | More prebuilt Tailwind blocks, fewer decisions to make. |
| **Flowbite** | https://flowbite.com | Same idea, different component coverage. |
| **Heroicons** / **Lucide** | https://heroicons.com, https://lucide.dev | Icon sets that pair cleanly with Tailwind. Pick one and never mix. |
| **Untitled UI** | https://www.untitledui.com | Large Figma system, if any mockup work happens in Figma first. |

---

## 6. What to avoid

> [!danger] Skip Dribbble and Behance for this project
> The work there is portfolio work, optimized to look striking in a thumbnail. It routinely ignores dense data, error states, empty states, and long text. Copying it produces an app that photographs beautifully and works badly. Emily's assistant needs twelve deals on one screen, not a hero shot.

Also treat with caution:

- **Template marketplaces** selling admin dashboards. Chart-heavy by default, and this product is almost entirely lists and state, not analytics.
- **Any reference showing three records** where the real screen shows fifty. Density is the whole problem in the internal workspace, and pretty references almost always cheat on it.
- **Trend articles.** Useful for a vocabulary of what things are called. Actively harmful as a source of decisions.

---

## Related notes

- [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document]]: the PRD these references support
- [[The basic idea]]: the originating brain dump
- [[Rough data model.canvas]]: first-pass data model
- [[Information Architecture]]: naming and structure
- [[Screen Inventory]]: what needs designing, with effort estimates
- [[Design System]]: tokens and components, built on shadcn-vue

## Next actions

- [ ] Decide on a Mobbin subscription before starting portal design 📅 2026-09-02
- [ ] Capture the Trackxi marketing site and demo video in full 📅 2026-08-26
- [ ] Request demos from Trackxi and Open To Close 📅 2026-09-02
- [ ] Screenshot GitHub Actions and Vercel step states for the timeline design 📅 2026-08-26
- [ ] Evaluate Tailwind Plus against shadcn-vue and commit to one 📅 2026-09-09
