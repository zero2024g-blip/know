#!/usr/bin/env bash
# provision_demo.sh — make a runnable demo for feature_example.c.
#
# It plays the SERVER's role once: picks a res_key, encrypts the app's real
# resource under it (AES-256-GCM, AAD "RES1") into resource.bin, and writes the
# config.json the connector would have delivered ({"res_key":"<base64>"}).
# Also writes bad_config.json (a junk key) to show the "locked" path.
#
# In production none of this lives on the device: the server holds the resource
# key and puts it into the encrypted config it sends. This script only exists so
# you can run the example locally.
#
# Needs: php (for AES-256-GCM). Run:  bash provision_demo.sh
set -euo pipefail

REAL_DATA='AIMBOT_OFFSET=0x00E3F1A0;ESP_OFFSET=0x000142B8;BUILD=ok'

php -r '
$data = $argv[1];
$key  = random_bytes(32);
$nonce= random_bytes(12); $tag="";
$ct = openssl_encrypt($data, "aes-256-gcm", $key, OPENSSL_RAW_DATA, $nonce, $tag, "RES1", 16);
file_put_contents("resource.bin", $nonce.$ct.$tag);
file_put_contents("config.json", json_encode(["res_key"=>base64_encode($key)], JSON_UNESCAPED_SLASHES));
file_put_contents("bad_config.json", json_encode(["res_key"=>base64_encode(random_bytes(32))], JSON_UNESCAPED_SLASHES));
echo "wrote resource.bin, config.json, bad_config.json\n";
' "$REAL_DATA"
