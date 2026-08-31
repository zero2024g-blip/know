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

### Request / response (must match `ConnectV2.php`)

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
