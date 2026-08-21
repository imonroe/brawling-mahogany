# Brawling Mahogany

*Working codename — product name TBD.*

Brawling Mahogany is a multi-tenant web application for small, independent real estate teams that runs the **process** side of the practice, not just the contacts.

Most tools in this space are contact databases with a task list bolted on. This one is built around the opposite bet: the unit of value isn't the contact record, it's the **workflow** — a repeatable, gated sequence of stages that every deal of a given type has to pass through, with client communication firing automatically at the right moments.

## The idea

Each deal (a purchase, a sale, a rental placement) runs one or more **workflows** built from **stages**. A stage is a period — "Under Contract," "Pre-Listing Improvements" — that holds tasks, gates (conditions that must clear before the deal can advance), and automations. Reaching certain stages produces a **milestone**: a moment worth telling the client about, like "Property Listed," which can trigger things like an automatic congratulations email with the MLS link.

The product answers three questions better than a plain CRM does:

1. What has to happen next on this deal, and who owes it?
2. Has the client been told?
3. Can we prove the process was followed?

It's built alongside existing tools rather than replacing them — e.g. contracts and e-signature stay in the agent's existing system of record (CTM eContracts, for the originating customer), and MLS data is linked to, not ingested.

## Who it's for

- **Team owner / agent** — runs deals, needs a single view of what's active and what's late, and wants to define the process once and have it followed every time.
- **Assistant / transaction coordinator** — the primary daily user, executing an unambiguous work queue across every deal.
- **Clients (buyers/sellers)** — get a read-only status page (magic-link, no password) showing where their deal stands. Not a workspace — no tasks, no checkboxes.
- **Past clients** — closed deals move into a lightweight nurture/keep-in-touch state rather than disappearing.
- **Vendors/contractors** — tracked as a directory (specialties, cost, history), no login required.
- **Super administrator** — cross-tenant visibility for platform support.

Multi-tenancy is deliberate and commercial: the goal is to sell subscriptions to other small, independent real estate teams, not just build a one-off internal tool.

## The stack

| Layer | Choice |
|---|---|
| Backend | Laravel 13 (PHP 8.4) |
| Frontend | Vue 3 via Inertia.js, built with Vite |
| CSS | Tailwind CSS v4, CSS-first `@theme` tokens |
| Components | shadcn-vue over Reka UI |
| Database | PostgreSQL |
| Cache/Queue | Redis + Laravel Horizon |
| Auth | Laravel Fortify (passwords, 2FA, passkeys) |
| Roles | `spatie/laravel-permission` in teams mode — planned, arrives with tenancy in Slice 1 |
| File storage | DigitalOcean Spaces (private, signed URLs) — planned, Slice 3 |
| Email | Amazon SES — planned, Slice 3. Local mail lands in Mailpit |
| Push | Web Push (VAPID) via an installable PWA — planned, Slice 3 |
| Hosting | Docker Compose (FrankenPHP) on a DigitalOcean droplet |
| Monitoring | Sentry, Horizon, structured JSON logs |

## Status

**Slice 0 is landing:** the application skeleton, the container stack, CI, and
the design system foundations. The product features begin at Slice 1 — see
[`Build Plan.md`](docs/Build%20Plan.md) for the order and why.

## Running it locally

Everything runs in containers: PHP, Postgres, Redis, the Horizon worker, the
scheduler, Mailpit, and Vite. Docker and Docker Compose are the only
prerequisites.

```bash
cp .env.example .env
# Set DB_PASSWORD. It is the only value you have to choose.
make setup
```

The containers run as `APP_UID`:`APP_GID` from `.env`, defaulting to
`1000:1000` — the same mechanism in every environment, so nothing runs as root.
Locally it matters because the working tree is bind-mounted: whatever the
containers write appears in the repository owned by that user. If `id -u` and
`id -g` do not say 1000, set them in `.env` and run `make build` — they are
baked into the image.

That builds the images, starts the stack, generates an application key, and
migrates the database. When it finishes:

| | |
|---|---|
| App | http://localhost:8000 |
| Mailpit | http://localhost:8025 — every local send lands here and nowhere else |
| Horizon | http://localhost:8000/horizon — add your address to `HORIZON_AUTHORIZED_EMAILS` |
| Design system gallery | http://localhost:8000/design-system — every component, both themes |

Day to day:

```bash
make up          # start
make down        # stop
make logs        # follow the app and worker logs
make shell       # a shell in the app container
make test        # the PHP test suite
make check       # everything CI runs
```

### Without containers

The stack expects Postgres 16 and Redis 7 reachable at the host and port in
`.env`:

```bash
composer install
npm install
php artisan key:generate
php artisan migrate
npm run dev        # and, in another terminal:
php artisan serve
```

### Running the checks

```bash
composer check     # Pint, PHPStan, Pest
npm run check      # Wayfinder, ESLint, Prettier, vue-tsc, Vitest
```

Both are exactly what the pipeline runs, so the local loop and CI cannot
disagree. Tests run against a real Postgres — see
[`docs/Testing.md`](docs/Testing.md) for why, and for the conventions every
later slice inherits.

## The documentation

The [`docs/`](docs) folder is the source of truth for scope, naming, and
design. Keeping it current is part of the development process, not an
afterthought.

- [`Product Requirements Document.md`](docs/Product%20Requirements%20Document.md) — the PRD: goals, personas, feature scope, data model, release slices, and open questions
- [`Information Architecture.md`](docs/Information%20Architecture.md) — the naming authority for the product's vocabulary
- [`Screen Inventory.md`](docs/Screen%20Inventory.md) — the full screen list
- [`Build Plan.md`](docs/Build%20Plan.md) — the build order and the map to the GitHub issue backlog
- [`Design System.md`](docs/Design%20System.md) and [`Design references.md`](docs/Design%20references.md) — visual direction
- [`Frontend conventions.md`](docs/Frontend%20conventions.md) — formatters, the state map, and the content rules in code
- [`Testing.md`](docs/Testing.md) — the test suites and conventions
- [`Environment and secrets.md`](docs/Environment%20and%20secrets.md) — what exists where, and how it is rotated
- [`Deployment.md`](docs/Deployment.md) — staging, production, backups, and the restore drill
- [`adr/`](docs/adr) — architecture decisions, starting with persistence conventions and multi-tenancy
- [`The basic idea.md`](docs/The%20basic%20idea.md) — the originating concept
- [`Rough data model.canvas`](docs/Rough%20data%20model.canvas) — the first-pass data model
- [`Conversation with Emily and Heather.md`](docs/Conversation%20with%20Emily%20and%20Heather.md) — the working session that shaped v0.2 of the PRD

See the PRD's Release Plan and Decision Log for the current build order and what's been settled versus still open.
