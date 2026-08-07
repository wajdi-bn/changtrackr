from __future__ import annotations

import os
import ssl

import pytest

from chargetrackr_ocpp.config import Settings
from chargetrackr_ocpp.tls import build_backend_ssl_context, build_server_ssl_context


OCPP_ENV_KEYS = tuple(key for key in os.environ if key.startswith("OCPP_"))


def load_settings(monkeypatch: pytest.MonkeyPatch, **values: str) -> Settings:
    for key in OCPP_ENV_KEYS:
        monkeypatch.delenv(key, raising=False)

    monkeypatch.setenv("OCPP_GATEWAY_SHARED_SECRET", "s" * 64)
    for key, value in values.items():
        monkeypatch.setenv(key, value)

    return Settings.from_env()


def test_local_transport_accepts_only_known_local_http_hosts(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    settings = load_settings(monkeypatch)

    assert settings.environment == "local"
    assert settings.tls_mode == "disabled"
    assert settings.laravel_base_url.startswith("http://host.docker.internal")
    assert build_server_ssl_context(settings) is None

    with pytest.raises(ValueError, match="loopback and local Docker hosts"):
        load_settings(
            monkeypatch,
            OCPP_LARAVEL_BASE_URL="http://api.example.com/api/internal/ocpp",
        )


def test_production_rejects_plain_websocket_transport(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    with pytest.raises(ValueError, match="TLS cannot be disabled"):
        load_settings(
            monkeypatch,
            OCPP_ENVIRONMENT="production",
            OCPP_LARAVEL_BASE_URL="https://api.example.com/api/internal/ocpp",
        )


def test_production_rejects_plain_backend_http(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    with pytest.raises(ValueError, match="must use HTTPS"):
        load_settings(
            monkeypatch,
            OCPP_ENVIRONMENT="production",
            OCPP_GATEWAY_TLS_MODE="proxy",
            OCPP_GATEWAY_PUBLIC_URL="wss://ocpp.example.com/ocpp",
            OCPP_LARAVEL_BASE_URL="http://localhost:8000/api/internal/ocpp",
        )


def test_proxy_mode_requires_a_public_wss_url(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    with pytest.raises(ValueError, match="absolute wss:// URL"):
        load_settings(
            monkeypatch,
            OCPP_ENVIRONMENT="production",
            OCPP_GATEWAY_TLS_MODE="proxy",
            OCPP_GATEWAY_PUBLIC_URL="ws://ocpp.example.com/ocpp",
            OCPP_LARAVEL_BASE_URL="https://api.example.com/api/internal/ocpp",
        )


def test_direct_mode_requires_certificate_and_private_key(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    with pytest.raises(ValueError, match="Direct OCPP TLS requires"):
        load_settings(
            monkeypatch,
            OCPP_ENVIRONMENT="production",
            OCPP_GATEWAY_TLS_MODE="direct",
            OCPP_GATEWAY_PUBLIC_URL="wss://ocpp.example.com/ocpp",
            OCPP_LARAVEL_BASE_URL="https://api.example.com/api/internal/ocpp",
        )


def test_secure_proxy_uses_verified_backend_tls(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    settings = load_settings(
        monkeypatch,
        OCPP_ENVIRONMENT="production",
        OCPP_GATEWAY_TLS_MODE="proxy",
        OCPP_GATEWAY_PUBLIC_URL="wss://ocpp.example.com/ocpp",
        OCPP_LARAVEL_BASE_URL="https://api.example.com/api/internal/ocpp",
    )

    context = build_backend_ssl_context(settings)

    assert settings.public_url == "wss://ocpp.example.com/ocpp"
    assert context.check_hostname is True
    assert context.verify_mode.name == "CERT_REQUIRED"


def test_direct_mode_builds_a_tls_12_server_context(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    settings = load_settings(
        monkeypatch,
        OCPP_ENVIRONMENT="production",
        OCPP_GATEWAY_TLS_MODE="direct",
        OCPP_GATEWAY_PUBLIC_URL="wss://ocpp.example.com/ocpp",
        OCPP_GATEWAY_TLS_CERTIFICATE_FILE="/run/tls/fullchain.pem",
        OCPP_GATEWAY_TLS_PRIVATE_KEY_FILE="/run/tls/privkey.pem",
        OCPP_LARAVEL_BASE_URL="https://api.example.com/api/internal/ocpp",
    )

    class FakeContext:
        minimum_version: ssl.TLSVersion | None = None
        certificate: tuple[str, str] | None = None

        def load_cert_chain(self, certfile: str, keyfile: str) -> None:
            self.certificate = certfile, keyfile

    context = FakeContext()
    monkeypatch.setattr(
        "chargetrackr_ocpp.tls.ssl.SSLContext",
        lambda _protocol: context,
    )

    assert build_server_ssl_context(settings) is context
    assert context.minimum_version == ssl.TLSVersion.TLSv1_2
    assert context.certificate == (
        "/run/tls/fullchain.pem",
        "/run/tls/privkey.pem",
    )
