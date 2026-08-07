from __future__ import annotations

import base64
from types import SimpleNamespace

from chargetrackr_ocpp.auth import parse_basic_authorization
from chargetrackr_ocpp.server import health_check_response, station_identity_from_path


def test_basic_auth_parser_accepts_valid_credentials() -> None:
    token = base64.b64encode(b"CT-TUN-001:station-secret").decode()

    assert parse_basic_authorization(f"Basic {token}") == (
        "CT-TUN-001",
        "station-secret",
    )


def test_basic_auth_parser_rejects_malformed_headers() -> None:
    assert parse_basic_authorization(None) is None
    assert parse_basic_authorization("Bearer token") is None
    assert parse_basic_authorization("Basic not-base64") is None


def test_station_identity_is_limited_to_one_path_segment() -> None:
    assert station_identity_from_path("/ocpp/CT-TUN-001", "/ocpp") == "CT-TUN-001"
    assert station_identity_from_path("/other/CT-TUN-001", "/ocpp") is None
    assert station_identity_from_path("/ocpp/org/CT-TUN-001", "/ocpp") is None


def test_health_endpoint_is_handled_before_ocpp_authentication() -> None:
    class Connection:
        @staticmethod
        def respond(status: object, body: str) -> tuple[object, str]:
            return status, body

    assert health_check_response(Connection(), SimpleNamespace(path="/health")) == (
        200,
        "ok\n",
    )
    assert health_check_response(Connection(), SimpleNamespace(path="/ocpp/station")) is None
