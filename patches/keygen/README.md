# Keygen — run this on your PHONE (Termux), not on the server

The Ed25519 **secret key** signs every activation response. If it leaks, someone
can forge activations. So it must never be generated on the server, land in its
shell history, or sit in a backup. Generate it on your own device, then paste
**only** the `.env` line onto the server.

You get two lines:
- `connect.signKeyV2 = …`  → goes in the panel's `.env` (SECRET).
- `SIGN_PUBKEY_B64 = "…"`   → goes in `eagle_connector_v2.cpp` (public, not secret).

Pick either tool — both produce the exact same, cross-checked format (verified:
PHP libsodium signs, the C++ OpenSSL client verifies).

## Option A — PHP (simplest in Termux)

```
pkg update && pkg install php
php genkey-sign.php
```

## Option B — Python

```
pkg install python
pip install pynacl
python genkey.py
```

## After generating

1. On the server, open `.env` and add the `connect.signKeyV2 = …` line. Do not
   paste it into any chat, commit, or screenshot.
2. In `eagle_connector_v2.cpp`, set `SIGN_PUBKEY_B64` to the public key.
3. Rebuild the client. Done.

**Rotating the key:** run the tool again, replace both places, ship a new client
build. The old secret stops working the moment you change `.env`.

**Never** put the `connect.signKeyV2` value in git, a zip you share, a support
chat, or a bug report. Treat it like your database password.
