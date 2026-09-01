# Protecting the client when the attacker has a valid key + root

The honest architecture for the hardest case: someone who **owns a real
licence**, logs in legitimately, and has **root** on their own device.

## First, the truth nobody selling "unbreakable" will tell you

A valid-key holder is, to the server, an **authorised user**. The server hands
them the real config and the app works — that is the whole point of a licence.
No crypto layer changes this, because they are not an outsider.

And "anti-cheats the giants use that were never bypassed" do not exist.
Vanguard, EAC, BattlEye, Denuvo have **all** been bypassed at points. They stay
ahead by three things, none of which is "unbreakable code":

1. **Kernel presence (ring-0)** — they run below the app and **refuse to run on
   a compromised/rooted system**. Their answer to root is *reject the device*,
   not *outsmart it*.
2. **Constant updates** — offsets and checks change faster than a static crack
   survives.
3. **Telemetry + ban waves** — they detect and punish after the fact.

Your constraint (native C/C++, no kernel module, **rooted** devices allowed)
means the client-side ceiling is lower than a kernel anti-cheat — that is
physics, not effort. So the strategy has to lean on the parts you *can* win.

## The two columns that actually work for you

### Column 1 — Server authority (this is where you win)

Since a rooted client can lie about anything local, **stop trusting it** and
make the server the judge:

| technique | what it catches | status |
|---|---|---|
| **Device binding** (key ↔ serial) | one key redistributed to many devices | already: device-slot claim + `max_devices` |
| **Short-lived, per-session payload** (`kx` + `exp`/`hb`) | a dumped config used later | already: per-session `kx`; set small `exp`/`hb` |
| **Heartbeat + instant revoke** | a leaked/abused session, kill on demand | design below |
| **Per-user watermark** (`wm`) | *who* leaked a config/asset | already in the config |
| **Anomaly detection** | impossible geo, request floods, replays | honeypot tables + rate limit already log this |
| **Canary keys** | keys seeded to catch crackers | already |

The valid-key attacker cannot escape this column: bind the key to their device,
watermark everything they receive, and the moment their key shows up on a second
device or feeds a leak, you **revoke and ban** — with proof of who it was.

**Heartbeat + revocation (add this):** issue a short-lived session token inside
the config; the client re-calls `/data/zezr_connector_v2` every `hb` seconds; the
server checks the key is still valid, still on the same device, not revoked. To
kill a leaker you set the key's `status = 0` (already blocks) or add a `revoked`
row — next heartbeat, the app stops. A dumped payload dies at the next beat.

### Column 2 — Raise client-analysis cost (`hardening.c`)

You cannot make a rooted client unbreakable, but you can make analysis
expensive and self-defeating. `hardening.c` gives you, all via **direct arm64
syscalls** so libc hooks (Frida) don't see them:

- **Anti-debug** — `TracerPid` via raw `svc`, not `ptrace()` libc.
- **Anti-hook** — Frida/Xposed/substrate signatures in `/proc/self/maps`, plus
  `rwxp` pages (inline hooks need them).
- **Anti-emulator** — goldfish/ranchu device nodes.
- **Self-integrity folded into a key** — the killer feature: `hd_bind_key()`
  derives your working key from `HMAC(base_key, hash_of_your_own_code)`. Patch
  the protected code (to strip a check) and the hash changes, so the key
  changes, so the server payload **won't decrypt**. There is no `if (tampered)
  return false;` to find and delete — the tampering breaks the math itself.
  Tested: a 1-byte patch changes the hash.

Wire it so the tamper flags ride in the request (`h9`, already) **and** so the
real key goes through `hd_bind_key()`. Then a patched or hooked client either
gets blacklisted server-side or simply produces a wrong key and a dead app.

Honest limit of Column 2: a patient attacker can compute the "clean" self-hash
from an unpatched build and hardcode it, or dump keys from memory in the live
window. That is why Column 1 exists — it does not depend on the client being
honest. Column 2 buys the time and the evidence; Column 1 lands the ban.

## What to tell yourself about "dour zadan" (bypass)

- You will not make a rooted, valid-key device unbreakable. Neither did the
  giants; they made bypasses **expensive, short-lived, detectable, and
  bannable**.
- Your equivalent: bind to device, expire fast, watermark, heartbeat-revoke,
  rotate offsets each update, and fold integrity into keys. A crack then works
  for one device, briefly, and names its author.
- Put the real value where root can't reach: **on your server**. Anything the
  client must hold, hold it for microseconds and wipe it (`hd_wipe`).

## Three specific bypasses, answered

**"They patch out the heartbeat."** A heartbeat that only *checks in* is
patchable and worthless. Make it *deliver* — each interval the server hands a
fresh, short-lived slice of the working secret (rotating key / config chunk).
Then skipping the heartbeat means the app runs out of valid secret and dies on
its own; keeping it means the key keeps calling from many IPs and the velocity
guard revokes it (see REDISTRIBUTION.md). Either branch loses — as long as the
secret rotates and `exp` is short.

**"They rip out the login and boot it free."** Only possible if the app can run
without the server secret. It cannot: the offsets/config/asset-key are issued
per session to a valid key and never live in the binary. "No login" = "no
server secret" = dead app. Their only move is to extract the secret once and
hardcode it — so **rotate the offsets each game update** and a hardcoded free
build breaks on the next patch, while the watermark (`wm`) names the key that
leaked. The login was never the guard; the data dependency is.

**"They delete your brand name from the file."** You cannot stop them editing a
visible string — but you can bind the brand to functionality. `HD_BRAND` lives
in a protected section that `hd_self_hash()` covers and `hd_bind_key()` folds
into the working key. Edit or remove the brand and the hash changes, the key
changes, and the server payload no longer decrypts. Tested: altering the brand
byte changes the self-hash. So a re-branded build is also a broken build — and
it still carries your hidden `wm` watermark to identify the leaker.

The theme in all three: never let the app enforce the rule locally (patchable);
make the app *need* something only your server can supply (not patchable).

## Build

```
cc -O2 -c hardening.c -o hardening.o            # link into your client
cc -O2 -DHD_TEST hardening.c -o hdtest -lcrypto && ./hdtest   # self-test
```

Put your hottest functions in the `hdprot` section (same attribute as
`hd_protected_marker`) so they are covered by the self-hash. On the Android NDK,
link against its BoringSSL for SHA/HMAC. Verified: builds and self-tests on
x86-64; the arm64 `svc` path cross-compiles and emits real `svc #0`.
