# Client hardening — the step-by-step plan (start simple, build up)

We build the client's tamper resistance in layers. Each step is small, testable,
and stacks on the last. This is a **cost** layer against IDA Pro + AI analysis,
not a wall — the wall is server authority (see ANTICHEAT-REALITY.md).

## The baseline (already done)

- **Panel connector v1** — your `Connect.php`, untouched.
- **Connector v2 link** — `ConnectV2.php` at `/data/zezr_connector_v2`, added to
  `Routes.php` (inside the `data` group) and to the `csrf`/`auth` `except` lists
  in `Filters.php`. Old apps keep using v1; new C/C++ client talks to v2.
- **Transport** — AES-256-GCM (EG2) + Ed25519-signed responses + obfuscated
  field names + the kx-encrypted server config.

## Step 1 — an obfuscated failure primitive (this step)  ✅ built + tested

`guard.h` / `guard.c`. Instead of `if (tampered) exit();` (which IDA + AI spot
and NOP in seconds), every check folds into one 64-bit accumulator; a correction
key `G_KEY()` is **zero only when every check passed**, and that key is XORed
into pointers and data the program actually uses:

- `G_CALL(...)` — an indirect call whose target is correct only when key==0;
  tampered → a wild address → the process **faults elsewhere**, with no `exit`
  and no branch to remove. Tested: clean run calls the real function; a tampered
  run dies with SIGSEGV, and the object references no `exit`/`abort`.
- `G_UNMASK(v,key)` — a value that is only correct when key==0.

## Step 2 — feed the real checks in

Wire `hardening.c`'s probes into the accumulator. **Important:** only the strong
signals (debugger, hook) may drive a crash — **not** root or emulator, or you
crash honest customers on rooted phones. Root/emulator still go to the server as
`h9` for logging.

```c
#include "hardening.h"
#include "guard.h"

#define BUILD_ID 0xB0071D2026ULL          // bump every release

// Clean values, computed once from a known-good build:
//   [0] strong tamper bits = 0, [1] first word of self-hash, [2] build id
static uint64_t CLEAN[3];
static void guard_init(void) {
    uint8_t sh[32]; hd_self_hash(sh);
    CLEAN[0] = 0;
    CLEAN[1] = ((uint64_t*)sh)[0];
    CLEAN[2] = BUILD_ID;
}

// At each guarded call site (inline, scattered — not one wrapper):
static uintptr_t guard_key(void) {
    uint8_t sh[32]; hd_self_hash(sh);
    uint64_t actual[3] = {
        (uint64_t)(hd_tamper_flags() & (HD_FLAG_DEBUG | HD_FLAG_HOOK)), // strong only
        ((uint64_t*)sh)[0],
        BUILD_ID
    };
    uint64_t expected = g_expect(CLEAN, 3);
    uint64_t state = 0;
    for (int i = 0; i < 3; i++) state = g_mix(state, actual[i]);
    return G_KEY(state, expected);   // 0 iff clean & unpatched
}
```

Then use `guard_key()` in two ways at once:
1. `G_CALL(...)` for control-flow (crash on tamper), and
2. fold it into your crypto: `hd_bind_key()` already folds the self-hash;
   additionally XOR `guard_key()` into the key so that even forcing the crash
   path away leaves the server payload undecryptable.

## Step 3 and up (later, one at a time)

- **Scatter** many guarded sites, each feeding the same accumulator, so no
  single patch clears it.
- **Delay** the reaction: let a bad key corrupt a value used minutes later, far
  from the check, so the crash's cause is not obvious in a debugger.
- **Self-integrity over more sections** (put hot functions in `hdprot`).
- **Anti-hook depth**: verify the first bytes of critical libc calls you use
  (a Frida inline hook rewrites them); read them via the direct-syscall path.
- **Rotate** `BUILD_ID` and the offsets server-side each release so a crack rots.
- **Server ties**: fold `kx` into the same accumulator so a client that didn't
  genuinely activate also fails the guard.

Each step is independent and testable. Tell me which one to build next.

## Honest note

A determined analyst with IDA + AI + root **will** eventually trace and defeat
any one layer on a binary they own. The point of stacking these is to make that
work slow and fragile (every release resets it), while the server layer
(velocity revoke, watermark, per-session `kx`) makes the *result* of a crack
short-lived and traceable. Client hardening buys time; the server lands the ban.
