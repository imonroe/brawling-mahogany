---
created: 2026-08-20
modified: 2026-08-28
project: Goldieflow
type: plan
status: draft
version: 1.0
tags:
  - monroe-digital
  - plan
  - goldieflow
---

# Build Plan

> [!info] What this document is for
> The order of operations for building Goldieflow, and the map from that order to the GitHub issue backlog.
>
> It does not restate scope. [[Product Requirements Document]] is the source of truth for what gets built, [[Information Architecture]] for what everything is called, [[Screen Inventory]] for which screens exist, and [[Design System]] for how they look. This document answers only: **in what order, and why that order.**

---

## 1. The shape of the build

The PRD defines seven release slices. This plan adds a **Slice 0** in front of them and otherwise follows the PRD's sequence exactly.

| Slice | Name | Issues | What it buys |
|---|---|---|---|
| **0** ✅ | Scaffolding, platform, design system | 19 | A stack that runs, a pipeline that gates once `scripts/protect-branches.sh` is run, and a component kit the other 70 screens assemble from |
| **1** ✅ | Tenancy, identity, people | 19 | A team can exist, log in, and hold contacts — with isolation proven |
| **2** ✅ | Deals and the workflow engine | 32 | **The product exists.** One real deal, end to end, manually |
| **3** ✅ | Automation, documents, mobile shell | 15 | The client gets told automatically, and Heather finds out on her phone |
| **4** ✅ | Calendar, key dates, status page | 8 | Deadlines drive the work, and the client can look without calling |
| **5** | AI document intelligence | 6 (+2 gates) | Parity with the competitor's headline feature |
| **6** | Post-close and Keep in Touch | 5 | A closed deal stays an asset |
| **7** | Commercial | 3 (+3 gates) | It becomes a business |
| — | Cross-cutting decisions | 12 | The non-code work that gates the code work |

**128 issues, 9 of them epics.** Counts exclude the epics themselves; a decision issue that also carries a slice label is counted once, under decisions.

> [!success] Slice 0 has landed
> The application skeleton, the container stack, the CI pipeline, the design
> system foundations, and the two ADRs are built and merged. Two things inside
> it are deliberately still open, and both are named in the pull request rather
> than quietly carried: **the `AppLayout` review with Heather**, and **the
> staging droplet**, which needs an account, a domain, and DNS that no
> repository can provide. Slice 1 starts from a stack that runs and a pipeline
> that gates — the gating needs `scripts/protect-branches.sh` run once by an
> admin, because branch protection lives in repository settings and cannot be
> committed.

> [!success] Slice 4 has landed
> All eight issues are built: `key_dates` with the derived-date cascade (#106),
> events and the calendar (#105), the Dates & Deadlines screens (#107), the
> tokenised iCal feeds (#108), deadline reminders and the `date_reached` gate
> (#109), magic-link access (#110), the client status page (#111), and the
> WCAG 2.1 AA audit of the client surface (#112). The exit criterion — *"deadlines
> drive the work, and the client can look without calling"* — is met, and
> **Slice 5's destination now exists**, which was the reason this slice came
> before it.
>
> One thing inside it is deliberately open and named in the pull request rather
> than quietly carried: **the manual screen-reader pass** on real iOS and
> Android devices. #112's automated audit is real — axe-core over the rendered
> client surface with a positive control, plus a server-side contrast pass over
> every accent a team can pick — but VoiceOver and TalkBack on a phone are not
> something a test suite can stand in for, and this is the one surface whose
> audience makes that difference matter.

### Why Slice 0 exists

The PRD folds "CI, staging" into Slice 1 alongside auth, people, and contact import. That understates it. `CLAUDE.md` requires tests from the first commit, a Docker stack, and a GitHub Actions pipeline — and the Design System requires the token layer and `AppLayout` before any screen is drawn. None of the Slice 1 product work can be built, tested, or reviewed until that exists.

Splitting it out does not change scope. It makes the first two weeks honest.

### Why the rest of the order is unchanged

Each slice's exit criterion is a sentence about a person doing something real, and each depends on the one before it:

- Slice 2 cannot run a deal without a team to own it.
- Slice 3 has nothing to send until there are stages to advance.
- Slice 4's contingency calendar needs deals to hang dates on.
- **Slice 5 has nowhere to put its output until Slice 4 exists.** The PRD is explicit: *"Extraction with no contingency calendar to populate has nowhere to put its output. Build the destination, then build the shortcut."*
- Slice 6 needs deals that can close.

The one place to stop and reconsider is after **Slice 2** — the PRD calls it *"the first genuinely useful build and the right place to stop for feedback."*

---

## 2. The critical path

Ignoring everything that can run in parallel, this is the chain that determines the date:

```
Scaffold + Docker + CI            (#21 #22 #24)
        ↓
Tokens → AppLayout → StatusBadge → list kit    (#29 #31 #32 #33)
        ↓
Tenancy enforcement + isolation suite          (#41 #42)
        ↓
Deals + participants                           (#59 #60)
        ↓
Template layer → runtime layer → snapshot      (#64 #65 #66)
        ↓
Gate evaluators → AdvanceWorkflow              (#67 #68)
        ↓
Create deal → overview → deals index           (#74 #75 #78)
        ↓
Timeline + advance modal                       (#76 #77)
        ↓
Templates and roles UI                         (#84 #85 #86 #88)
        ↓
Seeded template packs   ← blocked on Emily's lists (#87 ← #11)
        ↓
        Slice 2 exit: one real deal, end to end
```

Three things on that path are worth naming.

**`AppLayout` (#31) is the highest-leverage single piece of work in the project.** Seventy screens inherit its decisions about density, type scale, and mobile collapse. The Design System requires a review with Heather before anything else is built. A week there saves a month.

**`AdvanceWorkflow` (#68) is the architectural keystone.** Every workflow mutation goes through it. If a controller ever writes `stages.state` directly, the audit trail, the automation dispatch, and the gate guarantees all become optional — and nobody notices until something has been silently skipped.

**The seeded template packs (#87) are blocked on input we do not have.** Emily's consolidated task list, including a buyer-side list that does not yet exist, is the direct input. Build the pack *mechanism* against a placeholder; do not invent the content to unblock the schedule.

That is now the state of it: the mechanism landed with #84–#86 — packs are listed, previewed, and copied, and the copy is deep — and what is missing is a pack with real stages in it. **A seeded pack whose content somebody invented is worse than an empty templates screen**, because it teaches a process nobody follows and gets copied before anyone notices, so #87 stays open on #11 rather than being closed with a plausible placeholder.

Two decisions sit on this path rather than beside it:

- **#10 — partner or first customer.** The PRD says settle it in writing *before* slice 2, and slice 2 is where Emily's specific process gets encoded into something sellable. It gates the start of the slice, not a single issue inside it.
- **#18 — shared versus duplicated person records.** It decides the shape of `people` and `team_memberships`, which #40 and #47 build on and which every later foreign key points at. It has to land before the first slice 1 migration.

Note that three screens sit on the path rather than beside it. #74 → #75 → #78 is the shortest route to a deal that exists, renders, and lists — and #76 depends on #78 for the reason below. Two of those three are L-effort, so the chain is longer than "build the engine, then draw the timeline" suggests.

One ordering subtlety inside slice 2: **prototype the stage rail early, build it after the deals index.** Screen Inventory sequences *design* (S16 first, because it is the one interaction with no precedent to copy); Design System §13.3 sequences *build* (S13 end to end first, to prove the density spec at 20 rows). Both are right about different activities, so #76 carries a dependency on #78 while its prototype runs much earlier.

---

## 3. What runs in parallel

Three tracks can proceed independently of the critical path, and should:

| Track | Issues | Why it is independent |
|---|---|---|
| **Long-lead externals** | #12 SES production access, #13 AI provider DPA, #15 product name, #17 legal | Weeks of waiting on other people. Start them now; they gate slices 3, 5, and 7 |
| **Research spikes** | #14 extraction corpus, #19 iPhone web push, #16 Tailwind Plus | Answer questions whose answers change the build. All three are throwaway code or conversations |
| **Design ahead of build** | Empty states (#33), mobile collapse (#31), S16 prototype (#76) | Design System §15 lists the first two as gaps; the S16 prototype comes from the Screen Inventory's sequencing recommendation. Designing them while Slice 1 is being built keeps Slice 2 unblocked |
| **Decisions that gate a slice** | #10 partner or customer, #18 person records | Neither is code. Both have to be settled before the slice they gate starts — #18 before the first slice 1 migration, #10 before slice 2 |

---

## 4. The rules that survive every slice

These come from `CLAUDE.md` and PRD §8, and they are the reason several issues that look like plumbing are marked P0.

**Tenancy fails closed.** Single database, `team_id` on every business table, a global scope that throws rather than leaking, and a test suite (#42) whose entire job is proving cross-tenant access returns 403 or 404. A gap here is a release blocker, not a follow-up.

**Template and instance never merge.** Instantiating snapshots (#66). Editing a template in September must not touch a deal that closed in August, and must not reorder the stages of a deal sitting at stage four today.

**One mutation path for workflow state.** `AdvanceWorkflow` (#68), in a transaction, dispatching to the queue *after* commit.

**Gates are data, not conditionals.** One evaluator class per type (#67), resolved from `gate_type`. An unknown gate type throws — it never evaluates as met.

**Automation is the highest-blast-radius feature in the product.** The approval queue (#93) and the safety rails (#96) are launch blockers, stated as such in the PRD. An email to the wrong client cannot be recalled.

**Uploads are the largest liability.** Restricted categories are refused outright, the PII warning appears at every upload point, and the scan runs in memory before anything is written (#99, #100). The terms describe what this actually does, not what it sounds like it does.

**Nothing a model proposes reaches a live record unconfirmed.** `extracted_fields` is the only path into `key_dates` and `tasks` (#115, #116, #117).

**No PII in logs. Ever.** Built into the log scrubber on day one (#37), not audited in later.

---

## 5. Working the backlog

### Labels

| Group | Labels |
|---|---|
| Slice | `slice-0` … `slice-7` |
| Type | `epic`, `infra`, `backend`, `frontend`, `design-system`, `security`, `testing`, `docs`, `decision` |
| Priority | `P0` (blocks the slice), `P1` (in the slice), `P2` (cut candidate) |
| State | `blocked` |

Each slice has an epic issue carrying a checklist of its children, its exit criterion, and the architectural rules that apply inside it.

### Branching

Per `CLAUDE.md`:

- Feature branches target **`dev`**
- `main` is for **tagged releases only**
- `dev` → `main` by pull request when a release is cut

One branch per issue. CI blocks the merge — once `scripts/protect-branches.sh`
has been run; until then the pipeline reports without gating anything
(Deployment §7).

### Pull requests

Also per `CLAUDE.md`, and applying to every PR in this repo:

1. **Subscribe to the PR** and confirm the tests pass.
2. **Adversarial review by a sub-agent**, recorded in the PR itself rather than in a chat window.
3. **Address what makes sense**, then review again — up to five rounds, or until there is no feedback left.
4. **Five rounds without convergence means flagging Ian**, not merging anyway.
5. **Documentation is updated in the same PR.** *"Keeping the documentation up to date is critical… Documentation is part of the development process here."* A PR that changes behaviour and leaves the PRD, IA, Screen Inventory, or Design System stale is not done.

That last rule is why several issues in this backlog name the document they have to write back into — #78 records the honest row count in Design System §4.3, #48 settles the vendor flag question in IA §13.3, #105 records the calendar library decision, and #16 records the Tailwind Plus outcome.

### Definition of done, everywhere

Every issue carries its own acceptance criteria, but four apply universally:

1. **Tests exist and pass in CI.** `CLAUDE.md`: *"For anything we build, we must have tests. This should be a basic principle from the very beginning of the build."*
2. **Vocabulary matches [[Information Architecture]].** No `projects`, no `milestones` in the old sense, no "Client Portal". IA wins over every other document on naming.
3. **No raw colours in components.** Semantic tokens only. Design System §2.1 calls it the one rule worth being pedantic about in review.
4. **Reused, not rebuilt.** `CLAUDE.md`: *"Try to re-use components when possible to try to keep everything DRY. Prefer pre-built components to rolling your own when practical."* Design System §13.2 gives the decision order — shadcn-vue first, then a composite in `components/app/`, then a third-party library, and only then something bespoke. A pattern used three times gets promoted with a name.

---

## 6. Sequencing risks, and what is being done about them

| Risk | Mitigation in this plan |
|---|---|
| **Scope expectation mismatch between Ian and Emily** (PRD §14.3) | The slice plan itself. Each slice has a visible exit criterion agreed in advance rather than argued about later |
| **Slice 2 is a third of the build** — 32 issues, 8 of the 15 hard screens | Known before it starts. The workflow engine issues (#64–#68) are sequenced ahead of the screens so the hard architecture lands before the hard design |
| **Seeded templates blocked on input** (#11) | The mechanism is built against a placeholder; only the content waits |
| **Slice 3's sending path is the highest-blast-radius thing in the product** (PRD §4.5) | The slice is split so that nothing able to reach a client ships before its rails do. #90 and #91 build the *definition* layer — templates and automations — and can send nothing at all; `action_instances` (#92), the approval queue (#93) and F5.9's rails (#96) land as one piece. An email to the wrong client cannot be recalled, so "the rails follow shortly" is not a sequence this slice offers |
| **Slice 5 blocked on a DPA** (#13) | Started in parallel from day one. If the DPA does not land, Slice 5 does not start and nothing else is affected |
| **Extraction may not be accurate enough to ship** (#14) | Measured against a hand-checked corpus *before* the build, not after. Zero tolerance on missed critical dates is a ship gate |
| **AI cost scales with deal volume, not team count** (PRD §14.3) | Cost tracking and a spend cap are in the first extraction issue (#113), not a follow-up |
| **iOS web push may not work well enough** (PRD A11) | Verified on a real device (#19) before the PWA work is committed to |
| **Building for one team** (PRD §14.3) | #20 — five willingness-to-pay conversations outside Emily's circle |

---

## 7. What is deliberately not in the backlog

Filed nowhere, on purpose, with the reason:

| Not built | Why |
|---|---|
| **F11.6 social life-event monitoring** | Would require scraping against platform terms; Meta's APIs do not expose third-party life events. PRD §4.11 *recommends* dropping it and PRD §15 records the decision as **pending Ian's confirmation** — so this is a recommendation the backlog follows, not a settled decision. Tracked as #130 |
| **F9.6 process reporting** | A PRD **Could** — average days per stage, most-overridden gates, automation failure rate. Deferred past v1, and worth revisiting once there is enough history to report on. The underlying data is captured from slice 2 onward, so this is a reporting layer rather than new instrumentation |
| **E-signature** | Confirmed unnecessary. Emily's market signs through CTM |
| **Client document upload** | Confirmed unnecessary. Clients sign and return through CTM |
| **MLS/IDX data ingestion** | Licensing, not preference. v1 stores links only |
| **Ongoing rental and property management** | Out permanently, for a licensing reason. Tenant placement stays in |
| **Commercial transactions** | Deferred to a later template pack, blocked on someone who knows commercial timelines |
| **Native mobile apps** | Superseded by the PWA. Revisit after it proves demand. F12.5 attaches a constraint that *does* apply now: *"the API layer should be designed so a native client can be added without rework."* Inertia is not a public API, so the practical reading is to keep controllers thin and the domain services transport-agnostic — carried as an acceptance criterion on #68, which is the service that would otherwise accrete HTTP concerns |
| **Blank-canvas workflow builder** | v1 is clone-and-edit. The builder is the graduation, not the start |
| **SMS** | Gated on TCPA consent handling, which is the hard part |

---

## Related notes

- [[Product Requirements Document]]: what gets built and why
- [[Information Architecture]]: what everything is named
- [[Screen Inventory]]: which screens exist and what they cost
- [[Design System]]: tokens, components, and the build order for the front end
