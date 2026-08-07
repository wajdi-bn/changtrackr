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
VITE_API_URL=http://localhost:8000/api
VITE_BACKEND_URL=http://localhost:8000
VITE_APP_NAME=ChargeTrackr
```

Only public frontend values should use the `VITE_` prefix.

## Google OAuth

Register this exact authorized redirect URI in Google Cloud:

```text
http://localhost:8000/auth/oauth/google/callback
```

Configure only the variable names in the committed example. Real values stay in `backend/.env`:

```env
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173
CORS_ALLOWED_ORIGINS=http://localhost:5173
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/auth/oauth/google/callback"
```

## Demo requests and invitations

```dotenv
DEMO_REQUEST_NOTIFICATION_EMAIL=
ACCOUNT_INVITATION_EXPIRATION_HOURS=48
DEMO_TRIAL_DAYS=30
```

`DEMO_REQUEST_NOTIFICATION_EMAIL` receives the internal queued notification for new public
requests. Account invitation tokens are stored only as SHA-256 hashes, expire after the configured
period, and can be accepted once. The Resend sandbox can deliver these messages only to the email
associated with the Resend account until a sending domain is verified.

## OCPP Transport

The local simulator configuration is explicit and remains loopback-only:

```dotenv
OCPP_ENVIRONMENT=local
OCPP_GATEWAY_TLS_MODE=disabled
OCPP_GATEWAY_PUBLIC_URL=ws://localhost:9000/ocpp
OCPP_LARAVEL_BASE_URL=http://host.docker.internal:8000/api/internal/ocpp
```

For a non-local deployment, set `OCPP_ENVIRONMENT=production`, use an HTTPS Laravel URL and choose
one secure station transport:

- `direct`: the gateway loads `OCPP_GATEWAY_TLS_CERTIFICATE_FILE` and
  `OCPP_GATEWAY_TLS_PRIVATE_KEY_FILE` from read-only mounts;
- `proxy`: a trusted reverse proxy terminates the public `wss://` connection.

Both secure modes require `OCPP_GATEWAY_PUBLIC_URL` to use `wss://`. A private CA bundle can be
provided through `OCPP_LARAVEL_CA_FILE`; server certificate verification cannot be disabled.
