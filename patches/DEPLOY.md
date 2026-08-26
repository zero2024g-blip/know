# Deploying the patched files

## 1. Back up first
Keep your current copy. Every change here is reversible by restoring it.

## 2. Delete these — they are removed, not replaced
    app/BACKUP/                  # stale view copies, still held the old secret
    public/default.php.old.php   # dead page in the webroot

## 3. Upload the zip over your install
Paths inside the zip mirror your project root. `public/assets/vendor/`
is new — 912 KB of self-hosted Bootstrap, Bootstrap Icons, jQuery,
SweetAlert2 and Poppins.

## 4. Edit .env by hand — I could not, I deleted my copy

    # was: g████████  (8 lowercase letters)
    encryption.key = hex2bin:<run `php spark key:generate`>

    # was: 2592000 (30 days)
    app.sessionExpiration = 604800
    cookie.expires        = 604800

Also rotate `database.default.password` — it was in the archive you sent.

## 4b. The encryption key is now blank on purpose

`app/Config/Encryption.php` used to carry a real 64-hex key as a literal.
A key committed to source travels with every copy of the code and cannot
be rotated per install, and this project shipped `app/BACKUP/` inside the
webroot. It is now `''`, and the value comes from `.env` only.

No app code calls the encrypter today, so nothing breaks either way — but
set it anyway, because CI4 needs it the moment you start using sessions
that encrypt, or `encrypt()`/`decrypt()`:

    encryption.key = hex2bin:<run `php spark key:generate`>

## 5. Check it works
- Sign in. Sign out. Sign in again.
- Open Keys. The table loads and the page is noticeably faster.
- Reset a key's devices. Then reset a second one **without reloading** —
  this proves the CSRF token rotation is handled.
- As a non-admin, open /admin/manage-users. You must be bounced.
- Register with a referral code. Then try the **same code again** — it
  must be refused. Before this patch it stayed usable forever.
- Change your own password, sign out, sign back in with the new one.
  Do it for a legacy account too, if you still have one.

## Not done — needs a decision from you
- **CSP.** Views contain inline `<script>`. Enabling CSP means adding a
  nonce to each block first, or the panel stops working.
- **Legacy password hashes.** Rehash-on-login is already in place, but
  idle accounts keep the old scheme. Find them with:

      SELECT id_users, username FROM users WHERE password LIKE '$2y$08$%';

  then force a reset on those, and delete `create_password()`.
- **Connect.php** was empty in the archive. It is exempt from CSRF *and*
  auth, so it is the most exposed route in the app and still unreviewed.
