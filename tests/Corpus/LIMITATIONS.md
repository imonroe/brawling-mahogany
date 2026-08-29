# What this corpus is not

Read this before quoting a number measured against it.

## These contracts are synthetic

Nobody signed them. No property in here exists, no person in here exists, and
no Colorado brokerage produced any of these twenty documents. They were written
to look like the Colorado Real Estate Commission's Contract to Buy and Sell
Real Estate, from the outside.

**They were written by the same kind of system that will be scored against
them.** That is the caveat that matters more than every other line in this
file. A model finds its own idiom easier to read than a brokerage's: the
section numbering is regular here, the deadline tables line up, the OCR noise
follows a pattern somebody chose rather than the pattern a particular scanner
at a particular office actually produces, and the awkward cases are awkward in
ways that were *thought of*. A real contract is awkward in ways nobody thought
of.

So: **every number this corpus produces is optimistic.** Not slightly. Treat a
score here as an upper bound that a real corpus will come in under, by an
unknown margin, in an unknown direction per fixture.

## What the targets actually say

PRD §12.3 sets two things, and only one of them is a percentage:

| Metric | Target |
|---|---|
| Extracted dates confirmed without edit | Above 85% |
| Critical dates missed entirely | **Zero tolerance.** *"A missed inspection deadline is a legal problem. Measure against a hand-checked corpus before shipping."* |

A score measured here is a **floor on the work remaining**, not evidence that
either target is met. If extraction scores 70% against these twenty, the real
number is below 70% and there is work to do. If it scores 95%, that is not 95%
— it is *"nothing coarse is broken, go and measure properly."* The zero
tolerance line in particular cannot be satisfied by a corpus nobody signed:
zero missed critical dates here is zero missed critical dates on documents
written to be readable.

## #14 stays open

PRD §14.1 Q5 asks for **20 real Colorado contracts**, and it says why: *"If
critical dates are missed, F10.1 is a liability rather than a feature."*
Assumption A10 — that a model can read a Colorado contract reliably — is still
recorded as **unverified**, and this corpus does not verify it, because the
documents A10 is about are the ones with no text layer at all.

#14 closes when the same harness has been run against twenty real contracts,
including genuinely scanned ones. Not before. Anything in a status update that
reads *"corpus built, extraction measured"* should say which corpus, and this
file is why.

## What it does earn

It is not nothing, and the reason to build it before the real one exists is
concrete:

- **The harness becomes runnable.** #118 needs something to score against on
  the day it is written, and waiting for twenty signed contracts to be cleared
  for use would have blocked it indefinitely.
- **A prompt regression becomes detectable.** The absolute score is
  untrustworthy; a score that was 91 last week and is 62 today, over an
  unchanged corpus, is a prompt change that broke something, and that signal is
  real.
- **The coarse failures get caught for free.** A date format nobody handles, an
  offset expression read as a literal, an amended date read as the printed one,
  a deadline table that is only read as far as the fifteenth row. Those are
  found here, on invented people, without spending a provider call on a real
  client's document — which is the other half of the argument. PRD §4.10 is
  explicit that contracts contain exactly the personal financial information
  Emily is worried about, and iterating a prompt against real ones is not free.

## It cannot see a redaction defect at all

Worth stating separately from the accuracy caveats above, because it is a
different kind of blindness and it has now cost three review rounds.

`RedactorCorpusTest` runs the real redactor over these twenty documents, which
sounds like coverage and is not. Every fixture is **pure ASCII**, and every
identifier in them is **unique**. Those two properties happen to make the
corpus incapable of distinguishing correct offset handling from two separate
bugs that shipped:

- A `strpos` cursor that finds the first occurrence of the matched *text*
  rather than the position the regex matched. Needs the same digits twice.
- A byte offset handed to `mb_substr`, which measures in characters. Needs a
  multi-byte character earlier in the document — a curly apostrophe, an em
  dash, a section sign, all of which a real contract is full of.

Against all twenty fixtures both versions report *identical rules fired*. The
corpus was cited as verification for one of them, in a commit message, and the
check could not have failed.

So: **a redaction change is not verified by running the corpus.** It is
verified by a fixture in `tests/Unit/Extraction/RedactorTest.php` built for the
specific failure, and by running that fixture against the commit that has the
defect. Adding non-ASCII punctuation and repeated identifiers to these
contracts would make them more realistic and is worth doing — but it would not
change this rule, because the next redaction defect will have a shape nobody
put in a fixture either.

## One more thing to distrust

The ground truth was written beside the text, by the same hand, in the same
sitting. `check-corpus.php` catches a ground truth that contradicts its own
document — a deadline before acceptance, a critical flag on the wrong label, a
calendar date that appears nowhere on the page. It cannot catch the two of them
being wrong together in the same way, which is what "hand-checked" is supposed
to mean and is exactly what a second reader would have provided.

When a real contract is added, it arrives with that problem solved, because
somebody else wrote it.
