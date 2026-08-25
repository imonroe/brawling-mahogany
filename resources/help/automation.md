---
title: Automated messages
summary: Client updates that send themselves, and the approval step in front of them.
section: coming-later
order: 3
arrives_with: A later release
---

Messages to clients that send themselves when something happens — a stage
completing, a deadline approaching — so the update that keeps somebody calm
does not depend on remembering to send it.

## What exists today

**You can write the messages and set up the automations. Nothing sends them.**

That is deliberate rather than half-finished. An email to the wrong client
cannot be recalled, so the parts that decide *whether* something goes out — the
review queue, the rate limit, the switch that stops everything — are being
built as one piece with the part that actually sends. Until all of it works,
none of it does.

So today you can:

- **Write message templates**, with merge fields, and see them rendered against
  a real deal of yours before anybody else does.
- **Send yourself a test**, which reaches you and nobody else.
- **Attach automations to a stage template**, saying what should happen when
  the stage starts or completes.

An automation you set up now will start working when the sending half lands.
Nothing you write is wasted, and nothing you write is going anywhere yet.

## Message templates

They live under **Templates**, on the *Message templates* link at the top of
that screen.

**Who it goes to is a rule, not an address.** A template says "the seller" or
"the deal's main contact", and the app works out who that is on the deal it is
sending about. That is what lets one template serve every deal — a template
holding an address emails the wrong person the moment it is reused.

**Merge fields** are the parts that change per deal: the client's name, the
property address, the MLS link. Click one to drop it into whatever you were
typing. They are checked when you save, so a misspelled field is refused then
rather than discovered in somebody's inbox.

Two of them are listed and cannot be filled in yet — the next deadline and the
client's status page link — because the features behind them have not been
built. The editor says so, and refuses to save a template using one.

**Every message carries a plain-text version.** It is what a watch, a screen
reader, and an inbox with images turned off will show, and it is not optional.

**The preview renders against a deal you choose**, with that deal's real
details in it. A preview full of placeholder text has verified nothing. It also
tells you who the message would actually reach, and an empty answer there —
"nobody on this deal" — is worth more than the preview itself.

## Fair Housing

Everything sent from here is automated client-facing content, written once and
read again months later by nobody. The editor carries the reminder beside the
body field because that is where it matters: describe the property and the
process, never the neighbourhood's people, the schools as a stand-in for them,
or who a home would "suit".

## Automations

On a stage in the template editor, **Add automation**. Three questions:

**When** — the stage starts, the stage completes, the workflow starts or
completes, or a requirement clears.

**Then** — send an email, create a task, or prompt somebody to do it by hand.

**How it runs** — and this is the one worth reading:

- *Fires on its own.* No human sees it before it goes.
- *Needs approving first.* The app prepares the message with the right
  recipient and the right words, and somebody releases it. **This is the one to
  reach for**, and it is what a new team's messages do regardless for their
  first month.
- *Prompts somebody to do it.* No automatic send at all — a person is told it
  is time.

The form narrows as you answer, and it will not let you build a combination
that cannot work. An automation that creates a task has no message template; an
email automation cannot point at a template written for push notifications; and
a requirement from another stage cannot be the thing that starts it.

## Archived, not deleted

A template that automations are standing on cannot be deleted, only archived —
which takes it out of the picker and leaves the automations already using it
alone. The list shows how many are using each one before you choose. Archiving
is reversible.

## What is still to come

**The approval queue.** Messages waiting for a person to release them, with the
rendered message in front of them and the ability to edit before it goes.

**Delivery tracking**, so a bounced message is something you find out about
rather than something a client mentions three weeks later.

**The safety rails**: a limit on how many messages can go out in an hour, a
switch that stops all sending immediately, and a sandbox mode where nothing
reaches a real address.
