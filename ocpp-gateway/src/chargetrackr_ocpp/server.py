from __future__ import annotations

import asyncio
from contextlib import suppress
from http import HTTPStatus
import logging
from typing import Any
from urllib.parse import unquote
from uuid import uuid4

from websockets.asyncio.server import ServerConnection, serve
from websockets.exceptions import ConnectionClosed

from chargetrackr_ocpp.api_client import GatewayApiError, LaravelOcppClient
from chargetrackr_ocpp.auth import parse_basic_authorization
from chargetrackr_ocpp.charge_point import ChargeTrackrChargePoint
from chargetrackr_ocpp.config import Settings
from chargetrackr_ocpp.events import build_event
from chargetrackr_ocpp.tls import build_server_ssl_context

LOGGER = logging.getLogger(__name__)


def health_check_response(connection: Any, request: Any) -> Any | None:
    if request.path != "/health":
        return None

    return connection.respond(HTTPStatus.OK, "ok\n")


async def poll_commands(
    charge_point: ChargeTrackrChargePoint,
    api_client: LaravelOcppClient,
    connection_id: str,
    poll_interval_seconds: float,
) -> None:
    while True:
        try:
            command = await api_client.claim_command(charge_point.id, connection_id)
            if command is None:
                await asyncio.sleep(poll_interval_seconds)
                continue

            try:
                result = await charge_point.execute_command(
                    command["action"],
                    command.get("payload", {}),
                )
                status = result.get("status", "failed")
                normalized_status = status if status in {"accepted", "rejected"} else "failed"
                await api_client.complete_command(
                    command["uuid"],
                    connection_id,
                    normalized_status,
                    result,
                )
            except Exception as error:
                LOGGER.exception(
                    "OCPP command execution failed",
                    extra={"station": charge_point.id, "command": command.get("uuid")},
                )
                await api_client.complete_command(
                    command["uuid"],
                    connection_id,
                    "failed",
                    {},
                    str(error),
                )
        except GatewayApiError:
            LOGGER.exception(
                "Could not poll Laravel for OCPP commands",
                extra={"station": charge_point.id},
            )
            await asyncio.sleep(poll_interval_seconds)


def station_identity_from_path(path: str, prefix: str) -> str | None:
    clean_path = path.split("?", 1)[0].rstrip("/")
    expected_prefix = prefix.rstrip("/") + "/"
    if not clean_path.startswith(expected_prefix):
        return None

    identity = unquote(clean_path[len(expected_prefix) :])
    if not identity or "/" in identity or len(identity) > 80:
        return None

    return identity


async def handle_connection(
    websocket: ServerConnection,
    settings: Settings,
    api_client: LaravelOcppClient,
) -> None:
    identity = station_identity_from_path(websocket.request.path, settings.path_prefix)
    credentials = parse_basic_authorization(
        websocket.request.headers.get("Authorization")
    )

    if identity is None or credentials is None or websocket.subprotocol != "ocpp1.6":
        await websocket.close(code=1008, reason="Invalid OCPP connection request")
        return

    username, password = credentials
    if username != identity:
        LOGGER.warning(
            "Station identity does not match Basic Auth username",
            extra={"station": identity},
        )
        await websocket.close(code=1008, reason="Station identity mismatch")
        return

    try:
        authenticated = await api_client.authenticate_station(identity, username, password)
    except GatewayApiError:
        LOGGER.exception("Station authentication service is unavailable")
        await websocket.close(code=1013, reason="Authentication service unavailable")
        return

    if not authenticated:
        LOGGER.warning("Station authentication rejected", extra={"station": identity})
        await websocket.close(code=1008, reason="Station authentication failed")
        return

    connection_id = str(uuid4())
    opened_message_id = f"{connection_id}:opened"
    try:
        await api_client.publish_event(
            build_event(
                station_identity=identity,
                connection_id=connection_id,
                action="ConnectionOpened",
                message_id=opened_message_id,
                payload={},
            )
        )
    except GatewayApiError:
        LOGGER.exception(
            "Could not persist station connection",
            extra={"station": identity},
        )
        await websocket.close(code=1013, reason="Event service unavailable")
        return

    LOGGER.info("OCPP station connected", extra={"station": identity})

    charge_point = ChargeTrackrChargePoint(
        identity,
        websocket,
        api_client,
        connection_id,
        settings.heartbeat_interval_seconds,
    )
    command_task = asyncio.create_task(
        poll_commands(
            charge_point,
            api_client,
            connection_id,
            settings.command_poll_interval_seconds,
        )
    )

    try:
        await charge_point.start()
    except ConnectionClosed:
        pass
    finally:
        command_task.cancel()
        with suppress(asyncio.CancelledError):
            await command_task
        close_payload = {
            "code": websocket.close_code,
            "reason": websocket.close_reason or "connection_closed",
        }
        try:
            await api_client.publish_event(
                build_event(
                    station_identity=identity,
                    connection_id=connection_id,
                    action="ConnectionClosed",
                    message_id=f"{connection_id}:closed",
                    payload=close_payload,
                )
            )
        except GatewayApiError:
            LOGGER.exception(
                "Could not publish station disconnection", extra={"station": identity}
            )
        LOGGER.info("OCPP station disconnected", extra={"station": identity})


async def main() -> None:
    settings = Settings.from_env()
    ssl_context = build_server_ssl_context(settings)
    async with LaravelOcppClient(settings) as api_client:
        async with serve(
            lambda websocket: handle_connection(websocket, settings, api_client),
            settings.host,
            settings.port,
            subprotocols=["ocpp1.6"],
            ping_interval=20,
            ping_timeout=20,
            max_size=1024 * 1024,
            ssl=ssl_context,
            process_request=health_check_response,
        ):
            LOGGER.info(
                "OCPP Gateway listening on %s://%s:%s%s (TLS mode: %s)",
                "wss" if ssl_context else "ws",
                settings.host,
                settings.port,
                settings.path_prefix,
                settings.tls_mode,
            )
            await asyncio.Future()


def run() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )
    asyncio.run(main())
