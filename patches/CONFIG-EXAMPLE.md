# What to put in the server config (`secretConfig()` → `d.rc`)

The config is the thing your app **cannot run without**. It is encrypted under
the per-session `kx` and sent as `d.rc`; a genuine activation decrypts it, a
bypass gets junk. So put here everything that, if missing, makes the app dead —
that is what forces a real activation instead of a patched `if`.

## Example config

```json
{
  "v": 3,
  "feat": ["aim", "esp", "radar"],
  "off": {
    "base":   "0x00E3F1A0",
    "entity": "0x000142B8",
    "matrix": "0x004C7A10"
  },
  "res_key": "s0m3Base64Key32Bytes............................=",
  "tune": { "t_time": 110 },
  "exp": "2026-12-31 23:59:59",
  "hb": 300,
  "wm": "3f9ac1b20e77"
}
```

## What each field is for

| field | meaning | why it belongs server-side |
|---|---|---|
| `v` | config schema version | lets the client handle old/new shapes |
| `feat` | features this key/tier unlocks | the **server** decides entitlements; the client only enables what is listed — a patched client cannot grant itself `esp` |
| `off` | the memory offsets the module reads | **the crown jewels.** A bypass has none, so it does nothing. And you hot-fix them after a game update by editing the server, with **no new build to ship** |
| `res_key` | key to decrypt your bundled encrypted assets/scripts | the asset key never sits in the binary — it arrives only after a real activation (see `feature_example.c`) |
| `tune` | server-controlled limits/timings | change behaviour remotely; the app obeys values it did not choose |
| `exp` | hard expiry / kill switch | a remote off-switch; the client refuses to run past it |
| `hb` | re-activate every N seconds | short life + re-activation makes a **dumped** config go stale fast |
| `wm` | per-key watermark | if a config leaks, this points at the source key — your leak trace |

## How the client uses it

`r.config` (already decrypted by the C++ client via `kx`) is a JSON string. Run
your feature **off it**, never off a local boolean:

```cpp
auto cfg = json::parse(r.config);

// 1) entitlements — enable only what the server granted
bool aim = false, esp = false;
for (auto& f : cfg["feat"]) { if (f=="aim") aim=true; if (f=="esp") esp=true; }

// 2) offsets — the module literally needs these numbers
uint64_t base   = strtoull(cfg["off"]["base"].get<std::string>().c_str(),   nullptr, 16);
uint64_t entity = strtoull(cfg["off"]["entity"].get<std::string>().c_str(), nullptr, 16);

// 3) asset key — decrypt your bundled resource (see feature_example.c)
std::string resKeyB64 = cfg["res_key"];

// 4) expiry / heartbeat — stop and re-activate on schedule
// long exp = parse(cfg["exp"]); if (now > exp) refuse();
```

A patched client that skipped activation has **no** `r.config` — no offsets, no
asset key, no entitlements. It cannot fake them, because it cannot produce a
valid `kx`.

## The honest split (dump resistance)

- `off` (offsets) and `tune` — rotate these server-side freely (each game
  patch). A dump of yesterday's offsets is worthless after you rotate.
- `exp` + `hb` — keep the config short-lived; a dumped config expires.
- `wm` — a leaked config names the leaker.
- `res_key` — this one is **static** (it must open statically-bundled assets),
  so a memory dump can capture it. Its protection is: not in the binary,
  delivered only on real activation, and traceable via `wm`. If an asset is
  truly high-value, deliver *it* per-session (encrypt the asset under `kx`
  server-side, like `rc` itself) instead of shipping it bundled.
