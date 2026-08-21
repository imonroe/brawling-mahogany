---
created: 2026-08-21
project: Brawling Mahogany
type: reference
status: draft
version: 1.0
---

# Deployment

> [!info] What this document is for
> How the same container stack reaches staging and production, what a deploy
> actually does, and how a restore is performed. Written as a runbook: somebody
> should be able to follow it at 2am.

> [!warning] What is written here and what exists
> The pipeline, the compose stack, and the runbook below are built and
> reviewable. **The staging droplet itself is not provisioned** — that needs an
> account, a domain, and DNS, none of which live in this repository. The deploy
> workflow is inert until the repository variable `STAGING_ENABLED` is set to
> `true`, so nothing fails while the infrastructure catches up. Section 6 is
> the checklist for standing it up.

---

## 1. The shape of it

| Environment | Runs from | Deploys on | Data |
|---|---|---|---|
| Local | `compose.local.yaml` (includes `compose.yaml`) | — | Throwaway |
| CI | `compose.yaml` + `compose.ci.yaml` | Every pull request | Throwaway, tmpfs |
| Staging | `compose.yaml` | Every merge to `dev` | Realistic, never real client data |
| Production | `compose.yaml` | A tagged release from `main` | Real |

One base file describes the stack everywhere (`CLAUDE.md`). Staging exists to
be production-shaped: same image, same Postgres version, same Redis, same
worker and scheduler containers.

Branching, from `CLAUDE.md`: `main` is for tagged releases only; feature
branches target `dev`; `dev` → `main` by pull request when a release is cut.
**Production deploys from a tag, never from a branch.**

---

## 2. What a deploy does

`.github/workflows/deploy-staging.yml`, on every merge to `dev`:

1. Fetch and hard-reset the droplet's checkout to `origin/dev`.
2. `docker compose -f compose.yaml build`.
3. `docker compose -f compose.yaml up -d --remove-orphans`.
4. `php artisan migrate --force`, as an explicit step.
5. Rebuild the config, route, and event caches.
6. `php artisan horizon:terminate`, so the worker restarts on the new code.
7. Curl `STAGING_URL/up` and fail the deploy if it does not answer. That is a
   separate secret from the SSH host on purpose — the box a deploy connects to
   and the name a browser resolves are not always the same thing.

**Migrations are a deploy step, not a container start-up side effect.** A bad
migration should fail a deploy loudly rather than leaving a container that
will not start and a database half-changed. (`AUTO_MIGRATE` exists for local
and CI, where a fresh `up` should end with a migrated database.)

### Production

The same steps, from a tag rather than a branch:

```
git fetch --all --tags
git checkout v1.4.0
docker compose -f compose.yaml build
docker compose -f compose.yaml up -d --remove-orphans
docker compose -f compose.yaml exec -T app php artisan migrate --force
docker compose -f compose.yaml exec -T app php artisan config:cache route:cache event:cache
docker compose -f compose.yaml exec -T app php artisan horizon:terminate
```

Roll back by checking out the previous tag and repeating. **A rollback does not
undo a migration** — a migration that cannot be rolled forward safely needs a
deliberate down path written before it ships.

---

## 3. TLS

Caddy runs inside the app container and terminates TLS itself — but only when
it is told a hostname. The mechanism is `SERVER_NAME`, and it is the one
setting that separates a laptop from a deployed environment:

| Environment | `SERVER_NAME` | Result |
|---|---|---|
| Local | `:80` (the default in `.env.example`) | Plain HTTP on `APP_PORT`. No certificate is requested, which is what a laptop wants |
| Staging, production | `staging.example.com` | Caddy obtains and renews a certificate for that name over ports 80 and 443 |

The compose file publishes `${APP_PORT:-8000}:80` and `${APP_TLS_PORT:-8443}:443`
(plus 443/udp for HTTP/3). **On a deployed host set both to the standard
ports** — `APP_PORT=80`, `APP_TLS_PORT=443` — because ACME's HTTP and TLS-ALPN
challenges arrive on 80 and 443 and nowhere else.

Certificates and Caddy's state live in the `caddy-data` volume. Without it
every `up -d` would start from nothing and re-request certificates until Let's
Encrypt rate-limits the domain.

Requirements from PRD §9:

- **TLS 1.2 minimum.** Caddy's default is 1.2, and nothing lowers it.
- **HSTS**, once the domain is confirmed correct — `Strict-Transport-Security:
  max-age=31536000; includeSubDomains`. Turn it on after the certificate is
  verified, never before: a mistaken HSTS header on a wrong domain is painful
  to undo.
- Renewal is automatic. The uptime check catches the case where it is not.

---

## 4. Backups

PRD §9: nightly, 30-day retention, **offsite**. RPO 24 hours, RTO 4 hours.

- `pg_dump` nightly, compressed, encrypted at rest, written to object storage
  in a different region from the droplet.
- Retention 30 days, enforced by a lifecycle rule rather than a script that can
  fail quietly.
- The backup job reports success to the same alerting path as queue failures. A
  backup nobody is told about is a backup nobody notices stopping.

### The restore drill

**A restore must be tested before launch** (PRD §9), and the drill is its own
issue rather than a line in this document. It is performed on staging:

1. Take last night's production backup.
2. Restore it into a fresh Postgres container on the staging droplet.
3. Point staging at the restored database and boot.
4. Confirm: a team's deals list renders, a workflow's stages are intact,
   documents resolve, and the audit log is continuous.
5. Record how long it took, end to end. That number is the real RTO; the four
   hours in the PRD is an aspiration until it is measured.

---

## 5. Observability in a deployed environment

- **Sentry** for both server and browser errors, with no PII attached
  (`send_default_pii=false`, request bodies never transmitted).
- **Horizon** at `/horizon`, gated to the addresses in
  `HORIZON_AUTHORIZED_EMAILS` — it shows queue payloads, so it is a super-admin
  surface.
- **Structured JSON logs to stdout.** Redaction is `App\Logging\Redactor`,
  reached from three places, because there are three ways out of the
  application: `RedactPii` (the Monolog processor, on every writing channel),
  and Sentry's `before_breadcrumb`, `before_send`, and `before_send_log` —
  Sentry's log breadcrumbs are raised before Monolog sees them, and its
  exception values carry interpolated query bindings.
- **An uptime check** against `/up`: `.github/workflows/uptime.yml`, every
  fifteen minutes, watching whichever of the `UPTIME_STAGING_URL` and
  `UPTIME_PRODUCTION_URL` repository **variables** are set. It retries once
  before failing, because a refused connection during a deploy is not an
  outage.

  The `UPTIME_` prefix matters: `STAGING_URL` is a *secret* used by the deploy
  workflow, and a variable of the same name would read as empty — a job that
  passes while checking nothing. They are variables rather than secrets
  deliberately, so the endpoint is legible in the run log; a hostname in public
  DNS is not a secret, and nothing else belongs in them.

  **It does not tick from `dev`.** GitHub runs `schedule` only from the copy of
  a workflow on the default branch, and this repository's default branch is
  `main`, reserved for tagged releases. So the check starts running at the next
  `dev` → `main` release, not when it merges — and `workflow_dispatch` will not
  offer it before then either. Setting the variables early is fine and simply
  produces no runs.

  An environment with no variable set emits a `::warning::` rather than a
  silent pass, because the misconfiguration it most needs to catch — storing
  the URL as a secret, which the checklist below asks for two lines earlier —
  otherwise looks exactly like a healthy environment.

  Be honest about what it is: a scheduled workflow is delayed under load,
  cannot tell you GitHub itself is down, notifies only whoever *created* it
  (transferring only when somebody else changes the cron syntax, not on any
  other edit), and is disabled automatically after 60 days without repository
  activity. It is the in-repository baseline. If a dedicated monitor is bought
  later, delete this rather than running both.

### Alert thresholds (PRD §9, §12.2)

| Signal | Threshold |
|---|---|
| Queue failure | Alert within **15 minutes** |
| Automation failure rate | Above 1% of queued actions |
| AI spend | Against the monthly cap |
| Bounce rate | Above 2% |
| Complaint rate | Above 0.1% — the SES danger threshold |

The last two matter more than they look: SES suspends a sender that crosses
them, and a suspended sender means no client hears anything.

---

## 6. Standing up the staging droplet

The checklist for the work this repository cannot do on its own:

- [ ] DigitalOcean droplet, Docker and Compose installed, firewall to 80/443/22
- [ ] A deploy user with a checkout at `/srv/brawling-mahogany` and a deploy key
- [ ] DNS for the staging hostname, and `SERVER_NAME` set to it in `.env`
- [ ] `APP_PORT=80` and `APP_TLS_PORT=443` in `.env`, so ACME's challenges can land
- [ ] `.env` on the droplet, `0600`, per `docs/Environment and secrets.md`
- [ ] Postgres: managed instance or the compose service, matching production's choice
- [ ] SES in sandbox, `MAIL_REDIRECT_TO` set
- [ ] A separate AI provider key with its own budget cap
- [ ] Sentry staging project, DSN in `.env`
- [ ] Repository secrets: `STAGING_SSH_HOST`, `STAGING_SSH_USER`, `STAGING_SSH_KEY`, `STAGING_PATH`, `STAGING_URL`
- [ ] Repository variable `STAGING_ENABLED=true`
- [ ] Repository variable `UPTIME_STAGING_URL` (and `UPTIME_PRODUCTION_URL` at launch), which turn the uptime workflow on — note these are variables, while `STAGING_URL` is a secret
- [ ] Nightly backup job and its offsite target
- [ ] Restore drill performed and its duration recorded

---

## 7. Branch protection

Branch protection lives in repository settings, so it cannot be committed — but
it can be scripted. Run it once, as a repository admin:

```
gh auth login
./scripts/protect-branches.sh          # apply
./scripts/protect-branches.sh --show   # print the payload, change nothing
```

What the script applies:

- `dev`: pull request required; CI checks — Tests, Static analysis, Code style,
  Front end, Container build — required to pass.
- `main`: pull request required; the same checks.

**Every job blocks the merge.** A red pipeline is not advisory — and until this
is run, that sentence is an intention rather than a fact, because the pipeline
reports without gating anything.

What stays a convention, because branch protection cannot express it: **only
`dev` merges into `main`.** There is no way to restrict a pull request by its
*source* branch, so this one is held by `CLAUDE.md` and by review, not by the
API.

Three deliberate choices in the script. `enforce_admins` is **off**: the point
is to stop a red merge by accident, not to lock the owner out during an
incident, and a rule that cannot be bypassed is a rule somebody eventually
disables. **No approving review is required** — a required reviewer who is out
on a showing is a stalled merge, and on a team this size the five checks are
the gate. And `strict` is **false**: a strict rule requires every pull request
to be up to date with its base before merging, so on a five-job pipeline every
merge invalidates every other open pull request. The cost is that a branch
green against an older base can still break `dev`; at this concurrency that
trade is the right way round.

One caveat before running it on settings somebody has adjusted by hand: the
API call is a `PUT`, so it **replaces** the branch's protection. Anything not
in the payload — required signed commits, required conversation resolution,
linear history — is cleared rather than preserved. `--show` prints exactly what
will be applied.

`tests/Unit/BranchProtectionTest.php` checks the script's list of required
checks against `ci.yml`'s job names and against this section. Renaming a CI job
without renaming it in the script would otherwise require a check that never
reports, which does not fail loudly — it blocks every pull request forever.
