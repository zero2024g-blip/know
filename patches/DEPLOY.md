# Deploying the patched files

## 1. Back up first
Keep your current copy. Every change here is reversible by restoring it.

## 1b. PHP version — 8.5 now works

**This build runs on PHP 8.5. It also still runs on 8.3 and 8.4.**
Do not go back to 7.4: it has had no security patches since November 2022.

Verified on PHP 8.5.9 against a real MariaDB, with the whole panel exercised:

    login .................. OK      generate keys ......... OK  (balance debited correctly)
    dashboard .............. OK      register + referral ... OK  (second use refused)
    keys / generate ........ OK      reset devices ......... OK  (CSRF rotation intact)
    manage-users ........... OK      keys DataTables API ... OK  (5 rows of 14)
    create-referral ........ OK
    check / settings ....... OK      warnings/deprecations/fatals: 0

### What was broken

One method. PHP 8.4 added a native `DateTime::createFromTimestamp(int|float): static`.
CodeIgniter 4.1.5's `Time` extends `DateTime` and declared the same method with a
narrower `int` parameter and no return type. PHP rejects that as an incompatible
override and raises a **fatal error while loading the class** — so every request
touching `Time` died, login included. A fatal cannot be masked by production mode,
which is why it looked like "some things crash at random".

Two changes fix it:

1. `vendor/codeigniter4/framework/system/I18n/Time.php` — the parameter is widened
   to `float|int` and the return type declared. This is exactly the signature
   upstream ships in 4.7. Every existing caller passes an int, so behaviour is
   unchanged.
2. `app/Config/Boot/production.php` — stopped naming `E_STRICT`. PHP 8.0 removed
   that error level and 8.4 deprecated the constant, so referencing it emitted a
   deprecation on *every request*. `development.php` also no longer uses
   `error_reporting(-1)`: CI 4.1.5 raises PHP 8.1+ deprecations during normal
   work and this framework promotes any reported deprecation to an exception, so
   `-1` made every page in development mode return 500 before rendering.

### Important: the vendor edit is not permanent

`composer update` restores `vendor/` from the package and drops change 1. If you
ever run it, re-apply with:

    php tools/patch-php85.php

The script is idempotent (safe to run twice), verifies the file still compiles
afterwards and reverts itself if not, and **refuses to touch a Time.php it does
not recognise** — so if you later upgrade the framework it tells you instead of
corrupting anything.

### The real fix, when you have time

Upgrading to CodeIgniter 4.7.4 (current, requires PHP ^8.2) removes the need for
the patch. I tried it: `composer require codeigniter4/framework:^4.7` resolves
cleanly and `hermawan/codeigniter4-datatables` moves to 0.5.4, but the app does
not boot afterwards —

    Error: Only arrays and Traversables can be unpacked, null given
    Autoloader.php:140  (Config\Autoload::$helpers does not exist)

— because every class in `app/Config/` is still the 4.1 layout. 4.7 expects new
properties and has moved session, security and cookie settings into their own
config classes; `Time` also became immutable. That is a migration to plan and
test, not a version bump to rush. The patch above is a correct stopgap until then.

## 2. Delete these — they are removed, not replaced
    app/BACKUP/                  # stale view copies, still held the old secret
    public/default.php           # Hostinger's placeholder page
    public/default.php.old.php   # dead page in the webroot

`default.php` is Hostinger's own "your site is installed" placeholder. It is
not a security hole — it contains no secrets and no logic beyond `date('Y')`.
The page itself says: *"Please delete the file default.php from the
public_html folder."* Two reasons to do as it asks: it names your hosting
provider to anyone who requests it, and if your DirectoryIndex order ever
changes, visitors get "site not yet uploaded" instead of your panel.

## 2b. Your existing database — run MIGRATION.sql first

**Your old database works with this build.** The four business tables
(`users`, `keys_code`, `referral_code`, `history`) are untouched: no column
added, renamed or dropped. But two things must be done before you go live,
and `MIGRATION.sql` in the zip does both. Back up first.

    mysqldump -u USER -p DBNAME > backup-before-migration.sql
    mysql     -u USER -p DBNAME < MIGRATION.sql

**Why it matters — I tested this against a database built the old way:**

*Two tables are missing.* `auth_ratelimit` and `check_ratelimit` are new.
Without them the panel **still runs perfectly**, which is the trap: every
rate-limit query fails, the failure is caught so pages keep working, and
there is no brute-force limit at all. Measured on an unmigrated database:
**0 of 8 wrong passwords were blocked.** After the migration the 6th attempt
is blocked, as intended. The code now also logs this at `critical`, so if it
ever happens again you will find "Rate limiter unavailable" in
`writable/logs/`.

*Every old referral code is spendable again.* The old code called
`useReferral($code)` without a username, so the guard inside never ran and
`used_by` was never written — every referral row in your database reads as
unused, whether or not somebody registered with it. The new code honours
`used_by`, so on deploy day every code you have ever issued becomes valid
again. I reproduced this: an already-used code created a fresh account and
credited it $50. Nothing in the data distinguishes used from unused codes,
so step 2 of the migration closes all of them. Issue new ones from the admin
panel for anyone still waiting.

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

## 4c. New look — what changed and how to undo it

The 31 KB `<style>` block that used to sit inside `Layout/Starter.php` is now
two files: `public/assets/css/theme.css` (the previous look, unchanged) and
`public/assets/css/ember.css` (the new layer on top). Everything else about
the panel behaves as it did.

Measured over a six-page session, this cuts what is transferred by **51%**
(80.5 KB -> 39.8 KB), because the CSS is now fetched once and cached instead
of travelling inside every page. That is the change that matters most on a
slow connection.

To go back to the old appearance, delete one line from `Layout/Starter.php`:

    <link rel="stylesheet" href="<?= asset_ver('assets/css/ember.css') ?>">

Asset URLs now carry `?v=<mtime>` via the new `asset_ver()` helper. This is
required, not cosmetic: `.htaccess` serves CSS and JS with `max-age=31536000,
immutable`, so without a version in the URL a returning visitor would keep a
stale stylesheet for a year.

**If you add a `bi-*` icon to any view, run `tools/subset-icons.sh`.** The
icon font is subsetted to only the icons in use; a new one renders as a blank
box until you rebuild it.

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
- On the dashboard as a **reseller**: the four counters and the activity list
  must show only your own keys. As admin they cover everyone.
- On a phone, open Keys. The reset and edit buttons must be reachable without
  sideways scrolling, and each key must show its expiry underneath it.
- Reset a key's devices. The "Please wait…" toast must appear — if it does
  not, `Toast` is undefined and the script order regressed.
- After switching PHP version in hPanel, sign in once. If login 500s, the
  `Time` patch is missing — run `php tools/patch-php85.php`.
- Get your password wrong six times. The sixth attempt must tell you to
  wait. If it does not, `MIGRATION.sql` has not been run — check
  `writable/logs/` for "Rate limiter unavailable".
- Open Generate and change the duration. The Estimation box must fill in
  with a figure. It was permanently blank before (see below).
- On a phone, tap the username field on the login page. The page must not
  zoom in.
- Open Admin → Games & Pricing. Add a game, give it a tier, then change
  that tier's price and check the Generate page shows the new figure.
- Sign in as a **reseller** and open Generate. The 1-hour test key must
  not be in the duration list for any game.
- In a browser, try `https://your-panel/.env` — it must be refused, not
  downloaded.

## Games, tiers and pricing are now edited in the panel

**Admin → Games & Pricing.** Games, durations and prices used to be PHP
arrays inside `Controllers/Keys.php`, so adding a game meant editing code
and re-uploading. They are database rows now.

- **A game** is a short code plus a display name — `CODM` /
  *Call of Duty Mobile*. The code goes inside every key it issues
  (`CODM_a1b2c3…`), so once keys exist the panel locks that field; the
  display name is always free to change.
- **A tier** belongs to one game: hours, label, price per device. So
  30 days can be $12 on one game and $25 on another, and a game can offer
  a yearly tier no other game has. Add, edit and remove them per game.
- **"Admins only"** on a tier is how the free 1-hour test key works. A
  seller never sees it, and the server refuses it even if the form is
  edited by hand — I tested exactly that: a seller posting `duration=1`
  is rejected and charged nothing, while an admin gets the key for $0.
- **Inactive** hides a game or tier from sellers without touching keys
  already sold. Deleting a game that still has keys is refused outright,
  because the code is baked into those keys.

`MIGRATION.sql` seeds the three games and four tiers that were hard-coded,
so nothing changes on the day you deploy.

Two bugs fell out of building this:

- **A free key could never be generated at all.** The balance debit ran
  `saldo = saldo - 0`, which leaves the row identical, so MySQL reports
  zero affected rows and the code read that as "insufficient funds". Any
  zero-cost tier failed. The debit is now skipped when there is nothing
  to charge.
- **"$test" in the dropdown.** That label is gone; the 1-hour tier is
  "1 Hour (test key)" at $0.00.

## Cross-platform check

Every page was loaded on twelve device profiles — iPhone SE / 14 Pro / 14 Pro
Max, iPad Mini, iPad Pro landscape, Galaxy S8, Pixel 7, Galaxy Tab, a 1366
laptop, a 1920 desktop, MacBook Air 13 and MacBook Pro 16 — checking for
sideways scrolling, tap targets under 44px, text under 11px and JavaScript
errors. All twelve are clean. What that turned up and fixed:

- **iOS zoomed the page when you tapped a login field.** Mobile Safari does
  that whenever a focused input is under 16px; these were 15.2px. Touch
  devices now get 16px inputs, desktop keeps the smaller ones.
- **Tap targets were 32–38px** (dismiss button, pagination, DataTables search
  and length, settings buttons, the two icon buttons on each key row). All
  are 44px or larger on touch now. The icon buttons had a hit area grown by a
  pseudo-element, but neighbouring buttons overlapped and ate each other's
  edges — measured 39×41px. Now 45×45px.
- **Labels at 10.56px** — the mono column headers and stat labels — are 11px,
  and 11.5px on small screens.
- **`100vh`** is joined by `100dvh`, which is the height iOS actually shows.
- Grey tap-flash removed, notch insets honoured, momentum scrolling kept.

One limit worth stating plainly: **WebKit and Firefox could not be installed
in my environment**, so the above ran on Chromium — real for Android Chrome,
Windows Chrome/Edge and Mac Chrome, and a good proxy for layout elsewhere,
but not a substitute for opening Safari yourself. I audited the CSS against
Safari's known gaps instead: `backdrop-filter` already carries its `-webkit-`
prefix, `scrollbar-width` has a `::-webkit-scrollbar` fallback, and `:has()`
(Safari 15.4+) is used only for a SweetAlert backdrop that degrades quietly.
`gap` and `inset` need iOS 14.5+, which is the effective floor for this build.

## Locking down the sensitive files

I served the panel from a real Apache with the document root set to the
**project root** — the worst case, and what `app.baseURL` ending in
`/public` implies — then probed it over HTTPS. 67 checks: every route and
asset still loads, and every one of these is refused:

    .env  .env.bak  env.template  .htaccess  .htpasswd  .user.ini
    composer.json  composer.lock  package.json  phpunit.xml.dist
    .git/config  .git/HEAD  .gitignore
    app/**  vendor/**  writable/**  tools/**  builds/**
    spark  LICENSE  README.md  MIGRATION.sql  DEPLOYMENT-MODES.md
    default.php  backup.sql  dump.sql.gz  panel.zip  id_rsa  server.key
    config.php~  .DS_Store  Database.php.bak

…and the tricks people try to get past a filter, all refused:
`/public/../.env`, `/./.env`, `/.env.`, `/.env%20`, `//.env`,
`/%2e%65nv`, `/app/../.env`, `/index.php/../.env`, `/.env?x=1`, `/.env/.`

**What changed.** The old rules were a blacklist — they blocked the
patterns someone had thought of. `env.template` and `default.php` sailed
straight through; I watched them download. It is a whitelist now: a real
file whose extension is not in the servable list is refused, whatever it
is called. PHP is refused everywhere except the front controller, so
`tools/genkey.php` and any file that ever gets uploaded cannot execute.
`writable/` has its own rules that both deny access *and* switch the PHP
engine off, so a file landing there is inert twice over.

**I also found my own rule had broken the site.** The previous
"deny anything without a file extension" matched every CodeIgniter route —
`/login`, `/keys`, `/dashboard` — because routes are not files. On Apache
that returns 403 for the entire panel. It now tests the resolved filename
and only fires for files that actually exist on disk, and I verified every
route still loads.

**The one thing worth more than all of it:** point the document root at
`public/`. Then `app/`, `vendor/`, `writable/` and `.env` are not in the
served tree at all and none of these rules is load-bearing. In hPanel this
is the "Document Root" field. Change `app.baseURL` to drop the `/public`
at the same time.

Both deployment modes were re-verified after the changes: with Cloudflare
(mode A) a request that does not come from a Cloudflare IP gets 403, and
`tools/set-mode.sh` still round-trips between modes byte-for-byte.

## Not done — needs a decision from you
- **CSP.** Views contain inline `<script>`. Enabling CSP means adding a
  nonce to each block first, or the panel stops working.
- **Legacy password hashes.** Rehash-on-login is already in place, but
  idle accounts keep the old scheme. Find them with:

      SELECT id_users, username FROM users WHERE password LIKE '$2y$08$%';

  then force a reset on those, and delete `create_password()`.
- **Connect.php** was empty in the archive. It is exempt from CSRF *and*
  auth, so it is the most exposed route in the app and still unreviewed.
