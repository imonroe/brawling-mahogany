---
created: 2026-08-21
project: Brawling Mahogany
type: index
---

# Architecture decisions

A decision belongs here when it is expensive to reverse and cheap to record:
something every later table, screen, or slice inherits.

| # | Decision | Status | Issue |
|---|---|---|---|
| [0001](0001-data-and-persistence-conventions.md) | Data and persistence conventions — ULIDs, soft deletes, `team_id`, money as integer cents, JSONB config, enum-backed states | Accepted | [#27](https://github.com/imonroe/brawling-mahogany/issues/27) |
| [0002](0002-multi-tenancy-enforcement.md) | Multi-tenancy enforcement — single schema, five layers, and what a violation does | Accepted | [#28](https://github.com/imonroe/brawling-mahogany/issues/28) |
| [0003](0003-no-email-only-flows.md) | No user flow depends on email alone — every email-initiated flow carries a second door, catalogued and tested | Accepted | — |

## Writing one

Keep it short and keep the reasoning. Context, decision, consequences, and an
explicit list of what was *not* decided — that last section is what stops the
next person re-opening a question that was deliberately left open.

Supersede rather than edit: a decision that changed is a new ADR that says
which one it replaces, so the history of the reasoning survives.
