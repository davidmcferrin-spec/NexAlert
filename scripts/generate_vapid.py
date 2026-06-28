#!/usr/bin/env python3
"""Generate VAPID keys compatible with pywebpush (same format as web-push CLI)."""

from __future__ import annotations

import os
import sys

try:
    from cryptography.hazmat.primitives import serialization
    from py_vapid import Vapid02
    from py_vapid.utils import b64urlencode
except ImportError:
    print("Install: pip install py-vapid", file=sys.stderr)
    sys.exit(1)


def vapid_public_b64url(vapid: Vapid02) -> str:
    raw = vapid.public_key.public_bytes(
        serialization.Encoding.X962,
        serialization.PublicFormat.UncompressedPoint,
    )
    encoded = b64urlencode(raw)
    return encoded.decode() if isinstance(encoded, bytes) else str(encoded)


def vapid_private_b64url(vapid: Vapid02) -> str:
    raw = vapid.private_key.private_numbers().private_value.to_bytes(32, "big")
    encoded = b64urlencode(raw)
    return encoded.decode() if isinstance(encoded, bytes) else str(encoded)


v = Vapid02()
v.generate_keys()

subject = "mailto:nexalert@yourdomain.com"
from_addr = os.environ.get("MAIL_FROM_ADDRESS", "").strip()
if from_addr and "@" in from_addr and "." in from_addr:
    subject = f"mailto:{from_addr}"

print("Add to .env:\n")
print(f"VAPID_PUBLIC_KEY={vapid_public_b64url(v)}")
print(f"VAPID_PRIVATE_KEY={vapid_private_b64url(v)}")
print(f"VAPID_SUBJECT={subject}")
