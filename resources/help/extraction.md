---
title: Reading dates out of documents
summary: Pulling deadlines out of a contract, and the human confirmation in front of it.
section: deals
order: 10
---

Upload a contract and the app reads it, proposing the dates and deadlines it
found. Upload an inspection report and it proposes a task list. You review
what it found and decide, item by item, what goes on the deal.

Nothing is written until you say so. That is not a setting.

## Starting one

Open a document on a deal and choose **Extract**. Say whether it is a contract
or an inspection report — they are read differently — and the app queues it.

Reading a contract takes a minute or two. You can leave the page; the deal's
documents list will show when it is ready, and you will get a notification.

## What you get back

**A contract** gives you the dates and deadlines it found, plus any additional
provisions worth knowing about — an inclusion, a seller concession, an unusual
contingency.

**An inspection report** gives you a proposed task list. Deliberately a short
one: an inspection report has dozens of findings and most of them are not worth
a task. The app tells you roughly how many it left out, so if that number
surprises you, you know to go and look.

## Nothing lands without you confirming it

**Every extracted value is a proposal.** It appears beside the passage it came
from, and nothing is written into the deal until a person accepts it.

You will always see what it read, where it read it, and how confident it is.

**Confidence is information, not permission.** A date the app is very sure
about still needs your click. There is no "confirm all" for dates, and there is
no setting that adds one. A wrong closing date entered by hand is a mistake
somebody made; a wrong closing date written silently by a machine is a mistake
nobody knows about until it matters.

For an inspection report you *can* tick several tasks and accept them together,
because an unwanted task is an annoyance rather than a legal problem. Dates are
not like that.

### Three things worth knowing while you review

**A date it worked out is marked as such.** Contracts often write a deadline as
_"MEC + 10 days"_ rather than as a date. The app does the arithmetic and shows
you the sum it did. Check those first — an offset counted from the wrong
starting date looks exactly like a date read correctly off the page.

**A conflict is shown, with what it will move.** If the deal already has that
deadline and the contract says something different, you are told both, and told
how many other dates will shift if you accept the new one. Confirming then
*moves* your existing date rather than adding a second one.

**Editing is expected.** If it misread something, correct it and confirm. What
you changed is kept — that is how the app knows what it is getting wrong.

## What leaves your account

The document's **words** are sent to an outside model. The file itself is not.

Before they go, the app removes financial and identity numbers it can find:
routing and account numbers, the numbers off the bottom of a cheque, card
numbers, social security numbers, driver licence and passport numbers. What was
removed, and how many of each, is recorded.

**This narrows what is exposed. It does not eliminate it.** A contract carries
names, an address, a price, and the terms of somebody's purchase, and those go
with it — they have to, because they are what the dates are attached to. If
that is not acceptable for a particular document, do not extract it; enter the
dates by hand from the **Dates & Deadlines** tab.

Every extraction is recorded with which model read it, which version, and what
it cost, so _"what happened to this document"_ has an answer. An owner can see
all of it under **Settings → Extractions**.

## What it cannot read

**A photograph of a contract, or a scan with no text layer.** The app reads
words, and an image has none for it to find. You will be told that plainly at
the point of uploading rather than after a wait — there is nothing to be done
about it except type the dates in.

**Anything, perfectly.** It is a reading tool and it makes mistakes. That is
the entire reason the review screen exists and the entire reason it will not
let you accept eleven dates without looking at them.

## Limits

There is a monthly limit on how much reading a team does, because each document
costs real money to read. If it is reached, extraction stops and says so — it
does not quietly get worse. An owner can raise it under **Settings →
Extractions**, or it resets at the start of the next month.

## Where the dates end up

Confirmed dates go to the deal's **Dates & Deadlines** tab, marked as having
come from Extract, with your name and the time against them. From there they
behave like any other date: they drive reminders, they can anchor other dates,
and you can edit them.

Accepted tasks go to the deal's **Tasks** tab under the Inspection stage, with
a due date worked out from the objection deadline if the deal has one.

Provisions go to the deal's **Timeline** as a note. Internal only — nothing
from an extraction is ever put in front of a client.
