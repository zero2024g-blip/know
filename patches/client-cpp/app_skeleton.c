// ============================================================================
//  app_skeleton.c — the CORRECT control flow for the rewrite.
//
//  The whole point: there is ONE path — activate, get the server config, run
//  FROM that config. No local "if (activated)". No offline branch. If the
//  config did not open (no valid key, or a patched client), there is simply
//  nothing to run, and the guard turns the tampered state into a crash.
//
//  Build (demo):  cc -O2 app_skeleton.c guard.c -o app && ./app        # clean
//                 cc -O2 -DSIMULATE_CRACK app_skeleton.c guard.c -o appx && ./appx
//  In the real app, replace activate_stub() with the connector
//  (eagle_connector_v2.cpp) and the check stubs with hardening.c.
// ============================================================================
#include "guard.h"
#include <stdint.h>
#include <stdio.h>
#include <string.h>

// --- what a real activation returns (from the connector) --------------------
typedef struct {
    int  config_ok;          // true ONLY when a genuine kx opened d.rc
    char config[128];        // the server config JSON (offsets, asset-key, ...)
} Activation;

// ---------------------------------------------------------------------------
//  STUBS — replace with the real modules in your app.
// ---------------------------------------------------------------------------

// hardening.c: harden() = PR_SET_DUMPABLE(0), etc.
static void harden_stub(void) { /* real: prctl(PR_SET_DUMPABLE,0) */ }

// hardening.c: strong tamper bits only (debugger|hook), NOT root/emulator.
static uint64_t strong_tamper_stub(void) {
#ifdef SIMULATE_CRACK
    return 1;               // pretend a debugger/hook was detected
#else
    return 0;
#endif
}

// The connector: activate + verify signature + decrypt d.rc into config.
// A cracked client that skipped the server gets config_ok = 0 here — there is
// no way to fake a real kx, so there is no config to run on.
static Activation activate_stub(void) {
    Activation a; memset(&a, 0, sizeof a);
#ifdef SIMULATE_CRACK
    a.config_ok = 0;                       // no valid activation -> no config
#else
    a.config_ok = 1;
    strcpy(a.config, "{\"off\":{\"base\":\"0x00E3F1A0\"},\"lim\":90}");
#endif
    return a;
}

// ---------------------------------------------------------------------------
//  The feature. It takes ONLY the server config. There is no parameter it can
//  invent locally, and no path that runs without one.
// ---------------------------------------------------------------------------
static void feature_run(const char* config, int arg) {
    // Real code: parse offsets/limits from `config` and act on them.
    printf("  running feature with server config: %s (arg=%d)\n", config, arg);
}
static void feature_table_slot1(int a) { (void)a; }   // decoy slot

int main(void) {
    harden_stub();

    // 1) The ONE path. No "if (license_ok)" — we ask the server and it either
    //    hands us a working config or it does not.
    Activation act = activate_stub();

    // 2) Fold the decisive facts into the guard accumulator:
    //    - config_ok  (did a real kx open the server config?)
    //    - strong tamper bits (debugger/hook)
    //    A clean, genuine run lands on EXPECTED; anything else does not.
    const uint64_t CLEAN[2] = { /*config_ok=*/1, /*tamper=*/0 };
    uint64_t expected = g_expect(CLEAN, 2);

    uint64_t actual[2] = { (uint64_t)(act.config_ok ? 1 : 0), strong_tamper_stub() };
    uint64_t state = 0;
    for (int i = 0; i < 2; i++) state = g_mix(state, actual[i]);

    uintptr_t key = G_KEY(state, expected);   // 0 iff genuine & untampered

    // 3) Call the feature through a pointer CORRECTED by the key. On a genuine
    //    run key==0, so the target is exactly feature_run. On a crack (no config
    //    or tampered) key!=0, so the target is a wild address and the process
    //    faults far from here — no exit(), no "denied" branch to find and NOP.
    //    In the real client this same XOR is woven through the feature's own
    //    calls and through the resource-decryption key, not done once here.
    (void)feature_table_slot1;
    typedef void (*feat_fn)(const char*, int);
    feat_fn f = (feat_fn)G_UNMASK((uintptr_t)&feature_run, key);
    f(act.config, 42);
    return 0;
}
