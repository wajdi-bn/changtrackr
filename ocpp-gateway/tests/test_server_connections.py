from __future__ import annotations

import asyncio
from types import SimpleNamespace
from typing import Any
from unittest.mock import AsyncMock, Mock

import pytest

from chargetrackr_ocpp.api_client import GatewayApiError
from chargetrackr_ocpp import server


class FakeWebSocket:
    def __init__(
        self,
        *,
        identity: str = "CT-TUN-001",
        username: str = "CT-TUN-001",
    ) -> None:
        import base64

        credentials = base64.b64encode(
            f"{username}:station-secret".encode()
        ).decode()
        self.request = SimpleNamespace(
            path=f"/ocpp/{identity}",
            headers={"Authorization": f"Basic {credentials}"},
        )
        self.subprotocol = "ocpp1.6"
        self.close = AsyncMock()
        self.close_code = 1000
        self.close_reason = "normal"


def settings() -> SimpleNamespace:
    return SimpleNamespace(
        path_prefix="/ocpp",
        heartbeat_interval_seconds=30,
        command_poll_interval_seconds=1.5,
    )


async def test_username_must_match_station_path_before_backend_authentication() -> None:
    websocket = FakeWebSocket(username="CT-TUN-999")
    api_client = SimpleNamespace(
        authenticate_station=AsyncMock(),
        publish_event=AsyncMock(),
    )

    await server.handle_connection(websocket, settings(), api_client)

    websocket.close.assert_awaited_once_with(
        code=1008,
        reason="Station identity mismatch",
    )
    api_client.authenticate_station.assert_not_awaited()
    api_client.publish_event.assert_not_awaited()


async def test_connection_is_closed_cleanly_when_open_event_cannot_be_persisted(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    websocket = FakeWebSocket()
    charge_point_factory = Mock()
    monkeypatch.setattr(server, "ChargeTrackrChargePoint", charge_point_factory)
    api_client = SimpleNamespace(
        authenticate_station=AsyncMock(return_value=True),
        publish_event=AsyncMock(side_effect=GatewayApiError("backend unavailable")),
    )

    await server.handle_connection(websocket, settings(), api_client)

    websocket.close.assert_awaited_once_with(
        code=1013,
        reason="Event service unavailable",
    )
    event = api_client.publish_event.await_args.args[0]
    assert event["action"] == "ConnectionOpened"
    charge_point_factory.assert_not_called()


async def test_station_can_reconnect_after_a_transient_open_event_failure(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    class FakeChargePoint:
        def __init__(self, identity: str, *_: Any) -> None:
            self.id = identity

        async def start(self) -> None:
            return None

    async def idle_poll(*_: Any) -> None:
        await asyncio.Future()

    monkeypatch.setattr(server, "ChargeTrackrChargePoint", FakeChargePoint)
    monkeypatch.setattr(server, "poll_commands", idle_poll)

    api_client = SimpleNamespace(
        authenticate_station=AsyncMock(return_value=True),
        publish_event=AsyncMock(
            side_effect=[
                GatewayApiError("temporary outage"),
                {"accepted": True},
                {"accepted": True},
            ]
        ),
    )
    first_connection = FakeWebSocket()
    second_connection = FakeWebSocket()

    await server.handle_connection(first_connection, settings(), api_client)
    await server.handle_connection(second_connection, settings(), api_client)

    first_connection.close.assert_awaited_once_with(
        code=1013,
        reason="Event service unavailable",
    )
    second_connection.close.assert_not_awaited()
    actions = [call.args[0]["action"] for call in api_client.publish_event.await_args_list]
    assert actions == ["ConnectionOpened", "ConnectionOpened", "ConnectionClosed"]
