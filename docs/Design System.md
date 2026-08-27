---
created: 2026-08-20
modified: 2026-08-22
project: Goldieflow
type: reference
status: draft
version: 2.1
tags:
  - monroe-digital
  - design-system
  - shadcn
  - goldieflow
---

# Design System

> [!info] What this document is for
> The visual and component contract for Goldieflow. What we borrow, what we build, what everything is worth, and the rules that stop 91 screens from drifting apart.
>
> **This document is the implementation source of truth.** It is written so that someone who cannot open `designs/Basic Designs.pen` can still build the screens correctly. Every number here is measured from the built designs, not aspirational.
>
> Companions: [[Information Architecture]] (what things are named), [[Screen Inventory]] (which screens exist), [[Design references]] (what to look at), [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document]] (what gets built).

> [!abstract] The governing principle
> **Borrow the wheel. Build only the axle nobody sells.**
>
> Roughly 76 of the 91 screens in [[Screen Inventory]] are assembly work if a component library is doing its job. The other 15 are where the product actually lives. Every hour spent hand-rolling a dropdown is an hour not spent on the stage timeline, which is the only thing here nobody else has built.

> [!warning] What changed in v2.0
> v1.0 was written before anything was drawn. v2.0 is written after 17 screens were built in Pencil, and it replaces principles with measurements wherever the two differ.
>
> **New:** [[#7. Component contracts]] (exact anatomy and Tailwind for every component), [[#8. Application chrome]] (shell, headers, cards, tables, dialogs), [[#9. Page patterns and composition recipes]], [[#14. The design file]].
>
> **Two corrections to v1.0:**
> 1. **The "25 deals above the fold" claim in §4.2 was wrong.** See [[#4.3 The density claim, corrected]].
> 2. **The composite list in v1.0 §7.1 was a wish list.** Section 7 now marks what exists versus what is still to build, and adds eight components v1.0 did not anticipate.

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

That is the right trade for this project. It also creates the one rule that matters most: **do not hand-edit `components/ui/`.** Extend through variants and wrappers instead, so re-running the CLI never silently destroys work. Section 13 covers governance.

### 1.2 Starting point

Laravel ships an official Vue starter kit with Inertia, Vue 3, Tailwind, and shadcn-vue already wired together. Start there rather than assembling it by hand. shadcn-vue also publishes a Laravel-specific installation guide if the starter kit is not a fit.

### 1.3 Lucide icon names, verified

Lucide has renamed several icons. These are the names that actually resolve, and the ones the designs use:

| Want | Correct name | Not |
|---|---|---|
| Filter | `funnel` | ~~`filter`~~ |
| Help | `circle-question-mark` | ~~`help-circle`~~ |
| Overflow menu | `ellipsis`, `ellipsis-vertical` | ~~`more-horizontal`~~ |
| Success tick | `circle-check` | ~~`check-circle`~~ |
| Warning ring | `circle-alert` | ~~`alert-circle`~~ |
| Danger triangle | `triangle-alert` | ~~`alert-triangle`~~ |

### 1.4 Tailwind Plus, rejected

**Decided 2026-08-20 ([#16](https://github.com/imonroe/brawling-mahogany/issues/16)): not purchased.**

[[Screen Inventory]] put the swing at roughly 136 days for a full design pass
against 55 with a strong component library, which made this worth deciding
rather than defaulting. The decision went the other way for two reasons:

- Tailwind Plus ships **plain Tailwind markup, not Vue components**. Every
  page composition pasted from it would be rebuilt against the shadcn-vue
  primitives and the token layer before it could ship, so the saving is on
  layout ideas rather than on code.
- The 76-of-91 assembly estimate rests on shadcn-vue and Reka UI, which are
  already committed to in §1 and already cover the primitives those
  compositions are made of.

**The token rule holds regardless of source.** Any markup borrowed from
anywhere gets its colours replaced with tokens before it lands, and
`tests/js/tokenDiscipline.test.ts` fails the build if it does not.

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

shadcn's standard set, unmodified in structure. Values in oklch, per Tailwind v4 convention. **Use the oklch values in code.** The hex column exists only because the design file cannot store oklch, and is listed here so design and code can be checked against each other.

```css
:root {
  --background:            oklch(1 0 0);          /* #FFFFFF */
  --foreground:            oklch(0.16 0.01 250);  /* #0A0E11 */
  --card:                  oklch(1 0 0);          /* #FFFFFF */
  --card-foreground:       oklch(0.16 0.01 250);  /* #0A0E11 */
  --popover:               oklch(1 0 0);          /* #FFFFFF */
  --popover-foreground:    oklch(0.16 0.01 250);  /* #0A0E11 */

  --primary:               oklch(0.45 0.11 250);  /* #1A588F  deep slate blue */
  --primary-foreground:    oklch(0.98 0.005 250); /* #F6F9FC */

  --secondary:             oklch(0.96 0.005 250); /* #EFF2F5 */
  --secondary-foreground:  oklch(0.28 0.02 250);  /* #212A33 */
  --muted:                 oklch(0.96 0.005 250); /* #EFF2F5 */
  --muted-foreground:      oklch(0.52 0.015 250); /* #636A71 */
  --accent:                oklch(0.95 0.015 250); /* #E7F0F8 */
  --accent-foreground:     oklch(0.28 0.02 250);  /* #212A33 */

  --destructive:           oklch(0.55 0.20 27);   /* #CC2827 */
  --destructive-foreground:oklch(0.98 0.01 27);   /* #FFF6F5 */

  --border:                oklch(0.91 0.005 250); /* #DFE1E4 */
  --input:                 oklch(0.91 0.005 250); /* #DFE1E4 */
  --ring:                  oklch(0.45 0.11 250);  /* #1A588F */

  --radius:                0.5rem;
}
```

**Primary is a muted, serious blue.** It reads as trustworthy next to financial information, and critically it leaves amber, green, and red free to carry meaning without competing with the brand.

### 2.3 State tokens

This is the layer shadcn does not ship, and the layer that matters most here. Every state value in [[Information Architecture]] section 8 maps to exactly one token pair: a strong colour for text and icons, and a subtle background for badges.

```css
:root {
  --state-neutral:        oklch(0.52 0.015 250);  /* #636A71 */
  --state-neutral-bg:     oklch(0.96 0.005 250);  /* #EFF2F5 */

  --state-info:           oklch(0.52 0.12 250);   /* #286CAB */
  --state-info-bg:        oklch(0.95 0.03 250);   /* #E0F1FF */

  --state-success:        oklch(0.50 0.13 150);   /* #137738 */
  --state-success-bg:     oklch(0.95 0.04 150);   /* #DCF7E1 */

  --state-warning:        oklch(0.52 0.12 75);    /* #905D00 */
  --state-warning-bg:     oklch(0.96 0.05 85);    /* #FFF0CC */

  --state-danger:         oklch(0.53 0.19 27);    /* #C22826 */
  --state-danger-bg:      oklch(0.96 0.03 27);    /* #FFEBE7 */
}
```

Expose them to Tailwind so `text-state-warning` and `bg-state-warning-bg` exist as utilities:

```css
@theme inline {
  --color-state-neutral:    var(--state-neutral);
  --color-state-neutral-bg: var(--state-neutral-bg);
  --color-state-info:       var(--state-info);
  --color-state-info-bg:    var(--state-info-bg);
  --color-state-success:    var(--state-success);
  --color-state-success-bg: var(--state-success-bg);
  --color-state-warning:    var(--state-warning);
  --color-state-warning-bg: var(--state-warning-bg);
  --color-state-danger:     var(--state-danger);
  --color-state-danger-bg:  var(--state-danger-bg);
}
```

### 2.4 State mapping

One table, and it is the single source of truth for every badge in the product. The **Tone** column is the value passed to `<StatusBadge :tone="…">`.

| Entity | State (UI label) | Tone | Reads as |
|---|---|---|---|
| **Stage** | Upcoming | `neutral` | Not started |
| | In Progress | `info` | Happening now |
| | Blocked | `warning` | Needs attention, not broken |
| | Complete | `success` | Done |
| | Skipped | `neutral` | Not applicable |
| **Task** | Open | `neutral` | |
| | Completed | `success` | |
| | Overdue | `danger` | |
| **Gate** | Met | `success` | |
| | Not Met | `neutral` | Expected, not alarming |
| | Overridden | `warning` | Deliberate, auditable |
| **Deal** | Active | `info` | |
| | Closed | `success` | |
| | Past Client | `neutral` | |
| | Fell Through | `danger` | |
| | Cancelled | `neutral` | |
| **Workflow** | Not Started | `neutral` | |
| | Active | `info` | |
| | On Hold | `warning` | Paused deliberately |
| | Completed | `success` | |
| | Cancelled | `neutral` | |
| **Person** | Lead | `info` | Live, not yet a client |
| | Client | `success` | |
| | Past Client | `neutral` | |
| | Archived | `neutral` | |
| **Message** | Scheduled | `neutral` | |
| | Needs Review | `warning` | |
| | Sent | `success` | |
| | Failed | `danger` | |
| | Cancelled | `neutral` | Superseded before it sent |
| **Extracted field** | Needs Review | `warning` | |
| | Confirmed | `success` | |
| | Edited | `info` | |
| | Rejected | `neutral` | |
| **Document** | Refused by scan | `danger` | |
| **Property** | Pre-listing | `neutral` | Not on the market yet |
| | For Sale | `info` | On the market |
| | Under Contract | `info` | On the market, spoken for |
| | Sold | `success` | |
| | Off Market | `neutral` | Withdrawn, not failed |
| | Rented | `success` | |
| | Other | `neutral` | |
| **Property interest** | Interested | `info` | The buyer wants a look |
| | Shortlisted | `success` | Top of their list |
| | Passed | `neutral` | Ruled out, which is a normal outcome |
| | Other | `neutral` | |

> [!note] Property status spends no amber and no red
> Every other row here describes something that can go wrong. A property's status describes a market position, and none of them is a problem: a house that is Off Market was withdrawn, not failed. Under Contract shares `info` with For Sale on purpose — the label already distinguishes them, and spending amber on an ordinary state is how amber stops meaning anything.

> [!warning] Blocked is amber, not red
> A blocked stage usually means a checkbox is unticked, not that something has gone wrong. Red is reserved for things that are actually broken: a failed send, an overdue deadline, a refused upload, a deal that fell through. Spending red on the ordinary case means it stops working when something genuinely burns.

### 2.5 Confidence is not a state

AI extraction (S66) shows a **confidence** level alongside a **review state**. They are different vocabularies and must not share a visual treatment, or a reader will think "Low confidence" is a status.

- **Review state** → a `StatusBadge` (pill, background fill). Needs Review / Confirmed / Edited / Rejected.
- **Confidence** → an icon plus text, no pill: `signal-high` in `text-state-success`, `signal-low` in `text-state-danger`.

### 2.6 Dark mode

**Tokens built now, light mode shipped in v1.** Define every value in the `.dark` block and keep it correct as tokens are added. Do not test, screenshot, or support dark mode until after v1.

The design file is **light-mode only** by deliberate choice, so there are no dark values to copy from it. Author them in CSS from the rule below and leave them untested until v1 ships.

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

**`--logo-plate`, the one token that exists because of dark mode.** Everything
else here inverts. The product mark cannot: it is a fixed two-tone PNG, so
unlike an SVG drawn in `currentColor` it has no way to answer the theme. Its
darker tone is near-black, which on the dark ground leaves half the mark
invisible — the shape reads as a fragment rather than a whole.

```css
:root { --logo-plate: var(--background);        /* the page is already the plate */ }
.dark { --logo-plate: oklch(0.97 0.005 250);    /* so the darkest tone keeps its contrast */ }
```

`AppLogoIcon` applies it as `dark:bg-logo-plate` — dark only, because in light
mode the mark already sits on a light page and a plate would be a white square
on white. The inset is left to each call site: the mark appears at 200px and at
32px, and one padding value cannot serve both.

The general rule this illustrates: **a raster asset cannot participate in the
token layer.** Where a mark has to work in both themes, the alternatives are a
plate like this one, or a second asset authored for dark.

### 2.7 Team branding

**Team branding applies to client-facing surfaces only:** the status page (S61 to S64) and transactional emails (S86 to S91). The internal app always wears the product's own palette.

Two reasons. Heather's screenshots and support conversations stay consistent across every tenant, and semantic state colors never have to survive next to an arbitrary customer-chosen accent.

Implementation: the client layout scopes a small set of overrides, and nothing else changes.

```css
.client-surface {
  --brand:            /* team's chosen accent */;
  --brand-foreground: /* computed for contrast */;
}
```

The design file carries a single `brand-client` token set to `#8A5A2B` purely as a stand-in, so the client screens demonstrate a brand that is visibly *not* the product blue. It is not a product value and must not be copied into code as a default.

**A team accent is used for headings, the progress indicator, markers, and links. It is never used for state.** A team whose brand colour is red does not get red "complete" badges.

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
> This product is dates and dollar amounts in columns. Apply `font-variant-numeric: tabular-nums` to every table cell, every date, and every currency value. Without it, digits have different widths and columns visibly wobble.
>
> Do it once, globally, rather than per component:
> ```css
> table, .tabular, [data-slot="table"] { font-variant-numeric: tabular-nums; }
> ```
> Everything in the designs that shows a date, an amount, a count, or a deadline assumes this is on.

### 3.2 Scale

Two base sizes, because the audiences differ.

**Internal app base: 14px.** Heather is looking at 25 deals and wants density.
**Client status page base: 16px.** A homeowner on a phone, skewing older, under WCAG 2.1 AA per PRD section 9.

| Token | Size | Line height | Use |
|---|---|---|---|
| `text-xs` | 12px | 16px | Badges, table meta, timestamps, helper text, column headers |
| `text-sm` | 14px | 20px | **Internal app default.** Body, forms, labels, nav, tabs |
| `text-base` | 16px | 24px | **Client surface default.** Long-form internal text |
| `text-lg` | 18px | 28px | Card titles, dialog titles |
| `text-xl` | 20px | 28px | Page titles |
| `text-2xl` | 24px | 32px | Deal name, client page headings |
| `text-3xl` | 30px | 36px | Client page hero only |

### 3.3 The 13px exception

Table and list rows use **13px**, not 14px. This is deliberate and is used throughout the built designs: `DealRow` cells, card list rows, participant cards, document rows.

Fourteen is right for controls and prose. In a 36px row with six columns it is a fraction too loose, and 13 buys back roughly one extra visible row per screenful without hurting legibility. Add it as a token rather than reaching for an arbitrary value:

```css
@theme { --text-13: 0.8125rem; --text-13--line-height: 1rem; }
```

Use `text-13` for row content. Do not use it for form controls, buttons, or anything a user types into.

### 3.4 Weight

| Weight | Use |
|---|---|
| 400 Regular | Body, table cells, secondary row text |
| 500 Medium | Labels, table headers, nav items, tabs, badges, emphasis in dense contexts |
| 600 Semibold | Headings, page titles, primary buttons, row primary column, card titles |
| 700 Bold | Client page hero and brand mark only. Never in the internal app. |

In a dense interface, 500 does the work 700 does in a roomy one. Reaching for bold in a table is usually a sign that something else needs fixing.

---

## 4. Spacing and density

### 4.1 Scale

Tailwind's default 4px scale. Use `1, 2, 3, 4, 6, 8, 12, 16`. Skipping around the scale is how spacing becomes arbitrary.

Four odd values earn their place and appear repeatedly in the designs. Treat these as sanctioned, and anything else as a mistake:

| Value | Tailwind | Where |
|---|---|---|
| 9px | `gap-[9px]` | Avatar-to-text in sidebar, person rows, activity rows |
| 13px | `py-[13px]` | Card header vertical padding |
| 14px | `px-3.5` | Button horizontal padding, compact card padding |
| 22px | `gap-[22px]` | Deal header tab spacing |

### 4.2 Measured control sizes

Every one of these is used in the built designs. Match them exactly.

| Context | Height | Padding | Type |
|---|---|---|---|
| **Table row** | 36px (`h-9`) | `px-4` | `text-13` |
| Table row, two-line | 44px (`h-11`) | `px-4` | 13 / 11 |
| Table column header | 32px (`h-8`) | `px-4`, `bg-muted` | `text-xs` / 500 |
| **List row (non-table)** | 44px (`h-11`) | `px-3`–`px-4` | 14 / 12 |
| List row, rich (icon + 2 lines + badge) | 52px (`h-13`) | `px-4` | 13 / 12 |
| **Primary / secondary button** | 36px (`h-9`) | `px-3.5` | 14 / 600 or 500 |
| Ghost button | 32px (`h-8`) | `px-2.5` | 14 / 500 |
| Compact button (in card headers, dialogs) | 28–30px | `px-2.5` | 12 / 600 |
| **Form control** | 40px (`h-10`) | `px-3` | 14 |
| **Inline filter control / chip** | 32px (`h-8`) | `px-2.5`–`px-3` | 12 |
| Icon button | 32×32 (`size-8`) | — | 18px icon |
| Nav item | 32px (`h-8`) | `px-2.5 py-[7px]` | 14 / 500 |
| Tab | 38px (`h-[38px]`) | `px-[3px]` | 14 |
| **Mobile touch target** | **44px minimum, always** | — | — |

| Region | Spec |
|---|---|
| Page gutter | 24px desktop (`p-6`), 16px mobile |
| Card padding | 24px for prose cards; **card headers use `px-4 py-[13px]`, card rows `px-4`** |
| Form field vertical rhythm | 16px between fields, 32px between groups |
| Section gap | 16px within a region, 20–24px between major blocks |
| Two-column page grid | 20–24px gap; right rail 330–352px fixed, left column `flex-1` |

### 4.3 The density claim, corrected

v1.0 asserted that "roughly 25 deals fit above the fold on a 1080p screen with the header and filter bar in place." **That is not true, and the arithmetic is worth writing down so nobody re-derives it.**

On a 1080px-tall display, a maximised browser gives roughly 1024px of viewport. Subtract:

| | |
|---|---|
| App top bar | 56 |
| Page gutter, top and bottom | 48 |
| Page header (title + subtitle + actions) | 44 |
| Gap | 16 |
| Filter bar | 32 |
| Gap | 16 |
| Table column header | 32 |
| Table footer (count + pagination) | 44 |
| **Remaining for rows** | **736 → 20 rows at 36px** |

**Twenty rows, not twenty-five.** To fit 25 you need about 1200px of viewport, which means a 1440px-tall display or a browser in full-screen.

Two consequences:
1. **Design to 20 visible rows** as the realistic desktop case. Emily's "25 concurrent deals" requirement is about the dashboard and the data model coping with 25, not about all 25 being simultaneously visible.
2. The deals index (S13) is drawn on a **1440×1200** frame precisely so all 25 rows can be seen and judged. That frame is a design convenience, not a viewport target.

#### Confirmed by building it (#78)

S13 is built, and the arithmetic above holds — **twenty rows** is the honest
desktop number. Every line of the budget is a real measurement rather than an
estimate now:

| | | |
|---|---|---|
| App top bar | 56 | `AppLayout`'s `h-14` |
| Page gutter | 48 | `p-4 md:p-6` — the desktop branch, twice |
| Page header | 44 | `PageHeader` with a subtitle |
| Gap | 16 | the page's `gap-4` |
| Filter bar | 32 | search, segments and the deal-type chip, all `h-8` |
| Gap | 16 | `gap-4` again |
| Table column header | 32 | `Table`'s `h-8 bg-muted` |
| Table footer | 44 | `Table`'s `h-11` |
| **Remaining** | **736** | **20 rows at `h-9`** |

**The one thing that would break it is the filter bar.** Properties and People
both hand-roll a `flex-wrap` row of inputs rather than using the `h-8`
components, and that row wraps to two lines the moment a third control is
added — which costs eight rows, not one. S13 uses `AppInput` at `h-8`,
`SegmentedControl` and `AppSelect` at the filter size for exactly that reason.
Adding a fourth filter to this screen means checking this number again.

So: **design to 20**, and keep drawing S13 at 1440×1200 when all 25 need to be
judged at once. Emily's twenty-five is a claim about the data model and the
dashboard, not about one screenful.

> [!warning] Density is for the desktop internal app only
> Everything on a phone is comfortable. Everything on the client status page is comfortable. Compact is a desktop-power-user affordance, not a house style.

---

## 5. Radius, borders, elevation, motion

### 5.1 Radius

`--radius: 0.5rem` (8px), giving shadcn's derived scale:

| Token | Value | Use |
|---|---|---|
| `rounded-sm` | 4px | Date chips, small inline tags, kbd, inline alert strips |
| `rounded-md` | 6px | Buttons, inputs, nav items, chips, filter controls, markers |
| `rounded-lg` | 8px | Cards, tables, dialogs, panels |
| `rounded-xl` | 12px | Not currently used |
| `rounded-full` | — | Avatars, status badges, count pills, timeline markers |

Slightly tighter than shadcn's 10px default. Eight reads a little more serious, which suits software handling somebody's house sale.

### 5.2 Borders and elevation

**Borders over shadows.** The internal app separates regions with 1px `--border` lines, not drop shadows. Shadows are noise at density, and 25 stacked cards with shadows look like a mess.

| Element | Elevation |
|---|---|
| Card, panel, table | `border` only, no shadow |
| Active/selected card (current stage, selected template) | `border` in `--primary` at **1.5px**, plus an `--accent` header |
| Dropdown, popover, tooltip, command palette | `shadow-md` |
| Dialog, sheet, drawer | `shadow-lg` plus a scrim |
| Mobile bottom nav | `shadow-lg` upward |
| Sticky table header | 1px bottom border, no shadow |

Two shadow recipes are used in the designs:

```css
/* Dialog */        box-shadow: 0 10px 30px -6px oklch(0.16 0.01 250 / 0.25);
/* Document page */ box-shadow: 0 2px 10px oklch(0.16 0.01 250 / 0.09);
/* Scrim */         background: oklch(0.16 0.01 250 / 0.45);
```

**Row separation is a bottom border on the row, not a gap.** Every list and table row carries `border-b`, and the last row in a card drops it (the card's own `overflow-hidden` and border close the box).

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
| Inline with `text-13` row content | 12–13px | 2 |
| Inline with text, buttons | 14–16px | 2 |
| Nav item, icon button | 18px | 2 |
| Section heading, alert | 16–17px | 2 |
| Empty state | 24px | 1.5 |
| Standalone feature icon | 32px | 1.5 |

Icons never carry meaning alone. A status is a badge with a word in it, optionally with an icon. This is both an accessibility requirement and a hedge against the fact that nobody agrees what any icon means.

**Icon-in-a-circle** is a recurring motif for activity and category markers: a `size-6` (24px) `rounded-full` frame filled `bg-muted`, holding a 14px icon tinted with the relevant state token. For row-level category markers the frame is `size-7` (28px) `rounded-md` filled with the state background.

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

## 7. Component contracts

Everything in this section is measured from the built designs. Tailwind classes assume the tokens in section 2 are registered with `@theme`.

### 7.1 Status

| Component | Location | Status |
|---|---|---|
| `StatusBadge` | `components/app/` | **Built in design** |
| `DealRow` | `components/app/` | **Built in design** |
| `TaskItem` | `components/app/` | **Built in code** (S16, S17), with an `actions` slot for the row controls a screen hangs on it |
| `ActivityItem` | `components/app/` | **Built in code** (S12, S31), with a default slot for a row's supporting lines |
| `DateChip` | `components/app/` | **Built in design** |
| `NavItem` | `layouts/` | **Built in design** |
| `Tab` | `components/app/` | **Built in design** |
| `AppShell`, `Sidebar`, `TopBar` | `layouts/AppLayout.vue` | **Built in code**, with the mobile tab bar and the impersonation banner |
| `DealHeader` | `components/app/` | **Built in design** |
| `IconButton` | `components/app/` | **Built in design** |
| `PageHeader` | `components/app/` | **Built** |
| `FilterBar` | `components/app/` | **Built**, with `FilterChip` and `SegmentedControl` |
| `PersonPicker` | `components/app/` | Not yet designed |
| `EmptyState` | `components/app/` | **Built.** Two variants: nothing exists yet, and nothing matches this filter |
| `ConfirmDestructive` | `components/app/` | Not yet designed |
| `UploadZone` | `components/app/` | **Built** (S51). A button that opens the file picker, with drop handling layered on top — built the other way, the keyboard path is a thing somebody remembers to add. Refuses nothing client-side: the bytes decide, server-side, and two copies of an allowlist disagree the moment one is edited |

### 7.2 Atoms

#### StatusBadge

The most-used component in the product. Dot plus word, always both, never the dot alone.

```
[•] Label          rounded-full  px-2 py-[3px]  gap-1.5
                   dot: size-1.5 rounded-full
                   label: text-xs font-medium
                   height: 21px
```

```html
<span class="inline-flex items-center gap-1.5 rounded-full px-2 py-[3px]
             text-xs font-medium bg-state-warning-bg text-state-warning">
  <span class="size-1.5 rounded-full bg-state-warning"></span>
  Blocked
</span>
```

Tone drives three properties together: container `bg-state-{tone}-bg`, dot `bg-state-{tone}`, label `text-state-{tone}`. Never mix tones across the three.

**Dotless variant.** When the badge is a *count* or a *terminal status* rather than a live state, drop the dot: header counts (`4`), "Met", "Confirmed", table status columns. Keep the pill and the colour.

#### Buttons

| Variant | Height | Padding | Fill | Border | Label |
|---|---|---|---|---|---|
| Primary | 36 (`h-9`) | `px-3.5` | `bg-primary` | — | 14 / **600** / `text-primary-foreground` |
| Secondary | 36 (`h-9`) | `px-3.5` | `bg-background` | `border` | 14 / 500 / `text-secondary-foreground` |
| Ghost | 32 (`h-8`) | `px-2.5` | none | — | 14 / 500 / `text-muted-foreground` |
| Compact | 28–30 | `px-2.5` | either | either | 12 / 600 |

All share `rounded-md gap-1.5` and a 16px leading icon. Icon colour follows the label colour.

**Destructive and warning actions** reuse the primary shape with the fill swapped: `bg-destructive` for delete, `bg-state-warning` for Override. A warning-filled primary is how "Override and Advance" reads as consequential without reading as broken.

**Disabled primary** is `bg-muted` with `text-muted-foreground` — see the blocked Advance button in S23.

#### IconButton

`size-8 rounded-md`, 18px icon in `text-muted-foreground`, centred. Optional unread dot: `size-2 rounded-full bg-destructive ring-2 ring-background`, absolutely positioned at top-right (offset 19,5 within the 32px box).

#### Avatar

`rounded-full bg-accent`, initials centred in `text-primary` at 600 weight. Sizes actually used:

| Size | Initials | Where |
|---|---|---|
| 20 | 9px | Inline in dense stage task rows |
| 24 (default) | 10px | Table rows, task items |
| 26 | 10px | Card list rows |
| 30 | 11px | Sidebar user block |
| 32 | 12px | Participant cards |
| 46 | 17px | Client page agent block (brand-filled) |

#### DateChip

`rounded-sm px-[7px] py-[3px] gap-[5px]`, 12px `calendar` icon, `text-xs font-medium`. Tone follows urgency, not stage state: `neutral` normally, `danger` when overdue or due today. All three properties (bg, icon, text) move together.

#### Checkbox

`size-4 rounded-sm border bg-background`. Checked: `bg-primary border-primary` with a 12px `check` in `text-primary-foreground`.

#### Tab

Vertical frame, `h-[38px]`, containing a horizontal inner row (`px-[3px] gap-1.5`) with a 14px label and an optional count pill (`rounded-full px-1.5 py-px text-[11px] font-medium bg-muted`).

**The active indicator is a 2px bottom border on the tab itself**, not a separate element: `border-b-2 border-primary`, label `text-foreground font-semibold`. Inactive: no border, label `text-muted-foreground font-medium`.

Tabs sit in a row with `gap-[22px]` and the container carries the full-width bottom border.

### 7.3 Composites

#### DealRow

The workhorse of every list screen. A fixed-height horizontal row of fixed-width cell frames.

```
h-9  px-4  border-b   ·   each cell is its own flex container, items-center, gap-1.5
┌────┬──────────────┬────────┬────────┬────────┬────────┬──────┐
│ 30 │ flex-1       │  170   │  150   │  140   │  115   │  40  │
│ ☐  │ Primary      │ Meta 1 │ Meta 2 │ State  │ Date   │ Owner│
└────┴──────────────┴────────┴────────┴────────┴────────┴──────┘
```

| Cell | Width | Content |
|---|---|---|
| Select | 30 | Checkbox, centred. Hidden unless the screen supports bulk select. |
| Primary | `flex-1` | `text-13 font-medium text-foreground` — the deal name / address |
| Meta 1 | 170 | `text-13 text-muted-foreground` — client |
| Meta 2 | 150 | `text-13 text-muted-foreground` — current stage |
| State | 140 | `StatusBadge` |
| Date | 115 | `DateChip` |
| Owner | 40 | `Avatar` 24 |

**The column header must use identical widths** (`h-8 px-4 bg-muted`, labels `text-xs font-medium text-muted-foreground`). Misaligning header and body by even 2px is visible.

Cells are generic on purpose. On the dashboard the row hides Meta 1 and narrows Meta 2; on S13 all seven show. Do not rename the cells after their S13 content.

#### TaskItem

```
h-11  px-3  gap-2.5  border-b
[☐] [ Title 14 / Meta 12 ]  [DateChip]  [Avatar 24]
```

Title `text-sm text-foreground`; completed tasks get `line-through text-muted-foreground` and a filled checkbox. Meta is `text-xs text-muted-foreground` and carries the deal context (`123 Main St · Under Contract`) on cross-deal screens, or the completion attribution (`Completed by Heather`) within a deal.

The assignee avatar is hidden on My Work, where it is always the current user.

> [!note] As built (#71)
> Two additions, both made when S17 gave the row somewhere to be worked from:
>
> - An **`actions` slot** after the avatar. §7.3 fixes four cells and Edit and
>   Delete are not a fifth — they are what the screen that owns the row wants
>   to hang on it, so the stage rail passes nothing and keeps the anatomy
>   exactly. The same argument as `ActivityItem`'s default slot.
> - A **`readonly`** flag, which is what a screen sets when the *reader* may
>   not complete the task rather than when the endpoint does not exist. The
>   checkbox is disabled rather than dropped, because its state is the
>   information: PRD §4.2 F2.2's Read Only role has to see what is done.
>
> And one departure, forced by arithmetic. The row is a single non-wrapping
> line, so on a 360px phone the checkbox, the date chip, the avatar and two
> 44px action buttons leave roughly 100px for the title — which truncates a
> real checklist item to a dozen characters. **Below `sm` the assignee avatar
> and the Delete action are hidden**, which buys the title back about 80px and
> keeps every remaining control at §11's 44px floor. §7.3 already hides this
> avatar on My Work; a fuller mobile treatment of the row belongs with S11,
> which is the phone-first screen and will force the question properly.

#### ActivityItem

```
py-2.5  gap-2.5  items-start
[icon circle 24] [ Text 14 (wraps) / Time 12 ]
```

The icon circle is `size-6 rounded-full bg-muted` holding a 14px icon tinted by event type: completion `state-success`, message sent `state-info`, override `state-warning`, a message that **failed to send** `state-danger`, everything else `state-neutral`. The text must be allowed to wrap; the timestamp must not.

`state-danger` was added with Slice 3's automations (#92). The rule had four tones because until then nothing on the feed reported something the product had *tried and failed* to do — every other row records something that happened. A failed client message is the one entry a team must not scroll past, and PRD §1.1's second question is *"has the client been told?"*: rendering that row in the same grey as a renamed task is the feed answering it wrongly. A **sandbox-redirected** message is `state-warning` rather than `state-info` for the neighbouring reason — it went out, but not to the client, and reading it as a send is the specific misunderstanding F5.9's sandbox creates.

The event-type mapping lives in `resources/js/lib/activity.ts`, never at a call site — S12, S31 and the deal timeline all render this row, and three copies of a colour rule disagree within a month. A default slot sits under the timestamp for the supporting lines a feed carries (a logged note, the deal, who did it); it is inside the text column deliberately, so those lines align under the text rather than under the icon.

#### NavItem

```
h-8  px-2.5 py-[7px]  gap-2.5  rounded-md
[icon 18] [ Label 14/500 ] [flex-1 spacer] [count 12]
```

Active: `bg-accent`, icon and label `text-primary`, label 600. Inactive: label `text-secondary-foreground`, icon `text-muted-foreground`. A section the user lacks permission for is **hidden, never disabled**.

### 7.4 Bespoke anatomies

These are the L-effort pieces. Nothing off the shelf is shaped like them, so they are specified in full.

#### The stage rail (S16) — the core interaction

A vertical list of rows. Each row is `flex gap-3` with a fixed rail column and a flexible card column.

```
┌──────┬────────────────────────────────────────┐
│ rail │ card                                   │
│ w-26 │ flex-1, pb-3.5                         │
│      │                                        │
│ ◉    │ ┌────────────────────────────────────┐ │
│ │    │ │ collapsed: h-11                    │ │
│ │    │ └────────────────────────────────────┘ │
│ │    │                                        │
└──────┴────────────────────────────────────────┘
```

**Rail column**: `w-[26px]` vertical flex, `items-center`, full row height. Contains a marker then a connector.

- **Marker**: `rounded-full` with a 1.5px border, 22px for a normal stage, **26px with a 2px border for the active stage**. Fill is the state background, border and 12px icon are the state colour.
- **Connector**: `w-0.5 flex-1 bg-border`, hidden on the last row.

**Row heights are explicit**, because the connector needs a definite height to stretch into: collapsed rows are **58px** (44px card + 14px bottom spacing), the expanded active row is **302px**.

**Marker by state:**

| Stage state | Fill / border | Icon |
|---|---|---|
| Complete | `state-success-bg` / `state-success` | `check` |
| Complete + milestone | `state-success-bg` / `state-success` | `flag` |
| Overridden | `state-warning-bg` / `state-warning` | `shield-alert` |
| In Progress | `state-info-bg` / `state-info` | `loader` |
| Blocked | `state-warning-bg` / `state-warning` | `loader` |
| Upcoming | `muted` / `state-neutral` | `circle` |
| Skipped | `muted` / `state-neutral` | `minus`, card text muted |

**Collapsed stage card**: `h-11 px-3.5 gap-2.5 rounded-lg border bg-card`, laid out as
`[name 14/600] [milestone pill?] [flex-1] [meta 12 muted] [StatusBadge] [chevron-down 15]`.

The meta string carries dates, duration, and counts: `15 Jul–2 Aug · 18 days · 8 of 8 tasks`.

**Milestone pill**: `rounded-full px-[7px] py-0.5 gap-1` with an 11px `flag` icon and `Milestone` at 11px/600. Tinted `state-success` when the stage is complete, `state-neutral` when it is still ahead.

**Expanded active stage card**: `rounded-lg border-[1.5px] border-primary bg-card overflow-hidden`, four bands stacked vertically:

1. **Header**, `h-12 px-3.5 bg-accent border-b`: `[name 15/600] [StatusBadge] [flex-1] [meta 12 muted] [chevron-up]`
2. **Body**, a two-pane split with a 1px vertical divider: **Tasks** on the left (`flex-1 p-3.5 gap-[9px]`), **Requirements** on the right (`w-[340px] p-3.5 gap-[9px]`)
3. **Footer**, `h-13 px-3.5 bg-muted border-t`: `[zap icon] [what advancing will do, 12 muted] [flex-1] [Override] [Advance Stage]`

Each pane opens with a 12px/600 muted heading carrying its own count (`Tasks · 5 of 7 complete`, `Requirements to advance · 2 of 3 cleared`, the latter in `state-warning` when something is still blocking).

**"Cleared", not "met".** The count is met *plus* overridden, and IA §8 makes
Overridden a state of its own rather than a kind of Met — so "1 of 1 met" over
a row badged Overridden says the opposite of what happened.


> [!note] Built as `components/app/StageRail.vue` and `StageRow.vue` (#76)
> Five departures from the anatomy above, each with a reason the spec could not
> have known:
>
> - **Row heights are not hard-coded.** §7.4 fixes them at 58px collapsed and
>   302px expanded so the connector has something definite to stretch into; the
>   build gets the same guarantee from `self-stretch` on the rail column and
>   `flex-1` on the connector, which also survives a stage name wrapping and a
>   requirements pane of six rows rather than three. A hard 302px would clip
>   them.
> - **The two-pane body stacks below `lg`.** The requirements pane is fixed at
>   340px, and two panes at that width on a phone is two columns of two words.
> - **The collapsed card and the expanded header band are one `<button>`**,
>   not two. They are the same control saying the same thing, and a `v-if` pair
>   dropped keyboard focus to `<body>` on every toggle.
> - **The meta string is hidden below `sm`.** §7.4's collapsed card carries it
>   unconditionally — `15 Jul–2 Aug · 18 days · 8 of 8 tasks` — and on a 360px
>   phone that string plus a stage name, a badge and a chevron does not fit on
>   one 44px line. §11 floors the row at 44px and the name is what a reader
>   scans by, so the meta is what gives way. It returns at `sm`.
> - **The footer has no `[Override]`.** The anatomy above lists one, and it
>   carries exactly the ambiguity §7.4 already rejects one section down for the
>   dialog's footer: *"an Override there cannot say which gate it means once
>   there are two blockers — which is the ordinary case."* The card's
>   requirement rows are the plain density and carry no actions, so there is
>   nowhere on the card to put a per-gate Override. Advance opens S23, whose
>   rows each carry their own.
>
> Two rules it settles that the anatomy does not:
>
> - **The Overridden marker is a presentation, not a sixth stage state.** IA §8
>   lists five and `lib/states.ts` throws on a sixth; a stage that completed over
>   an overridden gate *is* `complete`, and carries `hasOverride` beside its
>   state. The marker takes `shield-alert` and the badge goes on saying Complete
>   — they disagree on purpose. **And only once the stage is finished**: an
>   override does not advance, so an *active* stage can carry a waived gate while
>   still blocked by two others, and marking that one Overridden would replace
>   the live "something is still in your way" with a historical note.
> - **The current stage is badged from the live verdict, not `stages.state`.**
>   That column is a cache only an advance attempt refreshes, so the ordinary
>   stage straight after an advance is cached `active` with its gate unmet.
>   `StageReadiness::stageState()` is the one place that decides, and S15's
>   overview reads it too — the two screens badged the same stage differently
>   until they did.

#### Requirement (gate) row

Used in the stage card, the deal overview, and the advance dialog. Three densities, one anatomy.

```
[icon 15–17] [ Label 13/500 · Sub 12 muted ] [flex-1] [action or Met badge]
```

- Met: `circle-check` in `state-success`, label `text-foreground` (or `text-secondary-foreground` in compact contexts)
- Unmet: `circle-alert` in `state-warning`, label `text-state-warning font-medium`

The sub-line always states the **gate type and its evidence**: `Manual confirmation · Heather Nguyen, 12 Aug`, `Document present · title-commitment.pdf`, `Approval required from Emily Roth`. This is what makes a refusal actionable.

In the advance dialog the row is promoted to a bordered box: `p-3 rounded-md border`, unmet rows getting `bg-state-warning-bg border-state-warning` and a right-aligned outlined action button that clears the gate.

> [!note] Built as `components/app/GateRow.vue` (#77)
> One component for all three densities, with `boxed` selecting the dialog's. Two rules it settles that the anatomy above does not:
>
> - **Overridden is a third marker, not a variant of met.** `circle-check`/`state-success` is met and `circle-alert`/`state-warning` is unmet; an overridden gate takes §7.4's own override glyph, `shield-alert` in `state-warning` — the same one the stage rail and `lib/activity.ts` use, so one fact has one glyph wherever it appears. IA §8 is emphatic that overridden is not a kind of met, and it is not a kind of advisory either: `StageReadiness` sorts an overridden gate into the advisory bucket (`blocksAdvance()` is `is_blocking && ! overridden`), which is why the payload carries `isBlocking` *and* `blocksAdvance` and the row badges from the first.
> - **Only a row genuinely in the way gets the amber box.** A met or overridden row inside the dialog is a plain bordered box, so the reader's eye lands on what is left.
>
> **The dialog's Override sits on the row, never in the footer.** §8.9's footer offers `cancel → alternate → primary`, and an Override there cannot say *which* gate it means once there are two blockers — which is the ordinary case. So the footer is `Cancel → Advance stage`, and each blocking row carries its own action: a link where the evaluator named a resolution, an **Override** where it could not.

#### "What happens when you advance" block

Sits in the advance dialog (S23) and, condensed to one line, in the stage card footer. Each entry is `[icon 15 muted] [ Label 13/500 · Detail 12 muted ]`, and the four entries are always in this order: emails, tasks, calendar events, stage completion.

Never ship the advance action without this block. An automation that emails the wrong client cannot be recalled, and this is the last place a human can catch it.

> [!note] Built server-side, and the empty entries say which slice fills them (#77)
> All four entries come from `App\Support\Workflow\AdvancePreview`, not from copy in the dialog, so what the reader is promised can be asserted in a test. Emails and calendar events have no data until Slices 3 and 4, and they render **naming the slice** rather than being dropped: an absent Emails row reads as "no emails will be sent", which is true today and will silently stop being true. The tasks entry carries the half nobody expects — open tasks on the completing stage stay open, because F4.10 keeps a task (work owed) apart from a gate (a condition on advancement).

#### Progress strip (S15)

A compact whole-workflow view above the fold. A horizontal row of equal-width segments, `gap-1.5`, each a vertical stack of a bar and a label.

- Bar: `h-1.5 rounded` (`h-2` for the current stage), filled `state-success` / `state-warning` (overridden) / `state-info` (current) / `border` (upcoming and skipped)
- Label: 11px below the bar, 600 and `state-info` for the current stage, otherwise 400 `text-muted-foreground`; skipped stages append `(skipped)`

#### Extraction review card (S66)

The highest-risk component in the product. `p-3.5 rounded-lg border bg-card`, three or four bands:

1. `[Label 13/600] [flex-1] [confidence: icon + 11px/600] [source page link: file-search + 11px/600 primary]`
2. `[date field: w-[170px] h-[34px] rounded-md border, calendar icon + 13/600 value] [verbatim quote, 12 muted, wrapping]`
3. *(conditional)* conflict strip: `p-2.5 rounded-sm bg-state-warning-bg` with `git-compare-arrows` and an explicit statement of what confirming will move
4. `[flex-1] [Reject] [Edit] [Confirm]` — or, once reviewed, `[StatusBadge Confirmed] [attribution 11 muted] [flex-1] [Undo]`

Three rules, all of them non-negotiable and all of them traceable to PRD §4.10:

- **There is no confirm-all, and no select-all.** Each field is confirmed individually.
- **The source page link is mandatory** on every field, and must jump the left pane to the highlighted region.
- **A conflict with an existing date must state the consequence** ("shifts 4 derived deadlines"), not just flag a difference.

The selected card carries `border-[1.5px] border-primary`; a conflicting card carries `border-state-warning`.

#### PII warning (S21, S51)

A compliance control, not a UI nicety. PRD §10 and the Screen Inventory both flag it. `p-3.5 rounded-lg bg-state-warning-bg border border-state-warning`, with `shield-alert` at 16px, a 13px/600 title in `state-warning`, and a 12px body in `text-secondary-foreground`.

It must name the alternative ("belong in CTM eContracts"), not merely prohibit. It is persistent on the documents surface and is repeated inside the upload dialog. **It must not be collapsed, dismissed, or softened for being repetitive.**

A refused upload renders as a table row with `bg-state-danger-bg`, a `file-x` icon, a `Refused` badge, and a sub-line stating plainly that the file was not stored.

#### Client status timeline (S62)

A separate visual language. See [[#9.5 P7 Client surface]].

### 7.5 Third-party additions

Beyond shadcn and its own dependencies. Keep this list short and justify every addition.

| Library | For | Notes |
|---|---|---|
| TanStack Table | Data tables | shadcn's Data Table is a recipe over it |
| VueUse | Composables | Breakpoints, storage, event listeners |
| date-fns | Formatting | Reka's calendar uses `@internationalized/date` separately |
| ~~A sortable library~~ | S38, S41, S42 | **Decided in S38 (#63): none.** Explicit move controls instead — see §13.2's note below |
| TipTap | S46 merge-field editor | Only if a simpler token-insert textarea proves insufficient |
| A PDF renderer | S52, S66 | pdf.js based |
| ~~A calendar library~~ | S57 | **Decided in S57 (#105): none.** The month grid is built by hand — see §15.3 |

> [!tip] Try the simple version of S46 first
> A textarea with a merge-field insert button and a live preview may be entirely adequate, and it avoids adding a rich text editor along with its serialization, sanitization, and paste-handling problems. Reach for TipTap only after the simple version demonstrably fails.

---

## 8. Application chrome

The frame every internal screen sits in. Built once in `AppLayout.vue`; roughly 70 screens inherit these decisions.

### 8.1 AppShell

```
┌────────┬──────────────────────────────────────┐
│        │ TopBar                        h-14   │
│Sidebar ├──────────────────────────────────────┤
│ w-60   │                                      │
│        │ <slot />           bg-background     │
│        │                                      │
└────────┴──────────────────────────────────────┘
```

Design frame is **1440×1024**. Sidebar 240px fixed; main column `flex-1`, vertical, with the top bar fixed at 56px and the content region filling the rest.

### 8.2 Sidebar — 240px

| Band | Spec |
|---|---|
| **Team switcher** | `h-14 px-3 gap-[9px] border-b`. Logo `size-7 rounded-md bg-primary` with 11px/700 mark; name 14/600; plan line 11/400 muted; `chevrons-up-down` 14px. |
| **Nav groups** | Three groups, each `p-2.5 px-3 gap-0.5`, separated by full-width 1px dividers. |
| **Spacer** | `flex-1` |
| **User block** | `h-15 px-3 gap-[9px] border-t`. Avatar 30; name 13/500; role 11/400 muted; `ellipsis-vertical` 16px. |

Group membership is fixed by [[Information Architecture]] §5.1 and the order is deliberate:

1. Dashboard · My Work · Deals
2. People · Properties · Calendar · Keep in Touch
3. Templates · Settings

Icons: `layout-dashboard`, `list-checks`, `briefcase`, `users`, `house`, `calendar-days`, `heart`, `layout-template`, `settings`. My Work carries a count.

### 8.3 TopBar — 56px

`h-14 px-6 gap-3 border-b bg-background` (`px-4 gap-1.5` below `md`, where
four 44px controls and a breadcrumb compete for a 375px bar — the toggle and
the search box are `display: none` there, so five gaps rather than seven, and
the tighter gap returns up to 30px to the breadcrumb — 24px without Log
contact), laid out as
`[Breadcrumb] [flex-1] [Search 300×32] [Report a bug] [Log contact] [Notifications] [Help]`.

- **Breadcrumb**: root 14/600 `text-foreground`; on a detail screen a 13px `chevron-right` and a 14/500 muted leaf appear.
- **Search**: `w-[300px] h-8 rounded-md border px-2.5 gap-2` — 14px `search` icon, 13px muted placeholder, `flex-1`, then a `⌘K` kbd pill (`rounded-sm px-[5px] py-0.5 bg-muted`, 11px/500).
- The top bar carries **no primary action**. One primary button per screen, and it belongs to the page header.

**Report a bug is the one labelled control in the row** (#176), and the
exception is the audience rather than the feature. Every other control here is
an icon because the person pressing it knows the product; this one is aimed at
somebody who has just hit something broken, is not going to recognise a bug
glyph, and will give up rather than hunt. So it is `variant="ghost"` with a
16px leading icon and its words — at three widths: §11's 44px square below
`md`, the icon buttons' own 32×32 box from `md` to `lg`, and full width with
its label above that. The `aria-label` carries the same sentence at all three.
The glyph stays 16px in the middle band where the boxes match, because §7.2
gives every *button* a 16px leading icon and `IconButton`'s 18px belongs to a
different control.

It is not a *primary* action, so the rule above still holds: the screen's own
primary button is untouched. It appears only when the environment supplies a
form URL, which means most developer installations never see it.

### 8.4 DealHeader — 120px

Shared by all eight deal tabs (S15–S22).

| Band | Spec |
|---|---|
| **Title row** | `py-4 px-6 gap-3`. Left: deal name `text-2xl/600` + deal-state `StatusBadge`, then a meta row `gap-3.5` of `[icon 13][text 13 muted]` pairs — client, deal type, location, owner. Right: `Log Contact` (ghost), `Add Task` (secondary), **`Advance Stage` (primary)**, overflow icon button. |
| **Tab row** | `px-6 gap-[22px]`, tabs per §7.2, container carries the bottom border. |

Tabs, in order: Overview · Timeline · Tasks · Dates · People · Properties · Documents · Offers. Counts appear on Tasks, Dates, People, Documents, Offers. Offers is hidden when the deal type has none.

**The Tasks count is what is open, not what the deal holds** (#71). Every other count is a total; this one is not, because a seeded pack puts eighty tasks on a deal and a tab reading `80` when all eighty are done says the opposite of what happened. Same rule as §7.4's stage-rail counts: the number has to mean what a reader will assume it means.

> [!warning] Three things this table does not settle, found while building S15 (#75)
> Each is answered in code for now, and each is really a design decision:
>
> 1. **One Advance button, several workflows.** PRD §7.5 gives a deal
>    concurrent workflows on purpose — pre-listing improvements and the sale
>    run at once — and this row specifies a single primary `Advance Stage`.
>    Built as: the header offers it only when exactly one workflow is running
>    with a stage to leave; with two, the header has no primary action and the
>    Overview's per-workflow cards carry one each. A primary action that
>    silently picks one of two workflows is worse than none, and there is no
>    honest label for *"advance one of these"*.
> 2. **`Add Task` is built; `Log Contact` is not.** Tasks landed with S17
>    (#71), and the button is a **link to the Tasks tab** with `?new` rather
>    than a dialog opened in place — the form needs this deal's stages and this
>    team's assignees, and carrying that payload on all eight tabs to save one
>    navigation is a cost every tab pays for a button most of them never press.
>    Contact logging is still on the person (S32) and has no deal-level write
>    path, and the overflow icon button has nothing to put in it either.
> 3. **There is no `owner`.** The meta row asks for one and `deals` has no
>    owning-agent column. Nothing is rendered rather than the person who
>    happens to be looking.
>
> The header is built as `resources/js/components/app/DealHeader.vue` and drawn
> by `layouts/DealLayout.vue`; its payload is
> `App\Support\Deals\DealHeader::for()`.

### 8.5 PageHeader

`flex items-center gap-3`, roughly 44px tall.

```
[ Title text-xl/600 · Subtitle 13 muted ]  [flex-1]  [secondary actions]  [primary]
```

The subtitle is not decoration — it carries the count and the temporal context that makes the screen legible at a glance: `25 active · 4 closed this quarter`, `12 tasks assigned to you across 7 deals · 3 overdue`.

### 8.6 FilterBar

A single `h-8` row, `gap-2`. Left to right: search input (`w-[260px]`), filter chips, `flex-1` spacer, then view controls.

**Filter chip**: `h-8 px-2.5 rounded-md border gap-1.5` holding `[Key: 12 muted] [Value 12/600] [chevron-down 12]`. Active chips take `border-primary bg-accent` and tint both texts `text-primary`.

**Segmented control** (used on My Work): a `rounded-md border overflow-hidden` container with zero gap; each segment `h-8 px-3` with `border-r`, active segment `bg-accent` with `text-primary` label at 600 and its count alongside.

### 8.7 Card

```html
<div class="rounded-lg border bg-card overflow-hidden">
  <header class="flex items-center gap-2 px-4 py-[13px] border-b">
    <h3 class="text-13 font-semibold">Needs attention</h3>
    <StatusBadge tone="warning" dotless>4</StatusBadge>
    <div class="flex-1"></div>
    <a class="text-xs font-medium text-primary">View all</a>
  </header>
  <!-- rows, each border-b, last one without -->
</div>
```

Card titles are 13–14px/600. The header action is a 12px/500 `text-primary` link, never a button. Rows are `px-4` at 44px or 52px depending on how many lines they carry.

### 8.8 Table

Three parts, all sharing one set of column widths.

1. **Column header** — `h-8 px-4 bg-muted border-b`, labels `text-xs font-medium text-muted-foreground`, sortable columns adding a 12px `chevron-down`.
2. **Rows** — `h-9` (or 40/44 when a row needs a second line), `px-4 border-b`. **No zebra striping**; the border does the separating.
3. **Footer** — `h-11 px-4 border-t`, `[count 12 muted] [flex-1] [Previous] [Next]`, both pagination buttons `h-7 px-2.5 rounded-md border` at 12/500.

### 8.9 Dialog

Width **600px** for a focused decision, **660px** when a checklist or preview needs the room. `rounded-lg bg-popover overflow-hidden` plus the dialog shadow, over a scrim at 45% `--foreground`.

| Band | Spec |
|---|---|
| Header | `py-5 px-6 gap-1 border-b`. Title 18/600, subtitle 13/400 muted. A consequential dialog leads with a `size-[34px] rounded-full` tinted icon circle. |
| Section | `py-[18px] px-6 gap-3 border-b`. Opens with a 12/600 muted heading. |
| Inline alert | `py-3.5 px-6 border-b`, filled with the relevant state background, full-bleed to the dialog edges. |
| Footer | `py-4 px-6 gap-2.5 bg-muted`. Left: an optional 12px muted note. Right: cancel (ghost) → alternate (secondary) → primary. |

**One exception to the full-bleed inline alert** (#176). A full-bleed band sits
*between* bands, which necessarily puts it outside the dialog's
`DialogDescription` — and therefore outside `aria-describedby`. That is right
for an alert *about* the dialog's state and wrong for one that is part of what
the dialog is telling you. S06's Report a bug modal carries the warning PRD §10
names as the whole of its mitigation, and drawn as a full-bleed band it was
visible to everyone except the one reader who cannot see amber. Inside the
header band it becomes a `rounded-md px-3 py-2.5` inset card instead. **When an
alert is load-bearing content rather than status, it goes inside the
description and takes the inset form.**

**Dialogs must not scroll their own header or footer away.** If content exceeds the viewport, the middle sections scroll.

### 8.10 Modal screens in the design file

A modal is drawn as a full 1440×1024 frame containing an `AppShell` instance, a scrim rectangle, and the dialog positioned at `y = 150`. That is a design-file convention for reviewing the modal in context; in code it is just a `Dialog`.

---

## 9. Page patterns and composition recipes

Seven layouts cover every screen in the inventory. A new screen picks one rather than inventing an eighth.

| Pattern | Structure | Used by |
|---|---|---|
| **P1 List** | PageHeader, FilterBar, Table, Pagination, EmptyState | S13, S30, S35, S50, S69 |
| **P2 Detail** | DealHeader, tab content | S15 to S22, S31, S36 |
| **P3 Form** | Field groups, sticky footer actions | S72 to S80 |
| **P4 Dashboard** | Stat row, then panels in a responsive grid | S10, S81 |
| **P5 Wizard** | Stepper, step content, back and next footer | S14, S33 |
| **P6 Split review** | Resizable two-pane: source on the left, proposals on the right | S66, S67 |
| **P7 Client** | Single centred column, 16px base, team branding | S61 to S64 |

### 9.1 P1 List

Content region is `p-6 flex flex-col gap-4`:

```
PageHeader
FilterBar
Table card (flex-1)
```

The table card is `flex-1` so the footer sits at the bottom of the viewport rather than floating under a short list.

### 9.2 P2 Detail

Content region is `flex flex-col` with **no padding** — the `DealHeader` is full-bleed. The tab body below it carries its own `p-6`.

Tab bodies vary but stay within three shapes:
- **List** (Tasks, Dates, Documents): filter row, then a full-height card
- **Grid of cards** (People, Properties): a heading row, then a horizontal grid of equal columns
- **Composed** (Overview): see below

**S15 Overview** is the densest composition in the product and the reference for "six kinds of information on one screen":

```
p-6, gap-5
├─ Progress strip                     full width, ~85px
└─ Grid  (flex, gap-5, flex-1)
   ├─ Column A  (flex-1)
   │  ├─ Current stage card           header + 2-pane body, ~126px body
   │  └─ Activity card                flex-1
   └─ Column B  (w-[340px])
      ├─ Property card                photo 116 + body
      ├─ People card                  4 rows at 42px
      └─ Documents card               4 rows at 40px
```

The right rail is fixed-width and its cards are `fit-content`; the left column's last card takes `flex-1`. **Budget the rail carefully** — it overflowed twice during design before the photo was cut to 116px.

> [!note] As built (#75)
> The progress strip and the current stage card **repeat per running
> workflow**, for the reason §8.4's warning above gives. The current stage
> card's two panes are *the stage* on the left and *what is stopping it* on the
> right — every unmet gate, with the sentence its evaluator wrote and, where
> the evaluator knows one, a link to the thing that clears it. That pane is the
> screen: issue #75's standard is that nobody should have to scroll or click to
> learn what is blocking the deal.
>
> The rail carries **no photo**. PRD §10 stores links to listing data and never
> the data itself, so there is no photo to render and the card is the address,
> its market status, and a count of the other properties on the deal. The rail
> also carries a Dates & Deadlines card and a Documents card, both stating the
> slice that fills them (4 and 3) rather than being wedged in later.

### 9.3 P4 Dashboard

```
p-6, gap-6
├─ PageHeader
├─ Stat row            4 cards, gap-4, each flex-1
└─ Grid (flex-1, gap-6)
   ├─ Column A (flex-1): Needs attention card, Active deals card (flex-1)
   └─ Column B (w-[352px]): Dates & Deadlines card, Activity card (flex-1)
```

**Stat card**: `p-4 gap-2 rounded-lg border bg-card`, containing `[label 12/500 muted] [flex-1] [icon 14]`, then the value at **26px/600**, then a 12px delta line tinted by the metric's own state (`state-warning` for blocked, `state-danger` for overdue, muted otherwise).

The four stats are fixed: Active deals · Blocked stages · Overdue tasks · Closing in 14 days. They answer "is anything on fire" before anything else loads.

### 9.4 P5 Wizard

A centred column, `w-[1000px]`, `py-7 px-6 gap-5.5`:

```
Title text-2xl/600
Stepper
Card (flex-1)  →  header · body · footer
```

**Stepper**: horizontal, alternating step and connector. Step = `[circle size-6.5] [label 13]`; connector = `h-px flex-1 bg-border`. Circle states: done `bg-primary` + white `check`; current `bg-accent border-2 border-primary` with the number in `text-primary`; upcoming `border` with a muted number. Labels follow the same three tints.

The card footer carries `[Back] [flex-1] [autosave note 12 muted] [Continue]`.

### 9.5 P6 Split review

A full-bleed review header (`h-16 px-6 border-b bg-card`) above a two-pane split.

- **Left, source**: fixed `w-[610px]`, `bg-muted`, with its own 44px toolbar and the document rendered on a padded white page.
- **Right, proposals**: `flex-1`, opening with a full-bleed guard alert, then a `p-4 gap-3` list of review cards.

The review header carries the review progress as a dotless badge (`3 of 11 reviewed`), the extraction provenance as a 12px muted line (**model, prompt version, and cost — required by PRD F10.4**), and two actions where the primary is scoped to what has actually been reviewed: `Confirm 3 reviewed dates`, never `Confirm all`.

### 9.6 P7 Client surface

**A different design language, deliberately.** Nothing in sections 4 or 7 applies except the tokens.

| Rule | Value |
|---|---|
| Frame | 390px wide, mobile-first, single column, no navigation |
| Base type | **16px**, headings 17–18, hero 30/700 |
| Touch targets | 52px for actions, 44px absolute minimum |
| Section padding | `p-5` (20px) |
| Accent | `--brand`, never a state token |
| Density | Comfortable everywhere. No 36px rows, no `text-13`, no compact controls. |

Composition, top to bottom: brand bar (60px, brand-filled) · hero (kicker + address + city) · property photo (190px) · **status card** · timeline · contact block · documents link · footer.

**The status card is the most important element on the page.** It states in plain sentences what is happening and, critically, whether the client needs to do anything:

> Your home is under contract, and the buyer's inspection is booked for Thursday 22 August.
>
> There is nothing you need to do right now. Emily will call you as soon as the inspection report comes back.

That second paragraph is the "nothing is happening" state the Screen Inventory flags as mattering most. It is not an empty state to be designed later — it is the default copy, present in every status.

**Client timeline** reuses the rail idea at client scale: markers 24px (28px with a 3px brand border for the current step), connectors tinted brand for completed segments and `border` ahead, rows 76px. Labels are 17px; sub-lines are 15px and say `Finished 2 August` / `Happening now` / `Expected 22 August`.

**Language rules** come from [[Information Architecture]] §9 and are absolute:

- The client sees the `milestone_label`, never the internal stage name. "Pre-Listing Preparation" → "Getting your home ready".
- **`blocked` is never shown.** A blocked stage renders as "Happening now".
- Skipped stages are hidden entirely.
- No gates, no workflows, no overrides, no tasks, no checkboxes.

The footer must state that this page is a summary and that signed documents live in the e-signature system of record. That is a compliance position from PRD §10, not a courtesy.

### 9.7 Required states

Every screen defines all five. This is the column that gets skipped in design and then invented at 11pm during implementation.

| State | Rule |
|---|---|
| **Loading** | Skeleton matching the real layout. Spinners only when duration is genuinely unknown. |
| **Empty** | Say what belongs here and offer the action that creates it. Never a bare "No results." |
| **Error** | What happened, then what to do. "Couldn't send. Check the sending address in Settings." |
| **Permission denied** | Prefer hiding the entry point entirely. If the URL is reachable, explain who can grant access. |
| **Overloaded** | What 25 deals, 500 people, or 50 tasks looks like. Design it, do not discover it. |

> [!note] Empty states: the component exists, the coverage does not
> `EmptyState` is built, and the two that matter first — a new team's dashboard and a filtered deals index — are designed and rendered in the component gallery at `/design-system`. The copy pattern is IA §10: state what belongs here, then offer the action that creates it.
>
> The remaining screens still need their own. **Every new screen defines its empty state as part of building it**, rather than leaving a bare "No results" behind.

---

## 10. Forms

| Rule | Detail |
|---|---|
| Label position | Above the field, always. Never floating, never inline. |
| Label style | `text-sm`, weight 500 |
| Required marker | The word `Required` in `text-destructive` at 12/500, beside the label. Mark required fields, not optional ones. |
| Help text | Below the field, `text-xs`, `--muted-foreground` |
| Errors | Replace help text, `--destructive`, with an icon |
| Validation timing | On blur first, then on change once a field has errored |
| Field width | Match the expected content. A ZIP field is not 400px wide. A date field is 170px. |
| Grouping | Related fields in a group with a heading, 32px between groups |
| Actions | Bottom right, primary last. Sticky footer in modals and long forms. |
| Destructive confirm | Type the object's name for anything irreversible |
| Autosave | Only in the template editors and the create-deal wizard. Everywhere else, explicit save. |

**Textarea**: `rounded-md border p-[11px]` at 13–14px, roughly 86px tall for a reason field.

**One primary button per screen.** If two things look equally primary, neither is.

**Consequential inputs carry their consequence beneath them.** The override reason field (S24) is followed by "This is written to the permanent audit log with your name and the time. It cannot be edited or deleted," and then by a preview of the follow-up task the override will create. Copy that pattern anywhere an action is irreversible or auditable.

### 10.1 Action verbs

Button labels are not a styling choice. [[Information Architecture]] §7 fixes one verb per action, and the banned alternatives are banned because they create ambiguity. The two that matter most:

- **Advance** moves a workflow to its next stage. Never Progress, Move, Next, or Complete.
- **Override** forces past an unmet gate with a reason. **Skip** marks a stage not applicable. Conflating them destroys the audit trail's meaning, and they are different buttons with different colours.

---

## 11. Accessibility

Baseline for the internal app, and a hard requirement on the client status page per PRD section 9.

| Requirement | Detail |
|---|---|
| Contrast | 4.5:1 body text, 3:1 large text and UI boundaries. Verify every state token against both its background and the card background. |
| Colour independence | Never colour alone. Every badge carries a word. |
| Focus | Visible focus ring on every interactive element. Never `outline: none` without a replacement. |
| Keyboard | Every action reachable. Reka handles most of it; the bespoke pieces in 7.4 do not get it free. |
| Touch targets | 44px minimum on mobile, without exception |
| Labels | Every input bound to a label. Placeholder is not a label. |
| Motion | Honour `prefers-reduced-motion` |
| Client page | **WCAG 2.1 AA, verified.** Older audience, unfamiliar interface, one chance to be understood. |

> [!success] Contrast measured, 2026-08-21
> Every state pair was measured from the oklch tokens in `resources/css/app.css`, converted to sRGB and checked against WCAG 2.1. All pass 4.5:1 — the threshold for normal text — which covers the 11px and 12px badge labels.
>
> | Tone | On its badge background | On the card |
> |---|---|---|
> | Neutral | 4.90 | 5.50 |
> | Info | 4.75 | 5.50 |
> | Success | 4.95 | 5.65 |
> | Warning | 4.97 | 5.61 |
> | Danger | 5.06 | 5.82 |
>
> Dark mode measures higher on every pair (5.62 to 7.01 on the badge). The margins are thin by design — the badges are deliberately subtle — so the measurement is a **test**, not a note: `tests/js/tokens.test.ts` recomputes it from the stylesheet on every run and fails the build if a token edit drops a pair below 4.5:1.
>
> One caveat, stated because the margins are thin. Four token values sit just outside the sRGB gamut (`--state-info-bg`, `--state-warning`, `--state-warning-bg`, `--state-danger-bg`). The measurement clips each channel; a browser gamut-maps by reducing chroma instead, so what renders differs slightly from what is measured. The direction of that difference is toward *less* saturation and therefore, for these pairs, marginally more contrast — but if a pair is ever tightened further, bring the token into gamut rather than trusting the measurement to the second decimal.

---

## 12. Email design system

A separate universe. None of the above applies, and pretending otherwise produces broken email.

| Constraint | Rule |
|---|---|
| Layout | Tables. No flexbox, no grid, no CSS variables. |
| Styles | Inline. A `<style>` block is a progressive enhancement at best. |
| Width | 600px maximum, single column |
| Fonts | Web-safe stack only. Inter will not load in most clients. |
| Colours | Literal hex, duplicated from the app palette and recorded below |
| Images | Always with alt text. Assume they are blocked. |
| Buttons | Bulletproof table-cell buttons, never a styled `<a>` alone |
| Dark mode | `prefers-color-scheme` where supported, and it must degrade gracefully where not |
| Plain text | A real plain-text alternative for every message, not a stripped-tag afterthought |
| Testing | Litmus or Email on Acid before launch. Outlook will surprise you. |

### 12.1 Email palette

Now reconciled against the design tokens rather than eyeballed, and carried in code by `App\Support\Mail\EmailPalette` — which `tests/Unit/EmailPaletteTest.php` holds against this table, the way the enums are held against the PRD's.

| Role | Hex | Dark | Matches |
|---|---|---|---|
| Primary | `#1A588F` | `#7FB1DC` | `--primary` |
| Text | `#0A0E11` | `#E7EBEF` | `--foreground` |
| Muted text | `#636A71` | `#9BA4AD` | `--muted-foreground` |
| Border | `#DFE1E4` | `#333C45` | `--border` |
| Background | `#FFFFFF` | `#171C21` | `--background` |
| Panel | `#EFF2F5` | `#1F262D` | `--muted` |
| Success | `#137738` | `#5FC383` | `--state-success` |
| Warning | `#905D00` | `#DCA33F` | `--state-warning` |
| Danger | `#C22826` | `#EE8B88` | `--state-danger` |
| Canvas | `#F4F6F8` | `#0E1216` | — the page behind the card |
| Plate | `#FFFFFF` | `#FFFFFF` | `--logo-plate` |

**The dark column is not [[#2.6 Dark mode]]'s deferral being walked back.** §2.6 defers dark mode *for the app*, where the product decides when a theme applies. An email has no such control: iOS Mail and Outlook.com invert a message the day the reader turns dark mode on. §12 already required `prefers-color-scheme` and this table had no values to do it with. These are authored by §2.6's own rule — invert lightness, lift chroma so a state colour survives on a dark ground.

**Team branding overrides the primary and the logo only.** Everything else stays fixed, so no tenant can accidentally produce an unreadable email.

#### The accent is a fill, never text — narrower than §2.7, and only here

[[#2.7 Team branding]] gives a team's accent to headings, markers and links. On the client status page that is safe: the app is light-mode in v1 and the ground under a heading is a colour the product chose. An email has neither guarantee, and a team is most likely to pick a deep colour *because* it looks right on white — which is exactly the colour that disappears when a phone inverts the card behind it.

So in email the accent appears only as a **fill with a computed foreground on it**: the header band and the call-to-action button. Both carry their own ground with them. Headings and body text take the palette above. `App\Support\Mail\BrandedEmail` computes the foreground with `AccentContrast`, choosing black or white by WCAG ratio — S72 *warns* an owner about a low-contrast accent and then takes it anyway, and an email has no second chance to be adjusted.

#### The logo sits on a plate, for §2.6's reason

*"A raster asset cannot participate in the token layer."* A team's logo is a PNG with a fixed idea of what is behind it, so a client reading in dark mode gets a white mark on near-black — or a black mark on it. The answer is the one `AppLogoIcon` already uses: a plate that stays light in both schemes. `Plate` above is `--logo-plate` one universe over.

The logo is **embedded**, not linked. The bytes live on a private disk and a client reading the email has no session to fetch them with, so a `src` pointing at the application would render as a broken image for the one reader it is for.

Client-facing emails follow the [[#9.6 P7 Client surface]] language rules. A milestone email uses the `milestone_label`, never the stage name.

---

## 13. Organization and governance

### 13.1 Structure

```
resources/js/
├── components/
│   ├── ui/          shadcn output. Do not hand-edit.
│   ├── app/         Our composites (section 7)
│   └── forms/       Domain field wrappers
├── layouts/
│   ├── AppLayout.vue        Internal, sidebar + top bar (section 8)
│   ├── ClientLayout.vue     Status page, branded, 16px base
│   ├── AuthLayout.vue       Login and invitations
│   └── AdminLayout.vue      Super admin, visually distinct
├── pages/           Mirrors routes: Deals/Index.vue, Deals/Documents.vue
├── composables/
└── lib/             utils, formatters, the state token map
```


> [!note] The sortable library, and why there is not one
> Decided while building S38 (#63), which was the first screen to need
> reordering. **None — explicit move controls.**
>
> Three reasons, in order of weight. Drag-and-drop needs a keyboard path to be
> usable at all, so shipping it means building the buttons *as well as* the
> drag; a photo gallery reorders perfectly well with two of them. §13.2 rule 3
> admits a third-party library only when nothing composes, and here something
> does. And the reorder endpoint takes **the whole order at once** rather than
> a move-one request — a reorder is one intention, and two adjacent swaps
> racing each other produce an order neither person chose — which is the same
> API whether a drag or a button produced it.
>
> S41 and S42 order longer lists than twenty photographs and may overturn this.
> It is deliberately cheap to overturn: the endpoint does not change, only what
> calls it.

### 13.2 Rules

1. **Need a component? Check shadcn-vue first.** It is probably there.
2. **Not there? Can it compose from two or three shadcn parts?** Then it belongs in `components/app/`.
3. **Only then consider a third-party library,** and only if it is maintained and tree-shakeable.
4. **Never hand-edit `components/ui/`.** Extend through `cva` variants or a wrapper in `components/app/`. Re-running the CLI must stay safe.
5. **No raw colours in components.** Semantic tokens only. This is the rule that prevents most drift.
6. **A pattern used three times gets promoted** into `components/app/` with a name.
7. **New state? Add it to section 2.4 first,** then build the badge. The table is the source of truth, not the code.
8. **Both light and dark values, always.** Adding a token means adding it to both blocks, even though dark ships later.
9. **A tone is three properties.** Background, foreground, and any icon move together or not at all.

### 13.3 Build order

1. ~~Tokens and the Tailwind theme, including the `state-*` utilities and `text-13`~~ — **done**
2. ~~`AppLayout` — sidebar and top bar, section 8. The highest-leverage work in the project.~~ — **built; the review with Heather is still outstanding**
3. ~~`StatusBadge`, since it appears on nearly every screen~~ — **done**
4. ~~`PageHeader`, `FilterBar`, `EmptyState`, which unlock every P1 list page~~ — **done**, along with `Card`, `Table`, `DealRow`, `TaskItem`, `ActivityItem`, `DateChip`, `IconButton`, and `Tab`
5. ~~One real list screen end to end (S13), to prove the density spec at 20 rows~~ — done in #78; §4.3 carries the measured budget
6. Then the bespoke work, starting with the stage rail (S16) — Slice 2

> [!warning] Step 2 still owes its review
> `AppLayout` is built to the measurements in section 8 and renders in both themes, but **it has not been reviewed with Heather.** Seventy screens inherit its decisions about density, type scale, and mobile collapse, so that review happens before any product screen is built — not after.
>
> Every component in steps 1 to 4 renders in the gallery at `/design-system`, in both themes, which is what the review should be run against.

---

## 14. The design file

`designs/Basic Designs.pen`, opened with Pencil. **The markdown in this document is authoritative; the `.pen` file is the visual reference.** Where they disagree, this document has been checked and the file has not.

### 14.1 Canvas conventions

| Region | Contents |
|---|---|
| `y ≈ 0–1300` | Reusable components. Editing one updates every instance. |
| `x ≈ 1560, y ≈ 0` | `AppShell` and `DealHeader` (large components, kept out of the small-component rows) |
| `x ≈ 3200, y ≈ 0` | A README note repeating these conventions |
| `y ≥ 2500` | Screens, in rows, left to right in priority order |

Every screen is a **`ref` instance of the `AppShell` component** with its `Content` slot replaced. That is why changing the sidebar once changes all of them, and it is the mechanism that keeps the set coherent.

### 14.2 Frame sizes

| Size | Use |
|---|---|
| 1440×1024 | Standard internal screen. The default. |
| 1440×1200 | Dense lists that need to show their full data set (S13 at 25 rows) |
| 390×1607 | Client status page, full scroll height |
| 600–660 wide | Dialogs, drawn over a shell instance and a scrim |

### 14.3 Screens built so far

Seventeen, covering all seven page patterns:

**S06** app shell · **S10** dashboard · **S11** My Work · **S13** deals index · **S14** create deal · **S15** deal overview · **S16** deal timeline · **S17** tasks · **S18** dates · **S19** people · **S20** properties · **S21** documents · **S22** offers · **S23** advance stage · **S24** override gate · **S62** client status · **S66** extraction review

The remaining 74 are listed in [[Screen Inventory]]. Anything built from this document should match what is already drawn — if it does not, one of the two is wrong and it is worth finding out which before building 74 more.

---

## 15. Open questions

1. **Product name.** Blocks the logo, the favicon, the email header, and the sending subdomain, which is painful to change once reputation is established.
2. **Empty states, beyond the first two.** The component is built and the dashboard and deals-index states are designed (§9.7). Every remaining screen still owes its own, and the rule is now that a screen is not finished without one.
3. **Calendar library for S57. Settled, and the answer is none** (#105). The month grid is `CalendarMonth.vue`: six rows of seven cells over a range the controller already computes.

    The styling argument stood — most calendar libraries bring opinions that would fight §2's tokens — but it is not what decided it. **No library models two kinds of thing on one square.** Every one of them has a single event type with a start and an end, and Screen Inventory calls S57 hard for precisely the case that is not: *"events and deadlines are different things sharing a grid."* A deadline is a moment with legal consequences that nobody attends, it has to be visually distinct from a 4pm showing **and sorted above it**, and the distinction has to survive a dense day where five of each land together.

    Adopting one would have meant fighting its cell renderer to express the single thing this screen exists to express, on top of fighting its CSS. A month grid is a smaller thing to own than an adapter.

    `CalendarItem.vue` carries the distinction in **three** channels rather than in colour alone (§11 does not let colour be the only one): shape — a deadline is a flag on a flat row with a left border, an event is a filled chip with a time; order — decided on the server, because it is a statement about which matters; and words — a deadline shows its name and nothing else. A dense cell shows three and counts the rest into a *"+4 more"* that opens the day, because a cell that grows to fit eleven pushes the rest of the month off the screen and silently dropping them is the version that looks fine and loses a closing.
4. **Rich text for S46.** Try the simple token-insert textarea first.
5. **Does anything need charts?** Only S85 and the optional F9.6 reporting. If it stays that small, shadcn's Chart component may be more than is needed.
6. **Team accent contrast validation. Settled, and the answer is *both*, split by surface** (#97). Warning is more honest and generates support questions; auto-adjusting is invisible and occasionally produces a colour they did not pick — and which of those costs more depends entirely on whether anybody is standing there.

    **S72 warns.** The owner is on the screen, looking at a preview, and can pick again; `AccentContrast::warningFor()` says what the ratio is and what to do about it, and then saves the colour they chose. A silently altered brand is an angrier support ticket later.

    **Email computes.** There is no second chance and nobody to notice, so `BrandedEmail` picks the readable one of black and white for the foreground — and §12.1 narrows where the accent may appear at all, to a fill that brings its own ground rather than to text over one the client's mail app chose. See §12.1.

    The status page (Slice 4) inherits S72's answer: it is a surface the product renders in a theme it controls, and the owner has been told.
7. **Density preference as a user setting.** Deliberately out of scope for v1. Revisit if Heather asks.
8. **Mobile collapse — specified and built, not yet validated on a phone.** The shell now switches at `md`: the sidebar is replaced by a bottom tab bar carrying Dashboard, My Work, Deals, and Calendar, with everything else behind a "More" sheet, per [[Information Architecture]] §5.3. Targets are 44px minimum. What remains is judging it on a real device, which belongs with the PWA slice rather than before it.
9. **`text-13` versus `text-sm`.** Still open, deliberately. The token exists and `DealRow`, `TaskItem`, and the card rows use it, so the comparison can now be made on a real screen — but the honest test is S13 at twenty rows (Slice 2), not the component gallery. Decide then, before it is baked into ninety-one screens.

---

## Related notes

- [Frontend conventions](Frontend%20conventions.md): where these components live in the codebase, and the formatters and content rules that go with them
- [[Information Architecture]]: naming, navigation, and the state vocabulary these tokens serve
- [[Screen Inventory]]: the 91 screens this system has to cover
- [[Design references]]: what to look at first
- [[Monroe Digital/brawling mahogany (real estate software)/docs/Product Requirements Document]]: what gets built and why

## Next actions

- [x] Design the empty states, starting with a new team's dashboard and deals index ✅ 2026-08-21 — both render in the gallery at `/design-system`
- [x] Scaffold from the Laravel Vue starter kit and confirm shadcn-vue is wired ✅ 2026-08-21
- [x] Write the token block, both light and dark, plus the `state-*` and `text-13` theme entries ✅ 2026-08-21
- [x] Measure contrast on the warning and success badge pairs at 11px ✅ 2026-08-21 — all pairs pass 4.5:1; now a test, see §11
- [x] Build `AppLayout` plus the sidebar (section 8) ✅ 2026-08-21
- [ ] **Review `AppLayout` with Heather before any product screen is built** 📅 2026-08-31
- [x] Build `StatusBadge` against the section 2.4 table ✅ 2026-08-21
- [x] Build S13 end to end and confirm 20 rows is the honest desktop number ✅ 2026-08-24 — #78; §4.3 now carries the measured budget rather than the estimate
- [x] Design the mobile collapse for the shell before the PWA slice ✅ 2026-08-21 — built; still to be judged on a real phone
- [x] ~~Choose the sortable library, needed by S38, S41, and S42~~ — **decided with S38 (#63): none.** See §13.2
- [ ] Decide the calendar approach for S57 📅 2026-09-14

## Sources

- [shadcn/vue documentation](https://www.shadcn-vue.com/docs/components)
- [shadcn/vue theming](https://www.shadcn-vue.com/docs/theming)
- [shadcn/vue Laravel installation](https://www.shadcn-vue.com/docs/installation/laravel)
- [shadcn/vue changelog](https://www.shadcn-vue.com/docs/changelog)
- [Reka UI](https://reka-ui.com/)
- [shadcn/ui Tailwind v4 guide](https://ui.shadcn.com/docs/tailwind-v4)
- [Laravel starter kits](https://laravel.com/docs/13.x/starter-kits)
