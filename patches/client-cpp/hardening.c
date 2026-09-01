// ============================================================================
//  hardening.c — see hardening.h for the honest scope note.
//
//  Build:  cc -O2 -c hardening.c -o hardening.o                (link into app)
//  Self-test: cc -O2 -DHD_TEST hardening.c -o hdtest -lcrypto && ./hdtest
// ============================================================================
#ifndef _GNU_SOURCE
#define _GNU_SOURCE          /* for memmem() in the self-test */
#endif
#include "hardening.h"
#include <openssl/sha.h>
#include <openssl/hmac.h>
#include <openssl/crypto.h>
#include <string.h>
#include <stdlib.h>

#if defined(__linux__) || defined(__ANDROID__)
#include <unistd.h>
#endif

// ---------------------------------------------------------------------------
//  direct syscalls on arm64 (svc #0) so libc hooks (Frida) don't see the reads
// ---------------------------------------------------------------------------
#if defined(__aarch64__)
enum { HD_SYS_openat = 56, HD_SYS_close = 57, HD_SYS_read = 63, HD_SYS_faccessat = 48 };
static const long HD_AT_FDCWD = -100;
static __attribute__((always_inline)) inline long hd_sc1(long n, long a) {
    register long x8 __asm__("x8") = n; register long x0 __asm__("x0") = a;
    __asm__ __volatile__("svc #0" : "+r"(x0) : "r"(x8) : "memory"); return x0;
}
static __attribute__((always_inline)) inline long hd_sc3(long n, long a, long b, long c) {
    register long x8 __asm__("x8") = n; register long x0 __asm__("x0") = a;
    register long x1 __asm__("x1") = b; register long x2 __asm__("x2") = c;
    __asm__ __volatile__("svc #0" : "+r"(x0) : "r"(x8), "r"(x1), "r"(x2) : "memory"); return x0;
}
static __attribute__((always_inline)) inline long hd_sc4(long n, long a, long b, long c, long d) {
    register long x8 __asm__("x8") = n; register long x0 __asm__("x0") = a;
    register long x1 __asm__("x1") = b; register long x2 __asm__("x2") = c;
    register long x3 __asm__("x3") = d;
    __asm__ __volatile__("svc #0" : "+r"(x0) : "r"(x8), "r"(x1), "r"(x2), "r"(x3) : "memory"); return x0;
}
static int hd_exists(const char* p) { return hd_sc4(HD_SYS_faccessat, HD_AT_FDCWD, (long)p, 0, 0) == 0; }
static size_t hd_read(const char* p, char* buf, size_t cap) {
    long fd = hd_sc4(HD_SYS_openat, HD_AT_FDCWD, (long)p, 0 /*O_RDONLY*/, 0);
    if (fd < 0) return 0;
    size_t total = 0; long n;
    while (total < cap && (n = hd_sc3(HD_SYS_read, fd, (long)(buf + total), (long)(cap - total))) > 0)
        total += (size_t)n;
    hd_sc1(HD_SYS_close, fd);
    return total;
}
#else
#include <fcntl.h>
static int hd_exists(const char* p) { return access(p, F_OK) == 0; }
static size_t hd_read(const char* p, char* buf, size_t cap) {
#if defined(__linux__) || defined(__ANDROID__)
    int fd = open(p, O_RDONLY); if (fd < 0) return 0;
    size_t total = 0; ssize_t n;
    while (total < cap && (n = read(fd, buf + total, cap - total)) > 0) total += (size_t)n;
    close(fd); return total;
#else
    (void)p; (void)buf; (void)cap; return 0;
#endif
}
#endif

// ---------------------------------------------------------------------------
//  the probes
// ---------------------------------------------------------------------------
uint32_t hd_tamper_flags(void) {
    uint32_t flags = 0;
#if defined(__linux__) || defined(__ANDROID__) || defined(__aarch64__)
    char buf[8192];

    // debugger: /proc/self/status -> TracerPid != 0
    {
        size_t n = hd_read("/proc/self/status", buf, sizeof buf - 1);
        buf[n] = 0;
        char* t = strstr(buf, "TracerPid:");
        if (t && strtol(t + 10, NULL, 10) != 0) flags |= HD_FLAG_DEBUG;
    }
    // root
    {
        static const char* paths[] = {
            "/system/bin/su", "/system/xbin/su", "/sbin/su", "/su/bin/su",
            "/system/app/Superuser.apk", "/system/bin/magisk", "/data/adb/magisk", 0
        };
        for (int i = 0; paths[i]; i++) if (hd_exists(paths[i])) { flags |= HD_FLAG_ROOT; break; }
    }
    // hooking frameworks + suspicious rwx regions in the module map
    {
        size_t n = hd_read("/proc/self/maps", buf, sizeof buf - 1);
        buf[n] = 0;
        static const char* sigs[] = { "frida", "gum-js", "xposed", "substrate", "libriru", 0 };
        for (int i = 0; sigs[i]; i++) if (strstr(buf, sigs[i])) { flags |= HD_FLAG_HOOK; break; }
        if (strstr(buf, "rwxp")) flags |= HD_FLAG_HOOK;   // inline hooks need rwx pages
    }
    // emulator
    {
        static const char* emu[] = { "/dev/qemu_pipe", "/dev/socket/qemud", "/dev/goldfish_pipe", 0 };
        for (int i = 0; emu[i]; i++) if (hd_exists(emu[i])) { flags |= HD_FLAG_EMU; break; }
    }
#endif
    return flags;
}

// ---------------------------------------------------------------------------
//  self-integrity: hash a protected code section. Patch a byte in it and the
//  hash changes. We fold that hash into keys (hd_bind_key), so a patched build
//  cannot decrypt the server payload — the check has no boolean to strip.
// ---------------------------------------------------------------------------
//
// Put the code you most want protected in section "hdprot". The linker gives us
// __start_hdprot / __stop_hdprot bracketing it. (This marker function is here so
// the section is never empty; add your real hot functions with the same
// attribute.)
__attribute__((section("hdprot"), used, noinline))
static uint32_t hd_protected_marker(uint32_t x) {
    x ^= 0x9e3779b9u; x *= 2654435761u; x ^= x >> 15; return x;
}

// Your brand/attribution lives in its own PROTECTED data section on purpose.
// hd_self_hash() covers this section too, and hd_bind_key() folds that hash into
// the working key — so editing or deleting this string changes the key, and the
// server payload no longer decrypts. "Remove the brand" also breaks the app.
// Change it to your own mark; it stays referenced (below) so the linker keeps it.
__attribute__((section("hdbrand"), used))
volatile const char HD_BRAND[] = "ZERO \xE2\x9A\xA1 panel.zeromods.id \xE2\x80\x94 do not remove";

extern char __start_hdprot[]  __attribute__((weak));
extern char __stop_hdprot[]   __attribute__((weak));
extern char __start_hdbrand[] __attribute__((weak));
extern char __stop_hdbrand[]  __attribute__((weak));

// SHA-256 over the protected CODE section concatenated with the BRAND section.
void hd_self_hash(uint8_t out[32]) {
    volatile uint32_t sink = hd_protected_marker(0x1234u);
    sink ^= (uint32_t)(unsigned char)HD_BRAND[0];       // keep the brand referenced
    (void)sink;

    size_t n1 = (__start_hdprot  && __stop_hdprot  && __stop_hdprot  > __start_hdprot)
              ? (size_t)(__stop_hdprot  - __start_hdprot)  : 0;
    size_t n2 = (__start_hdbrand && __stop_hdbrand && __stop_hdbrand > __start_hdbrand)
              ? (size_t)(__stop_hdbrand - __start_hdbrand) : 0;
    if (n1 + n2 == 0) { memset(out, 0xA5, 32); return; }  // unusual toolchain

    unsigned char* buf = (unsigned char*)malloc(n1 + n2);
    if (!buf) { memset(out, 0xA5, 32); return; }
    if (n1) memcpy(buf,      __start_hdprot,  n1);
    if (n2) memcpy(buf + n1, __start_hdbrand, n2);
    SHA256(buf, n1 + n2, out);
    OPENSSL_cleanse(buf, n1 + n2);
    free(buf);
}

void hd_bind_key(const uint8_t base[32], uint8_t out[32]) {
    uint8_t sh[32];
    hd_self_hash(sh);
    // out = HMAC-SHA256(base, self_hash). Patch the protected code -> different
    // self_hash -> different key -> the payload it was meant to open won't.
    unsigned int len = 32;
    uint8_t tmp[32];
    HMAC(EVP_sha256(), base, 32, sh, 32, tmp, &len);
    memcpy(out, tmp, 32);
    OPENSSL_cleanse(sh, sizeof sh);
    OPENSSL_cleanse(tmp, sizeof tmp);
}

void hd_wipe(void* p, size_t n) { OPENSSL_cleanse(p, n); }

// ---------------------------------------------------------------------------
#ifdef HD_TEST
#include <stdio.h>
static void hex(const uint8_t* b, int n) { for (int i = 0; i < n; i++) printf("%02x", b[i]); }

int main(void) {
    printf("tamper flags: 0x%x", hd_tamper_flags());
    printf("  (DEBUG=%d ROOT=%d HOOK=%d EMU=%d)\n",
        !!(hd_tamper_flags() & HD_FLAG_DEBUG), !!(hd_tamper_flags() & HD_FLAG_ROOT),
        !!(hd_tamper_flags() & HD_FLAG_HOOK), !!(hd_tamper_flags() & HD_FLAG_EMU));

    uint8_t h[32]; hd_self_hash(h);
    printf("self hash   : "); hex(h, 32); printf("\n");

    // Build the same combined buffer hd_self_hash() uses, then show that a
    // 1-byte change in the CODE section, or in the BRAND, changes the hash —
    // which changes the bound key, which breaks decryption.
    size_t n1 = (size_t)(__stop_hdprot  - __start_hdprot);
    size_t n2 = (size_t)(__stop_hdbrand - __start_hdbrand);
    uint8_t* buf = (uint8_t*)malloc(n1 + n2);
    memcpy(buf, __start_hdprot, n1);
    memcpy(buf + n1, __start_hdbrand, n2);

    uint8_t hc[32]; SHA256(buf, n1 + n2, hc);
    printf("=> self_hash matches recomputed: %s\n", memcmp(h, hc, 32) == 0 ? "yes" : "NO");

    buf[0] ^= 0x01;                                  // patch the protected code
    uint8_t h2[32]; SHA256(buf, n1 + n2, h2);
    printf("=> 1-byte code patch changes the hash: %s\n",
           memcmp(h, h2, 32) != 0 ? "YES (key would break)" : "no");
    buf[0] ^= 0x01;                                  // restore

    buf[n1] ^= 0x01;                                 // edit the brand's first byte
    uint8_t h3[32]; SHA256(buf, n1 + n2, h3);
    printf("=> editing the brand changes the hash: %s\n",
           memcmp(h, h3, 32) != 0 ? "YES (stripping the brand breaks the app)" : "no");
    free(buf);

    uint8_t base[32]; memset(base, 0x11, 32);
    uint8_t k1[32], k2[32];
    hd_bind_key(base, k1);
    hd_bind_key(base, k2);
    printf("bound key stable across calls: %s\n", memcmp(k1, k2, 32) == 0 ? "yes" : "NO");
    return 0;
}
#endif
