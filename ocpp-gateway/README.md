# ChargeTrackr OCPP Gateway

The Gateway owns long-lived OCPP 1.6 JSON WebSocket connections. It validates station Basic Auth
credentials through Laravel and publishes normalized, HMAC-signed events to Laravel. It never
connects directly to PostgreSQL and never calculates business availability.

Required environment variables:

```dotenv
OCPP_ENVIRONMENT=local
OCPP_GATEWAY_TLS_MODE=disabled
OCPP_GATEWAY_PUBLIC_URL=ws://localhost:9000/ocpp
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
OCPP_LARAVEL_CA_FILE=
```

The charge point connects to `ws://<gateway>:9000/ocpp/<station-identity>` with subprotocol
`ocpp1.6` and HTTP Basic Auth. The username must equal the station identity.

Plain `ws://` and backend `http://` are accepted only for local development and only toward
loopback or known local Docker hosts. Non-local environments fail closed unless the station
transport uses direct TLS or a TLS reverse proxy and Laravel is reached through HTTPS.

For direct TLS, mount the certificate and private key read-only and configure:

```dotenv
OCPP_ENVIRONMENT=production
OCPP_GATEWAY_TLS_MODE=direct
OCPP_GATEWAY_PUBLIC_URL=wss://ocpp.example.com/ocpp
OCPP_GATEWAY_TLS_CERTIFICATE_FILE=/run/tls/fullchain.pem
OCPP_GATEWAY_TLS_PRIVATE_KEY_FILE=/run/tls/privkey.pem
OCPP_LARAVEL_BASE_URL=https://api.example.com/api/internal/ocpp
```

Use `OCPP_GATEWAY_TLS_MODE=proxy` when a trusted reverse proxy terminates `wss://`. In that mode,
the proxy-to-gateway connection remains private and the public URL must still use `wss://`.

The current handlers cover `BootNotification`, `Heartbeat` and `StatusNotification`. Connection
open/close events are also published. See [the OCPP architecture guide](../docs/ocpp.md) for the
complete architecture and local simulator workflow.
