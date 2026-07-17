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

## 4. Local Services

The current PostgreSQL database is local and managed outside this repository.

If Docker is installed later, `infra/docker-compose.yml` can provide:

- PostgreSQL
- Redis
- Mailpit

These services are optional for the frontend-only phase.

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
