// ============================================================================
//  wm_tool.c — forensic per-buyer watermark: stamp a hidden, encrypted,
//  unforgeable code into each buyer's binary, and later read it from a leaked
//  copy to name the leaker. VENDOR-ONLY tool; keep the key off the client.
//
//  Build:  cc -O2 wm_tool.c -o wm_tool -lcrypto
//
//  Stamp a buyer's copy (at download time, server-side):
//     wm_tool embed  app_master  app_for_buyer  <buyer_id>  <key_hex>
//  Read the code from a leaked copy:
//     wm_tool extract leaked_app  <key_hex>
//
//  The token per slot (32 bytes) is:  nonce(8) || ct(8) || mac(16)
//    ct  = buyer_id XOR HMAC(key, nonce)[:8]          (encrypts the id)
//    mac = HMAC(key, nonce||ct)[:16]                  (authenticates)
//  Without the key it is indistinguishable from random bytes — there is no
//  magic string to grep for. The extractor scans every offset and keeps only
//  windows whose MAC verifies, so a stripped/zeroed slot just yields nothing
//  while the redundant ones still name the buyer.
// ============================================================================
#include <openssl/hmac.h>
#include <openssl/rand.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <sys/stat.h>

#define SLOT 32

static const unsigned char LOCATOR[16] =
    { 0xA7,0x11,'W','M','S','L','O','T',0x00,0x7F,'z','e','r','o',0x11,0xA7 };

static int hexkey(const char* h, unsigned char* out, int cap) {
    int n = strlen(h); if (n % 2 || n / 2 > cap) return -1;
    for (int i = 0; i < n / 2; i++) { unsigned v; if (sscanf(h + 2*i, "%2x", &v) != 1) return -1; out[i] = v; }
    return n / 2;
}
static void mac16(const unsigned char* k, int kl, const unsigned char* d, int dl, unsigned char out[16]) {
    unsigned char full[32]; unsigned int l = 0;
    HMAC(EVP_sha256(), k, kl, d, dl, full, &l);
    memcpy(out, full, 16);
}
static void ks8(const unsigned char* k, int kl, const unsigned char* nonce, unsigned char out[8]) {
    unsigned char full[32]; unsigned int l = 0;
    HMAC(EVP_sha256(), k, kl, nonce, 8, full, &l);
    memcpy(out, full, 8);
}
static void make_token(const unsigned char* key, int kl, uint64_t buyer, unsigned char tok[SLOT]) {
    unsigned char nonce[8]; RAND_bytes(nonce, 8);
    unsigned char ks[8]; ks8(key, kl, nonce, ks);
    unsigned char idb[8]; for (int i = 0; i < 8; i++) idb[i] = (buyer >> (8*i)) & 0xFF;
    unsigned char ct[8];  for (int i = 0; i < 8; i++) ct[i] = idb[i] ^ ks[i];
    unsigned char pre[16]; memcpy(pre, nonce, 8); memcpy(pre + 8, ct, 8);
    unsigned char mac[16]; mac16(key, kl, pre, 16, mac);
    memcpy(tok, nonce, 8); memcpy(tok + 8, ct, 8); memcpy(tok + 16, mac, 16);
}
static int read_token(const unsigned char* key, int kl, const unsigned char* p, uint64_t* buyer) {
    const unsigned char* nonce = p; const unsigned char* ct = p + 8; const unsigned char* mac = p + 16;
    unsigned char pre[16]; memcpy(pre, nonce, 8); memcpy(pre + 8, ct, 8);
    unsigned char m[16]; mac16(key, kl, pre, 16, m);
    if (memcmp(m, mac, 16) != 0) return 0;              // not a genuine token
    unsigned char ks[8]; ks8(key, kl, nonce, ks);
    uint64_t b = 0; for (int i = 0; i < 8; i++) b |= (uint64_t)(ct[i] ^ ks[i]) << (8*i);
    *buyer = b; return 1;
}
static unsigned char* slurp(const char* path, long* len) {
    FILE* f = fopen(path, "rb"); if (!f) return NULL;
    fseek(f, 0, SEEK_END); long n = ftell(f); fseek(f, 0, SEEK_SET);
    unsigned char* b = malloc(n); if (!b) { fclose(f); return NULL; }
    if (fread(b, 1, n, f) != (size_t)n) { fclose(f); free(b); return NULL; }
    fclose(f); *len = n; return b;
}

int main(int argc, char** argv) {
    if (argc >= 2 && !strcmp(argv[1], "embed") && argc == 6) {
        unsigned char key[64]; int kl = hexkey(argv[5], key, sizeof key);
        if (kl < 16) { fprintf(stderr, "key must be >= 32 hex chars\n"); return 2; }
        uint64_t buyer = strtoull(argv[4], NULL, 10);
        long n; unsigned char* buf = slurp(argv[2], &n);
        if (!buf) { fprintf(stderr, "cannot read %s\n", argv[2]); return 2; }
        int stamped = 0;
        for (long i = 0; i + SLOT <= n; i++) {
            if (memcmp(buf + i, LOCATOR, 16) == 0) {        // an empty slot
                unsigned char tok[SLOT]; make_token(key, kl, buyer, tok);  // fresh per slot
                memcpy(buf + i, tok, SLOT);
                stamped++;
            }
        }
        if (!stamped) { fprintf(stderr, "no free slots found (already stamped, or wm_slots.h missing)\n"); free(buf); return 1; }
        FILE* o = fopen(argv[3], "wb"); if (!o) { fprintf(stderr, "cannot write %s\n", argv[3]); free(buf); return 2; }
        fwrite(buf, 1, n, o); fclose(o); free(buf);
        struct stat st; if (stat(argv[2], &st) == 0) chmod(argv[3], st.st_mode);  // keep the exec bit
        printf("stamped %d slot(s) for buyer %llu -> %s\n", stamped, (unsigned long long)buyer, argv[3]);
        return 0;
    }
    if (argc >= 2 && !strcmp(argv[1], "extract") && argc == 4) {
        unsigned char key[64]; int kl = hexkey(argv[3], key, sizeof key);
        if (kl < 16) { fprintf(stderr, "key must be >= 32 hex chars\n"); return 2; }
        long n; unsigned char* buf = slurp(argv[2], &n);
        if (!buf) { fprintf(stderr, "cannot read %s\n", argv[2]); return 2; }
        uint64_t seen[64]; int ns = 0, hits = 0;
        for (long i = 0; i + SLOT <= n; i++) {
            uint64_t b;
            if (read_token(key, kl, buf + i, &b)) {
                hits++;
                int dup = 0; for (int j = 0; j < ns; j++) if (seen[j] == b) dup = 1;
                if (!dup && ns < 64) { seen[ns++] = b; printf("watermark found: buyer %llu (offset %ld)\n", (unsigned long long)b, i); }
            }
        }
        free(buf);
        if (!hits) { printf("no watermark found (stripped, or wrong key)\n"); return 1; }
        return 0;
    }
    fprintf(stderr,
        "usage:\n"
        "  %s embed   <master> <out> <buyer_id> <key_hex>\n"
        "  %s extract <leaked> <key_hex>\n", argv[0], argv[0]);
    return 2;
}
