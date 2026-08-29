# Security posture — what a tester will find already covered

A summary of the panel's defences, for the person you hand it to. Everything
below was checked and, where noted, proven with a live attempt on a running
copy.

## Authentication
- **Login is rate-limited per IP**: 6 wrong attempts starts a 15-minute block;
  the correct password is refused while blocked (verified).
- **No user enumeration**: a wrong username and a wrong password give the same
  message, and the password hash is computed even when the username does not
  exist, so timing does not separate them.
- **Session fixation is prevented**: the session id is regenerated on every
  successful login (verified — the id before and after differ).
- **Session theft**: a session is bound to the browser's user-agent
  fingerprint; a stolen cookie replayed from another browser is rejected and
  the session is destroyed.
- **Passwords** are Argon2id (64 MB, time cost 4) — the only hasher in the
  panel. The old `create_password()` (md5-then-bcrypt-cost-8) is gone: it is
  not used to create, change, or verify any password. Registration, password
  change, and login all go through Argon2id, and a still-current Argon2id hash
  is re-hashed only if the parameters change.
  **Before deploying this build, run the check under "Removing the legacy
  password bridge" below** — with the bridge gone, any account not already on
  Argon2id can no longer log in.
- Cookies are `Secure`, `HttpOnly`, `SameSite=Lax`.

## Removing the legacy password bridge (do this before deploy)

Older accounts were stored as `bcrypt(md5(pepper + password))`. Until now,
login verified those the old way and upgraded them to Argon2id on the spot.
That bridge has been removed, so **an account still on the old scheme cannot
log in after this update**. Almost always every active account has already
been upgraded (anyone who logged in since Argon2id was added is Argon2id), but
check first:

```sql
-- Accounts that will be locked out (anything not Argon2id):
SELECT id_users, username FROM users WHERE password NOT LIKE '$argon2id$%';
```

- **No rows** → nothing to do, deploy freely.
- **Some rows** → those users need a password reset by you. Upload
  `tools/hash-pass.php` once, open it, type a temporary password to get an
  Argon2id hash, set it with
  `UPDATE users SET password='<hash>' WHERE username='<name>';`, hand the user
  the temporary password, and delete `tools/hash-pass.php`. (Referral codes are
  unaffected — they never used passwords; they are hashed with a separate
  `code_digest()` and keep working unchanged.)

## Registration
- **No privilege escalation by mass assignment**: `level`, `saldo` and
  `status` are set by the server, never taken from the form. Posting
  `level=1&saldo=999999` still produced a level-2 account with the code's
  credit (verified).
- **Referral codes are single-use and atomic**: the code is claimed with a
  conditional UPDATE inside the registration transaction, so two registrations
  racing for the same code produce exactly one account (verified with a
  concurrent race — one succeeded, one was refused).
- Referral codes are stored hashed, not in plaintext.
- Registration is rate-limited per IP and never logs the new user in.

## Request integrity
- **CSRF** is enforced on every state-changing POST, with **randomized tokens**
  (BREACH mitigation) that rotate after each request. A missing or wrong token
  never reaches an authenticated action (verified); forms and AJAX both work.
- **SQL injection**: every query is parameterized. The DataTables endpoints
  additionally whitelist column identifiers and filter values, so an
  `ORDER BY`/`LIKE` injection through a column name is inert (verified with
  timing and blind-extraction attempts). Login, register, the owner filter and
  the connector all treat input as data.
- **XSS**: output is escaped both server-side and in the DataTables renderers;
  payloads planted directly in the database fire nothing in a real browser.
- **Open redirect**: the maintenance "back" field only accepts a relative path
  of ours; schemes, hosts and `//` are dropped.

## Authorization
- Every admin action re-checks the level in the controller, not only on the
  route, and a seller is scoped to their own keys and account by immutable id
  (a re-used username cannot inherit a previous holder's keys). The full
  admin-vs-seller matrix was exercised: a seller cannot reach or drive any
  admin action, see another seller's keys, delete a key, or widen their view
  with `?owner=`.

## The connector (most exposed surface)
- CSRF- and auth-exempt by necessity, so it stands on its own: an AES-256-GCM
  sealed envelope with AAD, a 300-second replay window and an echoed client
  nonce, a required `User-Agent`, and its own per-IP rate limiter. Malformed or
  replayed requests are refused with a constant-shape error.

## Known trade-offs (not weaknesses to fix blindly)
- The Content-Security-Policy allows inline scripts, because the panel uses
  them; the escaping layers above are what stop XSS. Tightening this needs a
  nonce on every inline block.
- Rate limiting is per-IP. Behind Cloudflare (the intended deployment) the edge
  adds Bot Fight Mode and a login rate-limit rule; see CLOUDFLARE.md.
- There is no password-reset flow by design — a locked-out account is reset by
  an admin — so there is no reset-token attack surface.
