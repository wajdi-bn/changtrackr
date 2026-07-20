from __future__ import annotations

from datetime import datetime, timezone
from enum import Enum
from typing import Any, Protocol

from ocpp.routing import on
from ocpp.v16 import ChargePoint as Ocpp16ChargePoint
from ocpp.v16 import call, call_result
from ocpp.v16.enums import Action, AvailabilityType, RegistrationStatus, ResetType

from chargetrackr_ocpp.events import build_event

BOOT_OPTIONAL_FIELDS = {
    "charge_point_serial_number": "chargePointSerialNumber",
    "charge_box_serial_number": "chargeBoxSerialNumber",
    "firmware_version": "firmwareVersion",
    "iccid": "iccid",
    "imsi": "imsi",
    "meter_type": "meterType",
    "meter_serial_number": "meterSerialNumber",
}


class EventPublisher(Protocol):
    async def publish_event(self, event: dict[str, Any]) -> dict[str, Any]: ...


def _value(value: Any) -> Any:
    return value.value if isinstance(value, Enum) else value


def _camel_case(value: str) -> str:
    head, *tail = value.split("_")
    return head + "".join(part[:1].upper() + part[1:] for part in tail)


def _ocpp_payload(value: Any) -> Any:
    if isinstance(value, Enum):
        return value.value
    if isinstance(value, dict):
        return {_camel_case(str(key)): _ocpp_payload(item) for key, item in value.items()}
    if isinstance(value, list):
        return [_ocpp_payload(item) for item in value]
    return value


class ChargeTrackrChargePoint(Ocpp16ChargePoint):
    def __init__(
        self,
        station_identity: str,
        connection: Any,
        publisher: EventPublisher,
        connection_id: str,
        heartbeat_interval_seconds: int,
    ) -> None:
        super().__init__(station_identity, connection)
        self._publisher = publisher
        self._connection_id = connection_id
        self._heartbeat_interval_seconds = heartbeat_interval_seconds

    async def execute_command(
        self,
        action: str,
        payload: dict[str, Any],
    ) -> dict[str, Any]:
        if action == "RemoteStartTransaction":
            response = await self.call(
                call.RemoteStartTransaction(
                    id_tag=str(payload["idTag"]),
                    connector_id=int(payload["connectorId"]),
                )
            )
        elif action == "RemoteStopTransaction":
            response = await self.call(
                call.RemoteStopTransaction(
                    transaction_id=int(payload["transactionId"]),
                )
            )
        elif action == "Reset":
            reset_type = ResetType(str(payload["type"]))
            if reset_type is not ResetType.soft:
                raise ValueError("Only Soft Reset is allowed")
            response = await self.call(call.Reset(type=reset_type))
        elif action == "UnlockConnector":
            response = await self.call(
                call.UnlockConnector(connector_id=int(payload["connectorId"]))
            )
        elif action == "ChangeAvailability":
            response = await self.call(
                call.ChangeAvailability(
                    connector_id=int(payload["connectorId"]),
                    type=AvailabilityType(str(payload["type"])),
                )
            )
        else:
            raise ValueError(f"Unsupported OCPP command: {action}")

        ocpp_status = str(_value(response.status))
        normalized_status = (
            "accepted"
            if ocpp_status in {"Accepted", "Unlocked", "Scheduled"}
            else "rejected"
        )
        return {"status": normalized_status, "ocppStatus": ocpp_status}

    async def _publish(
        self,
        action: str,
        message_id: str,
        payload: dict[str, Any],
        occurred_at: str | None = None,
    ) -> dict[str, Any]:
        response = await self._publisher.publish_event(
            build_event(
                station_identity=self.id,
                connection_id=self._connection_id,
                action=action,
                message_id=message_id,
                payload=payload,
                occurred_at=occurred_at,
            )
        )
        return response.get("ocpp_response", {})

    @on(Action.boot_notification)
    async def on_boot_notification(
        self,
        charge_point_vendor: str,
        charge_point_model: str,
        call_unique_id: str,
        **kwargs: Any,
    ) -> call_result.BootNotification:
        payload = {
            "chargePointVendor": charge_point_vendor,
            "chargePointModel": charge_point_model,
        }
        payload.update(
            {
                BOOT_OPTIONAL_FIELDS[key]: _value(value)
                for key, value in kwargs.items()
                if key in BOOT_OPTIONAL_FIELDS and value is not None
            }
        )
        await self._publish("BootNotification", call_unique_id, payload)

        return call_result.BootNotification(
            current_time=datetime.now(timezone.utc).isoformat(),
            interval=self._heartbeat_interval_seconds,
            status=RegistrationStatus.accepted,
        )

    @on(Action.heartbeat)
    async def on_heartbeat(self, call_unique_id: str) -> call_result.Heartbeat:
        await self._publish("Heartbeat", call_unique_id, {})

        return call_result.Heartbeat(current_time=datetime.now(timezone.utc).isoformat())

    @on(Action.status_notification)
    async def on_status_notification(
        self,
        connector_id: int,
        error_code: Any,
        status: Any,
        call_unique_id: str,
        timestamp: str | None = None,
        info: str | None = None,
        vendor_id: str | None = None,
        vendor_error_code: str | None = None,
        **_: Any,
    ) -> call_result.StatusNotification:
        payload = {
            "connectorId": connector_id,
            "errorCode": _value(error_code),
            "status": _value(status),
        }
        optional = {
            "timestamp": timestamp,
            "info": info,
            "vendorId": vendor_id,
            "vendorErrorCode": vendor_error_code,
        }
        payload.update({key: value for key, value in optional.items() if value is not None})
        await self._publish(
            "StatusNotification",
            call_unique_id,
            payload,
            occurred_at=timestamp,
        )

        return call_result.StatusNotification()

    @on(Action.authorize)
    async def on_authorize(
        self,
        id_tag: str,
        call_unique_id: str,
    ) -> call_result.Authorize:
        response = await self._publish(
            "Authorize",
            call_unique_id,
            {"idTag": id_tag},
        )

        return call_result.Authorize(id_tag_info=response["idTagInfo"])

    @on(Action.start_transaction)
    async def on_start_transaction(
        self,
        connector_id: int,
        id_tag: str,
        meter_start: int,
        timestamp: str,
        call_unique_id: str,
        reservation_id: int | None = None,
        **_: Any,
    ) -> call_result.StartTransaction:
        payload = {
            "connectorId": connector_id,
            "idTag": id_tag,
            "meterStart": meter_start,
            "timestamp": timestamp,
        }
        if reservation_id is not None:
            payload["reservationId"] = reservation_id

        response = await self._publish(
            "StartTransaction",
            call_unique_id,
            payload,
            occurred_at=timestamp,
        )

        return call_result.StartTransaction(
            transaction_id=response["transactionId"],
            id_tag_info=response["idTagInfo"],
        )

    @on(Action.meter_values)
    async def on_meter_values(
        self,
        connector_id: int,
        meter_value: list[dict[str, Any]],
        call_unique_id: str,
        transaction_id: int | None = None,
        **_: Any,
    ) -> call_result.MeterValues:
        payload: dict[str, Any] = {
            "connectorId": connector_id,
            "meterValue": _ocpp_payload(meter_value),
        }
        if transaction_id is not None:
            payload["transactionId"] = transaction_id

        await self._publish("MeterValues", call_unique_id, payload)

        return call_result.MeterValues()

    @on(Action.stop_transaction)
    async def on_stop_transaction(
        self,
        meter_stop: int,
        timestamp: str,
        transaction_id: int,
        call_unique_id: str,
        id_tag: str | None = None,
        reason: Any | None = None,
        transaction_data: list[dict[str, Any]] | None = None,
        **_: Any,
    ) -> call_result.StopTransaction:
        payload: dict[str, Any] = {
            "meterStop": meter_stop,
            "timestamp": timestamp,
            "transactionId": transaction_id,
        }
        if id_tag is not None:
            payload["idTag"] = id_tag
        if reason is not None:
            payload["reason"] = _value(reason)
        if transaction_data is not None:
            payload["transactionData"] = _ocpp_payload(transaction_data)

        response = await self._publish(
            "StopTransaction",
            call_unique_id,
            payload,
            occurred_at=timestamp,
        )

        return call_result.StopTransaction(id_tag_info=response.get("idTagInfo"))
