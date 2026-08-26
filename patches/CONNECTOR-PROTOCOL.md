# The app connector — wire protocol

`POST /data/zezr_connector`

Everything below replaces the old AES-256-CBC scheme. **The Android app has to
change with it**: an old client cannot talk to a new server, and that is
deliberate — the version prefix exists so the two can never half-understand
each other.

---

## What changed, and why

| | Before | Now |
|---|---|---|
| Cipher | AES-256-CBC | AES-256-**GCM** |
| IV / nonce | one fixed value, forever | fresh 12 random bytes per message |
| Integrity | none | 16-byte GCM tag |
| Replay | unlimited | 5-minute window + echoed client nonce |
| Key location | hard-coded in the PHP | `.env` only |
| Versioning | none | `EG1.` prefix |

Three things were wrong with the old scheme, and all three were reproduced
before being fixed:

**The IV never changed.** Encrypting the same request twice produced
byte-identical output, so anyone watching the network could tell which
requests repeated — which key was being checked, how often — without holding
the key. Two different requests still shared their first two 16-byte blocks.

**Nothing checked integrity.** CBC is malleable: flipping bits in the
ciphertext flips the matching plaintext bits. In a test, 16 bytes of the
server's reply were rewritten to a chosen value **with no key at all**, and
the old code parsed the result without complaint. GCM's tag turns that into a
hard failure.

**The key was in the source file.** It travelled with every copy of the code
and every backup, and could not be rotated without a release.

### The limit you should know about

The key still lives inside the Android app. Anyone who unpacks the APK has it
and can read and forge anything on this endpoint. What this design buys you is
protection against interception and tampering **on the wire**, replay, and
casual probing. It does not protect against someone who owns the client.

The only thing that would is signing responses with a private key the app
never holds — the app would carry a public key and verify, so extracting it
would let an attacker *read* but not *forge*. That is a bigger change and it
needs the app rebuilt; worth planning, not worth rushing.

---

## Setup

Generate a key and put it in `.env` — never in a PHP file:

    php -r 'echo bin2hex(random_bytes(32)), "\n";'

    # .env
    connect.aesKey = 3f7a...   # exactly 64 hex characters

If it is missing or malformed the connector answers `503` with an empty body
and logs `critical`. It fails closed on purpose: replying with some other key
would be worse than not replying.

---

## Wire format

One line of ASCII, three parts separated by dots:

    EG1.<base64url(nonce)>.<base64url(ciphertext || tag)>

- `EG1` — protocol version. Bump it when the format changes.
- `nonce` — 12 random bytes, different for every single message.
- `tag` — the last 16 bytes of part three, GCM's authentication tag.
- base64url — `+` → `-`, `/` → `_`, no `=` padding.
- The string `EG1` is passed as GCM's **additional authenticated data**, so a
  message cannot be replayed against a future version that reads it differently.

Send it as an ordinary form POST field named `data`, with
`User-Agent: EagleA/1.2`.

### Request body (inside the envelope)

```json
{
  "game":     "CODM",
  "app_ver":  "<md5 of the version string>",
  "user_key": "CODM_a1b2c3d4e5f6",
  "serial":   "<device id>",
  "public":   "<shared public key>",
  "ts":       1735689600,
  "cnonce":   "5f2e8a1c9d3b7e04"
}
```

`ts` is a unix timestamp. More than **300 seconds** away from the server's
clock and the request is refused, so a recorded request stops working quickly.

`cnonce` is any random value you generate per request. The server echoes it
back inside the response. **Check it matches** — that is what stops someone
replaying a recorded "licence valid" response at your app later.

### Response body (inside the envelope)

```json
{
  "status": 1,
  "data": {
    "id_key":  1234,
    "token":   "<sha256>",
    "salt":    "<hex>",
    "rng":     1735689600,
    "expired": "2026-02-01 12:00:00",
    "access":  "<access token>"
  },
  "game":   { "t_time": 110 },
  "cnonce": "5f2e8a1c9d3b7e04",
  "ts":     1735689600
}
```

`status` is `1` on success, `-1` otherwise with a `reason`.

Every failure — tampered ciphertext, wrong key, stale timestamp, malformed
JSON — returns the same `"Decryption failed."`. That is intentional: telling a
caller *which* check failed hands them a tool for narrowing down the answer.

---

## Testing it with curl

curl cannot do AES-GCM, so seal the envelope first and paste it in. This
script does the whole round trip:

```bash
#!/usr/bin/env bash
# connector-test.sh — seal a request, send it, open the reply.
KEY_HEX="paste-your-64-hex-key"
URL="https://panel.zeromods.id/data/zezr_connector"

REQ=$(php -r '
$key = hex2bin($argv[1]);
$body = [
  "game"     => "CODM",
  "app_ver"  => md5("8.6.0:rFGZ1g03"),   // your real version string
  "user_key" => $argv[2],
  "serial"   => "TEST-DEVICE-01",
  "public"   => $argv[3],
  "ts"       => time(),
  "cnonce"   => bin2hex(random_bytes(8)),
];
$nonce = random_bytes(12); $tag = "";
$ct = openssl_encrypt(json_encode($body), "aes-256-gcm", $key,
                      OPENSSL_RAW_DATA, $nonce, $tag, "EG1", 16);
$b64u = fn($b) => rtrim(strtr(base64_encode($b), "+/", "-_"), "=");
echo "EG1." . $b64u($nonce) . "." . $b64u($ct . $tag);
' "$KEY_HEX" "CODM_yourkeyhere" "your-public-key")

RESP=$(curl -s -X POST "$URL" \
  -H "User-Agent: EagleA/1.2" \
  --data-urlencode "data=$REQ")

php -r '
$key = hex2bin($argv[1]);
$p = explode(".", trim($argv[2]));
if (count($p) !== 3 || $p[0] !== "EG1") { exit("not an EG1 envelope: $argv[2]\n"); }
$ub = fn($t) => base64_decode(strtr($t, "-_", "+/"), true);
$n = $ub($p[1]); $blob = $ub($p[2]);
$plain = openssl_decrypt(substr($blob, 0, -16), "aes-256-gcm", $key,
                         OPENSSL_RAW_DATA, $n, substr($blob, -16), "EG1");
echo $plain === false ? "TAG FAILED — tampered, wrong key, or wrong version\n"
                      : json_encode(json_decode($plain), JSON_PRETTY_PRINT) . "\n";
' "$KEY_HEX" "$RESP"
```

Run it:

    chmod +x connector-test.sh && ./connector-test.sh

A working key prints `"status": 1` with a token. Things worth trying:

- change one character in `$REQ` before sending → `Decryption failed.`
- set `"ts" => time() - 600` → `Decryption failed.`
- send 21 unknown keys in five minutes → the 21st gets `Invalid Parameter.`

---

## Android side

The Java/Kotlin equivalent, for whoever builds the client:

```java
// SEAL
byte[] nonce = new byte[12];
new SecureRandom().nextBytes(nonce);

Cipher c = Cipher.getInstance("AES/GCM/NoPadding");
c.init(Cipher.ENCRYPT_MODE,
       new SecretKeySpec(keyBytes, "AES"),
       new GCMParameterSpec(128, nonce));           // 128-bit tag
c.updateAAD("EG1".getBytes(StandardCharsets.UTF_8));
byte[] sealed = c.doFinal(json.getBytes(StandardCharsets.UTF_8));
// Java appends the tag to the ciphertext already — matches the PHP layout.

String envelope = "EG1."
    + Base64.encodeToString(nonce,  Base64.URL_SAFE | Base64.NO_PADDING | Base64.NO_WRAP)
    + "."
    + Base64.encodeToString(sealed, Base64.URL_SAFE | Base64.NO_PADDING | Base64.NO_WRAP);

// OPEN — and then check the echoed cnonce equals the one you sent,
// or a recorded "valid" response can be replayed at you.
```

Two things to get right:

1. **`GCMParameterSpec(128, nonce)`** — 128 bits is 16 bytes, which is what the
   PHP side writes. A different tag length will not open.
2. **`updateAAD("EG1")`** before `doFinal`, on both encrypt and decrypt. Miss
   it and every message fails the tag check.
