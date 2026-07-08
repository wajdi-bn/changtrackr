# Environment Configuration

## Rule

Never commit real environment files or secrets.

Ignored files:

- `backend/.env`
- `frontend/.env`
- any other `.env.*` file except `.env.example`

Committed examples:

- `backend/.env.example`
- `frontend/.env.example`

## Backend Local Database

The local backend currently targets PostgreSQL:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=changetrackr
DB_USERNAME=changetrackr
DB_PASSWORD=
```

The real password must stay only in `backend/.env`.

## PHP Extensions

Required local PHP extensions enabled during setup:

- `fileinfo`
- `zip`
- `pdo_sqlite`
- `sqlite3`
- `pdo_pgsql`
- `pgsql`

## Frontend

Local frontend example:

```env
VITE_API_URL=http://127.0.0.1:8000/api
VITE_APP_NAME=ChargeTrackr
```

Only public frontend values should use the `VITE_` prefix.
