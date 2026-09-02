# Release playbook — multiply the cracker's cost, rot the crack each update

Your strategy is the right one: nothing is uncrackable, so **make each crack
expensive and short-lived.** The lever is the game's own update cycle — every
time the game patches, you ship a build that shares nothing with the last one,
so the attacker's previous work (offsets, byte patches, the wire parser they
built) no longer lines up and they must start over.

## The one command each release

```
python diversify.py --seed <unique-per-release>   --out build/
# e.g.  --seed 2026.09.14-r2
```

It regenerates `build/build_config.h` (client) and `build/build_config.php`
(server) — matched, but different from last release:

| what rotates | what it breaks for the attacker |
|---|---|
| wire field names (`game`→`pv`, `user_key`→`u1`, …) | the parser/understanding they built of your traffic |
| crypto tag (`EG2`→`EHE`) | their decrypt/replay tooling keyed to the old tag |
| build id + guard salt | the byte offsets their patch targeted shift |
| string-XOR key | their string dump changes |
| watermark locator | their "find the watermark" recipe |
| **your game offsets** (you change these too) | their memory reads — the core of the tool |

Bump the seed **every release**. Never reuse one.

## The per-update checklist

1. Game updated → grab the new **offsets** (this you already do).
2. `diversify.py --seed <new>` → new `build_config.{h,php}`.
3. Rebuild the client against the new `build_config.h` (+ new offsets in the
   server config `secretConfig()`), rebuild with a fresh obfuscation pass if you
   use one.
4. Deploy `build_config.php` to the panel so `ConnectV2` speaks the new field
   names/tag. (Old clients stop matching — that's fine, everyone updates.)
5. Re-stamp downloads with the forensic watermark (`wm_tool embed`).
6. Ship. The old cracked/free build is now broken against the new game + new
   protocol; the attacker must re-crack from scratch.

## Cost multipliers you already have (stack them on the first crack)

- AES-256-GCM + **Ed25519-signed** responses (fake server impossible).
- **Obfuscated** field names (now rotating per release).
- **kx-encrypted server config** — the feature runs from server data, not a local
  flag (see REWRITE-ADVICE.md).
- **guard + hardening**: integrity folded into keys, obfuscated crash instead of
  `exit` — patching a check breaks decryption, not just returns false.
- **forensic watermark**: even an offline free copy names the buyer who leaked it.

Each is a wall the first crack must climb; diversification means the *next*
release rebuilds the walls, so the climb repeats every update.

## Integration note (optional next step)

To make the rotation fully automatic, `ConnectV2.php` and the C++ client should
read the field names / crypto tag from the generated `build_config` instead of
their current hardcoded constants. It's a small refactor (swap the string
literals for the `BC_F_*` / `$cfg['fields']` values). Say the word and I'll wire
both sides to consume `build_config` so a single `diversify.py` run + redeploy is
all a release needs.

## Honest expectation

This does not make you uncrackable. It makes the crack **cost several times more
each release and expire at the next game update** — which is exactly the war real
providers win: be first with the working update, keep the free build broken and
lagging, trace and ban the leaker, and let the treadmill do the rest. Ship
faster than they can re-crack and you keep your customers.
