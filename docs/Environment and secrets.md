---
created: 2026-08-21
project: Brawling Mahogany
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
| `MAIL_*` | Mailpit, no credentials | array driver | **SES sandbox** | SES production |
| `MAIL_REDIRECT_TO` | optional | unset | **set — every message redirected** | **must be empty** |
| `AWS_*` (Spaces) | unset — local disk | unset | real, staging bucket | real, production bucket |
| `SENTRY_LARAVEL_DSN` | unset | unset | real, staging project | real, production project |
| `AI_API_KEY` | unset | unset | **separate key, own budget cap** | real, production cap |
| `VAPID_*` | generated locally | unset | real | real |
| `HORIZON_AUTHORIZED_EMAILS` | developer's address | unset | ops addresses | ops addresses |

### The two staging guardrails that are not optional

PRD §8.6, restated because both protect somebody else's client:

1. **SES runs in sandbox mode with all mail redirected.** `MAIL_REDIRECT_TO` is
   set on staging, and `AppServiceProvider` rewrites every recipient to it. The
   application **refuses to boot in production** with that value set, so the
   guardrail cannot be left on by accident either.
2. **A separate AI provider key with its own budget cap.** Staging never
   spends against the production budget, and a runaway loop in a test costs a
   small, capped amount rather than a large, uncapped one.

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

---

## Related

- [ADR 0001 — Data and persistence conventions](adr/0001-data-and-persistence-conventions.md), for the `encrypted` cast rules
- [Deployment](Deployment.md), for what a deploy does with these values
- PRD §8.6 Environments, §9 Non-functional requirements
