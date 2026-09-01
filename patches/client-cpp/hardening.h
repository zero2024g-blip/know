// ============================================================================
//  hardening.h — native client-integrity layer for the connector client.
// ============================================================================
//  This is the "anti-analysis" column: anti-debug, anti-hook, anti-emulator,
//  and a self-integrity check that is FOLDED INTO A KEY so that patching the
//  checks out breaks decryption instead of just returning a bool an attacker
//  can flip.
//
//  Honest scope: none of this stops a determined attacker with root on their
//  own device — it raises cost and feeds server-side detection. The durable
//  protection is server authority (per-device binding, short-lived payloads,
//  heartbeat + revocation, watermarking); this layer buys time and evidence.
// ============================================================================
#ifndef EAGLE_HARDENING_H
#define EAGLE_HARDENING_H

#include <stdint.h>
#include <stddef.h>

#ifdef __cplusplus
extern "C" {
#endif

// Tamper bits — same values the connector sends as "h9".
enum {
    HD_FLAG_DEBUG = 1,   // a debugger is attached
    HD_FLAG_ROOT  = 2,   // rooted device (server treats as info only)
    HD_FLAG_HOOK  = 4,   // Frida/Xposed/substrate present
    HD_FLAG_EMU   = 8,   // emulator
};

// Run every probe and return the OR of the bits above. Uses direct arm64
// syscalls where it touches the kernel, so libc hooks do not see the calls.
uint32_t hd_tamper_flags(void);

// SHA-256 of this build's protected code section. Any byte patched in that
// section changes the result. 32 bytes written to out.
void hd_self_hash(uint8_t out[32]);

// Derive an EFFECTIVE key from a base key by folding in the self-hash. If the
// protected code was patched, the effective key is wrong and whatever it
// decrypts (your server payload / resource) fails — with no "if (tampered)"
// branch to find and remove. base and out are 32 bytes; they may alias.
void hd_bind_key(const uint8_t base[32], uint8_t out[32]);

// Convenience: zero memory in a way the compiler cannot optimise away.
void hd_wipe(void* p, size_t n);

#ifdef __cplusplus
}
#endif
#endif
