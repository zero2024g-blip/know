// ============================================================================
//  guard.h — obfuscated tamper reaction. No exit(), no obvious "if bad -> die".
// ============================================================================
//  The idea (harder for IDA Pro + AI than a branch to exit):
//
//   * Every integrity check folds its result into a 64-bit accumulator with
//     g_mix(). A clean run lands on a known constant EXPECTED; any tampered
//     check lands somewhere else.
//   * G_KEY(state, EXPECTED) is therefore ZERO on a clean run and a large,
//     unpredictable value on a tampered one.
//   * That key is XORed into the things the program actually uses:
//        - G_UNMASK(value,key): a data value comes out correct only when key==0.
//        - G_CALL(...):         an indirect call's TARGET is correct only when
//                               key==0; otherwise it jumps to a wild address and
//                               the process faults far away from the check.
//
//  So there is no `jz exit` to NOP. Removing a check changes the accumulator,
//  which corrupts a pointer or a value used later — the program dies or
//  produces garbage somewhere unrelated. Combine with hd_bind_key() (fold the
//  same accumulator into your crypto key) so that even forcing key==0 leaves
//  the app unable to decrypt the server payload.
//
//  This is a COST layer, not a wall. Scatter many checks, feed them all into
//  one accumulator, and use the key in many places so no single patch is enough.
// ============================================================================
#ifndef EAGLE_GUARD_H
#define EAGLE_GUARD_H

#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

// One mixing step. Fold every check result through this into a running state.
uint64_t g_mix(uint64_t state, uint64_t x);

// Compute EXPECTED for a sequence of clean check values (build/init time).
uint64_t g_expect(const uint64_t* clean_values, int n);

#ifdef __cplusplus
}
#endif

// Correction key: 0 exactly when the run matches EXPECTED.
#define G_KEY(state, expected) ((uintptr_t)((uint64_t)(state) ^ (uint64_t)(expected)))

// Unmask a data value. Equals the real value only when key == 0.
#define G_UNMASK(masked, key)  ((uintptr_t)(masked) ^ (uintptr_t)(key))

// Guarded indirect call. FT is the function-pointer type. The target is the
// table slot XOR key: correct when key==0, a wild address (fault) otherwise.
// Use it inline at call sites — do not wrap it in one helper an attacker can hook.
#define G_CALL(FT, table, slot, key, ...) \
    ( ((FT)( (uintptr_t)((table)[(slot)]) ^ (uintptr_t)(key) ))(__VA_ARGS__) )

#endif
