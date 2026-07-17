# Security Baseline

## Authentication

- Use Laravel Sanctum stateful session cookies for the first-party SPA.
- Fetch the CSRF cookie before password login and send credentials on API requests.
- Keep Google OAuth2 as a backend-managed integration. Microsoft is deferred.
- Do not expose OAuth client secrets to the frontend.
- Store roles and permissions locally, even when the identity comes from Google.
- Never place OAuth access tokens in callback URLs or browser storage.
- Revoke legacy SPA personal access tokens when switching to cookie sessions.
- Only verified Google emails can be linked. New identities create a global `client`; existing employees keep their role and organization.
- Public registration always creates one unverified global `client` with no organization assignment.
- Require signed email verification before a locally registered client can sign in.
- Queue verification and password reset notifications, use short-lived links and return generic recovery responses to prevent account enumeration.
- Provision organization accounts as `pending` and activate them through a single-use invitation instead of emailing temporary passwords.
- Store only the SHA-256 hash of each invitation token, enforce its expiry and invalidate it after acceptance, revocation or reissue.

## Authorization

Planned roles:

- `super_admin`
- `admin`
- `operator`
- `technician`
- `client`

Authorization must be enforced in the backend with middleware, policies and organization scoping.

Important rules:

- A user can only access data from their organization unless they are `super_admin`.
- An admin cannot remove or deactivate the last active admin of an organization.
- A user cannot change their own role or deactivate their own account from user management.
- Technicians can consult stations and handle assigned work, but cannot create stations or connectors.
- Operators can create stations and connectors for their own organization only.

## Secrets

- Keep real secrets in `.env`.
- Commit only `.env.example` files.
- Mask sensitive tokens in API responses.
- Never log `Authorization` headers, passwords, payment payload secrets or OAuth secrets.

## API Protection

- Validate every write request with Laravel Form Requests.
- Use API Resources to shape responses.
- Add rate limits for login, password reset, payment and public contact endpoints.
- Protect public demo requests with server-side validation, consent capture, a honeypot, per-IP throttling and recent-request deduplication.
- Use independent named rate-limit buckets for demo submission, invitation inspection and invitation acceptance so one public workflow cannot exhaust another endpoint's allowance.
- Keep applicant acknowledgements separate from internal review notifications; only the configured platform mailbox receives the administrative review link.
- Enforce invitation revocation or expiration before issuing a replacement one-time token.
- Add audit logs for sensitive actions: user changes, role changes, tariff changes, station edits, payment status changes.

## Payments

The MVP uses a simulated payment provider.

The backend should expose a `PaymentGateway` interface so future adapters can be added for:

- ClicToPay
- e-DINAR / D17
- GPGCheckout
- Konnect
- Flouci

Payment confirmation must be handled backend-side. Future real providers must use signed webhooks or verified callbacks.
