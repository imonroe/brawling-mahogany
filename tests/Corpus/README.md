# The contract corpus

Twenty Colorado residential purchase contracts with hand-checked ground truth,
built for [#14](https://github.com/imonroe/brawling-mahogany/issues/14) so that
[#118](https://github.com/imonroe/brawling-mahogany/issues/118)'s regression
harness has something to score against.

PRD §14.1 Q5 is the question this exists to answer — *"How accurate is contract
extraction actually? Build a hand-checked corpus of 20 real Colorado contracts
and measure before committing"* — and it is worth saying at the top that this
corpus does **not** answer it. These contracts are synthetic. Read
[LIMITATIONS.md](LIMITATIONS.md) before quoting a number off them; it is short,
and it is the more important of the two files.

## Layout

Every contract is two files in [`contracts/`](contracts):

| File | What it holds |
|---|---|
| `<slug>.txt` | The contract's text — the words a PDF yields, not the PDF |
| `<slug>.json` | The ground truth: the dates, which of them are critical, the provisions, the traits |

Plus [`contracts/index.json`](contracts/index.json), a manifest of all twenty,
and [`check-corpus.php`](check-corpus.php), which writes the manifest and
refuses the corpus when a fixture and its ground truth have drifted apart.

## Ground truth

```json
{
  "slug": "0001-golden-native",
  "description": "A clean native-PDF listing-side contract with every standard deadline present.",
  "traits": ["native", "complete", "money"],
  "dates": [
    { "label": "Mutual Acceptance", "value": "2026-03-14", "critical": false },
    { "label": "Inspection Objection Deadline", "value": "2026-03-28", "critical": true }
  ],
  "provisions": [
    "Seller to convey the washer and dryer with the property."
  ]
}
```

- **`slug`** matches the file name. `check-corpus.php` refuses a mismatch,
  because a ground truth scored against the wrong text produces a number rather
  than an error.
- **`description`** is written for the person reading a failed score at 11pm.
  It says what is awkward about this fixture and why it is in the corpus.
- **`traits`** are the tags the harness filters on. See below.
- **`dates[].label`** is the canonical name of the deadline, from the closed
  list below. **`value`** is ISO `YYYY-MM-DD`. **`critical`** marks the five
  PRD §12.3 gives **zero tolerance** on missing.
- **`provisions`** is a plain-language summary of each additional-provision
  clause, not a verbatim quote. F10.1 captures provisions *as notes*, so the
  thing worth scoring is whether the substance survived, not whether the
  wording was copied.
- **`identifiers`** appears only on the three fixtures carrying a financial
  identifier. See [Identifiers](#identifiers).

### Why `.txt` and not `.pdf`

Because a PDF's bytes are never what the model sees. The pipeline reads a
document's *words* with `App\Support\Documents\ReadableText` and sends those;
`App\Support\Extraction\Redaction\RedactedDocument` is the only thing an
`ExtractionProvider` accepts, and it holds text. Its own docblock states the
consequence plainly: *"the pipeline reads the words out with `ReadableText` and
sends those, which has the useful property that what the provider receives is
exactly what is recorded on the `extractions` row."*

So the unit this corpus is made of is the text a PDF yields. Storing that
directly keeps the fixtures readable, diffable, and correctable by hand — which
matters, because a ground truth nobody can edit by hand is not hand-checked.

**What this does not cover, stated rather than implied:**

1. **The text-extraction step itself.** Nothing here exercises
   `ReadableText::from()`. A PDF whose text layer this application cannot
   inflate is unreadable *before* any of this begins, and the corpus starts one
   step downstream of that failure.
2. **Genuinely scanned contracts.** PRD assumption **A10** — *"A vision-capable
   LLM can read a Colorado contract reliably. Unverified. Blocks slice 5"* — is
   about exactly the document this corpus cannot contain. `ReadableText`
   returns **null** for a PDF that is only images; there is no OCR in this
   product. The `scanned` trait simulates what OCR *output* looks like. It does
   not test OCR, and a good score on those eight fixtures is not evidence about
   a photographed contract.

## Canonical labels

A closed list. The failure it prevents is silent rather than loud: two fixtures
spelling one deadline two ways still score, and score against different things,
so the corpus stops being comparable without anything going red. Adding a label
means editing `CANONICAL_LABELS` in `check-corpus.php` **and** this table.

| Label | Critical | Notes |
|---|---|---|
| Mutual Acceptance | | MEC. Every offset in a document resolves against this |
| Alternative Earnest Money Deadline | | |
| Due Diligence Documents Delivery Deadline | | Association documents; not on every contract |
| Record Title Deadline | | Delivery, not objection |
| Off-Record Title Deadline | | |
| **Record Title Objection Deadline** | **yes** | PRD §12.3's *title objection* |
| Title Resolution Deadline | | Downstream of an objection that already happened |
| New ILC or New Survey Deadline | | |
| New ILC or New Survey Objection Deadline | | |
| New Loan Application Deadline | | |
| **New Loan Terms Deadline** | **yes** | PRD §12.3's *financing/loan objection* |
| New Loan Availability Deadline | | |
| **Appraisal Deadline** | **yes** | |
| Appraisal Resolution Deadline | | |
| **Inspection Objection Deadline** | **yes** | |
| Inspection Termination Deadline | | |
| Inspection Resolution Deadline | | |
| Property Insurance Objection Deadline | | |
| Security Deposit Deadline | | Rental placement only |
| Lease Commencement Date | | Rental placement only |
| Move-In Inspection Deadline | | Rental placement only |
| Lease Expiration Date | | Rental placement only |
| **Closing Date** | **yes** | |
| Possession Date | | |

The five critical labels are the five PRD §12.3 names. The PRD names them as
concepts and this table names them as fields, so the mapping is written down
here rather than assumed: *title objection* is the **record** title objection
(the resolution deadlines are downstream of an objection that has already
happened, and are not the deadline a missed date costs you); *financing/loan
objection* is the **New Loan Terms Deadline**, which is the row the current CTM
form heads that way.

`check-corpus.php` asserts the flag from the label rather than trusting what is
written in the file. A critical date silently marked `false` is the one defect
in a ground truth that makes a *good* score out of a bad extraction.

### The document may not use these words

That is the point. Three fixtures word a deadline the way a brokerage does and
the ground truth still records the canonical label, which asks whether
extraction **resolves** a synonym or reports a deadline the product has no
field for:

| Fixture | What the document says | Canonical label |
|---|---|---|
| `0004-springs-va-loan` | Notice of Value Deadline (Appraisal) | Appraisal Deadline |
| `0012-pueblo-fha` | Loan Objection Deadline | New Loan Terms Deadline |
| `0012-pueblo-fha` | Loan Application Deadline | New Loan Application Deadline |

### What is deliberately *not* a date

- **A time of day.** Possession Time is on almost every contract and is never a
  `dates[]` entry, because `key_dates` stores a **day** (#106: *"the day is
  stored, not computed on read"*) and the reminder sweep asks for the dates
  seven days out. A contract that says *Possession Time: 12:00 Noon* has
  nothing here to record it against, on purpose.
- **A date that is not a deadline.** Several fixtures contain dates that must
  **not** end up in a contingency calendar: the date a family trust was
  executed (`0003`), the date of an inspection report referenced in an
  additional provision (`0008`), the date on an improvement location
  certificate (`0010`). They are distractors, and a harness scoring precision
  should treat an extraction that reports them as wrong rather than as extra
  credit.

## Traits

`traits` is a free list; these are the ones in use. Every fixture carries
exactly one of `native`/`scanned` and exactly one of `complete`/`sparse`, which
`check-corpus.php` enforces.

| Trait | Count | Meaning |
|---|---|---|
| `native` | 12 | Clean text, as a native PDF yields it |
| `scanned` | 8 | OCR-style noise: `l` for `1`, `rn` for `m`, broken words, lost punctuation, uneven spacing |
| `complete` | 17 | The full deadline table |
| `sparse` | 3 | A cash deal or a rental placement, with whole sections legitimately absent |
| `derived-only` | 3 | Deadlines written as offsets from MEC rather than as calendar dates |
| `two-date-formats` | 2 | The table is numeric and the prose is long-form, for the same days |
| `ambiguous-dates` | 2 | Deliberately awkward: a stated weekday that contradicts its own date, a deadline written in words, a deadline stated in two places |
| `handwritten-amendment` | 2 | A later writing changes a date the document already states |
| `amended` | 2 | The ground truth records the amended date, not the printed one |
| `has-identifiers` | 3 | Carries a financial or identity identifier the redactor is meant to take |
| `money` | 6 | Carries a purchase price, an earnest money amount and a loan amount |
| `synonym-wording` | 2 | A deadline headed with wording other than the canonical label |
| `rental` | 1 | A rental placement; no purchase-contract deadline at all |

Two of these are worth expanding on, because they are the ones most likely to
be scored wrongly rather than read wrongly:

- **`derived-only`.** The ground truth records the **resolved calendar date**
  and the document does not contain it. An offset read as a literal produces a
  key date of *"10"*, and a contingency calendar nobody can use. In
  `0014-scanned-westminster-offsets` and
  `0020-scanned-greeley-offsets-ambiguous` not even the Closing Date is written
  as a day: the only calendar date on the page is MEC.
- **`sparse` and `criticalCount`.** `0006` and `0019` are cash purchases with
  no financing and no appraisal, so two of the five critical deadlines are
  legitimately absent. `0007` is a rental placement whose `criticalCount` is
  **zero**. A harness that assumes five critical dates per document will report
  a hundred percent miss rate against a document that has none.

## Identifiers

Exactly three fixtures carry a plausible-looking identifier that
`App\Support\Extraction\Redaction\Redactor` is meant to strip before anything
leaves. Each declares which rule should catch it, using the rule's own key:

| Fixture | `identifiers` | What is on the page |
|---|---|---|
| `0009-arvada-identifiers` | `routing_number`, `account_number` | Earnest money wire instructions, labelled, native text |
| `0017-scanned-identifiers-wire` | `routing_number`, `account_number` | The same, read back through OCR noise, with the label a line away from the digits |
| `0018-scanned-ssn-disclosure` | `social_security_number` | A labelled SSN on an attached seller certification |

Every number is obviously fake. The count is fixed at three rather than
declared a minimum, so a test asserting a corpus-wide redaction count is a
number somebody reads rather than a number somebody updates to match.

`identifiers` records what **should** be caught. It is not a transcript of what
the redactor does today — see the next section, which is the part worth reading
twice.

## What running the redactor over this corpus found

The corpus was run through the real `Redactor` before it was committed. It
takes all three declared identifiers, and it damages no date and no dollar
figure anywhere in the twenty — which is the claim #114 actually makes:
*"Redaction cannot destroy the dates. A redactor that masks a purchase price or
a deadline has broken the feature."*

It also masks three things nobody declared, and they are recorded here rather
than quietly designed around, because a corpus that avoids the thing it just
found protects nothing:

1. **`tin` matches inside ordinary English words.** `Redactor::LABELS` lists
   `tin` under `government_id` for *taxpayer identification number*, and it is
   matched as a bare substring — so it hits inside `ligh**tin**g`,
   `lis**tin**g`, `exis**tin**g`. In `0013-scanned-aurora` the inclusions line
   *"All attached lighting and plurnbing fixtures"* sits within the 48-character label
   window of the county schedule number, and the schedule number is masked as a
   government ID. Inclusions clauses and brokerage lines are on every contract
   in Colorado.
2. **A parcel number can pass Luhn.** In `0004-springs-va-loan` the schedule
   number `6318-04-031-0310` is thirteen digits and passes the checksum, so the
   card-number rule takes it — and that rule is the one deliberately allowed to
   fire with no label. `Redactor`'s own docblock argues that a bare digit run is
   *"a routing number, a parcel number, an MLS reference or a phone number with
   the punctuation lost"* and that the words beside it are what tell them apart;
   for a thirteen-digit parcel number, nothing asks.
3. **A label claims a number two lines down.** In `0009-arvada-identifiers` the
   escrow file reference `2026-08814` is inside the window of the *Account
   Number* label above it and is masked as an account number. Harmless here,
   and the same mechanism is what makes 1 and 2 reach as far as they do.

None of this is redaction *failing open*, which is the direction that would be
serious. It is the other direction, and #114 names the cost: a document arrives
at the model with a field of it deleted, and nobody is told which. Worth a look
before slice 5's numbers are believed.

## `index.json`

An array plus totals, so the harness can enumerate without globbing and a
missing fixture is a visible diff rather than a quietly smaller denominator:

```json
{
  "contracts": [
    { "slug": "0001-golden-native", "traits": ["native", "complete", "money"], "dateCount": 16, "criticalCount": 5 }
  ],
  "totals": { "contracts": 20, "dates": 294, "criticalDates": 91 }
}
```

It is **generated**, never edited. `php tests/Corpus/check-corpus.php --write`
rewrites it from the ground truth on disk; without `--write` the script fails
when the two disagree.

## The tool

```
php tests/Corpus/check-corpus.php            # validate, exit 1 on failure
php tests/Corpus/check-corpus.php --write    # rewrite index.json, then validate
```

Plain PHP with no framework, because the corpus is edited by hand and has to be
checkable in an environment where `vendor/` is not installed.

It refuses a corpus where a label is off the canonical list, a critical flag
disagrees with PRD §12.3, a date is not a real day, a deadline falls before
Mutual Acceptance or after Closing, a `.txt` and a `.json` are not paired, the
`has-identifiers` trait and the `identifiers` key are not both present, a rule
key names something `Redactor` does not have, or the manifest has drifted. It
also asserts that every **critical** date is written on the page in some
recognised rendering unless the fixture is `derived-only` — an offset that
resolves to the wrong day is otherwise indistinguishable from one that
resolves to the right one, and it is the arithmetic, not the reading, that
would be wrong.

It **cannot generate a contract**, deliberately. A generator that owns the
corpus is a generator that cannot represent the twenty real contracts PRD
§14.1 Q5 asks for, and #14 does not close until those are in here beside the
synthetic ones.

## Adding a contract

1. Write `contracts/<nnnn>-<slug>.txt`. Several hundred words, with real
   structure: parties, property, price, a Dates and Deadlines table, a few
   additional provisions. Invented names, Colorado-plausible addresses, no real
   person.
2. Write `contracts/<nnnn>-<slug>.json` by hand, reading the deadlines off the
   text you just wrote — which is the step that makes it ground truth.
3. Run `php tests/Corpus/check-corpus.php --write`.
4. If the fixture is awkward, say so in `description`. A trait tells the
   harness what to filter on; the description tells the next person why the
   score is low.

A real contract goes in the same way, with the client's details replaced and
the `synthetic` claim in [LIMITATIONS.md](LIMITATIONS.md) narrowed to say so.
