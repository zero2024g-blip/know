# Two-factor authentication for the panel login

Adds a bank-style second step to the panel sign-in: after the password, the
user enters a 6-digit code from an authenticator app (Google Authenticator,
Authy, 1Password, Microsoft Authenticator — any of them). A stolen password
alone no longer gets anyone in.

It layers on top of what the login already had (Argon2id, DB rate limiting,
session-fixation protection, session-to-browser binding, the security log) —
nothing existing is removed.

## What it does

- **TOTP (RFC 6238)** — the standard every authenticator app speaks. The
  library is verified against the official RFC test vectors, so the codes it
  expects are exactly the ones the apps show.
- **Encrypted secret at rest** — the authenticator seed is stored encrypted
  with the panel's `encryption.key`, never in plaintext. A database dump alone
  does not reveal anyone's seed.
- **Single-use recovery codes** — 10 codes handed out at enrolment for when a
  phone is lost. Each works once; stored only as keyed hashes (HMAC under the
  app key), never in the clear.
- **Opt-in, self-service** — each user turns it on from Settings → Two-factor.
  A half-finished enrolment can never lock anyone out: the secret is only
  written to the database after the user proves they can read a live code.
- **Fails safe** — turning 2FA off, or regenerating recovery codes, requires a
  current code, so a stolen session cannot do either.

## Sign-in flow

```
password OK ──► 2FA on? ──no──► signed in
                   │
                  yes
                   ▼
        /login/2fa  (a short-lived, half-authenticated ticket — 5 min)
                   │
       enter app code  OR  a recovery code
                   ▼
              signed in
```

Wrong second-factor attempts count against the same DB rate limiter as wrong
passwords, so a code cannot be brute-forced.

## Install

### 1. Database (safe to re-run)

`MIGRATION.sql` section 13 adds the columns and the table:

```sql
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `totp_secret`  VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `totp_enabled` TINYINT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `twofa_recovery` (
  `id_rec`     INT AUTO_INCREMENT PRIMARY KEY,
  `id_user`    INT NOT NULL,
  `code_hash`  CHAR(64) NOT NULL,
  `used_at`    DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  KEY `idx_user` (`id_user`),
  KEY `idx_lookup` (`id_user`, `code_hash`, `used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Run it once. (It is already appended to your `MIGRATION.sql`.)

### 2. Files

Copy the `twofa/app/` tree over your panel's `app/` — the paths already match:

| File | Goes to | New / changed |
|---|---|---|
| `app/Libraries/Totp.php` | `app/Libraries/` | **new** |
| `app/Models/TwoFactorModel.php` | `app/Models/` | **new** |
| `app/Views/Auth/twofa.php` | `app/Views/Auth/` | **new** (the code step) |
| `app/Views/User/twofa.php` | `app/Views/User/` | **new** (the settings page) |
| `app/Controllers/Auth.php` | `app/Controllers/` | changed (2FA gate + `twofa()`) |
| `app/Controllers/User.php` | `app/Controllers/` | changed (adds `twofa()`) |
| `app/Views/User/settings.php` | `app/Views/User/` | changed (adds the Two-factor link) |

`Totp.php` and `TwoFactorModel.php` are pure additions. The three changed
files are drop-in replacements for the versions in your panel.

### 3. Two small edits to config (2 lines each)

**`app/Config/Routes.php`** — add the two step routes near the existing
`login` / `settings` lines:

```php
$routes->match(['get', 'post'], 'login',    'Auth::login');
$routes->match(['get', 'post'], 'login/2fa', 'Auth::twofa');    // <— add

$routes->match(['get', 'post'], 'settings',     'User::settings');
$routes->match(['get', 'post'], 'settings/2fa', 'User::twofa');  // <— add
```

**`app/Config/Filters.php`** — the second-factor page is reached before the
session exists, so add `'login/2fa'` to the `auth` filter's `except` list
(leave `csrf` as it is — the form carries a token):

```php
'auth' => ['except' => ['/', 'login', 'login/2fa', 'register', 'check', /* …the rest… */]],
```

That's all. No `.env` change is required — 2FA uses your existing
`encryption.key`.

## Using it

- A user opens **Settings → Two-factor → Turn on two-factor**, adds the shown
  key to their authenticator app (or taps the `otpauth://` link on the same
  phone to add it automatically), enters one code to confirm, and saves the 10
  recovery codes shown once.
- Next sign-in asks for a code after the password.
- Lost the phone? Use a recovery code at the code step, then regenerate codes
  or disable 2FA from Settings.

> **Recommended:** turn it on for every admin account. An admin login is the
> keys to the whole panel.

## Tested

- TOTP against all six official RFC 6238 vectors → matches Google
  Authenticator byte-for-byte.
- Secret seal/open through the real CI4 encrypter → round-trips, and the
  stored blob never contains the plaintext seed.
- Against a live database: enable issues 10 codes, `verifyCode` accepts the
  current code and rejects a wrong one, a recovery code works exactly once
  (reuse rejected), the count decrements, and disable wipes the secret and
  every code.
- The migration is idempotent (re-running it changes nothing).

## Note on QR codes

The enrolment page shows the secret key for manual entry and an `otpauth://`
link (tap-to-add on the same phone) rather than a scannable QR image — on
purpose. Rendering a QR from a third-party web service would mean sending your
authenticator seed to that service, which defeats the point. Every
authenticator app supports entering the key by hand.
