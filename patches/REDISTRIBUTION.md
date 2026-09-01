# Stopping "one bought key → a free public build for everyone"

The threat: someone buys a key (often with a throwaway/fake account), uses AI to
help reverse the client, and ships a **free build** so anyone can use your app
without paying.

## Why they can't actually give it away for free

Your app cannot run without the **per-session secret the server issues only to a
valid key** (`kx` → the encrypted config → offsets/asset key). So a free build
they distribute still has to call **your** server to work. That leaves them only
two options, and both lose:

1. **Embed their own key in the free build.** Then every free user activates with
   that ONE key → your server sees one key lighting up from **hundreds of IPs**.
   That is the signal below: you auto-revoke it, and the free build dies for
   everyone at once — and you know exactly which buyer to ban.
2. **Proxy through their own server** that holds the key. Same signal (one key,
   many IPs), plus their proxy is a single chokepoint you can identify and block.

They cannot bake a universal offline unlock, because the working secret is
per-session, server-issued, device-bound, and rotates.

## What is now built into ConnectV2 (tested)

| mechanism | what it does |
|---|---|
| **Device binding** | `max_devices` already caps how many devices a key serves. Keep it LOW (1–2). A free build on one key then only works for 1–2 people before everyone else gets "Max Devices Reached." |
| **Velocity detection** | counts **distinct IPs per key** in a rolling hour. A single buyer = a few IPs; a public build = many. Over `VEL_SOFT` (15) logs evidence; over `VEL_HARD` (40) **auto-revokes** the key. |
| **Auto-revoke → poison** | a revoked key still gets a `status:1` response, but with a **junk** `kx`/config — the free build *looks* alive and produces broken output, wasting the cracker's audience while you act. |
| **Manual kill switch** | one row in `connect_revoked` shuts a key off instantly; deleting it restores. Reversible, so a false positive is not fatal. |
| **Watermark** (`wm` in the config) | every delivered config carries the key's fingerprint — a leaked build's traffic names the source key. |
| **Activation log** | `connect_activations` is your evidence: which key, which devices, which IPs, when. |

Tuning: `VEL_SOFT` / `VEL_HARD` / `VEL_WINDOW` are constants at the top of
`ConnectV2.php`. If your legit users share carrier NAT (many IPs), raise
`VEL_HARD`; if keys are strictly single-device, lower it. Start conservative and
watch `connect_flags` (rows with flag `256` are velocity events).

## Kill a leaked key by hand

```sql
-- shuts the free build off for everyone using this key
INSERT IGNORE INTO connect_revoked (user_key, reason, created_at)
  VALUES ('THE_LEAKED_KEY', 'manual', NOW());

-- restore it if you were wrong
DELETE FROM connect_revoked WHERE user_key = 'THE_LEAKED_KEY';
```

Find the leaked key from the watermark in the public build's traffic, or from
`connect_flags` / `connect_activations` (the key with dozens of IPs).

## The "fake account bought the key" part

Detection above does not care who they are — it catches the key. To also make
the *purchase* traceable and raise the cost of buying keys to burn:

- **Bind key → first device on first activation** (already: the device slot). A
  key that later appears on a different device is a red flag.
- **Keep `max_devices` at 1** for single-user licences. Redistribution then
  fails immediately, not after 40 IPs.
- **Correlate at purchase**: log the payment fingerprint / email / IP with the
  issued key (in the panel, not here) so a burned key points back to a buyer,
  and a buyer who burns keys can be blocked from buying again.
- **Short licences + re-activation** (`exp` / `hb` in the config): a leaked build
  stops working at expiry, forcing the cracker to keep re-buying and re-cracking
  — each attempt caught by velocity.

## The AI-assisted-reversing part

AI lowers the cost of reading the client, so do **not** rely on the client
keeping secrets. Everything above is **server-side** and does not care how well
they understand the client:

- The working secret is issued per session by the server, not hidden in the app.
- Redistribution is detected by server-side behaviour (one key, many IPs), which
  no amount of client analysis removes.
- Client hardening (`hardening.c`) only buys time; the revoke + watermark +
  velocity loop is what actually ends a free public build.

## Honest bottom line

You cannot stop a determined buyer from analysing the client they legitimately
hold. You **can** make their free build: work for only a couple of devices,
light up an unmissable server-side signal, auto-revoke within an hour, produce
broken output once revoked, and name the buyer who leaked it. That turns "free
app for everyone, forever" into "a broken build, dead within the hour, and a
banned buyer."
