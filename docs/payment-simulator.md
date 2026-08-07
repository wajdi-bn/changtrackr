# External Payment Simulator

ChargeTrackr uses the official WireMock Docker image as an external payment sandbox. Laravel calls it over HTTP through `WireMockPaymentAdapter`; the in-memory `SimulatedPaymentAdapter` remains available for fast automated tests.

## Responsibilities

- Laravel owns payment rules, idempotency keys, local state and revenue reconciliation.
- WireMock emulates a remote provider API and asynchronous callbacks.
- `PaymentGateway` keeps the domain independent from WireMock and future ClicToPay, e-DINAR/D17, GPGCheckout, Konnect or Flouci adapters.
- `payment_provider_events` records valid callbacks for audit and duplicate protection.

No card number, wallet identifier, credential or real money is used by this sandbox.

## Start And Stop

The Laravel server must listen on `0.0.0.0:8000` so the Docker container can call the webhook through `host.docker.internal`.

```powershell
npm run dev:backend:ocpp
npm run payment:up
npm run payment:status
```

`payment:up` reads `PAYMENT_SIMULATOR_API_KEY` from the ignored `backend/.env` file. It generates a strong local key when the value is missing or still uses the retired development default, renders private WireMock mappings under an ignored directory, clears Laravel's configuration cache, and then starts WireMock. The key is never printed or committed.

Use `npm run payment:logs` to inspect provider requests and callbacks. Use `npm run payment:reset` to clear WireMock's request journal and `npm run payment:down` to stop it.

## Scenarios

In development, the charging and post-session payment forms expose four deterministic outcomes:

| Outcome | Provider behavior | Expected local result |
|---|---|---|
| `success` | HTTP 200 and signed webhook | Authorized, captured, released or paid |
| `declined` | HTTP 422 and signed webhook | Business failure, not retryable |
| `timeout` | Response delayed beyond Laravel timeout | Technical failure, retryable |
| `provider_error` | HTTP 503 | Provider unavailable, retryable |

Successful and declined callbacks carry an HMAC SHA-256 signature in `X-ChargeTrackr-Signature`. Laravel rejects an invalid signature, stores each provider event once, and prevents duplicate revenue updates.

## Configuration

All local variable names are declared in `backend/.env.example` without a simulator API key value. Production must supply provider-specific secrets and endpoints through its secret manager. The simulator's webhook secret is a development value only.

Automated tests force `PAYMENT_DRIVER=simulated` in `backend/phpunit.xml`; adapter and webhook contract tests explicitly cover the external integration without requiring Docker.
