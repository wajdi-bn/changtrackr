from __future__ import annotations

from dataclasses import dataclass
import ipaddress
import os
from urllib.parse import urlsplit


LOCAL_ENVIRONMENTS = frozenset({"local", "development", "testing"})
LOCAL_HTTP_HOSTS = frozenset(
    {"localhost", "host.docker.internal", "backend", "laravel"}
)
TLS_MODES = frozenset({"disabled", "direct", "proxy"})


def _optional_env(name: str) -> str | None:
    value = os.getenv(name, "").strip()
    return value or None


def _is_loopback_host(host: str) -> bool:
    if host in LOCAL_HTTP_HOSTS:
        return True

    try:
        return ipaddress.ip_address(host).is_loopback
    except ValueError:
        return False


def _validate_laravel_url(url: str, environment: str) -> None:
    parsed = urlsplit(url)
    if parsed.scheme not in {"http", "https"} or not parsed.hostname:
        raise ValueError("OCPP_LARAVEL_BASE_URL must be an absolute HTTP(S) URL")

    if parsed.username or parsed.password:
        raise ValueError("OCPP_LARAVEL_BASE_URL must not contain credentials")

    if parsed.scheme == "https":
        return

    if environment not in LOCAL_ENVIRONMENTS:
        raise ValueError("OCPP_LARAVEL_BASE_URL must use HTTPS outside local environments")

    if not _is_loopback_host(parsed.hostname):
        raise ValueError(
            "HTTP OCPP_LARAVEL_BASE_URL is limited to loopback and local Docker hosts"
        )


def _validate_gateway_transport(
    environment: str,
    tls_mode: str,
    public_url: str | None,
    certificate_file: str | None,
    private_key_file: str | None,
) -> None:
    if tls_mode not in TLS_MODES:
        raise ValueError("OCPP_GATEWAY_TLS_MODE must be disabled, direct or proxy")

    if environment not in LOCAL_ENVIRONMENTS and tls_mode == "disabled":
        raise ValueError("OCPP WebSocket TLS cannot be disabled outside local environments")

    if tls_mode == "direct" and (not certificate_file or not private_key_file):
        raise ValueError(
            "Direct OCPP TLS requires OCPP_GATEWAY_TLS_CERTIFICATE_FILE and "
            "OCPP_GATEWAY_TLS_PRIVATE_KEY_FILE"
        )

    if tls_mode in {"direct", "proxy"}:
        if public_url is None:
            raise ValueError("Secure OCPP transport requires OCPP_GATEWAY_PUBLIC_URL")

        parsed = urlsplit(public_url)
        if parsed.scheme != "wss" or not parsed.hostname:
            raise ValueError("OCPP_GATEWAY_PUBLIC_URL must be an absolute wss:// URL")


@dataclass(frozen=True, slots=True)
class Settings:
    environment: str
    host: str
    port: int
    path_prefix: str
    tls_mode: str
    public_url: str | None
    tls_certificate_file: str | None
    tls_private_key_file: str | None
    laravel_base_url: str
    laravel_ca_file: str | None
    shared_secret: str
    heartbeat_interval_seconds: int
    http_timeout_seconds: float
    http_max_attempts: int
    command_poll_interval_seconds: float

    @classmethod
    def from_env(cls) -> "Settings":
        shared_secret = os.getenv("OCPP_GATEWAY_SHARED_SECRET", "")
        if len(shared_secret) < 32:
            raise ValueError("OCPP_GATEWAY_SHARED_SECRET must contain at least 32 characters")

        environment = os.getenv("OCPP_ENVIRONMENT", "local").strip().lower()
        path_prefix = "/" + os.getenv("OCPP_GATEWAY_PATH_PREFIX", "/ocpp").strip("/")
        tls_mode = os.getenv("OCPP_GATEWAY_TLS_MODE", "disabled").strip().lower()
        public_url = _optional_env("OCPP_GATEWAY_PUBLIC_URL")
        certificate_file = _optional_env("OCPP_GATEWAY_TLS_CERTIFICATE_FILE")
        private_key_file = _optional_env("OCPP_GATEWAY_TLS_PRIVATE_KEY_FILE")
        laravel_base_url = os.getenv(
            "OCPP_LARAVEL_BASE_URL",
            "http://host.docker.internal:8000/api/internal/ocpp",
        ).rstrip("/")

        _validate_laravel_url(laravel_base_url, environment)
        _validate_gateway_transport(
            environment,
            tls_mode,
            public_url,
            certificate_file,
            private_key_file,
        )

        return cls(
            environment=environment,
            host=os.getenv("OCPP_GATEWAY_HOST", "0.0.0.0"),
            port=int(os.getenv("OCPP_GATEWAY_PORT", "9000")),
            path_prefix=path_prefix,
            tls_mode=tls_mode,
            public_url=public_url,
            tls_certificate_file=certificate_file,
            tls_private_key_file=private_key_file,
            laravel_base_url=laravel_base_url,
            laravel_ca_file=_optional_env("OCPP_LARAVEL_CA_FILE"),
            shared_secret=shared_secret,
            heartbeat_interval_seconds=int(
                os.getenv("OCPP_HEARTBEAT_INTERVAL_SECONDS", "30")
            ),
            http_timeout_seconds=float(os.getenv("OCPP_HTTP_TIMEOUT_SECONDS", "10")),
            http_max_attempts=max(1, int(os.getenv("OCPP_HTTP_MAX_ATTEMPTS", "3"))),
            command_poll_interval_seconds=max(
                0.5,
                float(os.getenv("OCPP_COMMAND_POLL_INTERVAL_SECONDS", "1.5")),
            ),
        )
