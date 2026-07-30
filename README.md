# ChargeTrackr

ChargeTrackr is a web platform for visualizing, supervising, and monitoring the availability of electric vehicle charging stations.

## Video Demo

Watch the [ChargeTrackr platform demonstration](https://1drv.ms/v/c/a087f1f584265db1/IQCIAt1JRSaZSaV3VEdsPAjLASDhXXfYAO7R0siK5-lyxV4?e=phgMDb).

The video presents the role-based workspaces, charging-station supervision, OCPP simulation, calculated availability, charging sessions, payments, maintenance, and reporting workflows.

## Planned Stack

- Frontend: React, TypeScript, Ant Design, React Router, TanStack Query, Axios, Recharts, Leaflet, Framer Motion, GSAP
- Backend: Laravel, REST API, Sanctum session cookies, OAuth2 Google, Laravel Reverb, Queue, Scheduler
- Data: PostgreSQL
- Infrastructure services: database-backed queues and cache, Mailpit for local email testing, Resend for transactional email
- EV communication: OCPP 1.6 JSON gateway and SAP charging-station simulator
- Payment: extensible gateway with in-memory tests and an external WireMock sandbox

## Current Status

- `frontend/` is scaffolded and ready to run.
- `backend/` is scaffolded with Laravel and the core backend dependencies.
- `backend/` is configured locally to use PostgreSQL.
- Password and Google sign-in use the same server-side Sanctum session flow.
- The OCPP gateway authenticates a nine-station SAP simulator fleet and stores normalized technical events through Laravel.
- Provisioned OCPP stations use calculated availability, audited transitions, automatic alerts and Reverb updates.
- Organization teams can plan preventive or corrective maintenance, generate recurring work orders, and synchronize active maintenance with OCPP availability.
- Technicians complete interventions through a guided, immutable report with private before/after evidence and controlled alert follow-up.
- Payment authorization, capture, release and direct charge can run against a Dockerized external sandbox with signed, idempotent webhooks.
- Personal in-app notifications cover operational alerts, assignments, maintenance, SLA deadlines and payment failures, with Reverb updates and traceable queued emails.
- Role-aware dashboards aggregate real stations, availability history, sessions, energy, payments and field activity for Super Admin, Admin, operator, technician and client scopes.
- Organization administrators invite operators and technicians through expiring, single-use activation links without handling employee passwords.
- `infra/` contains optional local service configuration.

## Quick Start

```bash
pnpm --dir frontend install
pnpm dev:frontend
```

Open the frontend URL printed by Vite.

Backend:

```bash
cd backend
C:\php\php.exe artisan migrate
C:\php\php.exe artisan serve
```

Availability supervision also requires the queue, scheduler and Reverb processes:

```bash
npm run dev:queue
npm run dev:scheduler
npm run dev:reverb
```

External payment sandbox:

```bash
npm run payment:up
npm run payment:status
```

## Backend Prerequisites

Backend requirements:

- PHP 8.3+
- Composer
- Required PHP extensions: OpenSSL, PDO, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath, Fileinfo, Curl, Zip, PDO PgSQL, PgSQL

Excel/XLSX export uses `openspout/openspout` because `maatwebsite/excel` is not compatible with the current PHP 8.5 runtime.

See [docs/setup.md](docs/setup.md) for complete setup notes.
See [docs/environment.md](docs/environment.md) for environment rules.
See [docs/security.md](docs/security.md) for the security baseline.
See [docs/ocpp.md](docs/ocpp.md) for the OCPP gateway and simulator workflow.
See [docs/payment-simulator.md](docs/payment-simulator.md) for the external payment sandbox and test scenarios.
See [docs/notifications.md](docs/notifications.md) for notification recipients, channels, SLA rules and operations.
See [docs/dashboards.md](docs/dashboards.md) for dashboard scopes, periods, formulas and API contracts.
See [docs/employee-invitations.md](docs/employee-invitations.md) for employee account activation and invitation lifecycle rules.

Run `npm run ocpp:configure`, `npm run ocpp:provision-fleet`, then `npm run ocpp:up` to start the
local OCPP fleet. `npm run ocpp:transaction-scenario -- CT-HAM-031` exercises an authorized cycle
from `StartTransaction` through metering and `StopTransaction` on a selected station.
