---
title: Templates
summary: Writing your process down once so every deal follows it.
section: setup
order: 1
---

A **template** is your process, written down once. Attach it to a deal and it
becomes that deal's workflow — its stages, its tasks, its requirements — with
dates worked out from when each stage starts.

This is where the app earns its keep. Everything else is bookkeeping; this is
the part that means the fortieth deal runs like the first good one.

## Editing a template never changes a running deal

Worth stating first, because most people assume the opposite.

When you attach a template to a deal, the app takes a **copy**. The deal
carries its own stages from that moment on. Edit the template afterwards —
reorder it, add a stage, delete one — and every deal already running is
untouched.

The template editor shows how many deals are running on a template, and it is
there to reassure rather than to warn: those deals will not change. If a live
deal needs fixing, fix the deal.

## Building one

**Templates → New template**, then add stages in order. A stage takes a name,
and that is all the editor asks for today. A stage also carries a description,
an expected duration and a milestone label — the fields the client-facing view
will read — but nothing on any screen can set them yet, so a stage you build
here has a name and its contents.

Reorder with the move controls. There is no drag-and-drop, deliberately: a
reorder is sent as one whole intention, so two people rearranging at once
cannot produce an order neither of them chose.

Under each stage, add:

**Tasks** — the things to do. Mark the ones that must be finished before the
stage can end as required.

**Gates** — the conditions that must clear before the stage can end. The
button says **Add gate**, and the word changes on purpose: here you are
configuring a condition, and on a deal the same thing is shown as a
_requirement_, because "2 requirements not met" is what somebody looking at a
stuck deal wants to read.

Two kinds can be built today: _manual confirmation_, which somebody ticks, and
_required tasks complete_, which clears itself when the required tasks are
done. The other kinds need configuration the editor cannot yet ask for, so
they are not offered — a gate built without its configuration is one nothing
can ever clear.

## Packs

**Packs** are ready-made processes shipped with the app, shown below your own.

You cannot edit a pack, because every team shares it. **Use a copy** takes a
full copy into your team, and that copy is yours to change however you like —
the pack itself is untouched for everybody else.

The packs built from real listing and buyer checklists are being prepared and
are not in the app yet. Until then, build your own, or ask whoever set your
team up.

## Who can do this

Templates need the `templates.manage` permission, which Team Owner holds by
default.

## Message templates and automations

Two things live alongside the workflow templates, and both are for the same
purpose: making the process tell the client what is happening without anybody
remembering to.

**Message templates** are the words. The link at the top of this screen opens
them. Who a message goes to is a _rule_ — "the seller", "the deal's main
contact" — worked out per deal when it sends, so one template serves every
deal.

**Automations** hang off a stage, under **Add automation**. They say what
should happen when the stage starts or completes: send a message, create a
task, or prompt somebody to do it by hand.

**Nothing sends yet.** You can write both and neither will reach anybody until
the review queue and the safety rails around sending are built. The
[Automated messages](/help/automation) article explains what is coming and why
it is arriving as one piece rather than in parts.
