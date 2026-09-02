# Rewriting the client so it can't be cracked "without a key"

## First, the diagnosis (this matters more than clean code)

Your old build runs **with no key at all** after cracking. That single fact
tells us exactly what was wrong: the old app **contained the working logic/data
and used the licence check only as a local gate** —

```c
if (license_ok())      // <- one branch
    run_feature();     // <- the real thing was already inside the app
```

The cracker flipped `license_ok()` to always-true (or NOP'd the `if`), and the
feature — which was sitting right there in the binary — ran for free.

**So rewriting the same design more cleanly will be cracked again.** Clean code
helps *you* read it; it does nothing against IDA + AI. The thing to change is the
architecture, not the tidiness.

## The one rule that fixes it

> The app must be **unable to function without data the server issues per
> session to a valid key.** No local copy of that data. No offline path.

When the feature literally needs `r.config` (offsets / asset-key / logic) that
only a genuine activation delivers, "run without a key" produces a dead app —
there is nothing to un-gate.

## What to change, concretely (a checklist for the rewrite)

1. **Move the crown jewels out of the binary.** Whatever the old app used to
   "do the thing" (offsets, config, scripts, the real algorithm) must NOT ship
   in the client. Deliver it per session from the server, encrypted under `kx`
   (connector v2 already does this via `d.rc`). The client without a valid
   activation holds none of it.

2. **Delete every offline / demo / fallback path.** A huge share of cracks just
   enable a hidden "works without server" branch. There must be exactly one
   path: activate → get config → run from config. If activation fails, there is
   nothing else to fall back to.

3. **Never gate on a local boolean.** Don't write `if (activated) run();`. Write
   `run(config_the_server_gave_you);`. The decisive value is `config_ok` (a real
   `kx` opened the config), not a flag a patch can set.

4. **No embedded master key / hardcoded unlock.** If any universal secret is
   baked in, they extract it once and it's over. Every secret must arrive from
   the server, per session, and expire.

5. **Fold integrity into the key, don't branch on it.** Use `hd_bind_key()` /
   the `guard` accumulator so that patching a check changes a *key*, which
   breaks decryption — instead of a check that returns false and can be removed.

6. **Rotate every release.** Bump a build id and rotate the server-side offsets
   each game update. A crack that extracted yesterday's data breaks tomorrow.

7. **Let the server be the judge.** Device binding (`max_devices` low),
   short `exp` + heartbeat, per-key watermark (`wm`), and the velocity
   auto-revoke are what actually end a redistributed "free build" — all already
   built server-side.

## Structure for the new C++ (thin client)

```
main()
 └─ harden()                         // PR_SET_DUMPABLE, early
 └─ result = activate(game,ver,key,serial)   // connector v2
       ├─ verify signature (Ed25519)          // fake server -> rejected
       ├─ check cnonce + ts                    // replay -> rejected
       └─ derive kx, decrypt d.rc -> config    // no kx -> no config
 └─ if (!result.config_ok) { /* corrupt state via guard, let it die */ }
 └─ run everything FROM result.config          // offsets, asset-key, limits
       - no branch that runs without config
       - feed kx/guard key into resource decryption (feature_example.c)
```

The reference files already do each piece:
- `eagle_connector_v2.cpp` — activate + verify + decrypt config.
- `feature_example.c` — use the config's key to decrypt the real bundled
  resource (the "run from config" pattern).
- `hardening.c` + `guard.c` — integrity folded into keys, obfuscated failure.
- `app_skeleton.c` — the clean control flow tying it together (in this bundle).

## Honest expectation

A determined attacker with root on their own device can still analyse the
client. What changes is the *result* of their work: with the crown jewels
server-side, the best they get is their own session working (they have a key) —
which the server then catches (one key, many IPs → auto-revoke) and traces (the
`wm` watermark). "Free for everyone forever" becomes "a broken build, dead within
the hour, and a banned buyer." That is the realistic win; there is no
client-only design that beats a rooted owner outright.
