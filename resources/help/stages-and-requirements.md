---
title: Stages and requirements
summary: What blocks a deal, how to clear it, and the difference between advancing, overriding and skipping.
section: deals
order: 2
---

This is the heart of the app, and the part worth reading properly.

A workflow is a sequence of **stages**. Each stage holds **tasks** — things to
do — and **requirements** — conditions that must be true before the stage can
finish. A deal sits in one stage at a time and **advances** to the next when
its requirements are satisfied.

## Advancing

Press **Advance stage** on any deal screen. One of two things happens.

If everything is clear, the deal moves on. The stage you were in completes,
the next one starts, and the timeline records it.

If something is in the way, you get a list of what — and the list is sorted by
what you can do about it. Requirements you can clear right now come first,
with a button that takes you to the thing that clears them. Requirements that
cannot clear on their own come after.

**Advancing does not skip anything.** If three things are blocking and you
clear one, the deal has not moved; you press Advance again.

## The kinds of requirement

| Kind                        | Clears when                                                |
| --------------------------- | ---------------------------------------------------------- |
| **Manual confirmation**     | Somebody ticks it                                          |
| **Required tasks complete** | Every required task on the stage is done                   |
| **Field populated**         | A named field on the deal or property has a value          |
| **Approval**                | Somebody holding a named role approves it                  |
| **Document present**        | A document of the right kind is attached — _later release_ |
| **Action completed**        | An automated step has run — _later release_                |
| **Date reached**            | A key date has passed — _later release_                    |

The last three are visible and selectable but cannot yet clear on their own,
so a deal that meets one needs an override to move past it. That is temporary
and each one says so when you look at it.

## Confirming a requirement

Manual confirmations are the common case — _"seller signed the listing
agreement"_, _"inspection complete"_. Open the Advance dialog and press
**Confirm** on the row. It records who confirmed it and when.

If you tick the wrong one, **Untick** takes it back, as long as the stage has
not moved on. Once a stage is complete its requirements are history, and
history does not get edited.

## Overriding, and why it is not the same thing

Sometimes a requirement will not clear and the deal has to move anyway. The
survey came by email and has not been uploaded. The condition was met in a way
the app cannot see.

**Override** is for that, and it asks for a reason in writing. It then does
four things: it records the override, writes a permanent audit entry naming
you and your reason, marks the deal's timeline distinctly, and **creates a
follow-up task** so the thing you skipped past does not simply vanish.

That last part is the point. An override defers an obligation; it does not
delete one.

Overriding **does not advance the deal**. It clears one requirement. If two
others are still blocking, they still are.

Overriding is a separate permission from advancing. Somebody can move deals
along all day without being able to decide a survey was unnecessary.

## Skipping, which is a third thing again

**Skip** says the stage does not apply to this deal at all. A cash purchase
genuinely has no appraisal contingency — that is not a requirement somebody
failed to meet, it is a stage that was never relevant.

The difference matters beyond bookkeeping. Overrides are counted, because a
process that is overridden constantly is a process that is wrong. Skips are
not, because a deal that differs is not a process that failed.

Skipping also takes a reason, and it is a Team Owner permission.

## Reopening

If a stage was finished too early, **Reopen** puts the deal back into it. Only
the most recently finished stage can be reopened — to go back further, reopen
them one at a time, which keeps the record honest about what happened.
