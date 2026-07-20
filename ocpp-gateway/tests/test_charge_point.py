from __future__ import annotations

from typing import Any
from unittest.mock import AsyncMock

import pytest
from ocpp.v16.enums import ChargePointErrorCode, ChargePointStatus

from chargetrackr_ocpp.charge_point import ChargeTrackrChargePoint


class DummyConnection:
    async def recv(self) -> str:
        raise RuntimeError("not used by direct handler tests")

    async def send(self, _: str) -> None:
        return None


class RecordingPublisher:
    def __init__(self) -> None:
        self.events: list[dict[str, Any]] = []

    async def publish_event(self, event: dict[str, Any]) -> dict[str, Any]:
        self.events.append(event)
        responses = {
            "Authorize": {"idTagInfo": {"status": "Accepted"}},
            "StartTransaction": {
                "transactionId": 42,
                "idTagInfo": {"status": "Accepted"},
            },
            "StopTransaction": {"idTagInfo": {"status": "Accepted"}},
        }
        return {"accepted": True, "ocpp_response": responses.get(event["action"], {})}


@pytest.fixture
def charge_point() -> tuple[ChargeTrackrChargePoint, RecordingPublisher]:
    publisher = RecordingPublisher()
    point = ChargeTrackrChargePoint(
        "CT-TUN-001",
        DummyConnection(),
        publisher,
        "15679535-609d-4c41-a5b3-1fa7ff0aa4b2",
        30,
    )
    return point, publisher


async def test_boot_and_heartbeat_are_published(
    charge_point: tuple[ChargeTrackrChargePoint, RecordingPublisher],
) -> None:
    point, publisher = charge_point

    boot = await point.on_boot_notification(
        "SAP",
        "ChargeTrackr Simulator",
        "boot-message",
        firmware_version="4.10.1",
    )
    await point.on_heartbeat("heartbeat-message")

    assert boot.interval == 30
    assert [event["action"] for event in publisher.events] == [
        "BootNotification",
        "Heartbeat",
    ]
    assert publisher.events[0]["payload"]["firmwareVersion"] == "4.10.1"


async def test_status_notification_preserves_ocpp_facts(
    charge_point: tuple[ChargeTrackrChargePoint, RecordingPublisher],
) -> None:
    point, publisher = charge_point

    await point.on_status_notification(
        connector_id=1,
        error_code=ChargePointErrorCode.no_error,
        status=ChargePointStatus.charging,
        call_unique_id="status-message",
        timestamp="2026-07-18T10:00:00Z",
    )

    event = publisher.events[0]
    assert event["action"] == "StatusNotification"
    assert event["payload"] == {
        "connectorId": 1,
        "errorCode": "NoError",
        "status": "Charging",
        "timestamp": "2026-07-18T10:00:00Z",
    }


async def test_transaction_messages_are_published_and_laravel_responses_are_returned(
    charge_point: tuple[ChargeTrackrChargePoint, RecordingPublisher],
) -> None:
    point, publisher = charge_point

    authorize = await point.on_authorize("TEST-TAG-001", "authorize-message")
    start = await point.on_start_transaction(
        connector_id=1,
        id_tag="TEST-TAG-001",
        meter_start=100000,
        timestamp="2026-07-18T10:00:00Z",
        call_unique_id="start-message",
    )
    await point.on_meter_values(
        connector_id=1,
        transaction_id=42,
        meter_value=[{
            "timestamp": "2026-07-18T10:05:00Z",
            "sampled_value": [{
                "value": "102500",
                "measurand": "Energy.Active.Import.Register",
                "unit": "Wh",
            }],
        }],
        call_unique_id="meter-message",
    )
    stop = await point.on_stop_transaction(
        meter_stop=104000,
        timestamp="2026-07-18T10:10:00Z",
        transaction_id=42,
        call_unique_id="stop-message",
        id_tag="TEST-TAG-001",
        reason="EVDisconnected",
    )

    assert authorize.id_tag_info["status"] == "Accepted"
    assert start.transaction_id == 42
    assert stop.id_tag_info["status"] == "Accepted"
    assert [event["action"] for event in publisher.events] == [
        "Authorize",
        "StartTransaction",
        "MeterValues",
        "StopTransaction",
    ]
    assert publisher.events[2]["payload"]["meterValue"][0]["sampledValue"][0]["unit"] == "Wh"


async def test_remote_start_and_stop_commands_are_sent_to_the_station(
    charge_point: tuple[ChargeTrackrChargePoint, RecordingPublisher],
) -> None:
    point, _ = charge_point
    point.call = AsyncMock(side_effect=[
        type("Response", (), {"status": "Accepted"})(),
        type("Response", (), {"status": "Accepted"})(),
    ])

    started = await point.execute_command(
        "RemoteStartTransaction",
        {"idTag": "APP12345678901234567", "connectorId": 1},
    )
    stopped = await point.execute_command(
        "RemoteStopTransaction",
        {"transactionId": 42},
    )

    assert started == {"status": "accepted", "ocppStatus": "Accepted"}
    assert stopped == {"status": "accepted", "ocppStatus": "Accepted"}
    assert point.call.await_count == 2


async def test_supervision_commands_are_sent_and_normalized(
    charge_point: tuple[ChargeTrackrChargePoint, RecordingPublisher],
) -> None:
    point, _ = charge_point
    point.call = AsyncMock(side_effect=[
        type("Response", (), {"status": "Accepted"})(),
        type("Response", (), {"status": "Unlocked"})(),
        type("Response", (), {"status": "Scheduled"})(),
        type("Response", (), {"status": "Rejected"})(),
    ])

    reset = await point.execute_command("Reset", {"type": "Soft"})
    unlock = await point.execute_command("UnlockConnector", {"connectorId": 2})
    availability = await point.execute_command(
        "ChangeAvailability",
        {"connectorId": 0, "type": "Inoperative"},
    )
    rejected = await point.execute_command(
        "ChangeAvailability",
        {"connectorId": 0, "type": "Operative"},
    )

    assert reset == {"status": "accepted", "ocppStatus": "Accepted"}
    assert unlock == {"status": "accepted", "ocppStatus": "Unlocked"}
    assert availability == {"status": "accepted", "ocppStatus": "Scheduled"}
    assert rejected == {"status": "rejected", "ocppStatus": "Rejected"}
    assert point.call.await_count == 4


async def test_hard_reset_is_never_forwarded(
    charge_point: tuple[ChargeTrackrChargePoint, RecordingPublisher],
) -> None:
    point, _ = charge_point
    point.call = AsyncMock()

    with pytest.raises(ValueError, match="Only Soft Reset"):
        await point.execute_command("Reset", {"type": "Hard"})

    point.call.assert_not_awaited()
