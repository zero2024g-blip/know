<?php
/**
 * ONE-TIME encryption key generator — for hosts without SSH.
 *
 * HOW TO USE
 *   1. Upload this file to your web root via hPanel's File Manager.
 *   2. Open  https://panel.zeromods.id/genkey.php  once.
 *   3. Copy the line it prints into .env, replacing encryption.key.
 *   4. DELETE THIS FILE IMMEDIATELY.
 *
 * It refuses to run twice by writing a marker next to itself, so a file
 * left behind by accident cannot be used to fish for keys. It also never
 * touches .env itself — you paste the value, so nothing is written by a
 * script that a stranger could trigger.
 */

$marker = __DIR__ . '/.genkey-used';

if (file_exists($marker)) {
    http_response_code(410);
    exit("Already used. Delete genkey.php and .genkey-used from the server.\n");
}

if (! function_exists('random_bytes')) {
    http_response_code(500);
    exit("random_bytes() unavailable — PHP is too old to generate a safe key.\n");
}

$key = bin2hex(random_bytes(32));   // 256 bits
@file_put_contents($marker, date('c'));

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo "Paste this into .env, replacing the encryption.key line:\n\n";
echo "encryption.key = hex2bin:{$key}\n\n";
echo "Then DELETE genkey.php and .genkey-used from the server.\n";
