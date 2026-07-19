from __future__ import annotations

from hashlib import sha256
import hmac

from chargetrackr_ocpp.events import build_event, deterministic_event_id
from chargetrackr_ocpp.signing import sign_json_body


def test_signature_matches_laravel_canonical_contract() -> None:
    body = b'{"action":"Heartbeat","payload":{}}'
    secret = "gateway-test-secret-0123456789abcdef"
    signed = sign_json_body(
        body,
        secret,
        timestamp=1_750_000_000,
        request_id="251d832f-0c06-4ec0-b0d7-cf62ba922870",
    )
    canonical = b"1750000000.251d832f-0c06-4ec0-b0d7-cf62ba922870." + body
    expected = hmac.new(secret.encode(), canonical, sha256).hexdigest()

    assert signed.headers["X-ChargeTrackr-Signature"] == f"v1={expected}"


def test_event_identifier_is_deterministic_for_an_ocpp_call() -> None:
    first = deterministic_event_id("CT-TUN-001", "Heartbeat", "message-1")
    second = deterministic_event_id("CT-TUN-001", "Heartbeat", "message-1")
    other = deterministic_event_id("CT-TUN-001", "Heartbeat", "message-2")

    assert first == second
    assert first != other


def test_event_contract_keeps_raw_payload() -> None:
    event = build_event(
        station_identity="CT-TUN-001",
        connection_id="15679535-609d-4c41-a5b3-1fa7ff0aa4b2",
        action="StatusNotification",
        message_id="message-1",
        payload={"connectorId": 1, "status": "Charging", "errorCode": "NoError"},
        occurred_at="2026-07-18T10:00:00Z",
    )

    assert event["protocol_version"] == "1.6"
    assert event["payload"]["status"] == "Charging"
    assert event["occurred_at"] == "2026-07-18T10:00:00Z"
