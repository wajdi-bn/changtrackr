from __future__ import annotations

import ssl

from chargetrackr_ocpp.config import Settings


def build_server_ssl_context(settings: Settings) -> ssl.SSLContext | None:
    if settings.tls_mode != "direct":
        return None

    if not settings.tls_certificate_file or not settings.tls_private_key_file:
        raise ValueError("Direct OCPP TLS certificate and private key are required")

    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(
        certfile=settings.tls_certificate_file,
        keyfile=settings.tls_private_key_file,
    )
    return context


def build_backend_ssl_context(settings: Settings) -> ssl.SSLContext:
    context = ssl.create_default_context(cafile=settings.laravel_ca_file)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    return context
