# Operational notifications

ChargeTrackr stores a separate personal notification for every authorized recipient. A notification is never exposed through a tenant-wide feed: REST responses and the private Reverb channel are both scoped to the authenticated user.

## Channels and delivery

- `in_app` is persisted immediately and displayed in the topbar notification center.
- `email` creates a pending delivery and dispatches `SendOperationalNotificationEmail` on the default Laravel queue.
- Every delivery tracks its status, attempt count, timestamps and final error.
- The email job retries after 10, 60 and 300 seconds and updates the existing delivery record.
- A deterministic key unique to the recipient prevents duplicate notifications when an event, webhook or scheduled scan is replayed.

Local development can use Mailpit. Resend is used when `MAIL_MAILER=resend` and its server-side credentials are configured. Email secrets must remain in `backend/.env` and must never be committed.

## Recipient matrix

| Event | Recipients | Channels |
|---|---|---|
| New warning or lower alert | Active administrators and operators of the station organization | In-app |
| New critical alert | Active administrators and operators of the station organization | In-app and email |
| Alert assigned | Selected technician of the same organization | In-app and email |
| Alert status changed | Organization administrators/operators and assigned technician | In-app |
| Intervention assigned | Assigned technician | In-app and email |
| Intervention status changed | Assigned technician | In-app |
| Maintenance scheduled or due within 24 hours | Organization administrators/operators and assigned technician | In-app |
| Alert SLA due within five minutes | Alert stakeholders | In-app |
| Alert SLA overdue | Alert stakeholders | In-app and email |
| Client payment failed | Payment owner | In-app and email |
| Payment provider unavailable | Organization administrators/operators | In-app |

Inactive users are ignored. Organization recipients are selected from the entity organization, not from a client-provided organization identifier.

## SLA scan

The scheduler runs the following command every minute:

```bash
C:\php\php.exe artisan notifications:check-sla
```

The scan covers unresolved alerts due within five minutes, overdue unresolved alerts, and assigned maintenance due within 24 hours. Running it repeatedly is safe because each stage uses an idempotent notification key.

## REST and realtime

- `GET /api/notifications?status=all|unread&limit=20`: personal notifications and unread summary.
- `PATCH /api/notifications/{notification}/read`: mark an owned notification as read.
- `POST /api/notifications/read-all`: mark every personal notification as read.
- Private channel: `users.{userId}.notifications`.
- Event: `.user-notification.created`.

Trying to read another user's notification returns `404` so identifiers do not disclose cross-user or cross-organization data.

## Local processes

The complete flow requires authenticated Redis, the backend server, queue worker, scheduler and Reverb server:

```bash
npm run infra:configure:redis
npm run infra:up:redis
npm run dev:backend
npm run dev:queue
npm run dev:scheduler
npm run dev:reverb
```

Use `C:\php\php.exe artisan queue:restart` after changing long-lived job or notification code.
Queued mail is routed to the Redis `emails` queue; realtime broadcasts, payments and maintenance
use separate queues so a slow mail provider cannot block operational processing.

## Current scope

Mandatory operational events have fixed server-side channel rules. A later personal-settings block will add preferences for non-mandatory channels while preserving critical security and operational notifications.
