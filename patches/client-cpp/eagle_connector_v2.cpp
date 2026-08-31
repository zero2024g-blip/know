// ============================================================================
//  Eagle Connector v2 — C++ client
// ============================================================================
//  Talks to the panel's /data/zezr_connector_v2 endpoint (ConnectV2.php).
//
//  Protocol (must match ConnectV2.php exactly):
//    * Transport : AES-256-GCM, namespace "EG2"
//    * Wire      : "EG2." + base64url(nonce) + "." + base64url(ciphertext||tag)
//    * AAD       : "EG2"       nonce: 12 bytes       tag: 16 bytes
//    * HTTP      : POST, field  data=<envelope>,  header User-Agent: EagleA/2.0
//    * Request JSON fields:
//         game      e.g. "CODM"
//         app_ver   md5(<the exact version string the panel is configured with>)
//         user_key  the licence key
//         serial    device serial  (NEVER contains a comma)
//         public    the Public_Key the panel checks
//         ts        current unix time  (server refuses if |now-ts| > 300)
//         cnonce    random hex, echoed back by the server so you can bind the
//                   reply to this request
//    * Response JSON:
//         status == 1  -> data.token / data.salt / data.access / expired ...
//         status == -1 -> reason
//         cnonce       -> must equal the one you sent
//         ts           -> must be within 300s of now
//
//  Dependencies: OpenSSL (libcrypto) + libcurl + nlohmann/json (single header).
//
//  Build (Linux/macOS):
//     g++ -std=c++17 eagle_connector_v2.cpp -o eagle_v2 -lcurl -lcrypto
//  (put json.hpp on the include path, e.g. -I./third_party, or install
//   nlohmann-json-dev)
//
//  Android (NDK): the same code compiles; link libcurl + libcrypto built for
//  your ABIs, and keep the key out of easy reach (see the note at the bottom).
// ============================================================================

#include <curl/curl.h>
#include <openssl/evp.h>
#include <openssl/rand.h>
#include <openssl/err.h>
#include <openssl/pem.h>

#include <nlohmann/json.hpp>   // https://github.com/nlohmann/json (single header)

#include <array>
#include <cstdint>
#include <cstring>
#include <ctime>
#include <iostream>
#include <stdexcept>
#include <string>
#include <vector>

using json = nlohmann::json;
using Bytes = std::vector<unsigned char>;

// ----------------------------------------------------------------------------
//  CONFIGURE THESE — must match ConnectV2.php / .env
// ----------------------------------------------------------------------------
namespace cfg {
    // The v2 AES key: the 64 hex chars from .env connect.aesKeyV2
    // (or connect.aesKey if you did not set a separate v2 key).
    static const std::string AES_KEY_HEX = "PUT_YOUR_64_HEX_KEY_HERE";

    // Must equal ConnectV2::$Public_Key and ::$staticWords.
    static const std::string PUBLIC_KEY   = "YOUR_PUBLIC_KEY";
    static const std::string STATIC_WORDS = "YOUR_STATIC_WORDS";

    // Ed25519 PUBLIC key that verifies the server's signature on every
    // response (base64, 32 bytes). Printed by patches/genkey-sign.php. This is
    // NOT a secret — but if it is wrong or empty, every response is rejected.
    // Leaving it empty disables verification (NOT recommended: that is the one
    // thing standing between you and a forged/fake server).
    static const std::string SIGN_PUBKEY_B64 = "PUT_SERVER_ED25519_PUBLIC_KEY_HERE";

    // The FULL endpoint URL.
    static const std::string ENDPOINT = "https://panel.example.com/data/zezr_connector_v2";

    // The exact User-Agent the server gates on.
    static const std::string USER_AGENT = "EagleA/2.0";

    // Crypto namespace — do not change unless the server does.
    static const std::string CRYPTO_VERSION = "EG2";
    static const int NONCE_BYTES = 12;
    static const int TAG_BYTES   = 16;
    static const int MAX_SKEW    = 300;
}

// ----------------------------------------------------------------------------
//  small helpers
// ----------------------------------------------------------------------------

[[noreturn]] static void die(const std::string& what) {
    throw std::runtime_error(what);
}

static Bytes random_bytes(int n) {
    Bytes b(n);
    if (RAND_bytes(b.data(), n) != 1) die("RAND_bytes failed");
    return b;
}

static Bytes hex2bin(const std::string& hex) {
    if (hex.size() % 2 != 0) die("hex length must be even");
    Bytes out(hex.size() / 2);
    auto nib = [](char c) -> int {
        if (c >= '0' && c <= '9') return c - '0';
        if (c >= 'a' && c <= 'f') return c - 'a' + 10;
        if (c >= 'A' && c <= 'F') return c - 'A' + 10;
        die("bad hex digit");
    };
    for (size_t i = 0; i < out.size(); ++i)
        out[i] = (unsigned char)((nib(hex[2 * i]) << 4) | nib(hex[2 * i + 1]));
    return out;
}

static std::string to_hex(const unsigned char* p, size_t n) {
    static const char* H = "0123456789abcdef";
    std::string s;
    s.reserve(n * 2);
    for (size_t i = 0; i < n; ++i) { s += H[p[i] >> 4]; s += H[p[i] & 0xF]; }
    return s;
}

// standard base64 alphabet, used to build base64url
static std::string b64_encode(const unsigned char* data, size_t len) {
    static const char* T =
        "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    std::string out;
    out.reserve(((len + 2) / 3) * 4);
    size_t i = 0;
    for (; i + 3 <= len; i += 3) {
        uint32_t n = (data[i] << 16) | (data[i + 1] << 8) | data[i + 2];
        out += T[(n >> 18) & 63]; out += T[(n >> 12) & 63];
        out += T[(n >> 6) & 63];  out += T[n & 63];
    }
    if (len - i == 1) {
        uint32_t n = data[i] << 16;
        out += T[(n >> 18) & 63]; out += T[(n >> 12) & 63]; out += "==";
    } else if (len - i == 2) {
        uint32_t n = (data[i] << 16) | (data[i + 1] << 8);
        out += T[(n >> 18) & 63]; out += T[(n >> 12) & 63]; out += T[(n >> 6) & 63]; out += '=';
    }
    return out;
}

static Bytes b64_decode(const std::string& in) {
    auto val = [](char c) -> int {
        if (c >= 'A' && c <= 'Z') return c - 'A';
        if (c >= 'a' && c <= 'z') return c - 'a' + 26;
        if (c >= '0' && c <= '9') return c - '0' + 52;
        if (c == '+') return 62;
        if (c == '/') return 63;
        return -1;
    };
    Bytes out;
    int buf = 0, bits = 0;
    for (char c : in) {
        if (c == '=' || c == '\n' || c == '\r') continue;
        int v = val(c);
        if (v < 0) die("bad base64");
        buf = (buf << 6) | v;
        bits += 6;
        if (bits >= 8) { bits -= 8; out.push_back((unsigned char)((buf >> bits) & 0xFF)); }
    }
    return out;
}

static std::string b64url_encode(const unsigned char* data, size_t len) {
    std::string s = b64_encode(data, len);
    for (char& c : s) { if (c == '+') c = '-'; else if (c == '/') c = '_'; }
    while (!s.empty() && s.back() == '=') s.pop_back();
    return s;
}

static Bytes b64url_decode(std::string s) {
    for (char& c : s) { if (c == '-') c = '+'; else if (c == '_') c = '/'; }
    return b64_decode(s);
}

static std::string md5_hex(const std::string& in) {
    unsigned char out[EVP_MAX_MD_SIZE];
    unsigned int  outlen = 0;
    EVP_MD_CTX* ctx = EVP_MD_CTX_new();
    if (!ctx) die("EVP_MD_CTX_new");
    if (EVP_DigestInit_ex(ctx, EVP_md5(), nullptr) != 1 ||
        EVP_DigestUpdate(ctx, in.data(), in.size()) != 1 ||
        EVP_DigestFinal_ex(ctx, out, &outlen) != 1) {
        EVP_MD_CTX_free(ctx); die("md5 failed");
    }
    EVP_MD_CTX_free(ctx);
    return to_hex(out, outlen);
}

static std::string sha256_hex(const std::string& in) {
    unsigned char out[EVP_MAX_MD_SIZE];
    unsigned int  outlen = 0;
    EVP_MD_CTX* ctx = EVP_MD_CTX_new();
    if (!ctx) die("EVP_MD_CTX_new");
    if (EVP_DigestInit_ex(ctx, EVP_sha256(), nullptr) != 1 ||
        EVP_DigestUpdate(ctx, in.data(), in.size()) != 1 ||
        EVP_DigestFinal_ex(ctx, out, &outlen) != 1) {
        EVP_MD_CTX_free(ctx); die("sha256 failed");
    }
    EVP_MD_CTX_free(ctx);
    return to_hex(out, outlen);
}

// constant-time string compare (for cnonce / token checks)
static bool ct_equal(const std::string& a, const std::string& b) {
    if (a.size() != b.size()) return false;
    unsigned char d = 0;
    for (size_t i = 0; i < a.size(); ++i) d |= (unsigned char)(a[i] ^ b[i]);
    return d == 0;
}

// ----------------------------------------------------------------------------
//  AES-256-GCM
// ----------------------------------------------------------------------------

// Returns nonce||ciphertext||tag pieces via out params. AAD is CRYPTO_VERSION.
static void gcm_encrypt(const Bytes& key, const Bytes& nonce, const std::string& aad,
                        const std::string& plaintext, Bytes& ciphertext, Bytes& tag) {
    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) die("EVP_CIPHER_CTX_new");
    int len = 0;
    ciphertext.assign(plaintext.size(), 0);
    tag.assign(cfg::TAG_BYTES, 0);
    try {
        if (EVP_EncryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr) != 1)
            die("EncryptInit");
        if (EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, cfg::NONCE_BYTES, nullptr) != 1)
            die("set ivlen");
        if (EVP_EncryptInit_ex(ctx, nullptr, nullptr, key.data(), nonce.data()) != 1)
            die("EncryptInit key/iv");
        int tmp = 0;
        if (!aad.empty() &&
            EVP_EncryptUpdate(ctx, nullptr, &tmp,
                              (const unsigned char*)aad.data(), (int)aad.size()) != 1)
            die("aad");
        if (EVP_EncryptUpdate(ctx, ciphertext.data(), &len,
                              (const unsigned char*)plaintext.data(), (int)plaintext.size()) != 1)
            die("EncryptUpdate");
        int final_len = 0;
        if (EVP_EncryptFinal_ex(ctx, ciphertext.data() + len, &final_len) != 1)
            die("EncryptFinal");
        if (EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_GET_TAG, cfg::TAG_BYTES, tag.data()) != 1)
            die("get tag");
    } catch (...) { EVP_CIPHER_CTX_free(ctx); throw; }
    EVP_CIPHER_CTX_free(ctx);
}

// Returns true and fills plaintext on success; false if the tag does not verify.
static bool gcm_decrypt(const Bytes& key, const Bytes& nonce, const std::string& aad,
                        const Bytes& ciphertext, const Bytes& tag, std::string& plaintext) {
    EVP_CIPHER_CTX* ctx = EVP_CIPHER_CTX_new();
    if (!ctx) die("EVP_CIPHER_CTX_new");
    Bytes out(ciphertext.size(), 0);
    int len = 0;
    bool ok = false;
    try {
        if (EVP_DecryptInit_ex(ctx, EVP_aes_256_gcm(), nullptr, nullptr, nullptr) != 1)
            die("DecryptInit");
        if (EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_IVLEN, cfg::NONCE_BYTES, nullptr) != 1)
            die("set ivlen");
        if (EVP_DecryptInit_ex(ctx, nullptr, nullptr, key.data(), nonce.data()) != 1)
            die("DecryptInit key/iv");
        int tmp = 0;
        if (!aad.empty() &&
            EVP_DecryptUpdate(ctx, nullptr, &tmp,
                              (const unsigned char*)aad.data(), (int)aad.size()) != 1)
            die("aad");
        if (EVP_DecryptUpdate(ctx, out.data(), &len,
                              ciphertext.data(), (int)ciphertext.size()) != 1)
            die("DecryptUpdate");
        if (EVP_CIPHER_CTX_ctrl(ctx, EVP_CTRL_GCM_SET_TAG, cfg::TAG_BYTES,
                                (void*)tag.data()) != 1)
            die("set tag");
        int final_len = 0;
        // Nonzero return here means the tag verified.
        if (EVP_DecryptFinal_ex(ctx, out.data() + len, &final_len) == 1) {
            out.resize(len + final_len);
            plaintext.assign((const char*)out.data(), out.size());
            ok = true;
        }
    } catch (...) { EVP_CIPHER_CTX_free(ctx); throw; }
    EVP_CIPHER_CTX_free(ctx);
    return ok;
}

// ----------------------------------------------------------------------------
//  Ed25519 signature verification (OpenSSL 1.1.1+)
// ----------------------------------------------------------------------------

// Verify that `sig` is the server's signature over `msg`, using the embedded
// 32-byte public key. Returns true only on a good signature.
static bool ed25519_verify(const Bytes& pubkey, const std::string& msg, const Bytes& sig) {
    if (pubkey.size() != 32 || sig.size() != 64) return false;
    EVP_PKEY* pk = EVP_PKEY_new_raw_public_key(EVP_PKEY_ED25519, nullptr,
                                               pubkey.data(), pubkey.size());
    if (!pk) return false;
    EVP_MD_CTX* ctx = EVP_MD_CTX_new();
    bool ok = false;
    if (ctx && EVP_DigestVerifyInit(ctx, nullptr, nullptr, nullptr, pk) == 1) {
        ok = EVP_DigestVerify(ctx, sig.data(), sig.size(),
                              (const unsigned char*)msg.data(), msg.size()) == 1;
    }
    if (ctx) EVP_MD_CTX_free(ctx);
    EVP_PKEY_free(pk);
    return ok;
}

// ----------------------------------------------------------------------------
//  envelope build / open
// ----------------------------------------------------------------------------

static std::string seal(const Bytes& key, const std::string& json_plain) {
    Bytes nonce = random_bytes(cfg::NONCE_BYTES);
    Bytes ct, tag;
    gcm_encrypt(key, nonce, cfg::CRYPTO_VERSION, json_plain, ct, tag);
    Bytes blob = ct;
    blob.insert(blob.end(), tag.begin(), tag.end());
    return cfg::CRYPTO_VERSION + "." +
           b64url_encode(nonce.data(), nonce.size()) + "." +
           b64url_encode(blob.data(), blob.size());
}

// Returns parsed JSON on success; throws on any malformed / failed envelope.
// A response envelope is  EG2.<nonce>.<ct||tag>.<sig>  (4 parts). The trailing
// signature is verified over the decrypted plaintext with the embedded public
// key: this is what proves the panel — not a cloned client or a fake server —
// produced the response.
static json open_envelope(const Bytes& key, const std::string& envelope) {
    // split on '.'
    std::vector<std::string> parts;
    size_t start = 0;
    while (true) {
        size_t dot = envelope.find('.', start);
        if (dot == std::string::npos) { parts.push_back(envelope.substr(start)); break; }
        parts.push_back(envelope.substr(start, dot - start));
        start = dot + 1;
    }
    if ((parts.size() != 3 && parts.size() != 4) || parts[0] != cfg::CRYPTO_VERSION)
        die("response: bad envelope header");

    Bytes nonce = b64url_decode(parts[1]);
    Bytes blob  = b64url_decode(parts[2]);
    if ((int)nonce.size() != cfg::NONCE_BYTES || (int)blob.size() <= cfg::TAG_BYTES)
        die("response: bad envelope size");

    Bytes tag(blob.end() - cfg::TAG_BYTES, blob.end());
    Bytes ct(blob.begin(), blob.end() - cfg::TAG_BYTES);

    std::string plain;
    if (!gcm_decrypt(key, nonce, cfg::CRYPTO_VERSION, ct, tag, plain))
        die("response: tag mismatch (tampered or wrong key)");

    // Signature check. When a public key is configured we REQUIRE a valid
    // signature and refuse anything without one — that is the whole defence
    // against a forged/fake server, so it fails closed.
    // On unless the key is empty or still the placeholder (which begins "PUT_";
    // a real standard-base64 key never does). Detecting the placeholder by
    // prefix keeps its full text to a single spot in this file, so replacing
    // that one constant cannot accidentally flip this check off.
    bool verifyOn = !cfg::SIGN_PUBKEY_B64.empty()
                 &&  cfg::SIGN_PUBKEY_B64.rfind("PUT_", 0) != 0;
    if (verifyOn) {
        if (parts.size() != 4)
            die("response: not signed (server has no signing key, or a downgrade)");
        Bytes pub = b64_decode(cfg::SIGN_PUBKEY_B64);  // standard base64
        Bytes sig = b64url_decode(parts[3]);
        if (!ed25519_verify(pub, plain, sig))
            die("response: bad signature (forged, tampered, or wrong signing key)");
    }

    return json::parse(plain, nullptr, /*allow_exceptions=*/true);
}

// ----------------------------------------------------------------------------
//  HTTP
// ----------------------------------------------------------------------------

static size_t curl_sink(char* ptr, size_t size, size_t nmemb, void* userdata) {
    ((std::string*)userdata)->append(ptr, size * nmemb);
    return size * nmemb;
}

static std::string http_post(const std::string& url, const std::string& body) {
    CURL* curl = curl_easy_init();
    if (!curl) die("curl init");
    std::string resp;
    struct curl_slist* headers = nullptr;
    headers = curl_slist_append(headers, "Content-Type: application/x-www-form-urlencoded");
    headers = curl_slist_append(headers, "Accept: text/plain");

    curl_easy_setopt(curl, CURLOPT_URL, url.c_str());
    curl_easy_setopt(curl, CURLOPT_POST, 1L);
    curl_easy_setopt(curl, CURLOPT_POSTFIELDS, body.c_str());
    curl_easy_setopt(curl, CURLOPT_POSTFIELDSIZE, (long)body.size());
    curl_easy_setopt(curl, CURLOPT_HTTPHEADER, headers);
    curl_easy_setopt(curl, CURLOPT_USERAGENT, cfg::USER_AGENT.c_str());
    curl_easy_setopt(curl, CURLOPT_WRITEFUNCTION, curl_sink);
    curl_easy_setopt(curl, CURLOPT_WRITEDATA, &resp);
    curl_easy_setopt(curl, CURLOPT_TIMEOUT, 20L);
    curl_easy_setopt(curl, CURLOPT_FOLLOWLOCATION, 0L);   // a redirect means "rejected"
    // TLS is verified by default; keep it that way.
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYPEER, 1L);
    curl_easy_setopt(curl, CURLOPT_SSL_VERIFYHOST, 2L);

    CURLcode rc = curl_easy_perform(curl);
    long code = 0;
    curl_easy_getinfo(curl, CURLINFO_RESPONSE_CODE, &code);
    curl_slist_free_all(headers);
    curl_easy_cleanup(curl);

    if (rc != CURLE_OK) die(std::string("http error: ") + curl_easy_strerror(rc));
    if (code == 302 || code == 301) die("server rejected the request (redirected — wrong User-Agent or GET?)");
    if (code != 200) die("http status " + std::to_string(code));
    return resp;
}

// url-encode a value for application/x-www-form-urlencoded
static std::string url_escape(const std::string& s) {
    CURL* c = curl_easy_init();
    char* e = curl_easy_escape(c, s.c_str(), (int)s.size());
    std::string out = e ? e : "";
    curl_free(e);
    curl_easy_cleanup(c);
    return out;
}

// ----------------------------------------------------------------------------
//  the connector call
// ----------------------------------------------------------------------------

struct ActivationResult {
    bool        ok = false;
    std::string reason;        // set when ok == false
    std::string token;         // data.token
    std::string salt;          // data.salt
    std::string access;        // data.access
    std::string expired;       // data.expired
    long long   id_key = 0;
    long long   t_time = 0;    // game.t_time
    bool        token_verified = false;
};

class EagleConnectorV2 {
public:
    EagleConnectorV2() : key_(hex2bin(cfg::AES_KEY_HEX)) {
        if (key_.size() != 32) die("AES key must be 32 bytes (64 hex chars)");
    }

    ActivationResult activate(const std::string& game,
                              const std::string& versionString,
                              const std::string& userKey,
                              const std::string& serial) {
        if (serial.find(',') != std::string::npos)
            die("serial must not contain a comma");

        // client nonce we expect echoed back
        Bytes cn = random_bytes(16);
        std::string cnonce = to_hex(cn.data(), cn.size());
        long long ts = (long long)time(nullptr);

        json req = {
            {"game",     game},
            {"app_ver",  md5_hex(versionString)},   // server compares md5(version)
            {"user_key", userKey},
            {"serial",   serial},
            {"public",   cfg::PUBLIC_KEY},
            {"ts",       ts},
            {"cnonce",   cnonce},
        };

        std::string envelope = seal(key_, req.dump());
        std::string body = "data=" + url_escape(envelope);

        std::string raw = http_post(cfg::ENDPOINT, body);
        json resp = open_envelope(key_, raw);

        // Bind the reply to this request.
        std::string rcnonce = resp.value("cnonce", std::string());
        if (!ct_equal(rcnonce, cnonce))
            die("response cnonce mismatch (replay or wrong key)");
        long long rts = resp.value("ts", 0LL);
        if (rts <= 0 || llabs((long long)time(nullptr) - rts) > cfg::MAX_SKEW)
            die("response timestamp out of window");

        ActivationResult out;
        long long status = resp.value("status", -1LL);
        if (status != 1) {
            out.ok = false;
            out.reason = resp.value("reason", std::string("unknown error"));
            return out;
        }

        const json& d = resp.at("data");
        out.ok      = true;
        out.token   = d.value("token", std::string());
        out.salt    = d.value("salt", std::string());
        out.access  = d.value("access", std::string());
        out.expired = d.value("expired", std::string());
        out.id_key  = d.value("id_key", 0LL);
        if (resp.contains("game") && resp["game"].is_object())
            out.t_time = resp["game"].value("t_time", 0LL);

        // Prove the server actually knew STATIC_WORDS: recompute the token.
        //   token = sha256( serial-game-user_key-STATIC_WORDS-salt )
        std::string expect = sha256_hex(
            serial + "-" + game + "-" + userKey + "-" + cfg::STATIC_WORDS + "-" + out.salt);
        out.token_verified = ct_equal(expect, out.token);

        return out;
    }

private:
    Bytes key_;
};

// ----------------------------------------------------------------------------
//  example
// ----------------------------------------------------------------------------

int main(int argc, char** argv) {
    if (argc < 5) {
        std::cerr << "usage: " << argv[0]
                  << " <GAME> <VERSION_STRING> <USER_KEY> <SERIAL>\n"
                  << "  e.g. " << argv[0]
                  << " CODM \"1.2.3:jp2c7H6Y1\" CODM_ABCD1234 DEV-SERIAL-001\n";
        return 2;
    }
    curl_global_init(CURL_GLOBAL_DEFAULT);
    int rc = 0;
    try {
        EagleConnectorV2 client;
        ActivationResult r = client.activate(argv[1], argv[2], argv[3], argv[4]);
        if (!r.ok) {
            std::cout << "REJECTED: " << r.reason << "\n";
            rc = 1;
        } else {
            std::cout << "OK\n"
                      << "  id_key         : " << r.id_key << "\n"
                      << "  token          : " << r.token << "\n"
                      << "  token_verified : " << (r.token_verified ? "yes" : "NO — server did not know STATIC_WORDS!") << "\n"
                      << "  access         : " << r.access << "\n"
                      << "  expired        : " << r.expired << "\n"
                      << "  t_time         : " << r.t_time << "\n";
            if (!r.token_verified) rc = 1;
        }
    } catch (const std::exception& e) {
        std::cerr << "ERROR: " << e.what() << "\n";
        rc = 3;
    }
    curl_global_cleanup();
    return rc;
}

// ============================================================================
//  SECURITY NOTE — read before shipping
//  The AES key ships inside the client, so anyone who unpacks the binary has
//  it and can read/tamper the WIRE. That is why responses are also SIGNED:
//  SIGN_PUBKEY_B64 verifies an Ed25519 signature made with a private key that
//  lives only on the server (connect.signKeyV2) and never ships here. So even
//  a fully cloned client, or a fake server, cannot produce a response this
//  client accepts — verifyOn rejects anything unsigned or mis-signed.
//
//  What signing does NOT do: it does not stop the *owner of the device* from
//  patching this binary to ignore the result of activate() — no client-side
//  check can, because they control the CPU. Signing removes the "forge a valid
//  server response" attack; defeating a patched client additionally needs the
//  protected feature itself to depend on a server-held secret (e.g. deliver it
//  inside the signed payload) rather than on a local boolean. Still: keep
//  AES_KEY_HEX split/obfuscated, strip symbols, and enable platform integrity
//  checks — every layer raises the cost.
// ============================================================================
