from __future__ import annotations

import base64
import binascii


def parse_basic_authorization(header: str | None) -> tuple[str, str] | None:
    if not header:
        return None

    scheme, separator, token = header.partition(" ")
    if not separator or scheme.lower() != "basic" or not token:
        return None

    try:
        decoded = base64.b64decode(token, validate=True).decode("utf-8")
    except (binascii.Error, UnicodeDecodeError):
        return None

    username, separator, password = decoded.partition(":")
    if not separator or not username or not password:
        return None

    return username, password
