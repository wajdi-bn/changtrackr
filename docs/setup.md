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

The backend is scaffolded with Laravel and currently configured for local PostgreSQL.

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
