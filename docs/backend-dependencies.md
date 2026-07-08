# Backend Dependencies

Planned Laravel dependencies:

| Package | Purpose |
|---|---|
| `laravel/sanctum` | SPA authentication and API tokens |
| `laravel/socialite` | OAuth2 / OpenID Connect login with Google and Microsoft |
| `spatie/laravel-permission` | Roles and permissions |
| `laravel/reverb` | WebSocket realtime events |
| `predis/predis` | Redis client |
| `barryvdh/laravel-dompdf` | PDF invoice/report generation |
| `openspout/openspout` | CSV/XLSX export |

Local PHP extensions:

- `fileinfo`
- `zip`
- `pdo_pgsql`
- `pgsql`

`REDIS_CLIENT=predis` is used locally because the PHP Redis extension is not installed.

Custom backend layers to implement:

- `PaymentProviderInterface`
- `SimulatedPaymentProvider`
- future adapters: ClicToPay, e-DINAR/D17, GPGCheckout, Konnect, Flouci
- OCPP ingestion service
- Alert detection service
- Availability calculation service
- Report generation jobs
