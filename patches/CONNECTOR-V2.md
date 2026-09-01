# Connector v2 — runs alongside the existing connector

This adds a **second** connector endpoint next to the current one. The old
`/data/zezr_connector` keeps working exactly as before; the new
`/data/zezr_connector_v2` is a parallel endpoint for a new client (the C++
client in `client-cpp/`). Ship the new app when you are ready and migrate at
your own pace — nothing about the old connector changes.

```
old app ──POST──▶ /data/zezr_connector      (Connect.php,   EG1, UA EagleA/1.2)
new app ──POST──▶ /data/zezr_connector_v2    (ConnectV2.php, EG2, UA EagleA/2.0)
                         │
                         └── same keys_code rows, same rate-limit table,
                             same device rules, same database. No schema change.
```

## What is different from v1 (and why)

| | v1 | v2 |
|---|---|---|
| Route | `/data/zezr_connector` | `/data/zezr_connector_v2` |
| Crypto namespace | `EG1` | `EG2` |
| User-Agent | `EagleA/1.2` | `EagleA/2.0` |
| AES key (.env) | `connect.aesKey` | `connect.aesKeyV2` *(falls back to `connect.aesKey`)* |
| Response signing | — | **Ed25519** (`connect.signKeyV2`), client verifies |

The crypto namespace is bound into the GCM tag as additional authenticated
data, so a captured v1 request **cannot** be replayed at v2 and vice-versa —
the tag simply fails to verify across versions. That is the whole point of
running them as two namespaces rather than one shared endpoint.

Everything else is identical to v1: AES-256-GCM transport with a per-message
nonce, replay window (±300 s), timing-safe key checks, the atomic per-device
slot claim, `max_devices <= 0` = unlimited, and per-IP rate limiting. The
rate-limit table is **shared** with v1 on purpose, so a scanner cannot dodge
the limit by hopping between the two endpoints.

## The one thing v2 adds that v1 could never do: signed responses

v1 (and any AES-only scheme) has a hard ceiling: **the AES key ships inside
the client**, so anyone who unpacks the binary has it and can then forge a
"valid" activation or stand up a fake server that answers `status: 1`. AES
proves *no outsider* tampered on the wire; it cannot prove *who* wrote the
message, because both sides share the same key.

v2 closes that hole the way a bank does — with a key the client never holds:

```
          ┌─────────── SECRET (server only, .env) ───────────┐
          │  connect.signKeyV2  — signs every response        │
          └───────────────────────────────────────────────────┘
                                │  Ed25519
          ┌───────────── PUBLIC (baked into client) ──────────┐
          │  SIGN_PUBKEY_B64 — only VERIFIES, cannot sign      │
          └───────────────────────────────────────────────────┘
```

Every v2 response carries an Ed25519 signature over its plaintext. The client
verifies it with the embedded public key and **refuses** anything unsigned or
mis-signed. Consequences:

- A cloned client that has the AES key still cannot forge an activation — it
  cannot produce a signature the real client will accept.
- A fake/emulated server cannot impersonate the panel.
- A captured response cannot be replayed (the signed plaintext contains your
  one-time `cnonce` and a timestamp).

This is verified fail-closed: if the client has a public key configured, an
unsigned response is rejected as a downgrade. It was tested against a forged
signing key, an unsigned server, and a wrong client key — all three are
rejected; only the genuine pair passes.

### Set it up (one command)

```
php genkey-sign.php
```

It prints two lines — put the first in the panel's `.env`, the second in the
C++ client:

```
# .env  (SECRET — never commit or share)
connect.signKeyV2 = <base64 secret key>

# eagle_connector_v2.cpp
static const std::string SIGN_PUBKEY_B64 = "<base64 public key>";
```

To rotate, run it again and ship a client build carrying the new public key.
While `connect.signKeyV2` is unset the panel logs a warning and answers
unsigned — and a client built with a real public key will reject those, which
is the safe direction to fail.

## Install (4 steps, no database change)

**1. Drop the controller in.** Rename `ConnectV2.php.new` to `ConnectV2.php`
and place it at `app/Controllers/ConnectV2.php`. Fill in the same
`$staticWords`, `$Public_Key`, `$setAccess` and per-game version strings you
used in `Connect.php` (they must match whatever the C++ client is built with).

**2. Add the route.** In `app/Config/Routes.php`, inside the existing `data`
group, add the second line:

```php
$routes->group('data', static function ($routes) {
    $routes->match(['get', 'post'], 'zezr_connector',    'Connect::index');
    $routes->match(['get', 'post'], 'zezr_connector_v2', 'ConnectV2::index');  // <— add
});
```

**3. Exempt it from CSRF and auth.** In `app/Config/Filters.php`, add
`'data/zezr_connector_v2'` to the `except` list of **both** the `csrf` and the
`auth` filter (right next to the existing `'data/zezr_connector'` entry):

```php
'csrf' => ['except' => ['data/zezr_connector', 'data/zezr_connector_v2', 'download', 'download/*']],
'auth' => ['except' => ['/', 'login', 'register', 'check',
                        'data/zezr_connector', 'data/zezr_connector_v2', 'download', 'download/*']],
```

**4. (Optional) Give it its own key.** In `.env`:

```
connect.aesKeyV2 = <64 hex chars>
```

If you skip this, v2 uses `connect.aesKey` (the v1 key) automatically, so it
works the moment you finish steps 1–3. Set a separate key later when you want
v1 and v2 to be fully independent — then rebuild the C++ client with the new
key.

## The C++ client

`client-cpp/eagle_connector_v2.cpp` is a complete, self-contained client for
the v2 endpoint. It builds the `EG2` envelope, POSTs it with the right
User-Agent, and verifies the reply (decrypts, checks the echoed `cnonce`, the
timestamp window, and — as proof the server knew `$staticWords` — recomputes
the `token`).

Configure the constants at the top (`AES_KEY_HEX`, `PUBLIC_KEY`,
`STATIC_WORDS`, `ENDPOINT`) to match your panel, then build:

```
g++ -std=c++17 eagle_connector_v2.cpp -o eagle_v2 -lcurl -lcrypto -I./third_party
./eagle_v2 CODM "1.2.3:jp2c7H6Y1" CODM_ABCD1234 DEV-SERIAL-001
```

Dependencies: OpenSSL (libcrypto), libcurl, and the single-header
[nlohmann/json](https://github.com/nlohmann/json) (`json.hpp` on the include
path, e.g. `third_party/nlohmann/json.hpp`). The same source compiles under
the Android NDK — link libcurl + libcrypto for your ABIs.

### Request / response (logical shape)

> These are the **logical** field names for clarity. The current protocol sends
> them under the **obfuscated** names in the map further down ("v2 hardening"),
> and the response is a 4-part signed envelope. The C++ client already uses the
> obfuscated names — this block is just to show what each field means.

Request JSON, sealed as `EG2.<b64url nonce>.<b64url ciphertext||tag>` and sent
as the POST field `data=`:

```json
{
  "game":     "CODM",
  "app_ver":  "<md5 of the exact version string the panel is configured with>",
  "user_key": "<licence key>",
  "serial":   "<device serial, never contains a comma>",
  "public":   "<Public_Key>",
  "ts":       1730000000,
  "cnonce":   "<random hex, echoed back by the server>"
}
```

Response JSON (sealed the same way):

```json
{ "status": 1,
  "data": { "id_key": 1, "token": "...", "salt": "...", "access": "...", "expired": "..." },
  "game": { "t_time": 110 },
  "cnonce": "<your cnonce>", "ts": 1730000000 }
```

`status: -1` carries a `reason` instead (same error strings as v1: blocked,
expired, max devices, please update, not registered, …).

## Honest limit (what signing does and does not buy)

Signing removes the biggest attack: no one can forge a valid server response
or stand up a fake server, because that needs the Ed25519 secret key, which
never leaves your panel.

It does **not** stop the owner of a device from patching the client binary to
ignore what `activate()` returned — no purely client-side check ever can,
because they control the CPU. To also defeat a patched client, the protected
feature must depend on something only the server can provide: put the real
secret the app needs *inside the signed payload* (e.g. an unlock value, a
per-session config) instead of gating on a local `if (ok)`. Then a patched
client that skips the check simply never receives the thing it needs.

Keep the embedded AES key split/obfuscated, strip symbols from release builds,
and enable your platform's integrity checks. Each layer raises the cost.

---

# v2 hardening: obfuscation, server payload, tamper signal, honeypot

Four more layers were added to `ConnectV2.php` and the C++ client. They need the
tables in `MIGRATION.sql` section 14 (safe to re-run; the connector still works
without them — only the honeypot/tamper recording go quiet).

## 1) Obfuscated field names

Inside the (already AES-encrypted) JSON, the fields no longer read as
`user_key` / `serial` / … but as opaque tokens. Both sides use the same map:

| meaning | wire (request) | meaning | wire (response) |
|---|---|---|---|
| game | `q0` | status | `s` |
| app_ver (md5) | `q7` | data object | `d` |
| user_key | `z2` | game object | `g` |
| serial | `d5` | reason (fail) | `e` |
| public | `p1` | id_key | `d.i` |
| ts | `t` | token | `d.tk` |
| cnonce | `n` | salt | `d.sl` |
| tamper flags | `h9` | rng | `d.rg` |
| | | expired | `d.xp` |
| | | access | `d.ac` |
| | | **payload** | `d.kx` |
| | | t_time | `g.tt` |

Honest note: the traffic is already encrypted, so a network sniffer never saw
the old names either — these opaque names only cost a reverse-engineer who has
unpacked the client a little more time. It is obfuscation, not secrecy.

## 2) Server-delivered payload (`kx`) — the real anti-bypass

Every successful response carries `d.kx`: a per-session secret derived from a
**server-only** key (`connect.payloadKeyV2`, or a value derived from your AES
key if unset). The point:

> Make your app genuinely NEED `kx` to work — e.g. use it to derive the key
> that decrypts your resources/config. Do **not** gate on a local
> `if (result.ok)`.

Why it matters: a rooted attacker can patch the client to ignore any local
check — but they cannot compute a correct `kx` (it needs the server key), and a
patched client that skips activation is handed a **junk** `kx`. If the feature
depends on `kx`, the bypass produces a broken app instead of a free one. This
is the only layer that resists a patched client; the C++ `main()` shows where
to plug `r.payload` in.

## 3) Tamper signal (`h9`) — native, no Java

The C++ client runs a pure-native probe (`tamperFlags()`, works under the NDK,
no Java/APK needed) and reports a bitmask:

| bit | value | meaning | server action |
|---|---|---|---|
| debugger | 1 | `/proc/self/status` TracerPid ≠ 0 | **blacklist** |
| root | 2 | `su`/magisk paths present | log only |
| hook | 4 | Frida/Xposed/substrate in `/proc/self/maps` | **blacklist** |
| emulator | 8 | goldfish/ranchu device nodes | log only |

Root and emulator alone are **not** grounds to block — plenty of paying
customers run rooted phones. Only an attached debugger or a hooking framework
(strong signs of active reverse-engineering) get the serial blacklisted. A
blacklisted serial then receives a poisoned (junk `kx`) activation. Tested: run
the client under a tracer and the server records `h9 = 1`.

## 4) Defensive honeypot (server-side only — attacks no one)

- **Canary keys** (`connect_canary`): seed trap license strings where crackers
  look (forums, paste sites). Any use flags the caller's serial and returns a
  poisoned success — they think it worked; it does not. Add your own:
  ```sql
  INSERT IGNORE INTO connect_canary (user_key, note, created_at)
    VALUES ('CODM_FREEVIP2024', 'seeded on a forum', NOW());
  ```
- **Blacklist** (`connect_blacklist`): serials flagged by canary use or a
  debugger/hook signal. Listed serials get poisoned activations.
- **Decoy endpoint** (`/data/zezr_activate`): not used by the real client, only
  visible in decompiled strings; any hit is logged to `connect_flags`.
- **Flags log** (`connect_flags`): append-only record of tamper reports and
  decoy hits — your evidence trail.

None of this runs code on anyone's device. It identifies abusers and denies
them **your** service — the legal, durable alternative to "hacking back", which
would put the legal risk on you, not them.

## What still cannot be done from the client

Running the app as root and patching it will always defeat a purely local
check — that is physics, not a bug. The defense that survives it is layer 2:
tie the real feature to the server-delivered `kx`. Everything else (signing,
obfuscation, tamper detection, honeypot) raises cost and catches abusers, but
`kx` is what makes a bypass produce a broken app.
