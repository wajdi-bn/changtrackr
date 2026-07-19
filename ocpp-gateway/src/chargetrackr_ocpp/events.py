from __future__ import annotations

from datetime import datetime, timezone
from typing import Any
from uuid import NAMESPACE_URL, uuid5


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def deterministic_event_id(station_identity: str, action: str, message_id: str) -> str:
    return str(
        uuid5(
            NAMESPACE_URL,
            f"https://chargetrackr.local/ocpp/{station_identity}/{action}/{message_id}",
        )
    )


def build_event(
    *,
    station_identity: str,
    connection_id: str,
    action: str,
    message_id: str,
    payload: dict[str, Any],
    occurred_at: str | None = None,
) -> dict[str, Any]:
    return {
        "event_id": deterministic_event_id(station_identity, action, message_id),
        "connection_id": connection_id,
        "station_identity": station_identity,
        "message_id": message_id,
        "protocol_version": "1.6",
        "action": action,
        "payload": payload,
        "occurred_at": occurred_at or utc_now(),
    }
