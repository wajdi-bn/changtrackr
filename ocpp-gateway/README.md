# ChargeTrackr OCPP Gateway

The Gateway owns long-lived OCPP 1.6 JSON WebSocket connections. It validates station Basic Auth
credentials through Laravel and publishes normalized, HMAC-signed events to Laravel. It never
connects directly to PostgreSQL and never calculates business availability.

Required environment variables:

```dotenv
OCPP_LARAVEL_BASE_URL=http://host.docker.internal:8000/api/internal/ocpp
OCPP_GATEWAY_SHARED_SECRET=
```

Optional values:

```dotenv
OCPP_GATEWAY_HOST=0.0.0.0
OCPP_GATEWAY_PORT=9000
OCPP_GATEWAY_PATH_PREFIX=/ocpp
OCPP_HEARTBEAT_INTERVAL_SECONDS=30
OCPP_HTTP_TIMEOUT_SECONDS=10
OCPP_HTTP_MAX_ATTEMPTS=3
```

The charge point connects to `ws://<gateway>:9000/ocpp/<station-identity>` with subprotocol
`ocpp1.6` and HTTP Basic Auth. The username must equal the station identity.

The current handlers cover `BootNotification`, `Heartbeat` and `StatusNotification`. Connection
open/close events are also published. See [the OCPP architecture guide](../docs/ocpp.md) for the
complete architecture and local simulator workflow.
