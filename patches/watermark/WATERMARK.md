# Forensic watermark — find out who leaked your build

A hidden, per-buyer code baked into each copy. Invisible to the user,
encrypted + authenticated so it can't be read or forged, and redundant so
zeroing one copy doesn't remove it. When a free copy shows up, you read the code
and it names the buyer who leaked it — even from an offline, re-branded copy.

## Files
- `wm_slots.h`   — include in your app; reserves the hidden slots (the app never
  reads them). Reference `WM_SLOTS_DATA` once so the linker keeps it.
- `wm_tool.c`    — VENDOR-ONLY tool. `embed` stamps a buyer's copy; `extract`
  reads the code from a leaked copy.

## Build
```
cc -O2 wm_tool.c -o wm_tool -lcrypto
```

## Use

Pick a secret **watermark key** once (32+ bytes hex), keep it on the server
only, separate from your other keys:
```
WMKEY=$(python3 -c "import os;print(os.urandom(32).hex())")
```

**At download time** (server-side, per buyer) stamp the master build with the
buyer's id (their key id / user id in your panel):
```
wm_tool embed  app_master  app_for_buyer_1042  1042  $WMKEY
# then serve app_for_buyer_1042 to that buyer
```

**When a free copy leaks**, read the code:
```
wm_tool extract  leaked_app  $WMKEY
# -> watermark found: buyer 1042 ...
```
Look 1042 up in your panel → that's the account that leaked it → revoke + ban
(and, if you keep purchase records, pursue them).

## How it stays hidden and unforgeable
- Each slot is `nonce(8) || ciphertext(8) || HMAC(16)`. The buyer id is XOR-
  encrypted; the HMAC authenticates. Without the key it is **indistinguishable
  from random bytes** — there is no magic string to grep for.
- `extract` scans every offset and keeps only windows whose HMAC verifies, so a
  wrong key finds nothing and a stripped slot is simply skipped.
- Three redundant slots, each with a fresh nonce (so they don't look identical).
  Wiping one still leaves the others (tested).

## This complements the server-side `wm`
The connector config already carries a per-key `wm` fingerprint. That catches an
**online** free build (it must call your server, so its traffic names the key).
This binary watermark additionally catches an **offline** copy (the code is in
the file itself, no server call needed). Use both.

## Honest limits (so you deploy it right)
- **Diffing two copies.** A skilled reverser who buys twice can diff the two
  binaries; the bytes that differ are the watermark slots, which they can then
  zero. Mitigate by also introducing *benign* per-buyer differences elsewhere
  (harmless constants, reordered data) so the diff is noisy and the real slots
  don't stand out — and lean on the server-side `wm` for the online case.
- **It identifies, it doesn't prevent.** This is evidence + attribution, not a
  lock. It turns "someone leaked it, no idea who" into "buyer 1042 leaked it,
  banned." Most leakers are lazy and won't strip it; the determined few you
  catch by the diff-mitigation + the server signal.
- **Keep the key secret.** Anyone with `WMKEY` can both read and forge codes.
  Server-side only; never in the client, a zip, or a chat.
