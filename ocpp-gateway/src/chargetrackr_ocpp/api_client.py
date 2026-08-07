from __future__ import annotations

import asyncio
import json
import logging
from typing import Any

import httpx

from chargetrackr_ocpp.config import Settings
from chargetrackr_ocpp.signing import sign_json_body
from chargetrackr_ocpp.tls import build_backend_ssl_context

LOGGER = logging.getLogger(__name__)


class GatewayApiError(RuntimeError):
    pass


class LaravelOcppClient:
    def __init__(
        self,
        settings: Settings,
        *,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        self._settings = settings
        self._client = httpx.AsyncClient(
            timeout=settings.http_timeout_seconds,
            transport=transport,
            verify=build_backend_ssl_context(settings),
        )

    async def __aenter__(self) -> "LaravelOcppClient":
        return self

    async def __aexit__(self, *_: object) -> None:
        await self.aclose()

    async def aclose(self) -> None:
        await self._client.aclose()

    async def authenticate_station(
        self,
        station_identity: str,
        username: str,
        password: str,
    ) -> bool:
        response = await self._post(
            "/authenticate",
            {
                "station_identity": station_identity,
                "username": username,
                "password": password,
                "protocol_version": "1.6",
            },
            retry_server_errors=False,
        )

        if response.status_code == 401:
            return False
        if response.status_code != 200:
            raise GatewayApiError(
                f"Laravel station authentication returned HTTP {response.status_code}"
            )

        return response.json().get("authenticated") is True

    async def publish_event(self, event: dict[str, Any]) -> dict[str, Any]:
        response = await self._post("/events", event, retry_server_errors=True)
        if response.status_code not in (200, 201):
            raise GatewayApiError(
                f"Laravel OCPP ingestion returned HTTP {response.status_code}"
            )

        return response.json()

    async def claim_command(
        self,
        station_identity: str,
        connection_id: str,
    ) -> dict[str, Any] | None:
        response = await self._post(
            "/commands/claim",
            {
                "station_identity": station_identity,
                "connection_id": connection_id,
            },
            retry_server_errors=True,
        )
        if response.status_code != 200:
            raise GatewayApiError(
                f"Laravel OCPP command claim returned HTTP {response.status_code}"
            )

        return response.json().get("command")

    async def complete_command(
        self,
        command_uuid: str,
        connection_id: str,
        status: str,
        result: dict[str, Any],
        message: str | None = None,
    ) -> None:
        payload: dict[str, Any] = {
            "connection_id": connection_id,
            "status": status,
            "result": result,
        }
        if message is not None:
            payload["message"] = message

        response = await self._post(
            f"/commands/{command_uuid}/result",
            payload,
            retry_server_errors=True,
        )
        if response.status_code != 200:
            raise GatewayApiError(
                f"Laravel OCPP command completion returned HTTP {response.status_code}"
            )

    async def _post(
        self,
        path: str,
        payload: dict[str, Any],
        *,
        retry_server_errors: bool,
    ) -> httpx.Response:
        body = json.dumps(
            payload,
            separators=(",", ":"),
            ensure_ascii=False,
        ).encode()
        max_attempts = self._settings.http_max_attempts if retry_server_errors else 1
        last_error: Exception | None = None

        for attempt in range(1, max_attempts + 1):
            signed = sign_json_body(body, self._settings.shared_secret)
            try:
                response = await self._client.post(
                    self._settings.laravel_base_url + path,
                    content=signed.body,
                    headers=signed.headers,
                )
                if response.status_code < 500 or not retry_server_errors:
                    return response
                last_error = GatewayApiError(
                    f"Laravel returned HTTP {response.status_code}"
                )
            except httpx.RequestError as error:
                last_error = error

            if attempt < max_attempts:
                LOGGER.warning(
                    "Laravel OCPP request failed; retrying",
                    extra={"path": path, "attempt": attempt},
                )
                await asyncio.sleep(0.25 * (2 ** (attempt - 1)))

        raise GatewayApiError("Laravel OCPP request failed after retries") from last_error
