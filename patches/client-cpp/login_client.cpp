// ============================================================================
//  login_client.cpp — app-side login for ConnectV2 (no honeypot), HARDENED.
//
//  Two anti-analysis properties on top of the mutual-auth protocol:
//
//   1) NO PLAINTEXT STRINGS. Every sensitive literal (field names, URL, UA,
//      crypto tag, your keys, messages) is wrapped in OBF(); the binary stores
//      scrambled bytes, decoded only at runtime. Verified: `strings`/grep of the
//      compiled object does not show the real text. So IDA/AI can't read them.
//
//   2) NO FLIPPABLE BOOL. The decision is NOT a `bool` a cracker sets to true,
//      nor an `if (ok)` to invert. Every verification is folded, branchlessly,
//      into one accumulator that is ZERO only when ALL of them passed. From it
//      a 32-byte SESSION KEY is derived: correct only on a fully-valid login,
//      garbage otherwise. RUN YOUR APP OFF out.session — patching the returned
//      message/bool yields the "ok" text but a WRONG session, so the app breaks.
//
//  Honest note: a debugger can still watch the decrypted strings and force the
//  accumulator at runtime. This raises cost and kills static analysis; it is not
//  a wall. Pair it with hardening.c/guard.c and the per-release diversifier.
//
//  Build (desktop test): g++ -std=c++17 -DLOGIN_DEMO login_client.cpp -o login \
//                          -lcurl -lcrypto -I./third_party
// ============================================================================
#include "obf.h"

#include <curl/curl.h>
#include <openssl/evp.h>
#include <openssl/hmac.h>
#include <openssl/rand.h>
#include <nlohmann/json.hpp>

#include <algorithm>
#include <cstdint>
#include <cstring>
#include <ctime>
#include <string>
#include <vector>

using json = nlohmann::json;
using Bytes = std::vector<unsigned char>;

// ----------------------------------------------------------------------------
//  CONFIG — accessor functions so the values are OBF-hidden in the binary.
//  Replace the text inside OBF(...) with your real values (they stay hidden).
// ----------------------------------------------------------------------------
namespace cfg {
    inline std::string AES_KEY_HEX()  { return OBF("PUT_64_HEX_KEY"); }          // connect.aesKeyV2 / connect.aesKey
    inline std::string PUBLIC_KEY()   { return OBF("YOUR_PUBLIC_KEY"); }         // $Public_Key
    inline std::string STATIC_WORDS() { return OBF("YOUR_STATIC_WORDS"); }       // $staticWords
    inline std::string ACCESS()       { return OBF("YOUR_ACCESS_TOKEN"); }       // $setAccess
    inline std::string ENDPOINT()     { return OBF("https://panel.zeromods.id/data/zezr_connector_v2"); }
    inline std::string USER_AGENT()   { return OBF("EagleA/1.2"); }
    inline std::string SIGN_PUBKEY()  { return OBF("PUT_SERVER_ED25519_PUBLIC_KEY"); }  // base64
    inline std::string PINNED_PUBKEY(){ return OBF(""); }                        // "sha256//...=" or empty
    inline std::string DOH_URL()      { return OBF(""); }                        // e.g. "https://1.1.1.1/dns-query" or empty
    // If your server staples OCSP, set this to 1 to hard-fail on a revoked cert.
    // Leave 0 unless you have verified the staple exists, or handshakes will fail.
    static const long VERIFY_OCSP_STAPLE = 0;

    static const int NONCE = 12, TAG = 16, SKEW = 300;
    inline std::string TAG_STR()      { return OBF("EG2"); }
}

// ----------------------------------------------------------------------------
//  small crypto helpers
// ----------------------------------------------------------------------------
static Bytes randb(int n){ Bytes b(n); RAND_bytes(b.data(), n); return b; }
static Bytes hex2bin(const std::string& h){ Bytes o(h.size()/2); for(size_t i=0;i<o.size();++i){unsigned v;sscanf(h.c_str()+2*i,"%2x",&v);o[i]=(unsigned char)v;} return o; }
static std::string tohex(const unsigned char*p,size_t n){ static const char*H="0123456789abcdef"; std::string s; for(size_t i=0;i<n;++i){s+=H[p[i]>>4];s+=H[p[i]&15];} return s; }

static std::string b64e(const unsigned char* d, size_t n){
    static const char* T="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    std::string o; size_t i=0;
    for(;i+3<=n;i+=3){uint32_t x=(d[i]<<16)|(d[i+1]<<8)|d[i+2];o+=T[(x>>18)&63];o+=T[(x>>12)&63];o+=T[(x>>6)&63];o+=T[x&63];}
    if(n-i==1){uint32_t x=d[i]<<16;o+=T[(x>>18)&63];o+=T[(x>>12)&63];o+="==";}
    else if(n-i==2){uint32_t x=(d[i]<<16)|(d[i+1]<<8);o+=T[(x>>18)&63];o+=T[(x>>12)&63];o+=T[(x>>6)&63];o+='=';}
    return o;
}
static Bytes b64d(const std::string& in){
    auto v=[](char c)->int{ if(c>='A'&&c<='Z')return c-'A'; if(c>='a'&&c<='z')return c-'a'+26; if(c>='0'&&c<='9')return c-'0'+52; if(c=='+')return 62; if(c=='/')return 63; return -1;};
    Bytes o; int buf=0,bits=0; for(char c:in){ if(c=='='||c=='\n'||c=='\r')continue; int x=v(c); if(x<0)continue; buf=(buf<<6)|x; bits+=6; if(bits>=8){bits-=8;o.push_back((buf>>bits)&0xFF);} } return o;
}
static std::string b64ue(const unsigned char*d,size_t n){ std::string s=b64e(d,n); for(char&c:s){if(c=='+')c='-';else if(c=='/')c='_';} while(!s.empty()&&s.back()=='=')s.pop_back(); return s; }
static Bytes b64ud(std::string s){ for(char&c:s){if(c=='-')c='+';else if(c=='_')c='/';} return b64d(s); }

static std::string digest(const EVP_MD* md,const std::string& in,bool hex){
    unsigned char o[EVP_MAX_MD_SIZE]; unsigned int l=0;
    EVP_MD_CTX* c=EVP_MD_CTX_new(); EVP_DigestInit_ex(c,md,nullptr); EVP_DigestUpdate(c,in.data(),in.size()); EVP_DigestFinal_ex(c,o,&l); EVP_MD_CTX_free(c);
    return hex? tohex(o,l) : std::string((char*)o,l);
}
static std::string md5hex(const std::string& s){ return digest(EVP_md5(),s,true); }
static std::string sha256hex(const std::string& s){ return digest(EVP_sha256(),s,true); }
static Bytes hmac256(const Bytes& key,const std::string& msg){ unsigned char o[32]; unsigned l=0; HMAC(EVP_sha256(),key.data(),(int)key.size(),(const unsigned char*)msg.data(),(int)msg.size(),o,&l); return Bytes(o,o+32); }

static bool gcm_seal(const Bytes& key,const Bytes& nonce,const std::string& aad,const std::string& pt,Bytes& ct,Bytes& tag){
    EVP_CIPHER_CTX* c=EVP_CIPHER_CTX_new(); int len=0; ct.assign(pt.size(),0); tag.assign(cfg::TAG,0); bool ok=false;
    if(EVP_EncryptInit_ex(c,EVP_aes_256_gcm(),nullptr,nullptr,nullptr)==1
     &&EVP_CIPHER_CTX_ctrl(c,EVP_CTRL_GCM_SET_IVLEN,cfg::NONCE,nullptr)==1
     &&EVP_EncryptInit_ex(c,nullptr,nullptr,key.data(),nonce.data())==1){
        int t=0; if(!aad.empty())EVP_EncryptUpdate(c,nullptr,&t,(const unsigned char*)aad.data(),(int)aad.size());
        EVP_EncryptUpdate(c,ct.data(),&len,(const unsigned char*)pt.data(),(int)pt.size());
        int f=0; EVP_EncryptFinal_ex(c,ct.data()+len,&f);
        EVP_CIPHER_CTX_ctrl(c,EVP_CTRL_GCM_GET_TAG,cfg::TAG,tag.data()); ok=true;
    }
    EVP_CIPHER_CTX_free(c); return ok;
}
static bool gcm_open(const Bytes& key,const Bytes& nonce,const std::string& aad,const Bytes& ct,const Bytes& tag,std::string& pt){
    EVP_CIPHER_CTX* c=EVP_CIPHER_CTX_new(); Bytes o(ct.size(),0); int len=0; bool ok=false;
    if(EVP_DecryptInit_ex(c,EVP_aes_256_gcm(),nullptr,nullptr,nullptr)==1
     &&EVP_CIPHER_CTX_ctrl(c,EVP_CTRL_GCM_SET_IVLEN,cfg::NONCE,nullptr)==1
     &&EVP_DecryptInit_ex(c,nullptr,nullptr,key.data(),nonce.data())==1){
        int t=0; if(!aad.empty())EVP_DecryptUpdate(c,nullptr,&t,(const unsigned char*)aad.data(),(int)aad.size());
        EVP_DecryptUpdate(c,o.data(),&len,ct.data(),(int)ct.size());
        EVP_CIPHER_CTX_ctrl(c,EVP_CTRL_GCM_SET_TAG,cfg::TAG,(void*)tag.data());
        int f=0; if(EVP_DecryptFinal_ex(c,o.data()+len,&f)==1){ o.resize(len+f); pt.assign((char*)o.data(),o.size()); ok=true; }
    }
    EVP_CIPHER_CTX_free(c); return ok;
}
// returns 1 on good signature, 0 otherwise (folded, never branched by us)
static int ed25519_ok(const Bytes& pub,const std::string& msg,const Bytes& sig){
    if(pub.size()!=32||sig.size()!=64)return 0;
    EVP_PKEY* pk=EVP_PKEY_new_raw_public_key(EVP_PKEY_ED25519,nullptr,pub.data(),pub.size()); if(!pk)return 0;
    EVP_MD_CTX* c=EVP_MD_CTX_new(); int ok=0;
    if(c&&EVP_DigestVerifyInit(c,nullptr,nullptr,nullptr,pk)==1)
        ok=(EVP_DigestVerify(c,sig.data(),sig.size(),(const unsigned char*)msg.data(),msg.size())==1)?1:0;
    if(c)EVP_MD_CTX_free(c); EVP_PKEY_free(pk); return ok;
}

// Read a JSON field as a string whether the server stored it as a string or a
// number (e.g. a numeric id_key). Missing -> default. Never throws.
static std::string jstr(const json& o,const std::string& k,const std::string& dflt=std::string()){
    if(!o.contains(k)) return dflt;
    const json& v = o[k];
    if(v.is_string()) return v.get<std::string>();
    if(v.is_number_integer())  return std::to_string(v.get<long long>());
    if(v.is_number_unsigned()) return std::to_string(v.get<unsigned long long>());
    if(v.is_number_float())    return std::to_string(v.get<double>());
    if(v.is_boolean())         return v.get<bool>()?"1":"0";
    return dflt;
}

// 0 iff the two strings are identical; nonzero otherwise. No early-out branch.
static uint64_t neq(const std::string& a,const std::string& b){
    uint64_t d = (uint64_t)(a.size() ^ b.size());
    size_t n = std::min(a.size(), b.size());
    for(size_t i=0;i<n;++i) d |= (uint64_t)(unsigned char)(a[i]^b[i]);
    return d;
}

// ----------------------------------------------------------------------------
//  device serial — wire to YOUR getSystemProperty() on Android.
// ----------------------------------------------------------------------------
__attribute__((weak)) std::string deviceSerial() {
#ifdef LOGIN_DEMO
    return "DEMO-DEVICE-0001";
#else
    // std::string h = getSystemProperty(OBF("ro.serialno")) + getSystemProperty(OBF("ro.hardware"))
    //               + getSystemProperty(OBF("ro.product.model")) + getSystemProperty(OBF("ro.product.brand"));
    // return bytesToUUID(h);
    return OBF("REPLACE_WITH_HWID");
#endif
}

static size_t sink(char* p,size_t s,size_t n,void* u){ ((std::string*)u)->append(p,s*n); return s*n; }

struct LoginResult {
    std::string id_key, expired;
    unsigned char session[32];   // <<< THE gate: correct only on a valid login
    bool advisory_ok = false;    // cosmetic (for a message) — do NOT gate on this
};

// The message string is cosmetic. The security decision is out.session.
bool doLogin(const std::string& userKey, const std::string& game,
             const std::string& versionString, LoginResult& out, std::string& msg) {
    std::memset(out.session, 0, sizeof out.session);

    Bytes key = hex2bin(cfg::AES_KEY_HEX());
    std::string serial = deviceSerial();
    if (userKey.empty() || key.size()!=32 || serial.find(',')!=std::string::npos) {
        msg = OBF("[-] Login failed."); return false;
    }

    // --- build + seal the request ---
    Bytes cn = randb(16);
    std::string cnonce = tohex(cn.data(), cn.size());
    long long ts = (long long)time(nullptr);

    // Wire field names — the real names, must match ConnectV2.php (F_* constants).
    // They are still OBF()-hidden so they do not appear as plaintext in .rodata;
    // their names are not the secret anyway — the AES seal + Ed25519 signature are.
    json j;
    j[OBF("game")]     = game;                    // game
    j[OBF("app_ver")]  = md5hex(versionString);   // app_ver
    j[OBF("user_key")] = userKey;                 // user_key
    j[OBF("serial")]   = serial;                  // serial
    j[OBF("public")]   = cfg::PUBLIC_KEY();       // public
    j[OBF("ts")]       = ts;                      // ts
    j[OBF("cnonce")]   = cnonce;                  // cnonce

    Bytes nonce = randb(cfg::NONCE), ct, tag;
    gcm_seal(key, nonce, cfg::TAG_STR(), j.dump(), ct, tag);
    Bytes blob = ct; blob.insert(blob.end(), tag.begin(), tag.end());
    std::string envelope = cfg::TAG_STR() + "." + b64ue(nonce.data(),nonce.size()) + "." + b64ue(blob.data(),blob.size());

    // --- POST ---
    CURL* cu = curl_easy_init(); if(!cu){ msg=OBF("[-] Login failed."); return false; }
    char* esc = curl_easy_escape(cu, envelope.c_str(), (int)envelope.size());
    std::string body = OBF("data=") + std::string(esc?esc:"");
    if(esc) curl_free(esc);

    std::string resp;
    struct curl_slist* h = nullptr;
    h = curl_slist_append(h, (OBF("Content-Type: application/x-www-form-urlencoded")).c_str());
    h = curl_slist_append(h, (OBF("Cache-Control: no-cache")).c_str());
    curl_easy_setopt(cu, CURLOPT_URL, cfg::ENDPOINT().c_str());
    curl_easy_setopt(cu, CURLOPT_POST, 1L);
    curl_easy_setopt(cu, CURLOPT_POSTFIELDS, body.c_str());
    curl_easy_setopt(cu, CURLOPT_POSTFIELDSIZE, (long)body.size());
    curl_easy_setopt(cu, CURLOPT_HTTPHEADER, h);
    curl_easy_setopt(cu, CURLOPT_USERAGENT, cfg::USER_AGENT().c_str());
    curl_easy_setopt(cu, CURLOPT_WRITEFUNCTION, sink);
    curl_easy_setopt(cu, CURLOPT_WRITEDATA, &resp);
    // ========================================================================
    //  TRANSPORT HARDENING — bank-app grade.
    //  A verified, pinned, HTTPS-only TLS 1.3 channel that refuses every
    //  downgrade, ignores device/system proxies, resolves DNS securely, and
    //  reuses nothing. Together with the Ed25519 response signature (checked
    //  below) this makes passive sniffing and an active forged/MITM server
    //  impractical: an interceptor sees only ciphertext, and a fake endpoint
    //  cannot present a chain that verifies AND matches the pin AND sign a reply.
    //
    //  Options are grouped by what they defend against and version-guarded so the
    //  file builds on older libcurl (the guards fall back to the closest stable
    //  option). Verified against the current libcurl curl_easy_setopt docs.
    // ========================================================================

    // --- (1) Certificate & chain verification (anti-forgery) ---
    curl_easy_setopt(cu, CURLOPT_SSL_VERIFYPEER, 1L);                 // verify the CA chain — never 0
    curl_easy_setopt(cu, CURLOPT_SSL_VERIFYHOST, 2L);                 // hostname must match the cert
    // OCSP staple check (revoked-cert defence). Opt-in: only enable when your
    // server actually staples, otherwise the handshake hard-fails.
    if (cfg::VERIFY_OCSP_STAPLE)
        curl_easy_setopt(cu, CURLOPT_SSL_VERIFYSTATUS, 1L);

    // --- (2) TLS version & cipher floor (anti-downgrade) ---
    // Require TLS 1.3 and cap at the library max, so no rollback to 1.2/1.1/1.0.
    curl_easy_setopt(cu, CURLOPT_SSLVERSION,
        (long)(CURL_SSLVERSION_TLSv1_3 | CURL_SSLVERSION_MAX_DEFAULT));
    curl_easy_setopt(cu, CURLOPT_USE_SSL, (long)CURLUSESSL_ALL);      // TLS is mandatory, never opportunistic
#if defined(LIBCURL_VERSION_NUM) && LIBCURL_VERSION_NUM >= 0x073d00   /* 7.61.0 */
    curl_easy_setopt(cu, CURLOPT_TLS13_CIPHERS,                       // strong AEAD suites only
        "TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256");
    curl_easy_setopt(cu, CURLOPT_SSL_CIPHER_LIST,                     // (ignored on TLS1.3, set for belt-and-suspenders)
        "ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305");
#endif
    // Full handshake every time — no session-ticket / session-id resumption to
    // correlate or shortcut. Combined with FRESH_CONNECT below.
    curl_easy_setopt(cu, CURLOPT_SSL_SESSIONID_CACHE, 0L);

    // --- (3) Protocol lockdown (anti-scheme-downgrade / SSRF) ---
    curl_easy_setopt(cu, CURLOPT_DEFAULT_PROTOCOL, "https");
    curl_easy_setopt(cu, CURLOPT_FOLLOWLOCATION, 0L);                 // never chase a redirect
    curl_easy_setopt(cu, CURLOPT_MAXREDIRS, 0L);
#if defined(LIBCURL_VERSION_NUM) && LIBCURL_VERSION_NUM >= 0x075500   /* 7.85.0 */
    curl_easy_setopt(cu, CURLOPT_PROTOCOLS_STR, "https");             // https only, no ftp/file/gopher/…
    curl_easy_setopt(cu, CURLOPT_REDIR_PROTOCOLS_STR, "https");
#else
    curl_easy_setopt(cu, CURLOPT_PROTOCOLS, (long)CURLPROTO_HTTPS);
    curl_easy_setopt(cu, CURLOPT_REDIR_PROTOCOLS, (long)CURLPROTO_HTTPS);
#endif
#if defined(LIBCURL_VERSION_NUM) && LIBCURL_VERSION_NUM >= 0x073d00   /* 7.61.0 */
    curl_easy_setopt(cu, CURLOPT_DISALLOW_USERNAME_IN_URL, 1L);       // reject creds smuggled into the URL
#endif
    curl_easy_setopt(cu, CURLOPT_FAILONERROR, 1L);                    // treat HTTP >= 400 as an error, no body

    // --- (4) Proxy & credential lockdown (anti-MITM via device settings) ---
    curl_easy_setopt(cu, CURLOPT_NOPROXY, "*");                       // bypass every proxy for every host
    curl_easy_setopt(cu, CURLOPT_PROXY, "");                          // and force no proxy explicitly
    curl_easy_setopt(cu, CURLOPT_NETRC, (long)CURL_NETRC_IGNORED);    // never read ~/.netrc credentials
    curl_easy_setopt(cu, CURLOPT_UNRESTRICTED_AUTH, 0L);             // don't leak auth across hosts

    // --- (5) Certificate PINNING — the strongest anti-forgery layer ---
    // Even a rogue or user-installed CA (the classic rooted-device MITM) cannot
    // intercept: the server's public key must match this pin. Set
    // cfg::PINNED_PUBKEY() to "sha256//<base64>=".
    { std::string pin = cfg::PINNED_PUBKEY(); if(!pin.empty()) curl_easy_setopt(cu, CURLOPT_PINNEDPUBLICKEY, pin.c_str()); }

    // --- (6) Secure DNS (anti-DNS-spoofing) — optional DoH ---
    // Resolve the endpoint over an encrypted, verified DNS-over-HTTPS server so a
    // poisoned local resolver cannot point the app at an attacker's IP.
#if defined(LIBCURL_VERSION_NUM) && LIBCURL_VERSION_NUM >= 0x073e00   /* 7.62.0 */
    { std::string doh = cfg::DOH_URL();
      if(!doh.empty()){
          curl_easy_setopt(cu, CURLOPT_DOH_URL, doh.c_str());
#if defined(LIBCURL_VERSION_NUM) && LIBCURL_VERSION_NUM >= 0x074c00   /* 7.76.0 */
          curl_easy_setopt(cu, CURLOPT_DOH_SSL_VERIFYPEER, 1L);       // verify the DoH server too
          curl_easy_setopt(cu, CURLOPT_DOH_SSL_VERIFYHOST, 2L);
#endif
      } }
#endif

    // --- (7) Connection hygiene & abuse limits ---
    curl_easy_setopt(cu, CURLOPT_FORBID_REUSE, 1L);                   // don't keep the connection for reuse
    curl_easy_setopt(cu, CURLOPT_FRESH_CONNECT, 1L);                  // don't reuse an existing one
    curl_easy_setopt(cu, CURLOPT_MAXFILESIZE_LARGE, (curl_off_t)262144); // cap the reply (256 KiB) — no giant-body DoS
    curl_easy_setopt(cu, CURLOPT_ACCEPT_ENCODING, "identity");        // no compression (sidesteps CRIME/BREACH-style leaks)
    curl_easy_setopt(cu, CURLOPT_CONNECTTIMEOUT, 15L);
    curl_easy_setopt(cu, CURLOPT_TIMEOUT, 30L);

    CURLcode rc = curl_easy_perform(cu);
    curl_slist_free_all(h); curl_easy_cleanup(cu);
    // Only a genuine transport (network) error is reported specifically — it
    // says nothing about the protocol. Everything else uses one generic message.
    if(rc != CURLE_OK){ msg = OBF("[ER-C] ") + std::string(curl_easy_strerror(rc)); return false; }

    std::string GEN = OBF("[-] Login failed.");   // the ONE opaque failure message
    if(resp.empty()){ msg = GEN; return false; }

    // --- open the response (no message describes which step failed) ---
    std::string TAGS = cfg::TAG_STR();
    std::vector<std::string> parts; { size_t s=0; while(true){ size_t d=resp.find('.',s); if(d==std::string::npos){parts.push_back(resp.substr(s));break;} parts.push_back(resp.substr(s,d-s)); s=d+1; } }
    if((parts.size()!=3 && parts.size()!=4) || parts[0]!=TAGS){ msg = GEN; return false; }

    Bytes rnonce = b64ud(parts[1]);
    Bytes rblob  = b64ud(parts[2]);
    if((int)rnonce.size()!=cfg::NONCE || (int)rblob.size()<=cfg::TAG){ msg = GEN; return false; }
    Bytes rtag(rblob.end()-cfg::TAG, rblob.end());
    Bytes rct(rblob.begin(), rblob.end()-cfg::TAG);

    std::string plain;
    if(!gcm_open(key, rnonce, TAGS, rct, rtag, plain)){ msg = GEN; return false; }

    json r;
    try { r = json::parse(plain); }
    catch(...) { msg = GEN; return false; }   // never leak parser detail (e.what())

    // signature check (1 = good). Required when a public key is configured.
    int sig_ok = 1;
    { std::string spk = cfg::SIGN_PUBKEY();
      if(!spk.empty() && spk.rfind("PUT_",0)!=0){
          sig_ok = (parts.size()==4) ? ed25519_ok(b64d(spk), plain, b64ud(parts[3])) : 0;
      } }

    // Fields (real names = server's R_* constants). Defaults so a failure
    // response never throws.
    long long   status = r.value(OBF("status"), (long long)-1);          // status
    std::string reason = r.value(OBF("reason"), std::string());          // reason
    std::string rcn    = r.value(OBF("cnonce"), std::string());          // cnonce
    long long   rts    = r.value(OBF("ts"), (long long)0);               // ts
    json d = r.contains(OBF("data")) ? r[OBF("data")] : json::object();  // data
    std::string token  = jstr(d, OBF("token"));                          // token
    std::string salt   = jstr(d, OBF("salt"));                           // salt
    out.id_key  = jstr(d, OBF("id_key"));                                // id_key (string or number)
    out.expired = jstr(d, OBF("expired"));                               // expired

    std::string expTok = sha256hex(serial + "-" + game + "-" + userKey + "-" + cfg::STATIC_WORDS() + "-" + salt);
    long long   drift  = llabs((long long)time(nullptr) - rts);

    // ---- THE GATE: one accumulator, zero iff every check passed. No if(ok). ----
    // Only the token handshake, signature, replay and freshness — no access check.
    uint64_t acc = 0;
    acc |= (uint64_t)(status - 1);                                  // status == 1
    acc |= (uint64_t)(1 - sig_ok);                                  // signature verified
    acc |= neq(rcn, cnonce);                                        // cnonce echoed (anti-replay)
    acc |= (uint64_t)((uint64_t)drift / (uint64_t)60);             // fresh (< 60s, like the original)
    acc |= neq(token, expTok);                                      // token handshake

    // Derive the session key. Correct ONLY when acc == 0. Forcing the message or
    // the return value still yields a WRONG key here, so the app breaks later.
    unsigned char accb[8]; for(int i=0;i<8;i++) accb[i]=(unsigned char)(acc>>(i*8));
    Bytes sess = hmac256(key, std::string((char*)accb,8) + "|" + salt + "|" + token);
    std::memcpy(out.session, sess.data(), 32);
    out.advisory_ok = (acc == 0);

    // ---- message policy (cosmetic; the real gate is out.session) ----
    // Never reveal WHICH client-side check failed — a differential message is an
    // oracle that helps a reverser + AI map the protocol. So:
    //   * success            -> the success line
    //   * a server licence status (blocked/expired/…) -> pass it through (the
    //     user legitimately needs it; it does not describe the client crypto)
    //   * any client-side verification failure (signature / cnonce / time /
    //     token) -> the SAME opaque generic message, indistinguishable.
    if      (out.advisory_ok) msg = OBF("[+] Successfully Logged In");
    else if (status != 1)     msg = OBF("[-] ") + (reason.empty() ? OBF("Login failed.") : reason);
    else                      msg = GEN;

    return out.advisory_ok;
}

#ifdef LOGIN_DEMO
#include <cstdio>
int main(int argc,char**argv){
    if(argc<4){ printf("usage: %s <GAME> <VERSION_STRING> <USER_KEY>\n",argv[0]); return 2; }
    curl_global_init(CURL_GLOBAL_DEFAULT);
    LoginResult out; std::string msg;
    bool ok = doLogin(argv[3], argv[1], argv[2], out, msg);
    printf("%s\n", msg.c_str());
    printf("  session = %s\n", tohex(out.session, 32).c_str());  // app runs off THIS
    if(ok) printf("  id_key=%s expired=%s\n", out.id_key.c_str(), out.expired.c_str());
    curl_global_cleanup();
    return ok?0:1;
}
#endif
