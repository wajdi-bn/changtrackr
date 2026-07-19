from __future__ import annotations

from dataclasses import dataclass
import os


@dataclass(frozen=True, slots=True)
class Settings:
    host: str
    port: int
    path_prefix: str
    laravel_base_url: str
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

        path_prefix = "/" + os.getenv("OCPP_GATEWAY_PATH_PREFIX", "/ocpp").strip("/")

        return cls(
            host=os.getenv("OCPP_GATEWAY_HOST", "0.0.0.0"),
            port=int(os.getenv("OCPP_GATEWAY_PORT", "9000")),
            path_prefix=path_prefix,
            laravel_base_url=os.getenv(
                "OCPP_LARAVEL_BASE_URL",
                "http://host.docker.internal:8000/api/internal/ocpp",
            ).rstrip("/"),
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
