# Architecture Conventions

## Frontend

Recommended structure:

```text
frontend/src/
  app/
  api/
  components/
  features/
    auth/
    dashboard/
    users/
    stations/
    connectors/
    sessions/
    alerts/
    interventions/
      reports/
    maintenance/
    payments/
    reports/
    settings/
  hooks/
  layouts/
  pages/
  routes/
  types/
  utils/
```

Rules:

- Keep API calls outside React components.
- Use typed service functions in `api/` or feature-specific service files.
- Use TanStack Query for server state.
- Use React Hook Form and Zod for forms.
- Use role-aware routes and menus.
- Keep profile and settings accessible from the avatar menu, not from the main sidebar.
- Keep intervention evidence on private storage and expose it only through policy-protected API responses.

## Backend

Recommended Laravel modules:

```text
backend/app/
  Http/
    Controllers/
    Requests/
    Resources/
    Middleware/
  Models/
  Policies/
  Services/
    Auth/
    Users/
    Stations/
    Maintenance/
    Payments/
    Notifications/
    Reports/
    Ocpp/
  Jobs/
  Events/
  Listeners/
```

Rules:

- Controllers should stay thin.
- Business logic should live in services.
- Authorization should use policies and permissions, not frontend checks.
- Use jobs for emails, notifications, report generation and station availability checks.
- Use scheduler tasks for recurring heartbeat/offline detection.
- Persist one personal notification per recipient and use deterministic deduplication keys before broadcasting or queuing email.
- Authorize private Reverb channels by authenticated user id; never broadcast an organization-wide notification payload to the whole tenant.
- Track each asynchronous delivery separately so retries update the same delivery record instead of creating another notification.
- Keep payment providers behind `PaymentGateway`; provider HTTP, signatures and error mapping stay inside adapters.
- Treat signed provider webhooks as an idempotent audit and reconciliation channel, never as an unverified frontend callback.

## Reused Lessons From Previous Projects

Useful patterns:

- Public and protected route separation.
- Central HTTP client with timeout and auth handling.
- Typed frontend services.
- Admin-created accounts with email activation.
- Store account invitation tokens only as hashes; reminder emails rotate the token because the original value cannot be recovered.
- Keep employee accounts pending until the invited user chooses a password. Administrators never set employee passwords.
- Login history and security status.
- User settings with token masking.
- Notification delivery status tracking.
- Global backend error handling.
- BCrypt password hashing.
- Role-specific dashboards and menus derived from authenticated backend roles.
- Prototype code is used as a visual reference only; production frontend uses Ant Design.

Patterns to avoid:

- Secrets committed in application config.
- JWT refresh implemented with the same access token.
- Logging full request headers.
- Hardcoded CORS origins.
- Duplicated HTTP clients.
