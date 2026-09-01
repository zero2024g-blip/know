#!/usr/bin/env python3
# ============================================================================
#  genkey.py — generate the Ed25519 response-signing key pair for connector v2,
#  OFF the server (e.g. in Termux on your phone). Same output as genkey-sign.php.
#
#  The SECRET key must NEVER be generated on, or copied to, the server's shell
#  history or logs. Run this on your own device, paste ONLY the .env line into
#  the panel's .env, and put the public key into the C++ client.
#
#  Termux:
#     pkg install python
#     pip install pynacl
#     python genkey.py
# ============================================================================
import base64
from nacl import signing

sk = signing.SigningKey.generate()
seed = bytes(sk)                       # 32-byte seed
pub  = bytes(sk.verify_key)            # 32-byte public key
# libsodium's crypto_sign secret key is seed(32) || public(32) = 64 bytes.
sodium_sk = seed + pub

sk_b64 = base64.b64encode(sodium_sk).decode()
pk_b64 = base64.b64encode(pub).decode()

print("=====================================================================")
print(" Ed25519 signing key pair for connector v2")
print("=====================================================================\n")
print("1) SERVER — add this line to your panel's .env (keep it SECRET):\n")
print("connect.signKeyV2 = " + sk_b64 + "\n")
print("2) CLIENT — set this in eagle_connector_v2.cpp:\n")
print('   static const std::string SIGN_PUBKEY_B64 = "' + pk_b64 + '";\n')
print("The public key is not a secret; the secret key is. Never commit or share")
print("the .env line.")

# self-test
msg = b"connector-v2-selftest"
sig = sk.sign(msg).signature
try:
    sk.verify_key.verify(msg, sig)
    print("\nself-test: OK (sign/verify round-trips)")
except Exception:
    print("\nself-test: FAILED — do not use this pair")
