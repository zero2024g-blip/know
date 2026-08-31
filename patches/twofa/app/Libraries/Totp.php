<?php

namespace App\Libraries;

/**
 * Totp — RFC 6238 time-based one-time passwords, the kind Google Authenticator,
 * Authy, 1Password and every other authenticator app produce.
 *
 * Self-contained: HMAC-SHA1 over an 8-byte time counter, no external package.
 * SHA1 here is not a security weakness — HOTP/TOTP are defined on HMAC-SHA1 and
 * that is what the authenticator apps compute; changing the hash would just make
 * the codes not match any app.
 *
 * Defaults are the interoperable ones: 6 digits, 30-second period, base32 secret.
 * verify() checks a small window of steps so a code entered a few seconds after
 * it rolled over, or a phone clock that drifts by a step, still works.
 */
class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;      // seconds per step
    public const WINDOW = 1;       // accept current step ±1 (=> ±30s of drift)

    /**
     * A fresh base32 secret. 20 random bytes = 160 bits, the RFC's recommended
     * length for SHA1, and exactly what the apps expect.
     */
    public static function secret(int $bytes = 20): string
    {
        return self::base32encode(random_bytes($bytes));
    }

    /**
     * The 6-digit code for a secret at a given time (default: now). Same math
     * the authenticator app runs, so the two agree.
     */
    public static function code(string $base32Secret, ?int $timestamp = null,
                                int $digits = self::DIGITS, int $period = self::PERIOD): string
    {
        $timestamp = $timestamp ?? time();
        $counter   = intdiv($timestamp, $period);

        // 8-byte big-endian counter.
        $binCounter = pack('N*', 0) . pack('N*', $counter);   // 4 zero bytes + 4 bytes

        $key  = self::base32decode($base32Secret);
        $hash = hash_hmac('sha1', $binCounter, $key, true);

        // Dynamic truncation (RFC 4226 §5.3).
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part   = (ord($hash[$offset]) & 0x7F) << 24
                | (ord($hash[$offset + 1]) & 0xFF) << 16
                | (ord($hash[$offset + 2]) & 0xFF) << 8
                | (ord($hash[$offset + 3]) & 0xFF);

        $code = $part % (10 ** $digits);
        return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * True if $input matches the secret's code within ±$window steps. The
     * comparison is constant-time so a wrong code cannot be tuned by timing.
     */
    public static function verify(string $base32Secret, string $input,
                                  int $window = self::WINDOW, ?int $timestamp = null,
                                  int $digits = self::DIGITS, int $period = self::PERIOD): bool
    {
        $input = preg_replace('/\s+/', '', $input);
        if (! preg_match('/^\d{' . $digits . '}$/', $input)) {
            return false;
        }
        $timestamp = $timestamp ?? time();

        $ok = false;
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = self::code($base32Secret, $timestamp + ($i * $period), $digits, $period);
            // Compare every candidate (no early break) so the number of
            // comparisons does not depend on which step matched.
            if (hash_equals($candidate, $input)) {
                $ok = true;
            }
        }
        return $ok;
    }

    /**
     * otpauth:// URI for a QR code. Scanning it enrolls the account in the app.
     * The issuer and label are what the user sees in their authenticator list.
     */
    public static function otpauthUri(string $base32Secret, string $accountLabel,
                                      string $issuer, int $digits = self::DIGITS,
                                      int $period = self::PERIOD): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountLabel);
        $q = http_build_query([
            'secret'    => $base32Secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => $digits,
            'period'    => $period,
        ]);
        return 'otpauth://totp/' . $label . '?' . $q;
    }

    // ------------------------------------------------------------------
    //  base32 (RFC 4648, no padding on output; tolerant on input)
    // ------------------------------------------------------------------

    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32encode(string $bin): string
    {
        if ($bin === '') {
            return '';
        }
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($bin) as $ch) {
            $value = ($value << 8) | ord($ch);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::B32[($value >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) {
            $out .= self::B32[($value << (5 - $bits)) & 0x1F];
        }
        return $out;
    }

    public static function base32decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
        if ($b32 === '') {
            return '';
        }
        $out = '';
        $bits = 0;
        $value = 0;
        $map = array_flip(str_split(self::B32));
        foreach (str_split($b32) as $ch) {
            if (! isset($map[$ch])) {
                continue;
            }
            $value = ($value << 5) | $map[$ch];
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($value >> $bits) & 0xFF);
            }
        }
        return $out;
    }
}
