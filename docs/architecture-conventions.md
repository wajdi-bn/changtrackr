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

## Reused Lessons From Previous Projects

Useful patterns:

- Public and protected route separation.
- Central HTTP client with timeout and auth handling.
- Typed frontend services.
- Admin-created accounts with email activation.
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
