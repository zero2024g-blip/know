// ============================================================================
//  login_client.cpp — the app-side login for ConnectV2 (no honeypot).
//
//  Mutual distrust, done right:
//    * the client SEALS the request (AES-256-GCM) and sends a one-time cnonce;
//    * the server SIGNS the response (Ed25519). The client REFUSES any response
//      that is not signed by the embedded public key — so a fake/MITM server
//      cannot forge a login even if it stole the AES key from this binary;
//    * the client also re-derives the token = SHA256(serial-game-key-static-salt)
//      and checks the echoed cnonce + fresh timestamp, so a replayed or
//      hand-built response is rejected.
//
//  Drop-in shaped like your BsjwkO(): fill the config, wire deviceSerial() to
//  your getSystemProperty(), and (optionally) your CertManager pinning.
//
//  Build (desktop test): g++ -std=c++17 -DLOGIN_DEMO login_client.cpp -o login \
//                          -lcurl -lcrypto -I./third_party
// ============================================================================
#include <curl/curl.h>
#include <openssl/evp.h>
#include <openssl/rand.h>
#include <nlohmann/json.hpp>

#include <cstdint>
#include <cstring>
#include <ctime>
#include <string>
#include <vector>

using json = nlohmann::json;
using Bytes = std::vector<unsigned char>;

// ----------------------------------------------------------------------------
//  CONFIG — must match ConnectV2.php
// ----------------------------------------------------------------------------
namespace cfg {
    static const std::string AES_KEY_HEX = "PUT_64_HEX_KEY";        // connect.aesKeyV2 (or connect.aesKey)
    static const std::string PUBLIC_KEY  = "YOUR_PUBLIC_KEY";       // $Public_Key
    static const std::string STATIC_WORDS= "YOUR_STATIC_WORDS";     // $staticWords
    static const std::string ACCESS      = "YOUR_ACCESS_TOKEN";     // $setAccess
    static const std::string ENDPOINT    = "https://panel.zeromods.id/data/zezr_connector_v2";
    static const std::string USER_AGENT  = "EagleA/1.2";
    // Ed25519 public key (base64) from genkey — REQUIRED. If empty, verification
    // is off (do not ship it empty).
    static const std::string SIGN_PUBKEY_B64 = "PUT_SERVER_ED25519_PUBLIC_KEY";
    // Optional TLS cert pin, exactly as you had it: "sha256//....="
    static const std::string PINNED_PUBKEY = "";

    static const std::string CRYPTO_TAG = "EG2";
    static const int NONCE = 12, TAG = 16, SKEW = 300;
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
static bool ct_eq(const std::string&a,const std::string&b){ if(a.size()!=b.size())return false; unsigned char d=0; for(size_t i=0;i<a.size();++i)d|=a[i]^b[i]; return d==0; }

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
static bool ed25519_ok(const Bytes& pub,const std::string& msg,const Bytes& sig){
    if(pub.size()!=32||sig.size()!=64)return false;
    EVP_PKEY* pk=EVP_PKEY_new_raw_public_key(EVP_PKEY_ED25519,nullptr,pub.data(),pub.size()); if(!pk)return false;
    EVP_MD_CTX* c=EVP_MD_CTX_new(); bool ok=false;
    if(c&&EVP_DigestVerifyInit(c,nullptr,nullptr,nullptr,pk)==1)
        ok=EVP_DigestVerify(c,sig.data(),sig.size(),(const unsigned char*)msg.data(),msg.size())==1;
    if(c)EVP_MD_CTX_free(c); EVP_PKEY_free(pk); return ok;
}

// ----------------------------------------------------------------------------
//  device serial — wire this to YOUR getSystemProperty() on Android.
// ----------------------------------------------------------------------------
__attribute__((weak)) std::string deviceSerial() {
#ifdef LOGIN_DEMO
    return "DEMO-DEVICE-0001";
#else
    // std::string h = getSystemProperty("ro.serialno") + getSystemProperty("ro.hardware")
    //               + getSystemProperty("ro.product.model") + getSystemProperty("ro.product.brand");
    // return bytesToUUID(h);
    return "REPLACE_WITH_HWID";
#endif
}

// ----------------------------------------------------------------------------
//  HTTP
// ----------------------------------------------------------------------------
static size_t sink(char* p,size_t s,size_t n,void* u){ ((std::string*)u)->append(p,s*n); return s*n; }

struct LoginResult { std::string id_key, token, salt, expired, access; };

// Returns true on a verified login. `msg` carries a human string either way.
bool doLogin(const std::string& userKey, const std::string& game,
             const std::string& versionString, LoginResult& out, std::string& msg) {
    if (userKey.empty()) { msg = "[-] License/Key has not been entered."; return false; }

    Bytes key = hex2bin(cfg::AES_KEY_HEX);
    if (key.size() != 32) { msg = "[ER] bad key config"; return false; }

    std::string serial = deviceSerial();
    if (serial.find(',') != std::string::npos) { msg = "[-] Invalid device."; return false; }

    // --- build + seal the request (with a one-time cnonce) ---
    Bytes cn = randb(16);
    std::string cnonce = tohex(cn.data(), cn.size());
    long long ts = (long long)time(nullptr);

    json j;
    j["game"]     = game;
    j["app_ver"]  = md5hex(versionString);
    j["user_key"] = userKey;
    j["serial"]   = serial;
    j["public"]   = cfg::PUBLIC_KEY;
    j["ts"]       = ts;
    j["cnonce"]   = cnonce;

    Bytes nonce = randb(cfg::NONCE), ct, tag;
    gcm_seal(key, nonce, cfg::CRYPTO_TAG, j.dump(), ct, tag);
    Bytes blob = ct; blob.insert(blob.end(), tag.begin(), tag.end());
    std::string envelope = cfg::CRYPTO_TAG + "." + b64ue(nonce.data(),nonce.size()) + "." + b64ue(blob.data(),blob.size());

    // --- POST ---
    CURL* cu = curl_easy_init(); if (!cu) { msg = "[ER-C] curl init"; return false; }
    char* esc = curl_easy_escape(cu, envelope.c_str(), (int)envelope.size());
    std::string body = "data=" + std::string(esc ? esc : "");
    if (esc) curl_free(esc);

    std::string resp;
    struct curl_slist* h = nullptr;
    h = curl_slist_append(h, "Accept: application/json");
    h = curl_slist_append(h, "Content-Type: application/x-www-form-urlencoded");
    h = curl_slist_append(h, "Cache-Control: no-cache");
    curl_easy_setopt(cu, CURLOPT_URL, cfg::ENDPOINT.c_str());
    curl_easy_setopt(cu, CURLOPT_POST, 1L);
    curl_easy_setopt(cu, CURLOPT_POSTFIELDS, body.c_str());
    curl_easy_setopt(cu, CURLOPT_POSTFIELDSIZE, (long)body.size());
    curl_easy_setopt(cu, CURLOPT_HTTPHEADER, h);
    curl_easy_setopt(cu, CURLOPT_USERAGENT, cfg::USER_AGENT.c_str());
    curl_easy_setopt(cu, CURLOPT_WRITEFUNCTION, sink);
    curl_easy_setopt(cu, CURLOPT_WRITEDATA, &resp);
    curl_easy_setopt(cu, CURLOPT_SSL_VERIFYPEER, 1L);
    curl_easy_setopt(cu, CURLOPT_SSL_VERIFYHOST, 2L);
    curl_easy_setopt(cu, CURLOPT_FOLLOWLOCATION, 0L);
    curl_easy_setopt(cu, CURLOPT_CONNECTTIMEOUT, 15L);
    curl_easy_setopt(cu, CURLOPT_TIMEOUT, 30L);
    if (!cfg::PINNED_PUBKEY.empty()) curl_easy_setopt(cu, CURLOPT_PINNEDPUBLICKEY, cfg::PINNED_PUBKEY.c_str());

    CURLcode rc = curl_easy_perform(cu);
    long code = 0; curl_easy_getinfo(cu, CURLINFO_RESPONSE_CODE, &code);
    curl_slist_free_all(h); curl_easy_cleanup(cu);
    if (rc != CURLE_OK) { msg = std::string("[ER-C] ") + curl_easy_strerror(rc); return false; }
    if (code == 301 || code == 302) { msg = "[ER-S] rejected (UA/route)."; return false; }

    // --- open the response: signature FIRST, then decrypt, then verify ---
    std::vector<std::string> parts; { size_t s=0; while(true){ size_t d=resp.find('.',s); if(d==std::string::npos){parts.push_back(resp.substr(s));break;} parts.push_back(resp.substr(s,d-s)); s=d+1; } }
    if ((parts.size()!=3 && parts.size()!=4) || parts[0]!=cfg::CRYPTO_TAG) { msg = "[ER-S] bad response."; return false; }

    Bytes rnonce = b64ud(parts[1]);
    Bytes rblob  = b64ud(parts[2]);
    if ((int)rnonce.size()!=cfg::NONCE || (int)rblob.size()<=cfg::TAG) { msg = "[ER-S] bad response size."; return false; }
    Bytes rtag(rblob.end()-cfg::TAG, rblob.end());
    Bytes rct(rblob.begin(), rblob.end()-cfg::TAG);

    std::string plain;
    if (!gcm_open(key, rnonce, cfg::CRYPTO_TAG, rct, rtag, plain)) { msg = "[ER-S] decrypt failed."; return false; }

    // Signature: with a public key configured, an unsigned/mis-signed reply is refused.
    bool verify = !cfg::SIGN_PUBKEY_B64.empty() && cfg::SIGN_PUBKEY_B64.rfind("PUT_",0)!=0;
    if (verify) {
        if (parts.size()!=4) { msg = "[ER-S] response not signed."; return false; }
        if (!ed25519_ok(b64d(cfg::SIGN_PUBKEY_B64), plain, b64ud(parts[3]))) { msg = "[ER-S] bad signature."; return false; }
    }

    json r;
    try { r = json::parse(plain); } catch (...) { msg = "[ER-J] parse error."; return false; }

    // bind to this request + freshness
    if (!ct_eq(r.value("cnonce", std::string()), cnonce)) { msg = "[ER-S] cnonce mismatch (replay?)."; return false; }
    long long rts = r.value("ts", 0LL);
    if (rts <= 0 || llabs((long long)time(nullptr) - rts) > cfg::SKEW) { msg = "[ER-S] response time invalid."; return false; }

    if (r.value("status", -1) != 1) { msg = std::string("[ER-S] ") + r.value("reason", std::string("Unknown error")); return false; }

    const json& d = r.at("data");
    out.id_key  = d.value("id_key", std::string());
    out.token   = d.value("token", std::string());
    out.salt    = d.value("salt", std::string());
    out.expired = d.value("expired", std::string());
    out.access  = d.value("access", std::string());

    // Re-derive the token: the client only trusts the server if this matches.
    std::string expect = sha256hex(serial + "-" + game + "-" + userKey + "-" + cfg::STATIC_WORDS + "-" + out.salt);
    if (!ct_eq(expect, out.token)) { msg = "[ER-S] Data verification failed."; return false; }
    if (!ct_eq(out.access, cfg::ACCESS)) { msg = "[ER-S] Invalid access scope."; return false; }

    msg = "[+] Successfully Logged In";
    return true;
}

#ifdef LOGIN_DEMO
#include <cstdio>
int main(int argc,char**argv){
    if(argc<4){ printf("usage: %s <GAME> <VERSION_STRING> <USER_KEY>\n",argv[0]); return 2; }
    curl_global_init(CURL_GLOBAL_DEFAULT);
    LoginResult out; std::string msg;
    bool ok = doLogin(argv[3], argv[1], argv[2], out, msg);
    printf("%s\n", msg.c_str());
    if(ok) printf("  id_key=%s access=%s expired=%s\n", out.id_key.c_str(), out.access.c_str(), out.expired.c_str());
    curl_global_cleanup();
    return ok?0:1;
}
#endif
