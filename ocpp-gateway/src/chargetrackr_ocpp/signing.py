from __future__ import annotations

from dataclasses import dataclass
from hashlib import sha256
import hmac
import time
from uuid import uuid4


@dataclass(frozen=True, slots=True)
class SignedRequest:
    body: bytes
    headers: dict[str, str]


def sign_json_body(
    body: bytes,
    secret: str,
    *,
    timestamp: int | None = None,
    request_id: str | None = None,
) -> SignedRequest:
    timestamp = timestamp if timestamp is not None else int(time.time())
    request_id = request_id or str(uuid4())
    canonical = str(timestamp).encode() + b"." + request_id.encode() + b"." + body
    digest = hmac.new(secret.encode(), canonical, sha256).hexdigest()

    return SignedRequest(
        body=body,
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-ChargeTrackr-Timestamp": str(timestamp),
            "X-ChargeTrackr-Request-Id": request_id,
            "X-ChargeTrackr-Signature": f"v1={digest}",
        },
    )
