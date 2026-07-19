from __future__ import annotations

import base64

from chargetrackr_ocpp.auth import parse_basic_authorization
from chargetrackr_ocpp.server import station_identity_from_path


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
