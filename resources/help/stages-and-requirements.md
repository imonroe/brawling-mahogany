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

Seven exist in the engine. **Two can be built in the template editor today**,
and they are the two that can actually clear:

| Kind                        | Clears when                              |
| --------------------------- | ---------------------------------------- |
| **Manual confirmation**     | Somebody ticks it                        |
| **Required tasks complete** | Every required task on the stage is done |

The other five — approval, field populated, document present, action
completed, date reached — each need a piece of configuration the editor cannot
yet ask for, so the editor does not offer them. That is deliberate: a
requirement built without its configuration is one nothing can ever clear, and
a stage carrying one could only be got past by overriding it every time.

If a template from a pack carries one of those, you will see it on the deal
and it will say why it cannot clear on its own.

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

The difference matters beyond bookkeeping. An override says the process was
right and this deal did not follow it; a skip says the process did not apply.
A team overriding the same requirement on every deal has a template to fix; a
team skipping the appraisal stage on every cash purchase has a template that is
working.

Both are recorded — the override with its reason, its audit entry and its
follow-up task, the skip with its reason — and that is what makes the
distinction readable a year later.

Skipping also takes a reason, and it needs the skip permission. A Team Owner
holds it; a role your team composed may hold it too.

## Reopening

If a stage was finished too early, **Reopen** puts the deal back into it. Only
the most recently finished stage can be reopened — to go back further, reopen
them one at a time, which keeps the record honest about what happened.
