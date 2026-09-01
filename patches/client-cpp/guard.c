// ============================================================================
//  guard.c — see guard.h. Self-test: cc -DGUARD_DEMO guard.c -o guarddemo && ./guarddemo
// ============================================================================
#include "guard.h"

// A bit-spreading mix (fmix64-style). Small change in x -> large change in state.
uint64_t g_mix(uint64_t state, uint64_t x) {
    state ^= x + 0x9E3779B97F4A7C15ULL + (state << 6) + (state >> 2);
    state *= 0xff51afd7ed558ccdULL;
    state ^= state >> 33;
    state *= 0xc4ceb9fe1a85ec53ULL;
    state ^= state >> 29;
    return state;
}

uint64_t g_expect(const uint64_t* clean_values, int n) {
    uint64_t s = 0;
    for (int i = 0; i < n; i++) s = g_mix(s, clean_values[i]);
    return s;
}

// ---------------------------------------------------------------------------
#ifdef GUARD_DEMO
#include <stdio.h>
#include <stdint.h>
#include <sys/wait.h>
#include <unistd.h>
#include <signal.h>

typedef void (*action_fn)(int);
static void real_feature(int arg) { printf("  feature ran with arg=%d (clean path)\n", arg); }
static void other_slot(int arg)   { printf("  other slot %d\n", arg); }

// The pointer table the guarded call dispatches through.
static action_fn g_table[2];

// Run the guarded feature given the *actual* check values. Returns nothing;
// on a clean set it calls real_feature, on a tampered set it faults.
static void run(const uint64_t* clean, const uint64_t* actual, int n, int arg) {
    uint64_t expected = g_expect(clean, n);
    uint64_t state = 0;
    for (int i = 0; i < n; i++) state = g_mix(state, actual[i]);

    uintptr_t key = G_KEY(state, expected);          // 0 iff actual == clean

    // (1) data path: a value the feature needs comes out right only when key==0.
    uintptr_t masked_arg = (uintptr_t)arg;           // stored plain here for demo
    int real_arg = (int)G_UNMASK(masked_arg, key);   // corrupted if tampered

    // (2) control path: the indirect call target is corrupted if tampered.
    G_CALL(action_fn, g_table, 0, key, real_arg);
}

int main(void) {
    g_table[0] = real_feature;
    g_table[1] = other_slot;

    // The "checks": e.g. tamper_flags (0 = clean), a self-hash word, a build id.
    uint64_t clean[3]  = { 0x0, 0xA11CE5, 0xB0071D };

    printf("clean run (all checks pass):\n");
    run(clean, clean, 3, 7);                          // key==0 -> real_feature(7)

    printf("tampered run (one check differs) in a child:\n");
    pid_t pid = fork();
    if (pid == 0) {
        uint64_t bad[3] = { 0x1 /*debugger bit set*/, 0xA11CE5, 0xB0071D };
        run(clean, bad, 3, 7);                         // key!=0 -> wild call -> fault
        _exit(0);                                      // not reached if it faults
    }
    int st = 0; waitpid(pid, &st, 0);
    if (WIFSIGNALED(st))
        printf("  child died from signal %d (%s) — crashed as designed, no exit() involved\n",
               WTERMSIG(st), WTERMSIG(st) == SIGSEGV ? "SIGSEGV" :
                              WTERMSIG(st) == SIGILL ? "SIGILL" :
                              WTERMSIG(st) == SIGBUS ? "SIGBUS" : "fatal");
    else
        printf("  child exited normally (unexpected on this platform)\n");
    return 0;
}
#endif
