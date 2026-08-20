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

## Planned stack

| Layer | Choice |
|---|---|
| Backend | Laravel (PHP) |
| Frontend | Vue 3 via Inertia.js |
| CSS | Tailwind |
| Database | PostgreSQL |
| Cache/Queue | Redis + Laravel Horizon |
| Auth/Roles | Laravel Fortify + `spatie/laravel-permission` (teams mode) |
| File storage | DigitalOcean Spaces (private, signed URLs) |
| Email | Amazon SES |
| Push | Web Push (VAPID), delivered via an installable PWA |
| Hosting | Docker Compose on a DigitalOcean droplet |

## Status

This project is in the planning stage — no application code yet. The [`docs/`](docs) folder holds the working documentation:

- [`Product Requirements Document.md`](docs/Product%20Requirements%20Document.md) — the PRD: goals, personas, feature scope, data model, release slices, and open questions
- [`Information Architecture.md`](docs/Information%20Architecture.md) — the naming authority for the product's vocabulary
- [`Screen Inventory.md`](docs/Screen%20Inventory.md) — the full screen list
- [`Build Plan.md`](docs/Build%20Plan.md) — the build order and the map to the GitHub issue backlog
- [`Design System.md`](docs/Design%20System.md) and [`Design references.md`](docs/Design%20references.md) — visual direction
- [`The basic idea.md`](docs/The%20basic%20idea.md) — the originating concept
- [`Rough data model.canvas`](docs/Rough%20data%20model.canvas) — the first-pass data model
- [`Conversation with Emily and Heather.md`](docs/Conversation%20with%20Emily%20and%20Heather.md) — the working session that shaped v0.2 of the PRD

See the PRD's Release Plan and Decision Log for the current build order and what's been settled versus still open.
