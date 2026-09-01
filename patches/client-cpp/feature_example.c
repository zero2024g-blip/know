// ============================================================================
//  feature_example.c — how to USE the connector's server-delivered config so
//  the real feature depends on it (not on a local "if (activated)").
// ============================================================================
//
//  The chain, end to end:
//
//    connector  --kx (per session)-->  decrypts server config "rc"  (C++ client)
//        │                                        │
//        │                                 config.res_key  (a static app secret,
//        │                                 delivered FRESH each session — it is
//        │                                 NOT compiled into the binary)
//        ▼                                        ▼
//    this file:  res_key  --decrypts-->  the app's BUNDLED encrypted resource
//                                         (offsets / assets / logic the feature
//                                          cannot run without)
//
//  Why this resists a patched client: the bundled resource ships encrypted, and
//  the only key that opens it arrives from the server after a genuine
//  activation. A cracker who patches out the licence check never receives a
//  valid config, so res_key is junk, so the resource never decrypts — the
//  feature has no data to run on. Knowing the design does not help them; they
//  are missing the server-held key.
//
//  Memory-dump hardening is included (see harden() and secure_wipe()): it does
//  NOT make a root attacker's dump impossible, it makes it costly and stale.
//  See the notes at the bottom.
//
//  Build:  cc -O2 feature_example.c -o feature -lcrypto
//  Demo:   ./provision_demo.sh          # makes resource.bin + config.json
//          ./feature config.json resource.bin        # works
//          ./feature bad_config.json resource.bin    # "locked"
// ============================================================================

#include <openssl/evp.h>
#include <openssl/crypto.h>      // OPENSSL_cleanse
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#if defined(__linux__) || defined(__ANDROID__)
#include <sys/prctl.h>
#include <sys/mman.h>
#endif

// --- minimal base64 decode (standard alphabet) ------------------------------
static int b64val(int c) {
    if (c >= 'A' && c <= 'Z') return c - 'A';
    if (c >= 'a' && c <= 'z') return c - 'a' + 26;
    if (c >= '0' && c <= '9') return c - '0' + 52;
    if (c == '+') return 62;
    if (c == '/') return 63;
    return -1;
}
static int b64_decode(const char* in, unsigned char* out, int outcap) {
    int buf = 0, bits = 0, n = 0;
    for (const char* p = in; *p; ++p) {
        if (*p == '=' || *p == '\n' || *p == '\r' || *p == ' ' || *p == '\\') continue; // skip JSON '\/'
        int v = b64val((unsigned char)*p);
        if (v < 0) return -1;
        buf = (buf << 6) | v; bits += 6;
        if (bits >= 8) { bits -= 8; if (n >= outcap) return -1; out[n++] = (unsigned char)((buf >> bits) & 0xFF); }
    }
    return n;
}

// --- read a whole file ------------------------------------------------------
static unsigned char* read_file(const char* path, long* len) {
    FILE* f = fopen(path, "rb");
    if (!f) return NULL;
    fseek(f, 0, SEEK_END); long n = ftell(f); fseek(f, 0, SEEK_SET);
    if (n < 0) { fclose(f); return NULL; }
    unsigned char* b = (unsigned char*)malloc((size_t)n + 1);
    if (!b) { fclose(f); return NULL; }
    if (fread(b, 1, (size_t)n, f) != (size_t)n) { fclose(f); free(b); return NULL; }
    fclose(f); b[n] = 0; *len = n; return b;
}

// --- pull "res_key":"...." out of the config JSON (demo-grade; use a real
//     JSON parser in production) ------------------------------------------------
static int extract_res_key_b64(const char* json, char* out, int outcap) {
    const char* key = strstr(json, "\"res_key\"");
    if (!key) return -1;
    const char* colon = strchr(key, ':'); if (!colon) return -1;
    const char* q1 = strchr(colon, '"'); if (!q1) return -1;
    const char* q2 = strchr(q1 + 1, '"'); if (!q2) return -1;
    int n = (int)(q2 - (q1 + 1));
    if (n <= 0 || n >= outcap) return -1;
    memcpy(out, q1 + 1, n); out[n] = 0; return n;
}

// --- AES-256-GCM open. Returns plaintext length, or -1 on a bad tag (which is
//     exactly what a wrong/junk key produces). --------------------------------
static int aesgcm_open(const unsigned char* key,
                       const unsigned char* blob, long blob_len,
                       const char* aad, unsigned char* out, int outcap) {
    if (blob_len <= 12 + 16) return -1;
    const unsigned char* nonce = blob;
    const unsigned char* ct    = blob + 12;
    int ct_len                 = (int)(blob_len - 12 - 16);
    const unsigned char* tag   = blob + blob_len - 16;
    if (ct_len > outcap) return -1;

    EVP_CIPHER_CTX* c = EVP_CIPHER_CTX_new();
    if (!c) return -1;
    int len = 0, ok = -1;
    do {
        if (EVP_DecryptInit_ex(c, EVP_aes_256_gcm(), NULL, NULL, NULL) != 1) break;
        if (EVP_CIPHER_CTX_ctrl(c, EVP_CTRL_GCM_SET_IVLEN, 12, NULL) != 1) break;
        if (EVP_DecryptInit_ex(c, NULL, NULL, key, nonce) != 1) break;
        int tmp = 0;
        if (aad && *aad && EVP_DecryptUpdate(c, NULL, &tmp, (const unsigned char*)aad, (int)strlen(aad)) != 1) break;
        if (EVP_DecryptUpdate(c, out, &len, ct, ct_len) != 1) break;
        if (EVP_CIPHER_CTX_ctrl(c, EVP_CTRL_GCM_SET_TAG, 16, (void*)tag) != 1) break;
        int fin = 0;
        if (EVP_DecryptFinal_ex(c, out + len, &fin) == 1) ok = len + fin;  // tag verified
    } while (0);
    EVP_CIPHER_CTX_free(c);
    return ok;
}

// Wipe that the compiler is not allowed to optimise away.
static void secure_wipe(void* p, size_t n) { OPENSSL_cleanse(p, n); }

// Best-effort anti-dump. None of this stops a root attacker; it stops a
// NON-root ptrace/coredump and keeps the secret out of swap.
static void harden(void) {
#if defined(__linux__) || defined(__ANDROID__)
    // A non-root debugger can no longer PTRACE_ATTACH or force a core dump.
    prctl(PR_SET_DUMPABLE, 0, 0, 0, 0);
#endif
}

int main(int argc, char** argv) {
    if (argc < 3) {
        fprintf(stderr, "usage: %s <config.json> <resource.bin>\n", argv[0]);
        return 2;
    }
    harden();

    // 1) The config the connector already decrypted with kx (here: read from a
    //    file; in your app you pass r.config straight from the C++ client).
    long clen = 0; unsigned char* cfg = read_file(argv[1], &clen);
    if (!cfg) { fprintf(stderr, "cannot read config\n"); return 2; }

    char res_b64[128];
    if (extract_res_key_b64((const char*)cfg, res_b64, sizeof res_b64) < 0) {
        fprintf(stderr, "no res_key in config — not a genuine activation\n");
        free(cfg); return 1;
    }

    // 2) Decode res_key into a locked, non-swappable buffer.
    unsigned char res_key[32];
    int kn = b64_decode(res_b64, res_key, sizeof res_key);
    secure_wipe(res_b64, sizeof res_b64);
    free(cfg);
    if (kn != 32) { fprintf(stderr, "bad res_key\n"); return 1; }
#if defined(__linux__) || defined(__ANDROID__)
    mlock(res_key, sizeof res_key);   // keep it out of swap
#endif

    // 3) The bundled resource ships ENCRYPTED. Only res_key opens it.
    long rlen = 0; unsigned char* res = read_file(argv[2], &rlen);
    if (!res) { secure_wipe(res_key, sizeof res_key); fprintf(stderr, "cannot read resource\n"); return 2; }

    unsigned char plain[4096];
    int pn = aesgcm_open(res_key, res, rlen, "RES1", plain, sizeof plain);

    // 4) res_key has done its job — wipe it immediately, minimise its lifetime.
    secure_wipe(res_key, sizeof res_key);
#if defined(__linux__) || defined(__ANDROID__)
    munlock(res_key, sizeof res_key);
#endif
    free(res);

    if (pn < 0) {
        // Junk key (a bypass / poisoned activation) lands here: the tag fails,
        // there is nothing to run on.
        printf("LOCKED — the resource did not decrypt (no valid activation).\n");
        return 1;
    }

    // 5) Use the real data the feature needs. Do the work, then wipe.
    printf("UNLOCKED — feature data from the server:\n  %.*s\n", pn, plain);
    //  ... your app would parse offsets / apply the config here ...
    secure_wipe(plain, sizeof plain);
    return 0;
}

// ============================================================================
//  MEMORY-DUMP REALITY (read this)
//
//  A root attacker with a debugger CAN dump this process and capture res_key
//  and the decrypted resource while they are in RAM. No client-side trick makes
//  that impossible on a device they own. What the design above buys you:
//
//   * The bundled resource key is NOT in the binary — a static-analysis dump of
//     the APK/so finds nothing; the key only exists after a live activation.
//   * res_key and plaintext live for microseconds and are wiped (OPENSSL_cleanse)
//     and kept out of swap (mlock). A dump must hit the exact window.
//   * PR_SET_DUMPABLE(0) blocks a NON-root ptrace/coredump outright.
//
//  What actually defeats "dump it once, share it forever":
//   * Make the server payload SHORT-LIVED and PER-SESSION (kx already is —
//     re-activate on a timer; expire the config). A dumped value goes stale.
//   * Make it PER-DEVICE / PER-USER and WATERMARKED, so a leaked config
//     identifies who leaked it, and only works on that device.
//   * Detect abnormal reuse server-side (one config on many devices, impossible
//     geo, replayed nonce) → blacklist (the honeypot tables already exist).
//   * Keep the crown-jewels logic SERVER-SIDE where a dump cannot reach it, and
//     stream only what each moment needs.
//
//  The honest bottom line: you cannot stop a determined root attacker from
//  reading their own device's memory. You CAN make each dump expensive,
//  short-lived, traceable, and useless to anyone but that one device — which is
//  what turns "one crack breaks everyone" into "each attacker must re-crack,
//  and gets caught."
// ============================================================================
