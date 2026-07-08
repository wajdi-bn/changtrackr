# ChargeTrackr

ChargeTrackr is a web platform for visualizing, supervising, and monitoring the availability of electric vehicle charging stations.

## Planned Stack

- Frontend: React, TypeScript, Ant Design, React Router, TanStack Query, Axios, Recharts, Leaflet, Framer Motion, GSAP
- Backend: Laravel, REST API, Sanctum/JWT, OAuth2 Google/Microsoft, Laravel Reverb, Queue, Scheduler
- Data: PostgreSQL
- Infrastructure services: Redis, Mailpit for local email testing
- EV communication: OCPP server integration
- Payment: simulated MVP provider with future adapters

## Current Status

- `frontend/` is scaffolded and ready to run.
- `backend/` is scaffolded with Laravel and the core backend dependencies.
- `backend/` is configured locally to use PostgreSQL.
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

## Backend Prerequisites

Backend requirements:

- PHP 8.3+
- Composer
- Required PHP extensions: OpenSSL, PDO, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath, Fileinfo, Curl, Zip, PDO PgSQL, PgSQL

Excel/XLSX export uses `openspout/openspout` because `maatwebsite/excel` is not compatible with the current PHP 8.5 runtime.

See [docs/setup.md](docs/setup.md) for complete setup notes.
See [docs/environment.md](docs/environment.md) for environment rules.
See [docs/security.md](docs/security.md) for the security baseline.
