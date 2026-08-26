---
title: Automated messages
summary: Client updates that send themselves, and the approval step in front of them.
section: setup
order: 4
---

Messages to clients that send themselves when something happens — a stage
completing, a deadline approaching — so the update that keeps somebody calm
does not depend on remembering to send it.

## How it works, end to end

You write the words once as a **message template**. You attach an **automation**
to a stage in a workflow template, saying when it should go and who it goes to.
Then, on every deal running that workflow, reaching that moment prepares the
message with the right client's name and the right property in it — and, unless
you have said otherwise, waits for a person to read it before it goes.

The waiting is the part worth understanding, so it has its own section below.

> **Your first month, everything waits.** For a new team, every outbound email
> is held for review regardless of how its automation is set up. An email to the
> wrong client cannot be recalled, and a template's first few real sends are
> when a mistake in it is most likely and least expected. **Settings →
> Sending** turns it off when you trust what you have written, and turning it
> back on starts a fresh month.

## Message templates

They live under **Templates**, on the _Message templates_ link at the top of
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

**“Replies go to” sets the reply address for this template.** Leave it blank
and replies go to the reply-to address in **Settings → Team**, which is the
right answer almost always. Fill it in when one kind of message should be
answered by somebody else — inspection chasers to your coordinator, say. It
does not change the address a message is **sent from**: that is one verified
address for the whole app, and it is what keeps your mail out of spam folders.

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

_A requirement clears_ covers every kind of requirement, but **it is noticed
when somebody presses Advance**, not the moment the world changes. Ticking a
requirement by hand fires it straight away; one that comes true on its own —
because the last required task got done, say — fires the next time anybody
tries to advance that deal, including a try that gets refused because something
else is still in the way. If you want a message to go the instant a stage is
finished, _when the stage completes_ is the trigger that means that.

**Then** — send an email, create a task, or prompt somebody to do it by hand.

**How it runs** — and this is the one worth reading:

- _Fires on its own._ No human sees it before it goes.
- _Needs approving first._ The app prepares the message with the right
  recipient and the right words, and somebody releases it. **This is the one to
  reach for**, and it is what a new team's messages do regardless for their
  first month.
- _Prompts somebody to do it._ No automatic send at all — a person is told it
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

## The review queue

**Messages** in the sidebar, with a count beside it when something is
waiting.

Each row is one message about one deal. Open it and you see exactly what the
client would see — the subject, the body, and the addresses it would go to. Then
**Approve and send**, or **Stop this message**.

**You can edit before sending.** The change belongs to that one message, not to
the template it came from. Fixing a sentence about this deal's inspection does
not rewrite what every future deal gets — that is the template editor's job, and
deliberately a different screen.

One thing the editor will not let you do is type a merge field. The words in
front of you are already filled in, so `{{ client_name }}` typed there would go
to the client exactly as written. Type the name.

**A message with a merge field that could not be filled in cannot be approved.**
The blanks are listed at the top of the page. Usually the fix is on the deal —
a property that has not been linked, a client with no name — and sometimes it is
in the template. Either way the message waits rather than going out with a gap
in it.

**There is no approve-all.** That is deliberate: a button that clears the queue
in one click is a button people press without reading, which is the entire
failure this queue exists to prevent.

## What did not go out

The same screen carries failures, above the queue rather than behind a tab. A
message that failed to send answers "has the client been told?" exactly as badly
as one still waiting, and it is the thing you most need to notice.

Each one says why in plain words — the address resolved to nobody, the mail
service refused it, somebody stopped it. Opening it shows the full message, so
you can send it by hand or pick up the phone.

**Stopping a message keeps the record.** It is marked stopped rather than
deleted, because three months from now the question is not "is this tidy", it is
"why did the client never hear about the inspection".

**And you get told.** People on your team who can approve messages get an email
about anything that has failed since the last one — within a few minutes, and
almost never twice about the same failure. On a large team it goes to the first
few rather than to everybody, because an alert copied to twenty people is an
alert twenty people assume somebody else is dealing with.

_Almost_ never, because if the alert itself is accepted and then fails on its
way out, the next one covers the same ground again. Told twice beats not told. When several have gone wrong it says how
many and links to the queue rather than to one of them, because one expired
password can take out a morning's worth and you want the list, not the first
row.

A message a rail is _holding_ does not send one of these. Nothing has been lost:
it goes on its own when the limit rolls over or the switch comes off, and the
queue says so in the present tense while it waits.

## Held before sending

Between those two lists sits a third, and it holds two different things.

**Paused by a rail.** A limit was reached, or somebody switched sending off.
These go out on their own once the reason clears — nothing is cancelled, and
the row says which rail is holding it.

**Handed over and never confirmed.** The mail service was given the message and
never came back to say what happened. This is rare and it means exactly what it
says: **nobody knows whether it arrived.** The app will not send it again on
your behalf, because sending twice is worse than the uncertainty — if it
matters, read what it said and follow up by another route.

A message in that second state is recorded as unconfirmed a few hours later, so
it stops sitting in limbo. It is never quietly retried.

## The safety rails

**Settings → Sending.** Three of them, and they apply to every send —
including one that happens at 3am with nobody watching.

**Sandbox mode.** Everything is redirected to the team owner instead of the real
recipient, with a banner saying so. New teams start here. Turn it off in team
settings when you are ready.

**A limit per hour and per day.** If a template goes wrong in a loop, this is
what stops it at sixty rather than six thousand. Reaching the limit **pauses**
sending rather than cancelling it — the messages go when the hour rolls over.
Only emails count against it; tasks an automation creates reach nobody outside
the team.

**A switch that stops everything.** One toggle in team settings, and it catches
what is already queued as well as what has not been prepared yet. Held, not
cancelled — turning it back off releases them. This is the one to reach for when
something is wrong and you are not yet sure what.

## What your client actually sees

Every email goes out in your team's frame: your logo at the top if you have
uploaded one, your accent colour, your words in the middle, and a plain-text
version underneath for watches, screen readers and inboxes with images turned
off. Set both in **Settings → Team**.

**In their inbox it says your team's name** — _"Bosart Group via Goldieflow"_ —
and the address beside it is the app's. That combination is deliberate: the
name is who your client thinks is writing, and the address is the one the mail
system is allowed to send from, so putting yours there would send the message
to spam instead. **A reply comes to you**, at the reply-to address in
**Settings → Team**.

**When the stage that just finished is a milestone**, the email opens with what
you called that milestone for the client — the wording on the stage in your
template, never the internal stage name. "Your home is on the market", not
"Stage complete: Property Listed". If the property has a listing linked and your
own words do not already point at it, a button to the listing goes underneath.

That headline appears when a stage **completes**, not when it starts. A
milestone is the moment something finished.

Like the words themselves, the headline and the address are worked out when the
automation fires, and the queue shows you both — so what you approve is what the
client gets, even if something on the deal changes while the message is
waiting. Your logo
and colour are the exception: those are read when the message actually goes, so
changing them updates everything still queued.

## What is still to come

**Delivery tracking**, so a bounced message is something you find out about
rather than something a client mentions three weeks later.

**Your own sending address.** Your clients see your team's name in their inbox,
and a reply reaches you — but the address underneath it is the app's, not
yours. Sending as `you@youragency.com` needs your domain verified with the mail
provider, which is a setup step rather than a feature.

**A link to the client's status page** in a milestone email. The email has a
place for it; the page itself is still being built.

**Push notifications and calendar events** as things an automation can do.
