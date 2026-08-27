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

*The rate limiter tables are missing.* `auth_ratelimit`, `check_ratelimit`
and `connect_ratelimit` are new. Without them the panel **still runs
perfectly**, which is the trap: every rate-limit query fails, the failure is
caught so pages keep working, and there is no brute-force limit at all.
Measured on an unmigrated database: **0 of 8 wrong passwords were blocked.**
After the migration the 6th attempt is blocked, as intended. The code now
also logs this at `critical`, so if it ever happens again you will find
"Rate limiter unavailable" in `writable/logs/`.

*Three more tables are new in this round.* `login_sessions` holds the
sign-in history and the browser fingerprint that stops a copied session
cookie; `keys_deleted` is the archive a deleted key moves into; and
`connect_ratelimit` is the connector's own limiter — that one was referenced
by `Connect.php` but never created, so until now the connector's rate limit
was silently doing nothing. All three are in `MIGRATION.sql`. It is safe to
run the file again if you already ran an earlier copy: every statement is
`CREATE TABLE IF NOT EXISTS` or equivalent, and I re-ran it twice against the
same database to confirm.

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
- Open a seller's account page from Manage Users, top them up, and check the
  movement appears in Balance history with your name against it.
- As that seller, open /account and try /account/1 — both must show their own
  page, never yours.

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

## Every account has a page now

**Account → My Account**, and for an admin, the chart icon beside any user in
Manage Users. It answers what nothing in the panel could answer before: how
much has this seller sold, and who has been topping them up.

- Balance, total topped up (and how many times), spent on keys, keys issued
- Keys broken down per game, with active count, devices and when they last
  issued one, plus a fourteen-day bar chart
- Who topped the account up, how often, and how much each person gave
- The full balance history: every movement, what it was for, who caused it,
  the amount and the balance it left

This needed a ledger, because nothing recorded balance movements — `saldo`
held a number and that was all. `balance_log` is append-only and written in
the same transaction as the balance change itself, so it can never disagree
with the figure it explains. I verified that: after four purchases, two
top-ups and one reduction, `users.saldo` and the last `balance_after` matched
to the cent.

Three things write to it: an admin editing a balance, a key purchase, and the
opening credit from a referral code. The admin edit sets an absolute figure,
so the movement is derived from a `SELECT … FOR UPDATE` — reading it unlocked
would let a purchase land in between and be silently overwritten. A reduction
is recorded as `adjustment`, not `topup`, and "spent on keys" counts only
purchases; folding a manual deduction into that figure made it read high,
which is a bug I caught by reading the rendered page against the raw rows.

**Access control:** a seller sees only their own page. `/account/5` shows a
seller their own page rather than an error, so there is nothing to probe —
a valid id and an invalid one look identical to them. An admin sees anyone's.
Both were tested.

**History starts the day you run `MIGRATION.sql`.** Movements from before
that cannot be reconstructed, so each account gets one `opening` row
explaining the balance it already had.

## The greeting shows once, at sign-in

"Welcome" used to appear on every page of every visit. It was not a message
at all: `msgStatus.php` had a final `else` that printed it whenever no real
flash was set, so it had no trigger and no end, and its only effect was to
bury the messages that did mean something.

It is now an ordinary one-time flash, set by `Auth` on a successful sign-in
and read once on the dashboard you land on. Every other message — errors,
"password changed", the balance banner on Generate — is untouched and still
works exactly as before. The dashboard heading no longer says "Welcome back"
either; a heading that greets you on every visit is the same fault in bigger
type.

## Sign-ins are recorded, and a stolen session cookie no longer works

**Settings → Your sign-ins.** Every sign-in to your account: the device, the
full user agent, the time you signed in to the second, the time you signed
out, and whether the session is still open. An admin gets a second panel,
**All sign-ins**, with the same for every account and the username on each
row.

This is also the fix for the attack you described — someone who knows a
password signs in, copies the session cookie out of the browser, and pastes
it into another browser to be treated as that person.

A session cookie is a bearer token: hold it and you are that account. So the
cookie is no longer sufficient on its own. At sign-in the session is bound to
a fingerprint of the browser that created it (an HMAC of the user agent under
a server secret), and `AuthFilter` recomputes and compares that fingerprint
on every request. A mismatch means the cookie is being presented by something
other than the browser it was issued to: the session is destroyed — not just
refused, or the thief could keep trying while you stayed signed in — both
parties are sent back to the login page, and the row is marked **Blocked** in
the list so you can see it happened. It is also written to the error log.

I tested it exactly as described: signed in, copied the `ci_session` value,
replayed it from a different browser. The replay was refused and the original
session was killed in the same moment.

**Deliberately not bound to the IP address.** On Iranian mobile networks the
IP changes constantly, and a check that signs honest people out every few
minutes gets switched off — which protects nobody. The IP is still recorded
(hashed), it just does not decide.

Be honest about what this is: it stops a copied cookie, which is the attack
you saw. It does not stop someone who also forges the user agent to match.
The session lifetime (30 minutes, or 24 hours with "stay signed in") is the
other half of the answer.

## Admins can delete keys, and the record survives

The keys list has a third button for admins only — the trash icon — with a
confirmation and an optional reason. A seller does not see it, and the
endpoint re-checks the level itself, so it is not a matter of the button
being hidden.

**Deleting does not erase.** The row is copied to `keys_deleted` first, in
the same transaction, and only then removed from `keys_code`. The `history`
rows are left exactly where they are. If the archive write fails, the whole
thing rolls back and the key stays — losing the key and its record together
is the one outcome worth avoiding. **Admin → Deleted Keys** shows the
archive: the key, its game, who issued it, who deleted it, why and when.

**Each admin may delete 20 keys in any 6 hours.** The point is not to
inconvenience you; it is that an admin account which gets taken over cannot
empty the table in one run and disappear. Clearing out a handful of test keys
never comes close; a script trying to wipe thousands hits the cap within
seconds, and the archive still holds every one it managed to remove. I tested
this by deleting 22 in a row: the 21st and 22nd were refused, 20 were
archived, and all 24 history rows were still there.

If the counter cannot be read, deletion **fails closed** — an unlimited
delete is precisely what this guards against. To change the numbers, edit
`DELETE_LIMIT` and `DELETE_WINDOW_HOURS` at the top of
`app/Controllers/Keys.php`.

One thing to know: the cap is per admin username. A compromised admin who
promotes a second account gets a fresh allowance on it. Each round is slow
and every deletion is signed into the archive, so it is traceable, but if you
want a hard ceiling the place to add it is a global counter rather than a
per-name one.

## Balance history for admins

**Admin → Balance History** lists every movement on every account, newest
first, with a filter per kind (top-up, key, adjusted, referral, opening) and
totals across all sellers at the top. Each account name links to that
account's own page.

A seller has no route to it and gets sent back to the dashboard. Their own
page already shows their own ledger and who topped them up, which is the
whole of what concerns them — that scoping lives in the model, so a template
change cannot widen it.

## The sign-in list pages, like the keys list

Both sign-in panels now page on the server. Each has its own **Show
25 / 50 / 100 / 200** control and its own Previous / 1 2 3 … / Next row, so
an admin can keep a short list of their own sign-ins next to a long one of
everyone's. The default is 50.

The state travels in the URL — `sm`/`pm` for your own list, `sa`/`pa` for
everyone's — and every link carries the whole page's state, so paging one
list never resets the other. Bad values are clamped rather than trusted:
`?pm=9999` lands on the last page, `?pm=-4` on the first, and a page size
that is not one of the four offered falls back to 50. That last one is not
cosmetic — taking the number as given would let `?sm=100000` ask the
database for the entire table in one query.

Only one page of rows is ever loaded. The total comes from a separate
`COUNT`, so a seller with 4,000 sign-ins costs the same to render as one
with 40.

## The app connector is not in the build

`app/Controllers/Connect.php` is deliberately **absent** from the zip. It
ships as `Connect.php.new` beside where it would go.

The reason is that you keep your own: the connector is the one file written
against your apps' protocol, and an upgrade that silently replaced it would
break every installed copy in the field. So upload the zip, then put your
own `Connect.php` back — see step 5 of `INSTALL.md`.

Forgetting is the one mistake this install can make quietly: the panel looks
perfectly healthy while `/data/zezr_connector` returns 404 and no app can
check a key. So the dashboard now says so, to admins only:

> **The app connector is missing.**

When you are ready for the new one, rename `Connect.php.new` to
`Connect.php`. Its AES-256-GCM envelope is not compatible with the old
protocol, so the apps have to be updated the same day —
`CONNECTOR-PROTOCOL.md` has the format and a working client.

## `/spark` is readable on the live site today

Checked against `panel.zeromods.id` while writing this:

    /.env                 403      /app/Config/App.php   403
    /vendor/autoload.php  403      /composer.json        403
    /spark                200   <- source disclosure

`spark` is CodeIgniter's CLI entry point. It holds no secrets, so the impact
is small — it confirms the framework and roughly its version — but it should
not be readable, and the `.htaccess` in this build refuses it. I verified
that under Apache 2.4 with the shipped file: `/spark` comes back 403 along
with `/.env`, `/composer.json`, `/env.template`, `/MIGRATION.sql`,
`/app/Config/App.php`, `/vendor/autoload.php`, `/writable/logs/` and
`/tools/genkey.php`, while `/public/assets/css/ember.css` still serves.

That it is 200 today means the `.htaccess` on the server is an older copy
than the one in this build. Re-check the list in step 9.2 of `INSTALL.md`
after you deploy.

## Installing it — `INSTALL.md`

`INSTALL.md` in the zip is the step-by-step, written in Persian and against
what is actually on your server rather than a generic template. It was
written after checking: LiteSpeed behind Cloudflare, everything inside
`public_html` with `index.php` at the root, and `app.baseURL` ending in
`/public`. It covers the backup, the migration, moving the old panel aside
rather than deleting it, the upload, restoring your `Connect.php`, the
`.env`, permissions, the PHP version, a test checklist, and a thirty-second
rollback.

I unpacked the finished zip into an empty directory, pointed a server at it
in exactly that layout and ran the whole panel on PHP 8.5: every page 200,
the pager working, the connector warning showing, and no log lines.

## CodeIgniter is now 4.7.4, not 4.1.5

This is the biggest change in this round, and it is a security change.

4.1.5 was released in November 2021. Since then **sixteen** security
advisories have been published that affect it. Pulled from Packagist's
advisory database rather than memory:

| CVE | Severity | Fixed in | What |
|---|---|---|---|
| CVE-2022-21647 | high | 4.1.6 | Deserialization of untrusted data |
| CVE-2022-21715 | medium | 4.1.8 | XSS in `API\ResponseTrait` |
| CVE-2022-24711 | **critical** | 4.1.9 | Remote CLI command execution |
| CVE-2022-24712 | medium | 4.1.9 | **CSRF protection bypass** |
| CVE-2022-39284 | low | 4.2.7 | Cookie Secure/HttpOnly not set |
| CVE-2022-46170 | high | 4.2.11 | Session handler vulnerability |
| CVE-2022-23556 | high | 4.2.11 | **IP spoofing behind a proxy** |
| CVE-2023-32692 | **critical** | 4.3.5 | RCE in validation placeholders |
| CVE-2023-46240 | high | 4.4.3 | Detailed error page in production |
| CVE-2024-29904 | high | 4.4.7 | Denial of service in the Language class |
| CVE-2025-24013 | medium | 4.5.8 | Header name/value validation |
| CVE-2025-54418 | **critical** | 4.6.2 | ImageMagick command injection |
| CVE-2026-48062 | **critical** | 4.7.2 | Upload extension bypass (`ext_in`) |
| CVE-2026-63220 | medium | 4.7.4 | Spoofable forwarded HTTPS headers |
| CVE-2026-63222 | high | 4.7.4 | Path traversal in `UploadedFile::move()` |
| CVE-2026-63223 | **critical** | 4.7.4 | Upload validation bypass (`is_image`, `mime_in`) |

Not all of them were reachable in this panel — it has no file upload, so the
four upload and ImageMagick ones were never exploitable here. But two of them
sit directly on paths this panel depends on: **CVE-2022-24712** is a bypass of
exactly the CSRF protection every form in the panel relies on, and
**CVE-2022-23556** lets a forged header spoof the client IP, which is what
every rate limiter in this panel counts against.

So the framework is replaced rather than patched. `composer audit` on the
result reports no known advisories.

**This raises the minimum PHP version to 8.2.** CodeIgniter 4.7 will not
start below it. Set the PHP version in hPanel *before* uploading; if you
forget, you get a plain "Your PHP version must be 8.2 or higher" page rather
than a broken panel. It was tested on 8.5 as well.

`tools/patch-php85.php` is gone with the upgrade: it existed to fix a
`Time::createFromTimestamp` clash with PHP 8.4, which 4.7 fixes upstream.

The port was not a leap of faith. Every page was loaded, every write path
exercised (key generation and its debit, deletion and its archive, device
reset, the maintenance switch, a balance edit and the ledger row it writes,
referral creation, registration, the public key check, logout), both
DataTables endpoints called, the connector's AES-256-GCM round-tripped, and
the stolen-cookie test repeated. Table rendering was measured column by
column on both versions at 375px and came out **identical**. Zero log lines.

`vendor/` also went from 2.4 GB to 22 MB, because the development
dependencies are no longer shipped.

## One real hole, found by the upgrade

`admin/api/users` — the DataTables endpoint behind Manage Users — was
**answering sellers**.

The route was declared as a group nested inside the `admin`-filtered group:

    $routes->group('admin', ['filter' => 'admin'], function ($routes) {
        $routes->group('api', function ($routes) {
            $routes->match(['get', 'post'], 'users', 'User::api_get_users');
        });
    });

On 4.1.5 the nested group inherited the filter. On 4.7 it does not — and
`php spark routes` still *lists* `admin` against that route, so the route
table looked correct while the endpoint was open. A seller got HTTP 200 and
a JSON body.

What leaked was bounded: `UserModel::API_getUser()` scopes to
`uplink = <your username>`, so a seller saw only accounts listed under them —
today, none. But it was open, it looked closed, and any future route added
inside that nested group would have been open too.

Fixed twice over: the group is flattened, so there is no inheritance to
reason about, and `api_get_users()` now checks the level itself and returns
403. Both were verified — a seller now gets 403, an admin still gets the data.

Every other admin route was then probed the same way, GET and POST, as a
seller: all refused, and nothing in the database changed.

## The rest of the review

What was checked, and what it found:

- **Output escaping.** Swept every view for unescaped variables. The 27
  `$validation->getError()` echoes were the only ones, and they are not
  reachable as XSS — no built-in message in CodeIgniter interpolates
  `{value}`, and the one rule that echoes `{param}` (`in_list`) is fed from
  game codes, which are `alpha_numeric`. They are escaped now anyway: it
  costs nothing and it removes a class of bug rather than an instance.
- **JavaScript injection.** Every PHP value interpolated into a `<script>`
  block goes through `json_encode` with the hex flags, or an `(int)` cast, or
  is an asset URL. Nothing user-controlled reaches JS unescaped.
- **SQL.** Three raw queries in the whole application; all three are
  parameterised with `?` placeholders. Everything else is Query Builder.
- **Authorization.** Every admin route probed as a seller and signed out,
  GET and POST. One hole (above), now closed.
- **Object references.** `/account/999` shows a seller their own page rather
  than an error, so a valid id and an invalid one are indistinguishable to
  them. `/keys/5` for a key that is not theirs redirects.
- **Rate limiting.** Login, registration, the public key check and the
  connector each have their own counter and their own table.
- **Secrets.** No literal password, key or token anywhere outside `vendor/`.
  The connector key and the encryption key come from `.env`.
- **File protection.** `.htaccess` at the root, plus the stock deny files in
  `app/` and `writable/`.

One thing to know rather than fix: the session fingerprint is an HMAC keyed
on `encryption.key`. Changing that key invalidates every open session at
once, so everybody gets "your session was ended for security" and signs in
again. Harmless, but surprising if you rotate the key and do not expect it.

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

## The app connector — new crypto, and it needs the app rebuilt

`CONNECTOR-PROTOCOL.md` in the zip is the full spec, with a working curl
script and the Android equivalent. The short version:

**AES-256-CBC with a fixed IV became AES-256-GCM with a fresh nonce.** Three
things were wrong and all three were reproduced before being fixed. The IV
never changed, so encrypting the same request twice produced byte-identical
output — anyone watching the wire could tell which requests repeated without
holding the key. Nothing checked integrity, so in a test 16 bytes of a reply
were rewritten to a chosen value **with no key at all** and the old code
parsed it happily. And the key sat in the PHP source, so it travelled with
every copy and every backup.

Now: a 12-byte random nonce per message, a 16-byte GCM tag, a 5-minute
timestamp window, and the client's own nonce echoed in the reply so a
recorded "valid" response cannot be replayed at the app later. Every failure
— tampered, wrong key, stale, malformed — returns the same
`"Decryption failed."`, so nothing tells an attacker which check they missed.

**Set the key in `.env`:**

    php -r 'echo bin2hex(random_bytes(32)), "\n";'
    connect.aesKey = <the 64 hex characters>

Missing or malformed and the connector answers 503 and logs `critical`. It
fails closed deliberately.

**This breaks the current app.** An old client cannot talk to the new server
— that is what the `EG1.` version prefix is for. Rebuild the app with the
matching key and format before you deploy this, or roll both together.

**The limit worth stating plainly:** the key still ships inside the APK.
Anyone who unpacks it can read and forge anything on this endpoint. What
this buys you is protection against interception, tampering and replay on
the wire. Only signing responses with a private key the app never holds
would stop someone who owns the client — a bigger change, worth planning.

Also fixed there: **MLBB and FFM keys could never connect.** A game code
missing from `$gameVersions` falls through to "Please Update Your App", and
both were missing while having live keys — 10 of them.

## Not done — needs a decision from you
- **CSP.** Views contain inline `<script>`. Enabling CSP means adding a
  nonce to each block first, or the panel stops working.
- **Legacy password hashes.** Rehash-on-login is already in place, but
  idle accounts keep the old scheme. Find them with:

      SELECT id_users, username FROM users WHERE password LIKE '$2y$08$%';

  then force a reset on those, and delete `create_password()`.
- **Connect.php** was empty in the archive. It is exempt from CSRF *and*
  auth, so it is the most exposed route in the app and still unreviewed.
