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

    // Meaningless wire field names — must match ConnectV2.php (F_* constants).
    // Even if OBF is peeled at runtime, "_u" reveals nothing about "user_key".
    json j;
    j[OBF("_i")] = game;                    // game
    j[OBF("_p")] = md5hex(versionString);   // app_ver
    j[OBF("_u")] = userKey;                 // user_key
    j[OBF("_x")] = serial;                  // serial
    j[OBF("_r")] = cfg::PUBLIC_KEY();       // public
    j[OBF("tl")] = ts;                      // ts
    j[OBF("gh")] = cnonce;                  // cnonce

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
    curl_easy_setopt(cu, CURLOPT_SSL_VERIFYPEER, 1L);
    curl_easy_setopt(cu, CURLOPT_SSL_VERIFYHOST, 2L);
    curl_easy_setopt(cu, CURLOPT_FOLLOWLOCATION, 0L);
    curl_easy_setopt(cu, CURLOPT_CONNECTTIMEOUT, 15L);
    curl_easy_setopt(cu, CURLOPT_TIMEOUT, 30L);
    { std::string pin = cfg::PINNED_PUBKEY(); if(!pin.empty()) curl_easy_setopt(cu, CURLOPT_PINNEDPUBLICKEY, pin.c_str()); }

    CURLcode rc = curl_easy_perform(cu);
    curl_slist_free_all(h); curl_easy_cleanup(cu);
    if(rc != CURLE_OK){ msg=OBF("[-] Login failed."); return false; }

    // --- open the response and FOLD every check into one accumulator ---
    std::string TAGS = cfg::TAG_STR();
    std::vector<std::string> parts; { size_t s=0; while(true){ size_t d=resp.find('.',s); if(d==std::string::npos){parts.push_back(resp.substr(s));break;} parts.push_back(resp.substr(s,d-s)); s=d+1; } }
    if((parts.size()!=3 && parts.size()!=4) || parts[0]!=TAGS){ msg=OBF("[-] Login failed."); return false; }

    Bytes rnonce = b64ud(parts[1]);
    Bytes rblob  = b64ud(parts[2]);
    if((int)rnonce.size()!=cfg::NONCE || (int)rblob.size()<=cfg::TAG){ msg=OBF("[-] Login failed."); return false; }
    Bytes rtag(rblob.end()-cfg::TAG, rblob.end());
    Bytes rct(rblob.begin(), rblob.end()-cfg::TAG);

    std::string plain;
    if(!gcm_open(key, rnonce, TAGS, rct, rtag, plain)){ msg=OBF("[-] Login failed."); return false; }

    // signature (folds to 0 when good). Required when a public key is set.
    std::string spk = cfg::SIGN_PUBKEY();
    int sig_ok = 1;
    if(!spk.empty() && spk.rfind("PUT_",0)!=0){
        int have_sig = (parts.size()==4) ? 1 : 0;
        sig_ok = have_sig ? ed25519_ok(b64d(spk), plain, b64ud(parts[3])) : 0;
    }

    json r; bool parsed=true;
    try { r = json::parse(plain); } catch(...) { parsed=false; }
    if(!parsed){ msg=OBF("[-] Login failed."); return false; }

    // Pull fields with defaults so nothing throws on a failure response.
    // Same meaningless tokens as the server's R_* constants.
    long long status = r.value(OBF("sx"), (long long)-1);          // status
    std::string rcn  = r.value(OBF("gh"), std::string());          // cnonce
    long long   rts  = r.value(OBF("tl"), (long long)0);           // ts
    json d = r.contains(OBF("d0")) ? r[OBF("d0")] : json::object(); // data
    std::string token = d.value(OBF("wv"), std::string());         // token
    std::string salt  = d.value(OBF("n2"), std::string());         // salt
    std::string acc_s = d.value(OBF("ca"), std::string());         // access
    out.id_key  = d.value(OBF("af"), std::string());               // id_key
    out.expired = d.value(OBF("px"), std::string());               // expired

    std::string expTok = sha256hex(serial + "-" + game + "-" + userKey + "-" + cfg::STATIC_WORDS() + "-" + salt);

    // ---- the accumulator: ZERO iff every check passed. No if(ok). ----
    uint64_t acc = 0;
    acc |= (uint64_t)(status - 1);                                  // status == 1
    acc |= (uint64_t)(1 - sig_ok);                                  // signature verified
    acc |= neq(rcn, cnonce);                                        // cnonce echoed
    acc |= (uint64_t)((uint64_t)llabs((long long)time(nullptr)-rts) / (uint64_t)(cfg::SKEW+1)); // fresh
    acc |= neq(token, expTok);                                      // token handshake
    acc |= neq(acc_s, cfg::ACCESS());                               // access scope

    // Derive the session key. Correct ONLY when acc == 0. A patched build that
    // forces the message/return still gets a WRONG key here and breaks later.
    unsigned char accb[8]; for(int i=0;i<8;i++) accb[i]=(unsigned char)(acc>>(i*8));
    Bytes sess = hmac256(key, std::string((char*)accb,8) + "|" + salt + "|" + token);
    std::memcpy(out.session, sess.data(), 32);

    out.advisory_ok = (acc == 0);
    msg = out.advisory_ok ? OBF("[+] Successfully Logged In") : OBF("[-] Login failed.");
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
