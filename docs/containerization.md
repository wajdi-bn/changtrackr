# Containerized local stack

ChargeTrackr provides a production-shaped local stack without replacing the
native development commands. Docker Compose runs each long-lived process in a
separate container so failures, logs and restarts remain observable.

## Services

| Service | Responsibility | Published port |
|---|---|---:|
| `frontend` | Built React application served by Nginx | `5173` |
| `backend` | Nginx entry point for the Laravel REST API | `8000` |
| `backend-php` | PHP-FPM application runtime | Internal only |
| `queue-worker` | Payments, broadcasts, emails and maintenance queues | Internal only |
| `scheduler` | Laravel scheduled commands | Internal only |
| `reverb` | Authenticated real-time events | `8080` |
| `postgres` | Persistent PostgreSQL database | `5433`, loopback only |
| `redis` | Cache, sessions and queues | `6379`, loopback only |
| `mailpit` | Local SMTP capture and web inbox | `1025` and `8025`, loopback only |
| `payment-simulator` | WireMock payment provider sandbox | `9090`, optional profile |
| `ocpp-gateway` | OCPP 1.6 JSON WebSocket gateway | `9000`, optional profile |
| `ocpp-simulator` | SAP charging-station fleet and authenticated control WebSocket | `8082`, optional profile |

PostgreSQL, Redis, Mailpit and both simulator administration ports stay bound to
`127.0.0.1`. The browser-facing bind address is controlled explicitly by
`APP_BIND_ADDRESS` in the ignored `infra/.env` file.

## First launch

Docker Desktop with the WSL2 engine must be running. From the repository root:

```powershell
pnpm stack:up
```

This command:

1. creates missing ignored environment files;
2. generates strong local secrets without rotating existing values;
3. prepares the signed WireMock mappings;
4. builds the frontend and backend images;
5. migrates the database before starting dependent services;
6. starts the application, real-time services and both simulators.

Open:

- application: `http://localhost:5173`;
- Laravel health endpoint: `http://localhost:8000/up`;
- Mailpit inbox: `http://localhost:8025`;
- SAP simulator control endpoint: `ws://localhost:8082` (use the provided
  `pnpm ocpp:*` commands rather than a browser).

The initial build of the SAP simulator is significantly slower than subsequent
launches because its pinned upstream source and dependencies are compiled.

## Core stack without simulators

For UI, API, queue, scheduler and email work that does not require OCPP or
external payment behavior:

```powershell
pnpm stack:up:core
```

Payment operations configured with `PAYMENT_DRIVER=wiremock` require the full
stack. Automated tests continue to use their isolated simulated adapter.

## Daily operations

```powershell
pnpm stack:status
pnpm stack:logs
pnpm stack:artisan -- about
pnpm stack:down
```

The existing `ocpp:plug`, `ocpp:unplug`, `ocpp:scenario`,
`ocpp:transaction-scenario` and status commands automatically target the
unified stack when its OCPP simulator is active. Otherwise, they retain the
legacy standalone OCPP behavior.

## Data and uploads

The following named volumes survive container recreation:

- `postgres_data` for relational data;
- `redis_data` for local cache, queue and session persistence;
- `backend_storage` for uploaded files and generated application artifacts.

`pnpm stack:down` preserves these volumes. Removing volumes is intentionally not
part of the project scripts because it destroys application data.

The host port is `5433` so the managed container can run beside a native
PostgreSQL installation on the standard `5432` port. The application containers
still reach PostgreSQL internally on `postgres:5432`.

## Trusted LAN demonstration

The secure default exposes browser-facing services only on the current machine.
For an explicit trusted-LAN demonstration, set this value in `infra/.env`:

```dotenv
APP_BIND_ADDRESS=0.0.0.0
```

The backend CORS and Sanctum configuration must also contain the current LAN
origin. Return the bind address to `127.0.0.1` after the demonstration. Never
publish PostgreSQL, Redis, Mailpit or simulator administration ports to an
untrusted network.

## Troubleshooting

Use `pnpm stack:status` first. A failed `migrate` service prevents the API,
workers and Reverb from starting, which protects the application from running
against an incompatible schema.

Inspect a specific service without following every log:

```powershell
docker compose --env-file infra/.env -f infra/docker-compose.yml logs --tail=150 backend
docker compose --env-file infra/.env -f infra/docker-compose.yml logs --tail=150 queue-worker
docker compose --env-file infra/.env -f infra/docker-compose.yml logs --tail=150 ocpp-gateway
```

The native commands documented in `docs/setup.md` remain available for hot
reload and step-by-step debugging.
