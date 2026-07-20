# Employee account invitations

Organization administrators create operator and technician accounts through a secure invitation. They never choose, receive, or reset an employee password.

## Workflow

1. The administrator enters the employee name, email, role, team, phone, and address.
2. Laravel assigns the administrator organization and creates the user with `pending` status.
3. An 80-character random token is generated. Only its SHA-256 hash is stored.
4. A queued email sends the public `/activate-invitation` link to the employee.
5. The employee chooses a password that satisfies the password policy.
6. Laravel atomically consumes the invitation, verifies the email, and activates the account.
7. The next login opens the workspace associated with the assigned operator or technician role.

The default employee invitation lifetime is 72 hours. Configure it with `EMPLOYEE_INVITATION_EXPIRATION_HOURS`.

## Lifecycle and actions

| Effective state | Administrator action | Result |
|---|---|---|
| `pending` | Send reminder | Rotates the token, invalidates the previous link, and sends a fresh link after the cooldown |
| `pending` | Cancel invitation | Revokes the link while preserving the pending employee record |
| `expired` | Renew invitation | Creates a new invitation with a new 72-hour lifetime |
| `revoked` | Renew invitation | Creates a new invitation with a new 72-hour lifetime |
| `accepted` | None | The employee account is active |

The reminder cooldown defaults to 10 minutes and is configured with `EMPLOYEE_INVITATION_REMINDER_COOLDOWN_MINUTES`.

## Security boundaries

- An administrator can invite only `operator` and `technician` roles.
- The organization identifier always comes from the authenticated administrator.
- Existing email addresses are rejected case-insensitively.
- Tokens are single-use and never returned by authenticated employee-management APIs.
- Accepting the link verifies ownership of the invited email address.
- A pending account cannot be activated manually from the employee CRUD API.
- Email and role cannot change while a valid invitation is pending; the invitation must be cancelled first.
- Reminder, renewal, and cancellation are policy-protected, tenant-scoped, rate-limited, and represented in invitation history.
- Deactivating an active employee revokes active API tokens. Password changes remain an employee self-service workflow.

## API

- `POST /api/users`: create a pending employee and queue the initial invitation.
- `POST /api/users/{user}/invitation/remind`: rotate and resend a valid pending invitation.
- `DELETE /api/users/{user}/invitation`: cancel a valid pending invitation.
- `POST /api/users/{user}/invitation/renew`: renew an expired or cancelled invitation.
- `POST /api/account-invitations/inspect`: inspect a public activation link.
- `POST /api/account-invitations/accept`: set the password and activate the account.

The queue worker must be running for invitation emails to leave the queue. After changing notification code, run `php artisan queue:restart`.
