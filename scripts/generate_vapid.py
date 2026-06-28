#!/usr/bin/env python3
"""Generate VAPID keys compatible with pywebpush (same format as web-push CLI)."""

from __future__ import annotations

import sys

try:
    from py_vapid import Vapid02
except ImportError:
    print("Install: pip install py-vapid", file=sys.stderr)
    sys.exit(1)

v = Vapid02()
v.generate_keys()
print("Add to .env:\n")
print(f"VAPID_PUBLIC_KEY={v.public_key.decode()}")
print(f"VAPID_PRIVATE_KEY={v.private_key.decode()}")
print("VAPID_SUBJECT=mailto:nexalert@yourdomain.com")
