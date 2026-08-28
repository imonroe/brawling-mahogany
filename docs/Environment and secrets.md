---
created: 2026-08-21
project: Goldieflow
type: reference
status: draft
version: 1.0
---

# Environment and secrets

> [!info] What this document is for
> Which configuration exists, where each secret lives, who can see it, and how
> it is rotated. `.env.example` is the complete list of keys; this document is
> the list of which of them are real in which environment.

`CLAUDE.md`: *"All environment variables and secrets should be stored in a
gitignored `.env` file in the root of the project. They should be passed into
the container which uses them transparently."*

---

## 1. The rules

1. **`.env` is gitignored and always will be.** `.env.example` is committed and
   complete: every key the application reads, with a safe placeholder.
2. **No secret is ever baked into an image.** Compose passes `.env` into the
   containers that need it; the Dockerfile copies a placeholder `.env` during
   the build purely so `artisan` can boot, and deletes it in the same layer.
3. **CI secrets come from GitHub Actions secrets**, never from a committed
   file. The CI job builds its own `.env` from `.env.example`.
4. **A new developer fills in one value.** Copy `.env.example` to `.env`, set
   `DB_PASSWORD`, run `make setup`. Everything else has a working default.
5. **Anything resembling a live key in the repository is an incident**, not a
   tidy-up. `git grep` for provider prefixes before every release.

---

## 2. What exists where

| Key | Local | CI | Staging | Production |
|---|---|---|---|---|
| `APP_KEY` | generated | generated per run | real, unique | real, unique |
| `DB_PASSWORD` | developer's choice | fixed, throwaway | real, unique | real, unique |
| `REDIS_PASSWORD` | none | none | real | real |
| `MAIL_*` | Mailpit, no credentials | array driver | **SES, every recipient redirected** | SES |
| `APP_NAME` | `Brawling Mahogany` | same | same | same |
| `APP_PRODUCT_NAME` | `Goldieflow` | same | same | same |
| `MAIL_FROM_ADDRESS` | anything | `.env.example`'s value — **used**, not unused | `goldieflow@monroedigitalconsulting.com` | `goldieflow@monroedigitalconsulting.com` |
| `MAIL_REDIRECT_TO` | optional | unset | **set — every message redirected** | **must be empty** |
| `SES_SNS_TOPIC_ARN` | empty | empty | the staging topic | the production topic — **required**, see below |
| `AWS_*` (Spaces) | unset — local disk | unset | real, staging bucket | real, production bucket |
| `SENTRY_LARAVEL_DSN` | unset | unset | real, staging project | real, production project |
| `AI_API_KEY` | unset | unset | **separate key, own budget cap** | real, production cap |
| `VAPID_*` | generated locally | unset | real | real |
| `HORIZON_AUTHORIZED_EMAILS` | developer's address | unset | ops addresses | ops addresses |
| `BUG_REPORT_ENABLED` / `BUG_REPORT_URL` | unset — no button | unset | real n8n form | real n8n form |

### `VAPID_*` is generated once and never rotated

Web push (#103). Three keys — `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`,
`VAPID_PRIVATE_KEY` — produced by `php artisan push:vapid-keys`, which prints
them rather than writing them, like `invitation:link` and `auth:reset-link`.

**This is the one secret in this document that must not be rotated on a
schedule.** The public half is baked into every subscription a browser has
already created, so replacing the pair does not re-key anything: it silently
invalidates every existing subscription, and every device has to be
re-subscribed by hand from Settings → Notifications. Nobody finds out until
they notice they have stopped being notified. Treat the pair like a domain
name, not like a password — and if the private key is ever *exposed*, rotating
it is still correct, but plan for the re-subscription rather than discovering
it.

`VAPID_SUBJECT` must be a `mailto:` or `https:` URL (RFC 8292); a push service
uses it to reach somebody about this application, and several reject a
malformed one at send time rather than at configuration time.

An environment with any of the three unset simply does not offer push:
`SendPush::configured()` is what S78 asks before drawing the switch and what
`Notify` asks before writing `push` into a notification's channels, so a
half-configured environment is not a state the product can reach.

### `APP_NAME` is not the product's name

Two keys, because one was doing two jobs. `APP_NAME` is slugged into the
session cookie name, the cache prefix, the Redis prefix and the Horizon prefix
(`config/session.php`, `config/cache.php`, `config/database.php`,
`config/horizon.php`), which makes it an **infrastructure identifier** — and the
one CLAUDE.md's rename note is explicit about leaving at the `Brawling Mahogany`
codename, because moving it orphans those keyspaces and signs everybody out.

`APP_PRODUCT_NAME` is what a person reads: the browser tab, an invitation, and
the *"via Goldieflow"* half of a client-facing `From` line. It is the only one
of the two that is safe to change, and changing it changes nothing but words.

**Both `config('app.name')` and `config('app.product_name')` resolve to it**,
and that is deliberate rather than redundant. Every infrastructure derivation
calls `env('APP_NAME')` **directly** — `config/session.php`, `config/cache.php`,
`config/database.php`, `config/horizon.php` — so the config key has only display
readers, and most of them are in vendor views this application cannot edit.
Fortify's password-reset email rendered the codename four times until it was
corrected. Application code should read `app.product_name`, because saying which
question you are asking is worth a few characters.

`VITE_APP_NAME` is a **build-time** value: Vite compiles it into the bundle, the
image builds with `cp .env.example .env`, and `.dockerignore` excludes `.env`.
Setting it in a runtime `.env` changes nothing, which is why the provisioning
script's managed block deliberately does not.

They were the same key until round 2 of review on #12 found teams sending
*"Bosart Group via Brawling Mahogany"* to their sellers. The rule that follows
from it: **before pinning a display string to an existing config value, check
what else derives from that value.** Here, four keyspaces did.

### The sending identity, what it is not, and what it now is

SES is configured and `monroedigitalconsulting.com` is a verified sending
domain (#12). Every message this product sends leaves as
`goldieflow@monroedigitalconsulting.com`, over SMTP.

**It is not a dedicated sending subdomain**, which is what PRD §8.5 asks for.
The product's reputation is therefore mixed with whatever else that domain
sends, and a deliverability problem in one is a deliverability problem in the
other. That is a deliberate trade to get Slice 3 sending — the alternative was
waiting on the naming decision in #15 — and it is worth revisiting before there
are enough customers for the reputation to be worth anything.

**It is production access**, confirmed 2026-08-28 (#12): the account is out of
the SES sandbox, and staging sends real mail through it.

That distinction was worth chasing, and is worth keeping written down for
whoever stands up the next environment. A verified *domain identity* and a
production *account* are two different grants: in the SES sandbox an account
may only send to addresses it has also verified, so a message to a real
client's inbox is rejected at the API rather than delivered. Both look
identical from inside this application right up to the moment a client is
supposed to receive something.

Two things follow from that, for any new environment:

- **Production access is granted per region.** An account can be out of the
  sandbox in one region and still in it in the region `MAIL_HOST` actually
  points at. Check the region you are sending from, not the account in general.
- **S91's alert (#97) is what makes the failure loud.** `automations:alert-on-failures`
  reads `state`, so a rejected send is `failed` however it got there rather than
  going quiet.

**A team's own address never goes in `From`.** It rides in `Reply-To`, which
needs no DNS and no verification. A `From` SES is not authorised to send as is
rejected at the API, and one the message is not DKIM-aligned with fails DMARC —
not SPF, which is evaluated against the envelope MAIL FROM rather than this
header. What a team *does* get in the inbox line is their **name**:
`App\Support\Mail\SendingIdentity` composes *"Bosart Group via Goldieflow"*
onto the verified address, so a client sees the agency they know beside an
address that would otherwise look forged — and the same class puts their
`sending_identity_email` in `Reply-To`, so hitting Reply reaches them and not
the product's mailbox.

### The bounce webhook, and why its ARN is a security control

SES publishes bounce and complaint notifications to an SNS topic, which posts
them to `POST /webhooks/ses` (#95). `SES_SNS_TOPIC_ARN` names that topic and is
**not optional in production** — the endpoint refuses everything while it is
empty, deliberately, because the other way round is the default that ships:
works in staging, unset in production, and nobody notices.

It is not merely configuration. The endpoint has no session, no CSRF token and
no authenticated person, so Amazon's signature over the canonical SNS string is
the whole of its authentication — and a valid signature only proves that *some*
SNS topic sent the message. Anybody with an AWS account can create a topic,
point it at this URL, and have its notifications signed exactly as genuinely as
ours. The ARN is what makes the check mean *our* topic.

The reason that matters more here than on an ordinary webhook is what the
endpoint writes to: `suppressed_addresses` is this product's one **account-wide**
table, so without the ARN check a stranger could stop this product writing to
any address they chose, for every team on the platform, permanently. See
[`adr/0002`](adr/0002-multi-tenancy-enforcement.md), "The deliberately
cross-tenant table".

Three checks, none of them optional: the signing certificate's host is matched
against an **anchored** `sns.<region>.amazonaws.com` pattern (an unanchored one
passes `https://evil.test/?x=amazonaws.com`), the signature is verified over
the canonical field-by-field string rather than over the raw body, and the ARN
is compared. Setting the topic up is console work like the rest of §2 — the
application half is done and the subscription confirms itself on the first
handshake.

Locally the value stays empty: nothing sends through SES, so nothing bounces.

### The two staging guardrails that are not optional

PRD §8.6, restated because both protect somebody else's client:

1. **Every recipient is redirected.** `MAIL_REDIRECT_TO` is set on staging, and
   `AppServiceProvider` rewrites every recipient to it. The application
   **refuses to boot in production** with that value set, so the guardrail
   cannot be left on by accident either. This is ours and it does not depend on
   SES's own sandbox — which is the point, since the same SES account now
   serves both environments.
2. **A separate AI provider key with its own budget cap.** Staging never
   spends against the production budget, and a runaway loop in a test costs a
   small, capped amount rather than a large, uncapped one.

### What that means for anything you have to *act on*

Both guardrails above make staging mail undeliverable on purpose, and local
mail never leaves the machine. [[adr/0003-no-email-only-flows|ADR 0003]] is the
rule that keeps this from being a dead end: **no user flow depends on email
alone**, so nothing in the product is unreachable because a message went to
Mailpit or was redirected to an ops mailbox.

In practice, for the two flows that exist today:

| Flow | Without the message |
|---|---|
| Team invitation | The invitee accepts it in-app if they are signed in as the invited address; the team owner issues the link from `/settings/members`; a platform operator issues it from the team's page in `/admin`; or `php artisan invitation:link <email>` prints one with no session at all |
| Password reset | `php artisan auth:reset-link <email>` prints a single-use link. It starts a reset without finishing one — only the account holder can set the password |

Both commands are audited (`invitation.link_issued`,
`auth.password_reset_link_issued`) and both prompt for confirmation in
production unless given `--force`. They are ordinary product features rather
than staging tools: a path that exists only in pre-production is a path nobody
tests and nobody reviews with production eyes.

### Bug reporting, which is configuration rather than a secret

`BUG_REPORT_URL` is the n8n form behind the top bar's **Report a bug** button
(#176); n8n turns each submission into a GitHub issue on this repository.
`BUG_REPORT_ENABLED` is whether the button appears.

Neither is a credential — the form is a public URL and nothing here
authenticates to it — so both live in `.env` beside everything else and neither
needs rotating. They are two keys rather than one so that the button can be
switched off during an n8n outage without losing the address.

Four things to know when it does not appear:

1. **It is signed-in only.** The URL is never in the page props of the sign-in
   screen, which is the one page the internet can reach.
2. **A URL that is not `http` or `https` is treated as unset.** An `iframe src`
   is not inert, and the same allowlist that guards `external_links` guards
   this (`App\Support\Links\SafeUrl`).
3. **A URL on an origin this application answers on is refused too.** The
   frame is sandboxed `allow-scripts allow-same-origin`, which a hosted form
   needs and which is not a sandbox at all against a same-origin document — it
   reaches `window.parent` and reads the session. Host **and port**: n8n on
   `localhost:5678` beside an app on `localhost:8000` is a different origin and
   is allowed; n8n proxied under the app's own hostname on the same port is
   not. Both `APP_URL` **and the host actually serving the request** are
   checked, because `APP_URL` is the value most likely to be stale — a guard
   against operator error that depends on the commonest adjacent operator error
   is not a guard.
4. **A misconfiguration is logged at most once an hour, per problem** — `The
   bug report button is switched on but has no usable form URL`, carrying a
   `reason_code`:

   | `reason_code` | What to do |
   |---|---|
   | `url_empty` | `BUG_REPORT_ENABLED` is on and `BUG_REPORT_URL` is unset |
   | `url_not_http` | The address is not `http://` or `https://` |
   | `url_own_origin` | The address is on a host and port this app answers on — give n8n its own hostname or its own port |

   `reason_code` rather than `reason`, because `App\Logging\Redactor` strips a
   key containing `reason` **unless its allowlist rescues it** — an override
   reason is free text that quotes clients — and a diagnostic that reaches the
   log as `[redacted]` is silence with extra steps. `ALLOWED_KEY_PATTERNS`'
   `_code$` is what rescues this one, which is why the suffix is load-bearing
   rather than decorative. Hiding the button rather than raising is deliberate: a
   bug-report form is not worth a white screen. The cooldown is in the cache
   rather than in a static, because this runs on every authenticated request
   and PHP tears a static down at each request boundary — a per-process latch
   would have written a line per page view for as long as the mistake stood.

`BUG_REPORT_ENABLED` is read with `filter_var`, not a cast, so `false`, `off`,
`no` and `0` all switch it off — as does **anything else not recognised as
true**, silently and with no log line, because nothing is wrong with a switch
that is off. `BUG_REPORT_ENABLED=enabled` therefore turns it off; write `true`.
Failing towards off is the safe end. `env()` converts only four spellings and
leaves the rest as strings, and `(bool) 'off'` is `true` — which would fail
open on the one setting somebody changes in a hurry during the outage it exists
for.

Local and CI leave both unset, which is why a developer never sees the button
unless they go looking for it.

> [!warning] A report is published the moment it is filed
> `imonroe/brawling-mahogany` is a **public** repository, so every submission
> becomes a public issue. Nothing in the pipeline redacts, and the tracker is
> outside both the 30-day purge and the audit log. The dialog and
> `resources/help/finding-your-way.md` both warn against putting client details
> in a report; that warning is the whole of the mitigation. Making the tracker
> private, or adding a redaction step to the n8n flow, is the real fix. See
> PRD §10.

---

## 3. Where secrets are held

| Environment | Held in | Who can read it |
|---|---|---|
| Local | `.env` on the developer's machine | that developer |
| CI | GitHub Actions repository secrets | repository admins |
| Staging | `.env` on the droplet, `0600`, owned by the deploy user | droplet admins, and anyone holding `STAGING_SSH_KEY` — see below |
| Production | `.env` on the droplet, `0600`, owned by the deploy user | droplet admins |

The deploy workflow never prints an environment value, and no workflow echoes a
variable that could hold one.

### The deploy secrets, which are not application secrets

`scripts/provision-staging.sh` generates the key and prints the values; you add
them by hand. They live as GitHub Actions repository secrets, not in any `.env`:

| Secret | What it is |
|---|---|
| `STAGING_SSH_HOST` | the droplet's hostname |
| `STAGING_SSH_USER` | the deploy user (`deploy`) |
| `STAGING_SSH_KEY` | that user's **unpassphrased** private key |
| `STAGING_SSH_PORT` | optional; the deploy workflow defaults to 22 |
| `STAGING_PATH` | the checkout path on the droplet |
| `STAGING_URL` | the base URL, used by the smoke check |

Two more are repository **variables** rather than secrets, which is a different
store in the same settings screen and easy to confuse:

| Variable | What it does |
|---|---|
| `STAGING_ENABLED` | set to `true` to take the deploy workflow out of its inert state |
| `UPTIME_STAGING_URL` | the URL the uptime workflow polls |

Setting either as a *secret* reads as empty and fails silently — the deploy
job skips, or the uptime job passes while checking nothing.

**`STAGING_SSH_KEY` is effectively root on staging, and should be treated as
the highest-value secret in the repository.** The `deploy` user is in the
`docker` group, which is root-equivalent by design — anyone who can run
`docker` can mount the host filesystem — and this key grants shell access as
that user without a passphrase. Whoever holds it can read the staging `.env`,
and therefore every application secret on the box.

Two consequences worth stating rather than assuming:

- It is a *deploy* credential, never a developer convenience. Do not copy it to
  a laptop, and do not reuse it for a second host.
- The provisioning script prints it once, on the run that generates it, and
  `--print-key` re-prints it on demand. Clear the scrolled buffer afterwards:
  the private half needs to exist in exactly two places, the droplet and the
  GitHub secret.

These do not appear in `.env.example`, because nothing in the application reads
them — they belong to the pipeline, not the product. That is the one sanctioned
exception to §1's rule that every key the project uses is listed there.

**There is no production equivalent yet.** No `PRODUCTION_*` secret is
configured and no production deploy workflow exists; §6 of `Deployment` is
about staging. When production is stood up it needs its own key, never a copy
of this one — a single key across both environments means a staging compromise
is a production compromise.

---

## 4. Rotation

Rotate on a schedule, and immediately on any suspicion of exposure — a laptop
lost, a contractor leaving, a secret pasted into the wrong window.

### `APP_KEY`

The sharpest one, because it decrypts every `encrypted` column (ADR 0001):
provider keys, iCal feed tokens, two-factor secrets.

1. Put the application into maintenance mode.
2. Add the current key to `APP_PREVIOUS_KEYS` and set the new `APP_KEY`.
3. Re-encrypt the affected columns with a one-off command that reads with the
   old key and writes with the new one.
4. Verify a decrypt of each affected column type, then drop the old key from
   `APP_PREVIOUS_KEYS`.

Rotating without step 3 makes every encrypted column unreadable. There is no
recovery from that beyond a restore.

### Database password

1. `ALTER ROLE … WITH PASSWORD` on the database.
2. Update `.env` on the droplet.
3. `docker compose up -d` to restart the app, worker, and scheduler together —
   all three read the same file, and a worker left on the old password fails
   silently into the retry queue.

### `STAGING_SSH_KEY`

Cheap to rotate and worth doing on any staff change, because it is the one
credential that grants shell on the box:

Order matters here. The old key is what the **deploy workflow** authenticates
with — you reach the droplet as an admin by your own means — so it is retired
**last**, after the new one has been proved, and there is a window where both
work. Do not reverse these.

1. Generate the new pair **alongside** the old one, as `deploy`:
   `ssh-keygen -t ed25519 -N '' -f /home/deploy/.ssh/id_ed25519.new`
2. **Append** the new public key to `/home/deploy/.ssh/authorized_keys`. Both
   keys work now, which is the point.
3. Update the `STAGING_SSH_KEY` repository secret with the new private half
   (`id_ed25519.new`).
4. Run the deploy workflow and confirm it **actually ran** — if
   `STAGING_ENABLED` is not `true` the job skips, and a skipped job is green.
   Check the log shows an SSH connection, not a skip. (Until
   `deploy-staging.yml` has reached `main`, GitHub offers no manual trigger for
   it, so this means a push to `dev` rather than a `workflow_dispatch`.)
5. Only now retire the old key: remove its line from `authorized_keys`. Leave
   the file `0600` and owned by `deploy`, or `sshd` ignores it and the deploy
   stops working for a reason that looks nothing like a permissions problem.
6. Move the new pair into place so the droplet's own copy matches the secret —
   both halves, or `--print-key` prints the retired one:
   `mv id_ed25519.new id_ed25519 && mv id_ed25519.new.pub id_ed25519.pub`
7. Re-run the deploy workflow to confirm the new key still works after the
   move. This does not prove the old key is gone — a run authenticating with
   the new one cannot show that. If you need that proof, try the old key
   directly and expect a refusal.

There is no re-encryption step and nothing to migrate, so unlike `APP_KEY` this
one has no reason to wait for a scheduled window.

### SES, Spaces, Sentry, AI provider

Create the new credential alongside the old one, update `.env`, restart,
confirm traffic on the new credential, then revoke the old one. Never revoke
first: a revoked SES credential means client email stops, and client email
stopping is the failure this product exists to prevent.

### After any rotation

Record what was rotated and when. The audit log covers application events, not
infrastructure ones, so this is a written note until there is somewhere better
to put it.

---

## 5. Compose and the environment

`.env` carries a few keys that configure the stack rather than the
application:

| Key | Purpose |
|---|---|
| `COMPOSE_FILE` | `compose.local.yaml` locally, so `docker compose up` layers the dev overrides on the shared base |
| `APP_PORT`, `VITE_PORT`, `MAIL_UI_PORT` | Host ports, so two projects can run at once |
| `DB_PORT_HOST`, `REDIS_PORT_HOST` | Host ports for Postgres and Redis, for `php artisan test` on the host |
| `APP_BUILD_TARGET`, `APP_IMAGE` | Which Dockerfile target the base file builds |
| `APP_UID`, `APP_GID` | Who the containers run as, in every environment — nothing runs as root. Locally the working tree is bind-mounted, so this owns anything they write into the repository. Baked into the image: change it and rebuild, and on a live host see [Deployment §3](Deployment.md) first |

---

## Related

- [ADR 0001 — Data and persistence conventions](adr/0001-data-and-persistence-conventions.md), for the `encrypted` cast rules
- [Deployment](Deployment.md), for what a deploy does with these values
- PRD §8.6 Environments, §9 Non-functional requirements
