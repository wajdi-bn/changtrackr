from __future__ import annotations

import http.client
import os
import ssl


def main() -> None:
    host = os.getenv("OCPP_GATEWAY_HOST", "0.0.0.0")
    if host in {"0.0.0.0", "::"}:
        host = "127.0.0.1"

    port = int(os.getenv("OCPP_GATEWAY_PORT", "9000"))
    tls_mode = os.getenv("OCPP_GATEWAY_TLS_MODE", "disabled").strip().lower()
    if tls_mode == "direct":
        # This in-container probe checks gateway readiness; remote clients still verify TLS.
        health_context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        health_context.check_hostname = False
        health_context.verify_mode = ssl.CERT_NONE
        connection: http.client.HTTPConnection = http.client.HTTPSConnection(
            host,
            port,
            timeout=2,
            context=health_context,
        )
    else:
        connection = http.client.HTTPConnection(host, port, timeout=2)

    try:
        connection.request("GET", "/health")
        response = connection.getresponse()
        if response.status != 200:
            raise RuntimeError(f"Gateway health check returned HTTP {response.status}")
        response.read()
    finally:
        connection.close()


if __name__ == "__main__":
    main()
