# Development Setup

## 1. Repository

Recommended repository structure:

```text
charge-trackr/
  frontend/
  backend/
  infra/
  docs/
```

Use one GitHub repository for the project. Keep frontend and backend in the same repo during the internship unless the company explicitly asks for separate repositories.

## 2. Frontend

The frontend is already scaffolded with Vite, React and TypeScript.

```bash
cd frontend
pnpm install
pnpm dev
```

Main dependencies:

- `antd`: UI components
- `react-router-dom`: routing
- `@tanstack/react-query`: API state management
- `axios`: HTTP client
- `recharts`: charts
- `leaflet` and `react-leaflet`: maps
- `framer-motion` and `gsap`: animations
- `react-hook-form`, `zod`, `@hookform/resolvers`: forms and validation
- `zustand`: lightweight client state

## 3. Backend Laravel

The backend is scaffolded with Laravel 13 and currently configured for local PostgreSQL.
The local runtime remains PHP 8.5.

Current local database:

```text
host: 127.0.0.1
port: 5432
database: changetrackr
username: changetrackr
```

The real password is stored only in `backend/.env`.

Required PHP extensions include:

- `fileinfo`
- `zip`
- `pdo_pgsql`
- `pgsql`

If the backend needs to be recreated from scratch, use:

```bash
composer create-project laravel/laravel backend
cd backend
composer require laravel/sanctum laravel/socialite spatie/laravel-permission predis/predis
composer require barryvdh/laravel-dompdf laravel/reverb openspout/openspout
```

Then configure:

```bash
php artisan key:generate
php artisan migrate
php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider"
php artisan reverb:install
```

For local development after migrations:

```bash
php artisan db:seed
```

Run the backend and its asynchronous email worker in separate terminals:

```bash
pnpm dev:backend
pnpm dev:queue
```

Account verification and password reset notifications are queued. When Mailpit is running,
open `http://localhost:8025` to inspect them. With `MAIL_MAILER=log`, the messages are written
to `backend/storage/logs/laravel.log` instead.

To send real email through Resend, override the mail values in `backend/.env`:

```dotenv
MAIL_MAILER=resend
MAIL_FROM_ADDRESS=onboarding@resend.dev
MAIL_FROM_NAME="${APP_NAME}"
RESEND_API_KEY=
```

The `onboarding@resend.dev` sandbox sender can only send to the email address associated with
the Resend account. Verify a domain in Resend and replace `MAIL_FROM_ADDRESS` before sending to
arbitrary recipients. Never commit the API key.

Public demo requests are available from the landing page. New requests are reviewed at
`/demo-requests` by the Super Admin. Set `DEMO_REQUEST_NOTIFICATION_EMAIL` to receive the internal
queued notification. Applicants receive a separate acknowledgement without an administrative
link. The workflow is action-driven: submitted requests enter review, then they are either rejected
or provisioned directly into an organization trial workspace. Provisioning sends a one-time account
activation link to the administrator. A pending invitation must be revoked or expire before the
Super Admin can issue a replacement link.

Seeded demo accounts all use the password `password`:

| Role | Email |
|---|---|
| Super Admin | `superadmin@chargetrackr.local` |
| Admin | `admin@chargetrackr.local` |
| Operator | `operator@chargetrackr.local` |
| Technician | `technician@chargetrackr.local` |
| Client | `client@chargetrackr.local` |

### Google sign-in

The SPA uses Laravel Sanctum cookies for both password and Google sign-in. Use `localhost` consistently for the frontend and backend URLs; mixing it with `127.0.0.1` prevents the browser from treating the requests as the same site.

Create a Google OAuth web client and register this exact callback:

```text
http://localhost:8000/auth/oauth/google/callback
```

Then set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` only in `backend/.env`. The frontend never receives the client secret or provider access tokens.

Note: `maatwebsite/excel` was intentionally not used in the current setup because its spreadsheet dependency is not compatible with PHP 8.5. `openspout/openspout` is installed instead for CSV/XLSX-style exports.

### Maps

The development map uses React Leaflet with OpenStreetMap tiles and does not require an API key.
`GET /api/stations/map` returns role-scoped markers and supports station status, city, connector,
minimum power, availability and bounding-box filters. Operators can place a station by clicking the
map, while technicians have read-only access. Latitude and longitude remain editable manually.

Clients can copy station coordinates or open a universal Google Maps directions URL. The optional
`Near me` action uses the browser Geolocation API only to display the position and sort stations by
distance; ChargeTrackr does not send or persist that position. Use a dedicated tile provider before
production traffic instead of relying on the public OpenStreetMap tile service.

Expected backend modules:

- Authentication and roles
- Organizations
- Users
- Stations
- Connectors
- Sessions
- Alerts
- Interventions
- Payments
- Invoices
- Reports
- Audit logs
- Settings
- OCPP integration layer

### OCPP gateway and simulator

The OCPP integration runs in Docker while Laravel and PostgreSQL continue to run on Windows.
Laravel must listen on `0.0.0.0:8000` so the gateway container can reach it through
`host.docker.internal`:

```bash
npm run dev:backend:ocpp
npm run ocpp:configure
cd backend && C:\php\php.exe artisan migrate
npm run ocpp:provision-fleet
npm run ocpp:up
npm run ocpp:scenario -- CT-ARI-006
npm run ocpp:transaction-scenario -- CT-HAM-031
npm run ocpp:status -- CT-TUN-001
npm run ocpp:fleet-status
```

For live availability updates, keep these processes running in separate terminals:

```bash
npm run dev:queue
npm run dev:scheduler
npm run dev:reverb
```

The local SAP fleet contains independently connected manifest-defined stations. Use
`npm run ocpp:plug -- <station> <connector>`, `ocpp:unplug`, `ocpp:disconnect` and `ocpp:connect`
to target one station without changing the others.

Administrators and operators can use `Add station` in `/stations` to create the station and its
connectors together. Select `Local SAP simulator` in the connection step, then run the command
displayed after creation:

```bash
npm run ocpp:add-simulator-station -- <station-reference-or-identity>
npm run ocpp:down
npm run ocpp:up
```

Physical stations instead receive a unique one-time Basic Auth password. Store it in the station
configuration when it is displayed; Laravel persists only its hash. `Inventory only` creates no
credential and therefore no live OCPP availability.

The scheduler recalculates provisioned OCPP station availability every 30 seconds. Reverb uses
`localhost:8080`; the SAP simulator UI uses `localhost:8082`.

Use `npm run ocpp:down` to stop the OCPP containers. The generated gateway, station and simulator
UI credentials exist only in ignored `.env` files. See [ocpp.md](ocpp.md) for the component
boundaries and security contract.

## 4. Local Services

The current PostgreSQL database is local and managed outside this repository.

`infra/docker-compose.yml` can optionally provide:

- PostgreSQL
- Redis
- Mailpit

The OCPP-specific services are defined separately in `infra/ocpp/compose.yaml`.

## 5. Environment Files

Real environment files must not be committed:

- `backend/.env`
- `frontend/.env`

Only example files are committed:

- `backend/.env.example`
- `frontend/.env.example`

## 6. GitHub

After creating a remote GitHub repository:

```bash
git remote add origin <YOUR_GITHUB_REPO_URL>
git branch -M main
git push -u origin main
```
