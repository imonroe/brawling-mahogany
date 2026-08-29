---
created: 2026-08-22
project: Goldieflow
type: adr
status: accepted
---

# ADR 0003 — No user flow depends on email alone

**Status:** Accepted · **Sources:** PRD §4.1 F1.3, §5.1, §9 · Screen Inventory S03, S04, S09, S74, S83, S90 · `CLAUDE.md`

## Context

Slice 1 shipped one flow that could only be started and only be answered by
email: the team invitation. It is also the first flow every customer meets, and
the one PRD §5.1 step 1 makes the whole of onboarding — *"Ian provisions a team
and invites the owner."*

That worked in exactly one environment. Everywhere else it failed silently:

- **A fresh local install has no mail transport.** The stack ships Mailpit, but
  it has to be running and somebody has to know to look at
  `http://localhost:8025`. Anybody working outside the container, or with the
  mail service down, gets an invitation with no delivery and no error.
- **Staging is required to redirect every message** — and when the variable is
  unset it simply does not, silently (#196, added 2026-08-28 once SES
  production access removed the sandbox that used to back this up).
  `MAIL_REDIRECT_TO` is a deliberate guardrail (`docs/Environment and
  secrets.md`), and **when it is set** it means a staging invitation never
  reaches the address it names.
- **Production is not immune either.** Relays drop messages, spam filters
  swallow them, shared mailboxes go unread, and people typo their own address.

The product had no answer to any of those, and could not have one, because the
invitation link exists only as a SHA-256 hash. Nothing could resend it, nothing
could display it, and no screen anywhere admitted that an invitation existed.

The dead end this produced is exact and reproducible: register, promote
yourself with `platform:promote`, open `/admin`, provision a team, invite
yourself as its owner — and then sit on `/no-team` forever, holding every
privilege in the system and unable to join the team you just created.

The same shape applies to a forgotten password (S03), and it will apply to
every Slice 5 flow that reaches a client: status page magic links, automated
messages, and Keep in Touch.

## Decision

**No user flow may depend on email alone to be initiated or to be answered.**

Every flow the product starts by email must have a second way in that does not
involve email. The second way need not be equally convenient, and often should
not be — but it must exist, be documented, and be covered by a test.

Three shapes satisfy the rule, in order of preference:

1. **The recipient answers it in the application.** Best, because it needs
   nobody's help. Available whenever the recipient already has an account and
   the message is addressed to that account's sign-in address.
2. **Somebody who already controls the flow hands the artifact over.** The
   inviter can copy an invitation link and deliver it however they like. This
   adds no privilege — they could already revoke and re-invite — and it is what
   makes a bounced message recoverable in production.
3. **An operator with shell access issues it from the console.** The floor,
   for the install with no screens yet and nobody who can open one. The bar is
   shell access on the server, which is the same bar as reading the database
   directly, and unlike editing a row by hand it leaves an audit entry.

**A second door is not a weaker door.** Each one is authorised on its own
terms, audited the same way, and — the case worth stating — never a way to
*create* credentials. An in-app claim cannot set a password or create an
account, and what it *can* do is bounded by the invitation: a membership, and
the one role the invitation names.

That second half took two review rounds to make true. `syncWithoutDetaching`
plus a cleared `revoked_at` quietly handed back every role a revoked
membership was still carrying — so revoking an owner and re-inviting them as a
member returned an owner. Reviving a revoked membership now `sync()`s to the
invited role instead. **A sentence in an ADR is not a property of the code**,
and this one was cited as the justification for offering the door at all.

### What this is not

It is not a pre-production-only affordance, and it deliberately avoids that
shape. A path that exists only in staging is a path nobody tests, nobody
audits, and nobody reviews with production eyes; the first time it is needed in
production it either does not exist or is reached for in a hurry. Every door
below ships in every environment, on the same code path, with the same audit
trail.

### As built

| Flow | Sends | Second door |
|---|---|---|
| Accept a team invitation (S04, S90) | `TeamInvitationMail` | The invitee accepts in-app from S09 or the shell banner (`invitations.claim`); the team owner issues the link from S74; the platform operator issues it from S83; `php artisan invitation:link` issues one with no session at all |
| Reset a forgotten password (S03) | Fortify's reset notification | `php artisan auth:reset-link` |
| Team data export ready (S79) | nothing — never emailed | Already in-app: the signed, expiring download appears on the export screen |
| Email verification (S05) | nothing — `Person` is not a `MustVerifyEmail`, so the enabled Fortify feature sends no message | None needed while that holds, and `EmailIndependenceTest` fails the day it stops |

### Why an in-app claim is not weaker than the emailed link

The instinct is that a token proves something a session does not. Follow what
the emailed token actually does when the invited address already has an
account: `AcceptInvitation` attaches the membership **to that account** and
explicitly refuses to sign anybody in. The link was never a way *into* an
account — it is a way of attaching a membership to whichever account holds the
invited address.

An in-app claim does exactly that, for somebody who has already proved they
hold the address by signing in with it. It is in fact the weaker of the two: it
cannot set a password and it cannot create an account, which the emailed link
can and must. A claim on an invitation for any other address is a **404**, not
a 403, so ids cannot be walked to learn which invitations are live.

**The residual risk, stated plainly.** Registration does not verify the address
— `Person` is not a `MustVerifyEmail` — so somebody can register as an address
they do not control. If a team then invites that address, the squatter holds
the account it resolves to. That is true *today*, without this change: the
honest invitee clicks the emailed link, `AcceptInvitation` finds the squatter's
account, attaches the membership to it, and tells the real person to sign in
with a password they never set. The claim removes the step where the victim has
to click; it does not create the exposure. The exposure is unverified
registration, it predates this decision, and closing it is filed below as *not
decided here* precisely because the obvious fix — turning on email verification
— is itself an email-only flow and would need its own second door.

### Why issuing a link rotates the token

Only the hash is stored, on purpose (`TeamInvitation`), so there is nothing to
read back — issuing a link mints a new one and the previous link stops working.
That is a real cost, and every screen that offers it says so before the click.
The alternative is keeping a recoverable credential in the database, which is
the exact thing the hash exists to prevent. The expiry is not extended either:
an invitation with two days left issues a link with two days on it, so "revoke
and send a new one" stays a decision rather than a formality.

### Why password reset has no screen

A page that mints reset links for other accounts is an account-takeover button
however carefully it is gated. The second door there is the console, and the
command starts a reset without finishing one: it mints the same single-use,
expiring token through the same broker, so only the account holder can complete
it.

## Consequences

- **Every new mailable is a design decision, at the moment it is cheapest.**
  `App\Support\Mail\EmailIndependence::FLOWS` catalogues each email-initiated
  flow with the route or command that is its second door, and
  `tests/Unit/EmailIndependenceTest.php` fails the build when a mailable — or a
  mail-sending Fortify feature — is not listed, or names an alternative that
  does not resolve against the real route table and artisan registry.
- **Slice 5 inherits it.** Status page magic links, automated client messages,
  and Keep in Touch all reach people by email. Each needs its second door
  designed with the feature, not retrofitted. For the status page that will
  most likely be a link the agent can copy from the deal; the catalogue is
  where that decision gets recorded.
- **A little more surface to audit.** Three new actions:
  `invitation.link_issued`, `auth.password_reset_link_issued`, and
  `membership.roles_replaced`. All three are permission or credential events
  under PRD §9. The first two were foreseeable from the features that needed
  them. The third was not: it exists because the review found that reviving a
  revoked membership deletes roles and recorded nothing about it.
- **One extra query per authenticated request.** The pending-invitation list is
  a shared Inertia prop, because the shell renders a banner from it and
  somebody who has just been invited does not know where to look. It is empty
  for almost every request.

  It needed an index to be one *lookup* rather than one *scan*, and the first
  cut of this ADR claimed one that did not exist. `team_invitations` had
  `(team_id, email)` and a partial unique on `(team_id, lower(email))`, both
  leading with `team_id` — and this query has no `team_id` by construction,
  which is the whole point. `team_invitations_pending_email`, partial over the
  three predicates a partial index can hold — deleted, accepted, revoked — is
  what makes the sentence true. Not `scopePending()`'s fourth: `now()` is not
  immutable, so expired invitations stay in the index and the scope filters
  them out after it has found them.

- **Not during impersonation.** A support session acts with the customer's
  permissions so an administrator can see what they see. Joining another team
  on the customer's behalf is not seeing, and the audit entry would carry the
  customer's name — so the banner is suppressed and the claim endpoint refuses,
  because hiding a button whose endpoint still answers is not a control.
- **Mailpit stops being load-bearing for local development.** It is still the
  right way to see what a message looks like; it is no longer the only way to
  finish setting up an environment.

## What the adversarial review caught

Recorded because both were in the code this decision added, and both were
invisible to a suite that passed:

- **Spending the invitation outside its team threw `CrossTenantException`.**
  `AcceptInvitation` attached the membership inside `runFor()` and then marked
  the invitation accepted outside it. `TeamInvitation` is `BelongsToTeam`, so
  its `updating` guard compares the resolved team against the row's — and
  `ResolveCurrentTeam` is global middleware, so anybody already on a team has
  one resolved. The result was a 500 for exactly the population the shell
  banner exists to serve: somebody in team A, invited to team B. Every test
  missed it because every test cleared the context first and claimed as
  somebody with no membership anywhere.

  ADR 0002's *"the hole the layers do not cover"* has a sibling here: **the
  layers also catch you when you are right about the data and wrong about the
  context.** `ProfileController` documents the identical mistake, and cited
  `AcceptInvitation` as the example of doing it correctly. It was not.

- **A claim overwrote the name the team had recorded.** The accept screen
  requires the invitee to type their name, so it wins. A claim types nothing,
  so its name is the invitation's or the address before the @ — and letting
  that win turned *Heather Cole* into *heather Cole* on the ordinary
  revoke-then-re-invite path. Silent, on the one field #140 moved onto the
  membership so the team would own it. `attachMembership` now takes
  `nameIsAuthoritative`, and only a typed name is.

## Not decided here

- **Whether email verification should become real.** `Person` does not
  implement `MustVerifyEmail`, so the enabled Fortify feature sends nothing and
  `verified` passes for everybody. Turning it on is a separate decision with
  its own bootstrap problem, and this ADR only guarantees that the day it is
  turned on, the catalogue test asks for the second door.
- **A second channel, as opposed to a second door.** SMS or push would satisfy
  the rule differently, by making the *message* redundant rather than the
  *response*. PRD §4.12 has push notifications in scope for a later slice; this
  decision does not pre-empt how that interacts.
- **Whether the invitation link should be visible without rotating.** It would
  require storing something recoverable. Revisit only alongside a decision to
  encrypt rather than hash it, which is a different security posture and
  belongs in its own ADR.
