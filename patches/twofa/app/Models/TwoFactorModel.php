<?php

namespace App\Models;

use App\Libraries\Totp;
use Config\Services;

/**
 * TwoFactorModel — the storage side of two-factor auth.
 *
 *  - The TOTP secret is kept ENCRYPTED at rest (CI4 encrypter, encryption.key),
 *    so a stolen database dump does not hand over anyone's authenticator seed.
 *  - Recovery codes are stored as keyed hashes (HMAC-SHA256 under the app key),
 *    never in the clear, and each is single-use.
 *
 * Works directly on the tables so it does not widen UserModel's mass-assignment
 * surface. Needs the columns added by MIGRATION.sql section 13.
 */
class TwoFactorModel
{
    private const REC_TABLE = 'twofa_recovery';
    private const REC_COUNT = 10;   // codes handed out at enrolment

    /** Encrypt a base32 secret for the users.totp_secret column. */
    public static function sealSecret(string $b32): string
    {
        return base64_encode(Services::encrypter()->encrypt($b32));
    }

    /** Decrypt what sealSecret() stored; null if it cannot be opened. */
    public static function openSecret(?string $stored): ?string
    {
        if (! $stored) {
            return null;
        }
        try {
            $raw = base64_decode($stored, true);
            if ($raw === false) {
                return null;
            }
            return Services::encrypter()->decrypt($raw);
        } catch (\Throwable $e) {
            log_message('error', '2FA secret could not be decrypted: {m}', ['m' => $e->getMessage()]);
            return null;
        }
    }

    public static function isEnabled($user): bool
    {
        return (int) ($user->totp_enabled ?? 0) === 1;
    }

    /**
     * Turn 2FA on for a user with a secret they have just proven they can read
     * (the caller verifies a live code first). Returns the plaintext recovery
     * codes to show ONCE — they are never retrievable again.
     */
    public static function enable(int $userId, string $b32): array
    {
        $db = db_connect();
        $db->table('users')->where('id_users', $userId)->update([
            'totp_secret'  => self::sealSecret($b32),
            'totp_enabled' => 1,
        ]);
        return self::resetRecoveryCodes($userId);
    }

    /** Turn 2FA off and destroy the secret and every recovery code. */
    public static function disable(int $userId): void
    {
        $db = db_connect();
        $db->table('users')->where('id_users', $userId)->update([
            'totp_secret'  => null,
            'totp_enabled' => 0,
        ]);
        $db->table(self::REC_TABLE)->where('id_user', $userId)->delete();
    }

    /** Verify a live TOTP code for a user. */
    public static function verifyCode($user, string $code): bool
    {
        $secret = self::openSecret($user->totp_secret ?? null);
        if (! $secret) {
            return false;
        }
        return Totp::verify($secret, $code);
    }

    /**
     * Spend a recovery code: true only if it matches an unused one, which is
     * then burned. Single-use, keyed-hash lookup, constant-time compare.
     */
    public static function useRecoveryCode(int $userId, string $input): bool
    {
        $input = strtolower(preg_replace('/[^0-9a-zA-Z]/', '', $input));
        if ($input === '') {
            return false;
        }
        $hash = self::hashCode($input);

        $db  = db_connect();
        $row = $db->table(self::REC_TABLE)
            ->where('id_user', $userId)
            ->where('code_hash', $hash)
            ->where('used_at', null)
            ->get()->getRowArray();

        if (! $row) {
            return false;
        }

        // Burn atomically: the UPDATE only touches a still-unused row, so two
        // requests racing the same code cannot both win.
        $affected = $db->table(self::REC_TABLE)
            ->where('id_rec', $row['id_rec'])
            ->where('used_at', null)
            ->update(['used_at' => date('Y-m-d H:i:s')]);

        return $db->affectedRows() === 1 && $affected;
    }

    /** How many recovery codes are still unused. */
    public static function remainingRecoveryCodes(int $userId): int
    {
        return db_connect()->table(self::REC_TABLE)
            ->where('id_user', $userId)->where('used_at', null)
            ->countAllResults();
    }

    /**
     * Replace all of a user's recovery codes with a fresh set. Returns the
     * plaintext codes to show once.
     */
    public static function resetRecoveryCodes(int $userId): array
    {
        $db = db_connect();
        $db->table(self::REC_TABLE)->where('id_user', $userId)->delete();

        $plain = [];
        $rows  = [];
        for ($i = 0; $i < self::REC_COUNT; $i++) {
            $code    = self::newCode();
            $plain[] = $code;
            $rows[]  = [
                'id_user'    => $userId,
                'code_hash'  => self::hashCode(str_replace('-', '', $code)),
                'used_at'    => null,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
        $db->table(self::REC_TABLE)->insertBatch($rows);
        return $plain;
    }

    // ------------------------------------------------------------------

    /** A recovery code: 10 hex chars, shown grouped as xxxxx-xxxxx. */
    private static function newCode(): string
    {
        $hex = bin2hex(random_bytes(5));       // 40 bits
        return substr($hex, 0, 5) . '-' . substr($hex, 5, 5);
    }

    /**
     * Keyed hash of a recovery code. HMAC under the app key means a leaked DB
     * alone cannot precompute or brute these; you would also need the key from
     * .env. Deterministic, so lookup is a plain WHERE.
     */
    private static function hashCode(string $code): string
    {
        $key = (string) (env('encryption.key') ?: config('Encryption')->key ?? '');
        // Strip CI4's "hex2bin:" / "base64:" prefixes to a raw key if present.
        if (str_starts_with($key, 'hex2bin:')) {
            $key = hex2bin(substr($key, 8));
        } elseif (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        return hash_hmac('sha256', strtolower($code), $key ?: 'zero-2fa-fallback');
    }
}
