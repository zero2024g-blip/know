<?php
/**
 * genkey-sign.php — generate the Ed25519 response-signing key pair for
 * connector v2 (ConnectV2.php).
 *
 * The SECRET key stays on the server, in .env, and signs every response.
 * The PUBLIC key is embedded in the client (eagle_connector_v2.cpp) and only
 * verifies. The client never holds the secret key, so no one who unpacks the
 * client can forge a response the client will accept.
 *
 * Run it once, on any machine with PHP + sodium (PHP 7.2+ has sodium built in):
 *
 *     php genkey-sign.php
 *
 * Then:
 *   - put the .env line it prints into your panel's .env
 *   - put the C++ line it prints into eagle_connector_v2.cpp (SIGN_PUBKEY_B64)
 *
 * Keep the secret key OUT of git, backups you share, and any zip. Treat it
 * like your database password. To rotate: run again, update both places, and
 * ship a client build carrying the new public key.
 */

if (! function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "This PHP has no sodium extension. Use PHP 7.2+ (sodium is bundled).\n");
    exit(1);
}

$pair = sodium_crypto_sign_keypair();
$sk   = sodium_crypto_sign_secretkey($pair);   // 64 bytes — SERVER ONLY
$pk   = sodium_crypto_sign_publickey($pair);   // 32 bytes — embed in client

$skB64 = base64_encode($sk);
$pkB64 = base64_encode($pk);

echo "=====================================================================\n";
echo " Ed25519 signing key pair for connector v2\n";
echo "=====================================================================\n\n";

echo "1) SERVER — add this line to your panel's .env (keep it secret):\n\n";
echo "connect.signKeyV2 = {$skB64}\n\n";

echo "2) CLIENT — set this in eagle_connector_v2.cpp:\n\n";
echo "   static const std::string SIGN_PUBKEY_B64 = \"{$pkB64}\";\n\n";

echo "The public key is not a secret; the secret key is. Never commit or share\n";
echo "the .env line.\n";

// Prove the pair is self-consistent before you rely on it.
$msg = random_bytes(32);
$sig = sodium_crypto_sign_detached($msg, $sk);
$ok  = sodium_crypto_sign_verify_detached($sig, $msg, $pk);
echo "\nself-test: " . ($ok ? "OK (sign/verify round-trips)\n" : "FAILED — do not use this pair\n");
