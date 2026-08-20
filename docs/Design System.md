---
created: 2026-08-20
modified: 2026-08-20
project: Brawling Mahogany
type: reference
status: draft
version: 1.0
tags:
  - monroe-digital
  - design-system
  - shadcn
  - brawling-mahogany
---

# Design System

> [!info] What this document is for
> The visual and component contract for Brawling Mahogany. What we borrow, what we build, what everything is worth, and the rules that stop 91 screens from drifting apart.
>
> Companions: [[Information Architecture]] (what things are named), [[Screen Inventory]] (which screens exist), [[Design references]] (what to look at), [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document]] (what gets built).

> [!abstract] The governing principle
> **Borrow the wheel. Build only the axle nobody sells.**
>
> Roughly 76 of the 91 screens in [[Screen Inventory]] are assembly work if a component library is doing its job. The other 15 are where the product actually lives. Every hour spent hand-rolling a dropdown is an hour not spent on the stage timeline, which is the only thing here nobody else has built.

---

## 1. The stack

| Layer | Choice | Why |
|---|---|---|
| Component source | **shadcn-vue** | Not a dependency. A CLI that copies source into your repo, so components are yours to own and modify without fighting a maintainer's opinions. |
| Accessible primitives | **Reka UI** | What shadcn-vue is built on. Handles focus traps, keyboard navigation, ARIA, and portalling, which is the part that takes months to get right by hand. |
| Styling | **Tailwind CSS v4** | CSS-first config via `@theme`, which is how the token layer below is expressed. |
| Icons | **Lucide** | shadcn's default. One icon set, no mixing. |
| Forms | **vee-validate + zod** | What shadcn-vue's Form component expects. |
| Toasts | **Sonner** (via shadcn-vue) | |
| Tables | **TanStack Table** | shadcn-vue's Data Table is a recipe over it, not a component. |

### 1.1 What "not a dependency" means in practice

`npx shadcn-vue@latest add button` writes `components/ui/button/` into the repo. There is no package to upgrade and no version to be trapped by. The tradeoff is that fixes upstream do not arrive automatically, so a component adopted today is a component maintained here forever.

That is the right trade for this project. It also creates the one rule that matters most: **do not hand-edit `components/ui/`.** Extend through variants and wrappers instead, so re-running the CLI never silently destroys work. Section 12 covers governance.

### 1.2 Starting point

Laravel ships an official Vue starter kit with Inertia, Vue 3, Tailwind, and shadcn-vue already wired together. Start there rather than assembling it by hand. shadcn-vue also publishes a Laravel-specific installation guide if the starter kit is not a fit.

---

## 2. Color

### 2.1 Token architecture

Three layers, and nothing in a component ever skips past layer three.

```
Layer 1  Raw values          oklch(0.45 0.11 250)
Layer 2  Semantic tokens     --primary, --state-blocked
Layer 3  Tailwind utilities  bg-primary, text-state-blocked
```

**A component never contains a raw hex value or a Tailwind palette class.** No `bg-blue-500`, no `#3B5C8F`, ever. If a color is needed and no token expresses it, the answer is a new token, not a one-off. This single rule prevents most design drift, and it is the one worth being pedantic about in review.

### 2.2 Base tokens

shadcn's standard set, unmodified in structure. Values in oklch, per Tailwind v4 convention.

```css
:root {
  --background:            oklch(1 0 0);
  --foreground:            oklch(0.16 0.01 250);
  --card:                  oklch(1 0 0);
  --card-foreground:       oklch(0.16 0.01 250);
  --popover:               oklch(1 0 0);
  --popover-foreground:    oklch(0.16 0.01 250);

  --primary:               oklch(0.45 0.11 250);   /* deep slate blue */
  --primary-foreground:    oklch(0.98 0.005 250);

  --secondary:             oklch(0.96 0.005 250);
  --secondary-foreground:  oklch(0.28 0.02 250);
  --muted:                 oklch(0.96 0.005 250);
  --muted-foreground:      oklch(0.52 0.015 250);
  --accent:                oklch(0.95 0.015 250);
  --accent-foreground:     oklch(0.28 0.02 250);

  --destructive:           oklch(0.55 0.20 27);
  --destructive-foreground:oklch(0.98 0.01 27);

  --border:                oklch(0.91 0.005 250);
  --input:                 oklch(0.91 0.005 250);
  --ring:                  oklch(0.45 0.11 250);

  --radius:                0.5rem;
}
```

**Primary is a muted, serious blue.** It reads as trustworthy next to financial information, and critically it leaves amber, green, and red free to carry meaning without competing with the brand.

### 2.3 State tokens

This is the layer shadcn does not ship, and the layer that matters most here. Every state value in [[Information Architecture]] section 8 maps to exactly one token pair: a strong colour for text and icons, and a subtle background for badges.

```css
:root {
  --state-neutral:        oklch(0.52 0.015 250);
  --state-neutral-bg:     oklch(0.96 0.005 250);

  --state-info:           oklch(0.52 0.12 250);
  --state-info-bg:        oklch(0.95 0.03 250);

  --state-success:        oklch(0.50 0.13 150);
  --state-success-bg:     oklch(0.95 0.04 150);

  --state-warning:        oklch(0.52 0.12 75);
  --state-warning-bg:     oklch(0.96 0.05 85);

  --state-danger:         oklch(0.53 0.19 27);
  --state-danger-bg:      oklch(0.96 0.03 27);
}
```

### 2.4 State mapping

One table, and it is the single source of truth for every badge in the product.

| Entity | State | Token | Reads as |
|---|---|---|---|
| **Stage** | Upcoming | neutral | Not started |
| | In Progress | info | Happening now |
| | Blocked | warning | Needs attention, not broken |
| | Complete | success | Done |
| | Skipped | neutral | Not applicable |
| **Task** | Open | neutral | |
| | Completed | success | |
| | Overdue | danger | |
| **Gate** | Met | success | |
| | Not Met | neutral | Expected, not alarming |
| | Overridden | warning | Deliberate, auditable |
| **Deal** | Active | info | |
| | Closed | success | |
| | Past Client | neutral | |
| | Fell Through | danger | |
| | Cancelled | neutral | |
| **Message** | Scheduled | neutral | |
| | Needs Review | warning | |
| | Sent | success | |
| | Failed | danger | |
| **Extracted field** | Needs Review | warning | |
| | Confirmed | success | |
| | Edited | info | |
| | Rejected | neutral | |

> [!warning] Blocked is amber, not red
> A blocked stage usually means a checkbox is unticked, not that something has gone wrong. Red is reserved for things that are actually broken: a failed send, an overdue deadline, a deal that fell through. Spending red on the ordinary case means it stops working when something genuinely burns.

### 2.5 Dark mode

**Tokens built now, light mode shipped in v1.** Define every value in the `.dark` block and keep it correct as tokens are added. Do not test, screenshot, or support dark mode until after v1.

This costs nearly nothing today, because shadcn ships both blocks anyway and the discipline is simply not to break the dark half. It converts "enable dark mode" from an archaeology project into about a week of visual QA. The alternative, hardcoding light values now, means auditing 91 screens by hand later.

The `.dark` block inverts lightness and lifts chroma slightly on state colors so they survive on a dark ground:

```css
.dark {
  --state-success:    oklch(0.72 0.14 150);
  --state-success-bg: oklch(0.27 0.05 150);
  --state-warning:    oklch(0.78 0.13 80);
  --state-warning-bg: oklch(0.29 0.05 75);
  /* and so on for every token above */
}
```

### 2.6 Team branding

**Team branding applies to client-facing surfaces only:** the status page (S61 to S64) and transactional emails (S86 to S91). The internal app always wears the product's own palette.

Two reasons. Heather's screenshots and support conversations stay consistent across every tenant, and semantic state colors never have to survive next to an arbitrary customer-chosen accent.

Implementation: the client layout scopes a small set of overrides, and nothing else changes.

```css
.client-surface {
  --brand:            /* team's chosen accent */;
  --brand-foreground: /* computed for contrast */;
}
```

**A team accent is used for headings, the progress indicator, and links. It is never used for state.** A team whose brand colour is red does not get red "complete" badges.

> [!note] Validate contrast on the way in
> A team owner picking their brand colour in S72 can pick something illegible. Check contrast at save time and either warn or auto-adjust the foreground. Do not discover it when a client cannot read their own timeline.

---

## 3. Typography

### 3.1 Typeface

**Inter**, variable, self-hosted, with a system fallback.

```css
--font-sans: "Inter var", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
--font-mono: ui-monospace, "SF Mono", "Cascadia Mono", Menlo, monospace;
```

Self-host through Fontsource rather than linking Google Fonts. It removes a third-party request, avoids a privacy question in the terms, and keeps the client status page fast on a phone.

> [!tip] Tabular numerals are not optional here
> This product is dates and dollar amounts in columns. Apply `font-variant-numeric: tabular-nums` to every table cell, every date, and every currency value. Without it, digits have different widths and columns visibly wobble. It is one line of CSS and it is the difference between a table that looks engineered and one that looks improvised.

### 3.2 Scale

Two base sizes, because the audiences differ.

**Internal app base: 14px.** Heather is looking at 25 deals and wants density.
**Client status page base: 16px.** A homeowner on a phone, skewing older, under WCAG 2.1 AA per PRD section 9.

| Token | Size | Line height | Use |
|---|---|---|---|
| `text-xs` | 12px | 16px | Badges, table meta, timestamps, helper text |
| `text-sm` | 14px | 20px | **Internal app default.** Body, tables, forms, labels |
| `text-base` | 16px | 24px | **Client surface default.** Long-form internal text |
| `text-lg` | 18px | 28px | Card titles, section headings |
| `text-xl` | 20px | 28px | Page titles |
| `text-2xl` | 24px | 32px | Deal name, client page headings |
| `text-3xl` | 30px | 36px | Client page hero only |

### 3.3 Weight

| Weight | Use |
|---|---|
| 400 Regular | Body, table cells |
| 500 Medium | Labels, table headers, nav items, emphasis in dense contexts |
| 600 Semibold | Headings, page titles, primary buttons |
| 700 Bold | Client page hero only. Never in the internal app. |

In a dense interface, 500 does the work 700 does in a roomy one. Reaching for bold in a table is usually a sign that something else needs fixing.

---

## 4. Spacing and density

### 4.1 Scale

Tailwind's default 4px scale, unmodified. Use `1, 2, 3, 4, 6, 8, 12, 16`. Skipping around the scale is how spacing becomes arbitrary.

### 4.2 Density rules

**Compact tables, comfortable forms.** Two densities with a clear boundary between them.

| Context | Spec |
|---|---|
| Table row height | 36px, `px-3 py-2`, `text-sm` |
| Table header | 32px, `text-xs`, weight 500, uppercase off |
| List item (non-table) | 44px minimum |
| Form control height | 40px (`h-10`) |
| Inline filter control | 32px (`h-8`) |
| Form field vertical rhythm | 16px between fields, 32px between groups |
| Card padding | 24px desktop, 16px mobile |
| Page gutter | 24px desktop, 16px mobile |
| Section gap | 24px, 32px between major blocks |
| **Mobile touch target** | **44px minimum, always, no exceptions** |

At 36px rows, roughly 25 deals fit above the fold on a 1080p screen with the header and filter bar in place. That is Emily's bar from the 2026-08-20 session, and it is the reason tables are compact.

> [!warning] Density is for the desktop internal app only
> Everything on a phone is comfortable. Everything on the client status page is comfortable. Compact is a desktop-power-user affordance, not a house style.

---

## 5. Radius, borders, elevation, motion

### 5.1 Radius

`--radius: 0.5rem` (8px), giving shadcn's derived scale: `sm` 4px, `md` 6px, `lg` 8px, `xl` 12px.

Slightly tighter than shadcn's 10px default. Eight reads a little more serious, which suits software handling somebody's house sale.

Full rounding (`rounded-full`) is reserved for avatars and badges.

### 5.2 Borders and elevation

**Borders over shadows.** The internal app separates regions with 1px `--border` lines, not drop shadows. Shadows are noise at density, and 25 stacked cards with shadows look like a mess.

Shadows appear only where something genuinely floats above the page:

| Element | Elevation |
|---|---|
| Card, panel, table | Border only, no shadow |
| Dropdown, popover, tooltip, command palette | `shadow-md` |
| Dialog, sheet, drawer | `shadow-lg` plus a scrim |
| Mobile bottom nav | `shadow-lg` upward |
| Sticky table header | 1px bottom border, no shadow |

### 5.3 Motion

| Purpose | Duration | Easing |
|---|---|---|
| Hover, focus, small state change | 150ms | `ease-out` |
| Popover, dropdown, tooltip enter | 150ms | `ease-out` |
| Overlay exit | 100ms | `ease-in` |
| Dialog enter | 200ms | `ease-out` |
| Sheet, drawer | 300ms | `ease-out` |
| Accordion, collapsible | 200ms | `ease-out` |

Rules that matter more than the numbers:

- **Never animate data.** Table rows do not fade in, numbers do not count up. At 25 rows it reads as lag.
- **Exits are faster than entrances.** Something leaving should feel like it got out of the way.
- **Respect `prefers-reduced-motion`.** Reduce to opacity only, never remove feedback entirely.
- **The stage timeline (S16) is the one place a transition earns its keep.** Advancing a stage is the product's core moment and deserves to feel like something happened.

### 5.4 Icons

**Lucide only.** One set, no mixing, no exceptions for "this one icon".

| Context | Size | Stroke |
|---|---|---|
| Inline with text | 16px | 2 |
| Button icon | 16px | 2 |
| Nav item | 18px | 2 |
| Empty state | 24px | 1.5 |
| Standalone feature icon | 32px | 1.5 |

Icons never carry meaning alone. A status is a badge with a word in it, optionally with an icon. This is both an accessibility requirement and a hedge against the fact that nobody agrees what any icon means.

---

## 6. What shadcn gives us

Confirmed against the current shadcn-vue roster. Mapped to the screens in [[Screen Inventory]].

### 6.1 Layout and navigation

| Component | Used by |
|---|---|
| Sidebar | S06 app shell |
| Navigation Menu, Breadcrumb | S06, deal pages |
| Tabs | Deal sub-navigation, settings |
| Resizable | **S66 extraction review split pane** |
| Scroll Area | Long lists, sidebar overflow |
| Separator | Everywhere |
| Collapsible, Accordion | Stage detail, filter groups |
| Stepper | S14 create deal, S33 contact import |
| Sheet, Drawer | Mobile filters, mobile deal actions |

### 6.2 Data display

| Component | Used by |
|---|---|
| Table + TanStack Table | S13, S30, S35, S50, S69 |
| Pagination | All list screens |
| Card | Dashboard panels, deal overview |
| Badge | **Every state in section 2.4** |
| Avatar | People, participants, assignees |
| Item | List rows outside tables |
| Empty | Every empty state, product-wide |
| Skeleton, Spinner | Loading states |
| Progress | Upload, extraction |
| Chart | S85 system health, F9.6 reporting |
| Carousel, Aspect Ratio | S36, S38 property photos |

### 6.3 Input

| Component | Used by |
|---|---|
| Form (vee-validate + zod), Field, Label | Every form |
| Input, Textarea, Number Field, Tags Input | Forms |
| Select, Combobox, Command | Pickers, S07 global search |
| Checkbox, Radio Group, Switch, Toggle, Toggle Group | Forms, filters, view switches |
| Calendar, Range Calendar, Date Picker | S18, S58, filters |
| PIN Input | S02 two-factor |
| Button, Dropdown Menu | Everywhere |

### 6.4 Feedback and overlay

| Component | Used by |
|---|---|
| Dialog | Most modals |
| Alert Dialog | Destructive confirmations |
| Alert | Inline warnings, **S51 PII warning** |
| Sonner (toast) | Action feedback |
| Tooltip, Hover Card | Affordance hints, person previews |
| Popover | Filters, inline pickers |
| Kbd | S07 keyboard hints |

**That covers roughly 76 of 91 screens.** Everything below is the remainder.

---

## 7. What we have to build

Split into composites, which are shadcn parts arranged into a repeatable unit, and bespoke, which is genuinely new work.

### 7.1 Composites (`components/app/`)

Build once, use everywhere. Each of these appears on three or more screens, which is the bar for promotion.

| Component | Made from | Used by |
|---|---|---|
| `StatusBadge` | Badge + section 2.4 mapping | Every list and detail screen |
| `DealRow` | Table row + StatusBadge + Avatar | S10, S13 |
| `DealHeader` | Breadcrumb + Tabs + StatusBadge + Dropdown | Every deal tab |
| `PersonPicker` | Combobox + Avatar + create-inline | S25, S14, forms |
| `TaskItem` | Item + Checkbox + Avatar + due date | S11, S17 |
| `DateChip` | Badge + tabular numerals + urgency colouring | S18, S59, S10 |
| `PageHeader` | Title, count, primary action, filter slot | Every list page |
| `FilterBar` | Popover + Toggle Group + saved views | S13, S30, S35 |
| `EmptyState` | Empty + icon + copy + action | Every screen |
| `ConfirmDestructive` | Alert Dialog + typed confirmation | Deletes |
| `ActivityItem` | Item + icon + timestamp + actor | S12, deal timeline |
| `UploadZone` | Progress + Alert + **PII warning** | S51 |

### 7.2 Bespoke

The real design work. These are the L-effort screens from [[Screen Inventory]].

| What | Screen | Why it is not off the shelf |
|---|---|---|
| **Stage timeline** | S16 | The core interaction. Ordered stages, mixed states, gates, concurrent workflows, overrides. Nothing in any library is shaped like this. |
| **Advance dialog with gate checklist** | S23 | Must explain a refusal clearly enough to act on, with each unmet gate linking to the thing that clears it. |
| **Workflow and stage template editors** | S41, S42 | Reordering with in-flight deals to protect. Needs a sortable library. |
| **Automation editor** | S44 | Trigger, action type, recipient rule, and approval, all interdependent. |
| **Merge-field email editor** | S46 | Needs a rich text editor with a custom token node. Not a shadcn concern. |
| **Extraction review split pane** | S66 | Source PDF beside proposed dates, with confidence and per-field confirmation. Highest-risk screen in the product. |
| **Field mapping** | S33 | Contact import column mapping and duplicate resolution. Always harder than it looks. |
| **Permission matrix** | S75 | A grid of checkboxes that has to stay legible. |
| **Scheduling calendar** | S57 | shadcn's Calendar is a date picker, not a month grid with events. Needs a real calendar library. |
| **Photo gallery manager** | S38 | Drag-to-reorder, primary image, captions. |
| **Client status timeline** | S62 | Separate visual language entirely: mobile-first, team-branded, jargon-free, WCAG AA. |
| **Email templates** | S86 to S91 | HTML email cannot use any of this system. See section 11. |

### 7.3 Third-party additions

Beyond shadcn and its own dependencies. Keep this list short and justify every addition.

| Library | For | Notes |
|---|---|---|
| TanStack Table | Data tables | shadcn's Data Table is a recipe over it |
| VueUse | Composables | Breakpoints, storage, event listeners |
| date-fns | Formatting | Reka's calendar uses `@internationalized/date` separately |
| A sortable library | S38, S41, S42 | Pick one, use it everywhere |
| TipTap | S46 merge-field editor | Only if a simpler token-insert textarea proves insufficient |
| A PDF renderer | S52, S66 | pdf.js based |
| A calendar library | S57 | Evaluate against building the month grid by hand |

> [!tip] Try the simple version of S46 first
> A textarea with a merge-field insert button and a live preview may be entirely adequate, and it avoids adding a rich text editor along with its serialization, sanitization, and paste-handling problems. Reach for TipTap only after the simple version demonstrably fails.

---

## 8. Page patterns

Seven layouts cover every screen in the inventory. A new screen picks one rather than inventing an eighth.

| Pattern | Structure | Used by |
|---|---|---|
| **P1 List** | PageHeader, FilterBar, Table, Pagination, EmptyState | S13, S30, S35, S50, S69 |
| **P2 Detail** | Breadcrumb, DealHeader, Tabs, tab content | S15 to S22, S31, S36 |
| **P3 Form** | Field groups, sticky footer actions | S72 to S80 |
| **P4 Dashboard** | Stat row, then panels in a responsive grid | S10, S81 |
| **P5 Wizard** | Stepper, step content, back and next footer | S14, S33 |
| **P6 Split review** | Resizable two-pane: source on the left, proposals on the right | S66, S67 |
| **P7 Client** | Single centred column, `max-w-lg`, 16px base, team branding | S61 to S64 |

### 8.1 Required states

Every screen defines all five. This is the column that gets skipped in design and then invented at 11pm during implementation.

| State | Rule |
|---|---|
| **Loading** | Skeleton matching the real layout. Spinners only when duration is genuinely unknown. |
| **Empty** | Say what belongs here and offer the action that creates it. Never a bare "No results." |
| **Error** | What happened, then what to do. "Couldn't send. Check the sending address in Settings." |
| **Permission denied** | Prefer hiding the entry point entirely. If the URL is reachable, explain who can grant access. |
| **Overloaded** | What 25 deals, 500 people, or 50 tasks looks like. Design it, do not discover it. |

---

## 9. Forms

| Rule | Detail |
|---|---|
| Label position | Above the field, always. Never floating, never inline. |
| Label style | `text-sm`, weight 500 |
| Help text | Below the field, `text-xs`, `--muted-foreground` |
| Errors | Replace help text, `--destructive`, with an icon |
| Required | Mark required fields, not optional ones |
| Validation timing | On blur first, then on change once a field has errored |
| Field width | Match the expected content. A ZIP field is not 400px wide. |
| Grouping | Related fields in a group with a heading, 32px between groups |
| Actions | Bottom right, primary last. Sticky footer in modals and long forms. |
| Destructive confirm | Type the object's name for anything irreversible |
| Autosave | Only in the template editors. Everywhere else, explicit save. |

**One primary button per screen.** If two things look equally primary, neither is.

---

## 10. Accessibility

Baseline for the internal app, and a hard requirement on the client status page per PRD section 9.

| Requirement | Detail |
|---|---|
| Contrast | 4.5:1 body text, 3:1 large text and UI boundaries. Verify every state token against both its background and the card background. |
| Colour independence | Never colour alone. Every badge carries a word. |
| Focus | Visible focus ring on every interactive element. Never `outline: none` without a replacement. |
| Keyboard | Every action reachable. Reka handles most of it; custom composites in 7.2 do not get it free. |
| Touch targets | 44px minimum on mobile, without exception |
| Labels | Every input bound to a label. Placeholder is not a label. |
| Motion | Honour `prefers-reduced-motion` |
| Client page | **WCAG 2.1 AA, verified.** Older audience, unfamiliar interface, one chance to be understood. |

---

## 11. Email design system

A separate universe. None of the above applies, and pretending otherwise produces broken email.

| Constraint | Rule |
|---|---|
| Layout | Tables. No flexbox, no grid, no CSS variables. |
| Styles | Inline. A `<style>` block is a progressive enhancement at best. |
| Width | 600px maximum, single column |
| Fonts | Web-safe stack only. Inter will not load in most clients. |
| Colours | Literal hex, duplicated from the app palette by eye and recorded below |
| Images | Always with alt text. Assume they are blocked. |
| Buttons | Bulletproof table-cell buttons, never a styled `<a>` alone |
| Dark mode | `prefers-color-scheme` where supported, and it must degrade gracefully where not |
| Plain text | A real plain-text alternative for every message, not a stripped-tag afterthought |
| Testing | Litmus or Email on Acid before launch. Outlook will surprise you. |

### 11.1 Email palette

Approximations of the app tokens, needing confirmation with a converter and a real client test.

| Role | Hex | Matches |
|---|---|---|
| Primary | `#3D5A96` | `--primary` |
| Text | `#1A1F2B` | `--foreground` |
| Muted text | `#6B7280` | `--muted-foreground` |
| Border | `#E4E7EC` | `--border` |
| Background | `#FFFFFF` | `--background` |
| Panel | `#F7F8FA` | `--muted` |
| Success | `#2F7A55` | `--state-success` |
| Warning | `#8A6516` | `--state-warning` |
| Danger | `#B3261E` | `--state-danger` |

**Team branding overrides the primary and the logo only.** Everything else stays fixed, so no tenant can accidentally produce an unreadable email.

---

## 12. Organization and governance

### 12.1 Structure

```
resources/js/
├── components/
│   ├── ui/          shadcn output. Do not hand-edit.
│   ├── app/         Our composites (section 7.1)
│   └── forms/       Domain field wrappers
├── layouts/
│   ├── AppLayout.vue        Internal, sidebar
│   ├── ClientLayout.vue     Status page, branded
│   ├── AuthLayout.vue       Login and invitations
│   └── AdminLayout.vue      Super admin, visually distinct
├── pages/           Mirrors routes: Deals/Index.vue, Deals/Documents.vue
├── composables/
└── lib/             utils, formatters, the state token map
```

### 12.2 Rules

1. **Need a component? Check shadcn-vue first.** It is probably there.
2. **Not there? Can it compose from two or three shadcn parts?** Then it belongs in `components/app/`.
3. **Only then consider a third-party library,** and only if it is maintained and tree-shakeable.
4. **Never hand-edit `components/ui/`.** Extend through `cva` variants or a wrapper in `components/app/`. Re-running the CLI must stay safe.
5. **No raw colours in components.** Semantic tokens only. This is the rule that prevents most drift.
6. **A pattern used three times gets promoted** into `components/app/` with a name.
7. **New state? Add it to section 2.4 first,** then build the badge. The table is the source of truth, not the code.
8. **Both light and dark values, always.** Adding a token means adding it to both blocks, even though dark ships later.

### 12.3 Build order

1. Tokens and Tailwind theme
2. `AppLayout` and the sidebar, which is S06 and the highest-leverage screen in the inventory
3. `StatusBadge`, since it appears on nearly every screen
4. `PageHeader`, `FilterBar`, `EmptyState`, which unlock every P1 list page
5. One real list screen end to end (S13), to prove the pattern
6. Then the bespoke work, starting with S16

Review step 2 with Heather before proceeding. Seventy screens inherit its decisions about density, type scale, and mobile collapse.

---

## 13. Open questions

1. **Product name.** Blocks the logo, the favicon, the email header, and the sending subdomain, which is painful to change once reputation is established.
2. **Calendar library for S57.** Evaluate building the month grid by hand against adopting one, since most calendar libraries bring heavy styling opinions that will fight this system.
3. **Rich text for S46.** Try the simple token-insert textarea first.
4. **Does anything need charts?** Only S85 and the optional F9.6 reporting. If it stays that small, shadcn's Chart component may be more than is needed.
5. **Team accent contrast validation.** Warn the owner, or auto-adjust silently? Warning is more honest and generates support questions. Auto-adjusting is invisible and occasionally produces a colour they did not pick.
6. **Density preference as a user setting.** Deliberately out of scope for v1. Revisit if Heather asks.

---

## Related notes

- [[Information Architecture]]: naming, navigation, and the state vocabulary these tokens serve
- [[Screen Inventory]]: the 91 screens this system has to cover
- [[Design references]]: what to look at first
- [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document]]: what gets built and why

## Next actions

- [ ] Scaffold from the Laravel Vue starter kit and confirm shadcn-vue is wired 📅 2026-08-27
- [ ] Write the token block, both light and dark, and validate every state pair for contrast 📅 2026-08-27
- [ ] Build `AppLayout` plus the sidebar (S06), then review with Heather 📅 2026-08-31
- [ ] Build `StatusBadge` against the section 2.4 table 📅 2026-08-31
- [ ] Build S13 end to end at 25 rows and confirm the density spec holds 📅 2026-09-07
- [ ] Choose the sortable library, needed by S38, S41, and S42 📅 2026-09-07
- [ ] Decide the calendar approach for S57 📅 2026-09-14

## Sources

- [shadcn/vue documentation](https://www.shadcn-vue.com/docs/components)
- [shadcn/vue theming](https://www.shadcn-vue.com/docs/theming)
- [shadcn/vue Laravel installation](https://www.shadcn-vue.com/docs/installation/laravel)
- [shadcn/vue changelog](https://www.shadcn-vue.com/docs/changelog)
- [Reka UI](https://reka-ui.com/)
- [shadcn/ui Tailwind v4 guide](https://ui.shadcn.com/docs/tailwind-v4)
- [Laravel starter kits](https://laravel.com/docs/13.x/starter-kits)
