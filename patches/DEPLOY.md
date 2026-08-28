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

## A missing `.env` used to fail in the worst possible way

Reported from the live install and reproduced here exactly: the panel came
up, rendered, and then every link went to `https://localhost:8080/…`. The
design was gone too.

One cause for all of it. Without a readable `.env`, CodeIgniter falls back to
`Config/App.php`, whose stock `baseURL` is `http://localhost:8080/`. Pages
still render — so it looks like the panel works — but every URL it builds,
including every stylesheet, points at localhost.
`forceGlobalSecureRequests` then upgrades the scheme, which is why the
redirect was `https://localhost`. Nothing on screen mentioned `.env`.

`setup-check.php` now runs in both front controllers before the framework
boots, and answers instead of failing silently. Four states, each reproduced
and tested rather than reasoned about:

| State | What it says |
|---|---|
| no `.env` | how to make one from `env.template`, plus the Show-hidden-files hint File Manager needs |
| `.env` unreadable | set it to 644 |
| `.env` never edited, or `baseURL` still localhost | the exact line to write |
| `.env` inside `public/` instead of the root | "not found" — **and it prints the absolute path it looked at** |
| a file called `env.`, `env`, `.env.txt`, `ENV`… | names the file you actually have, and what to rename it to |

That last row was added after watching it happen: the file on the live
server was named **`env.`** — the dot on the end instead of the front. It sat
in the file listing looking correct, and CodeIgniter, which reads the name
`.env` and nothing else, never saw it. Telling someone "no .env found" while
they are looking straight at their env file is useless, so the check now
scans for the near miss and names it back to them. Six wrong spellings
tested: `env.`, `env`, `.env.txt`, `env.txt`, `ENV`, `.ENV`.

That path line is the other one that matters: it tells you where the file has to
be, on your server, in your layout. The check costs one `file_exists()` once
the panel is configured, and is invisible from then on.

It lives at the project root, not in `app/`, so it is in the zip but not in
`app.patch`.

## Two ways a front-end change shipped as a no-op

Both reported from the live panel, both silent, both now checked by
`tools/check-assets.sh` which exits non-zero on either.

**Ten icons were blank.** The Bootstrap Icons font is subsetted to the glyphs
the views actually use — 6 KB instead of 120 KB — and `subset-icons.sh` has to
be re-run when a new `bi-` class appears. It was not. So every icon added over
the last several rounds rendered as empty space on the live server:
`bi-archive`, `bi-cone-striped`, `bi-funnel`, `bi-laptop`, `bi-play-circle`,
`bi-plug`, `bi-plug-fill`, `bi-shield-exclamation`, `bi-sliders`,
`bi-toggles`. Deleted Keys and Maintenance in the nav had no icon; the
Deleted button on the keys list, which is icon-only on a phone, was a
completely empty box — no icon because the glyph was missing, no label
because `.em-blur-label` hides it below 700px.

**And the compressed twins were a day stale.** `.htaccess` serves
`foo.css.br` in preference to `foo.css` whenever the browser accepts brotli,
which every browser does. `ember.css` and the icon CSS had `.br`/`.gz` copies
from the previous day, so *every stylesheet change since then reached nobody* —
the pager, the maintenance banner, the DataTables wrapping — while looking
perfectly correct on disk and in git. Regenerated with `precompress.sh`, and
verified by decompressing the `.br` and checking the new icon classes are
inside it.

The check catches both, and I proved it bites by breaking each on purpose.

## The panel header overlapped on a phone

`.em-panel-h h2` carried `flex: 1`, which is `1 1 0%` — it tells the row the
heading is happy at zero width. On the Games page at 393px the heading got
70px while the pills beside it kept 268px, and the icon, the code pill and
the game name, none of which shrink below their content, spilled out and drew
on top of one another.

`flex: 1 1 auto` makes the heading ask for the width it needs, so when it and
the buttons cannot share a line the row wraps instead. The heading also wraps
internally now, so a long game name goes under the code pill rather than
through it. It is a shared rule, so every panel header on every page gets it.

## The database user needs four privileges, not twenty

Asked what to tick in hPanel, so I measured it rather than guessed: made a
MySQL user with `SELECT, INSERT, UPDATE, DELETE` on the panel database and
nothing else, pointed the panel at it, and ran everything.

Every page 200. Both DataTables endpoints. The connector's AES round trip.
Key generation and its balance debit, key deletion and its archive row,
device reset, the maintenance switch, a balance edit and the ledger row it
writes, referral creation, registration, the public key check, login and
logout. **Zero log lines.**

So untick everything except those four. Specifically not needed: `DROP`,
`CREATE`, `ALTER`, `INDEX`, `LOCK TABLES`, `CREATE TEMPORARY TABLES`,
`CREATE ROUTINE`, `ALTER ROUTINE`, `EXECUTE`, `TRIGGER`, `EVENT`,
`CREATE VIEW`, `SHOW VIEW`, `REFERENCES`. The panel's row locking is
`SELECT … FOR UPDATE` inside a transaction, which needs no privilege of its
own — that is why `LOCK TABLES` can go.

`MIGRATION.sql` is the exception, and only while it runs. Confirmed by
running it under the four-privilege user and watching it fail on the first
`CREATE TABLE`, then adding `CREATE, ALTER, INDEX, REFERENCES` and watching
it finish. So: tick everything, run the migration once, untick back down to
the four. Verified the panel still runs afterwards.

## The key edit page had never been redesigned

It was the one page the redesign missed — still raw Bootstrap `card`,
`card-header` and `btn-outline-light` while every other page had moved to the
panel's own components. That is why its two header buttons sat oddly: they
were `btn-outline-light`, which ember.css does not style, on a dark ground.

They were also icon-only with no label and no title — a person-plus and a
pair of people, meaning "Generate" and "All keys", which neither icon says.
The page now has a proper header with the key as its title, the status as a
pill, and those two actions as labelled buttons where every other page keeps
them.

While converting it: `theme.css` forced `.maxDev { background: accent
!important }`, written when the device counter was a Bootstrap badge. As an
`em-pill` it fought the pill's own styling, so the dead rule is gone.

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

## Saving a key with an empty-looking device list threw "Whoops!"

Clearing the Devices box on the key edit page and leaving a single space in
it lost the save and showed the error screen. Reproduced against the running
panel, HTTP 500 on the POST, the row unchanged:

    input=" "        http=500  devices unchanged
    input="..."      http=500  devices unchanged
    input="AAA-111\nBBB-222"  http=303  devices=AAA-111,BBB-222

`setDevice()` in `app/Helpers/nata_helper.php` treated any non-empty string as
a device list. A space is non-empty, but nothing survives the `[^A-Za-z0-9-]`
strip, so the `foreach` never ran, `$result` was never assigned, and PHP 8
threw on `array_unique(null)`:

    array_unique(): Argument #1 ($array) must be of type array, null given

Not just a space — any text with no id characters in it (`...`, `@@@`) did the
same. The function is now written so the empty result is a normal outcome: it
returns `NULL`, which is exactly what an empty box already meant — the key is
released and devices may re-bind. Same run after the fix:

    " "  -> NULL       "..." -> NULL      "" -> NULL
    "AAA-111\nBBB-222" -> AAA-111,BBB-222 (unchanged)
    "a\nb\nc" with max 2 -> a,b           (the cap still holds)

Zero log lines during the whole run.

## "Turn it off" in the banner now actually turns it off

The maintenance banner sits on every page while the switch is on. Its button
was a **link to the maintenance screen**, not a switch — pressing it took you
to the page and left the banner exactly where it was, which reads as a button
that does not work.

It is a one-click POST now: `maintenance=0`, with a CSRF token, and a `back`
field holding the page you pressed it on so you land back there with the
banner gone. Measured in a real browser at 1440px and at 393px:

    banner before click = 1 | after click = 0 | url = /keys      (desktop)
    banner before click = 1 | after click = 0 | url = /keys      (phone)

Two things guard it:

- `save()` no longer overwrites the message when the form carries no message
  field, so the one-click switch cannot wipe the text you typed on the page.
  Verified: message survived an on/off cycle from the banner.
- the `back` field is a form value, so it is only ever allowed to be a
  relative path of ours. Everything else falls back to the maintenance page:

      //evil.com/x  https://evil.com  javascript:alert(1)  /\evil.com  keys  ""
      -> all six redirected to /admin/maintenance

A seller still cannot reach it. With a valid CSRF token and a real seller
session, the POST redirected to the dashboard with "Access Denied!" and the
setting stayed `0`.

## The "Is your connector wired to it?" panel is gone

Removed from `app/Views/Admin/maintenance.php`, along with the controller
method that read `Connect.php` to produce it. The maintenance screen is now
just the switch and the message.

The two lines it used to print are in `CONNECTOR-PROTOCOL.md` instead, since
the connector is your own file and you edit it by hand.

## Maintenance and a key's clock — and how the downtime is returned

Asked and answered by measurement. On its own, maintenance does **not** pause
a key's clock — so the panel now gives the downtime back when you turn it off
(next section). Here is why it has to.

`expired_date` is an absolute timestamp written **once**, at the key's first
successful check: `now + duration hours`. Nothing else in the codebase writes
it except the admin edit form. So the countdown is wall-clock, and maintenance
does not touch it. A 24-hour key activated at noon dies at noon tomorrow
whether the server answered anything in between or not.

Measured on the running panel with a real connector call:

    activate                  -> expired_date 2026-08-29 00:57:13, 99000s left
    maintenance ON            -> app is refused, "Update in progress"
    ...20 seconds of maintenance...
                              -> 98967s left   (20 seconds gone)
    maintenance OFF           -> app served again, expired_date unchanged

A key that has **never** been activated loses nothing: the connector refuses
before the activation branch, so `expired_date` stays NULL and its clock has
not started. Verified — a fresh key checked during maintenance still had
`expired_date = NULL` afterwards.

To compensate for the downtime the panel records when maintenance started
and, on turning it off, adds the elapsed time to every key whose
`expired_date` is still in the future. That is exactly what the next section
describes — and it is now in the build.

## Maintenance now gives the downtime back to the keys

Follows directly from the finding above that maintenance does not pause a
key's clock. It could not, before — the expiry is absolute — so instead the
downtime is returned when the switch goes off.

Turning maintenance ON stamps the moment it started. Turning it OFF, before
the apps are served again, shifts every key that was still running forward by
exactly how long maintenance lasted, so each one is left with the same time
remaining it had when the switch was thrown. Two kinds of key are left alone,
on purpose:

- keys that had already expired before maintenance began — they were not
  running, so there is nothing to give back;
- keys nobody has activated yet (`expired_date IS NULL`) — their clock has
  not started.

Measured on the running panel:

    before ON     ACTIVE-2H 7200s left   EXPIRED -3600s   NEVER-RUN NULL
    ON, wait 15s
    OFF           ACTIVE-2H 7200s left   EXPIRED -3600s   NEVER-RUN NULL
                  (+15s added to the active key, the other two untouched)

Guards, each tested:

- **Only on the real transition.** Saving the page again while it is already
  on does not restart the clock; saving off while already off credits
  nothing. Verified the `since` stamp was unchanged across a second "on"
  save.
- **Concurrency.** The stamp is read `FOR UPDATE` and cleared inside the same
  transaction as the key update, so two admins turning it off at the same
  instant cannot both credit the same downtime. Two simultaneous turn-offs
  added the downtime exactly once (100s, not 200s).
- **A runaway is capped at 30 days.** A switch left on for a month, or a
  server clock that jumps, cannot push every key years into the future; the
  cap is applied and logged.

The maintenance page shows how long it has been on (what will be returned)
and, after a turn-off, how much went back to how many keys. It is all done in
`SettingModel::creditDowntime()`; nothing else writes `expired_date` except
this and the admin edit form.

## A found hole: the DataTables endpoints put client column names into SQL

Found during this review, fixed, and confirmed by test. Both list endpoints
(`keys/api`, `admin/api/users`) are backed by
`hermawan/codeigniter4-datatables`, which builds `ORDER BY` and `LIKE` from
the request's **column identifiers** — `columns[i][name]` and
`columns[i][data]` — treating them as trusted SQL fragments. They are not
trusted: they come from the query string. A logged-in seller could send

    columns[0][name] = CASE WHEN (SELECT SUBSTRING(password,1,1)
                                    FROM users WHERE id_users=1)='$'
                            THEN id_keys ELSE user_key END

and read the admin's password hash out one character at a time by watching
how the rows re-ordered — a blind SQL injection, available to any seller, and
the row-scoping (`WHERE registrator = …`) did nothing to stop it because the
injection was in the ORDER BY, not the data.

The same library also read `columns[i][search][value]` and `search[value]`
without checking they were there, so a hand-written or truncated request
threw `Undefined array key` and the endpoint answered **500** with a stack
trace instead of an empty table.

Both are fixed in one place: `BaseController::normalizeDataTableRequest()`,
called at the top of each endpoint with the list of columns that endpoint
actually has. It

- rebuilds the request into the exact shape the library expects, so a
  missing key is filled in rather than fatal;
- **whitelists** every column identifier against that list — anything else is
  replaced with a safe default and made unsearchable, so no client string
  ever reaches ORDER BY or LIKE;
- caps the page length (500), the column count (32) and the sort-term count
  (4), so `length=999999` cannot ask for the whole table.

Verified after the fix: every injected `CASE WHEN` payload returns the
**same** row order (the injection is inert), real search and sort still work,
malformed and scalar-shaped requests answer 200 with an empty or sane result,
and the row-scoping still holds — a seller's `keys/api` returns zero rows
belonging to anyone else. Zero log lines across the whole run.

## A full privilege sweep: what a seller cannot do

Re-run this round as a matrix, admin vs. seller, against the running panel:

    admin-only PAGES as a seller (GET)     -> all redirect to dashboard
    admin/api/users as a seller            -> 403 JSON, no user list
    POST create-referral / games save+del  -> nothing written, saldo unchanged
    POST admin/edit + manage-users         -> level stays 2, saldo frozen
    POST maintenance/save                  -> setting stays 0
    another seller's key: view/edit/reset  -> refused, key untouched
    keys/delete (admin only)               -> 403, key count unchanged
    own key edit                           -> only status moves; game, user_key,
                                              duration, devices, registrator,
                                              expiry all frozen
    keys/api                               -> only the seller's own rows
    account/1 (admin's balance)            -> admin's figures not shown

Also confirmed still holding from earlier rounds: the atomic balance debit
(two concurrent buys on a 1-unit balance produce one key, not two), the
device-slot lock (five devices racing a 2-device key bind exactly two), the
login lockout (correct password refused while the IP is blocked), and the
session fingerprint (a stolen cookie replayed from another browser is
rejected).

## An admin can now open any seller's keys from their account page

The account page for a seller (`/account/{id}`) gained a **View keys** button,
and its "Keys issued" figure is now a link. Both open the keys list filtered
to that seller: `/keys?owner=<username>`, headed "Keys by <name>" with an
"All keys" way back.

The filter is admin-only and safe by construction:

- `Keys::ownerFilter()` returns null for a seller, so the parameter can never
  widen a seller's own-rows scope. Verified: a seller hitting
  `keys/api?owner=admin` still gets only their own rows.
- the username is validated against a real user; an unknown or injected value
  falls back to the whole table rather than erroring, and the query is
  parameterized, so `owner=sara' OR '1'='1` is treated as a (non-existent)
  name, not SQL.

Tested on the running panel: `owner=sara` returned sara's 7 keys and nobody
else's; `owner=alireza` returned alireza's 13; the page and the AJAX both stay
scoped through paging and search.

## The maintenance credit could revive already-dead keys — fixed

A real bug in the downtime credit from the last round, found on review before
it could bite. The credit query decided which keys were "still running at the
start of maintenance" with

    WHERE expired_date > FROM_UNIXTIME(<since>)

`expired_date` is written by the app in its **own** timezone
(`Config\App::$appTimezone`, `Asia/Tehran`). `FROM_UNIXTIME()` renders in
**MySQL's** session timezone, which on the live server (Hostinger) is UTC. The
two are 3.5 hours apart, so the boundary was off by the offset — and a key
that had already expired up to 3.5 hours *before* maintenance began would be
counted as "still running" and pushed into the future. A dead key coming back
to life is exactly the "adds time to the wrong keys" failure to avoid.

Proven on the panel: a key that died 2 hours before maintenance, in Tehran
wall-clock, compared as `00:34 > 23:04` against the UTC boundary and was
wrongly credited.

The fix builds the boundary in PHP as an app-timezone wall-clock string, in
the same clock `expired_date` is stored in, and compares against that. `DATE_ADD`
works on the stored string and is timezone-agnostic, so the amount added was
never wrong — only which rows were touched.

Re-tested with keys planted at exact boundaries (Tehran clock):

    died 2h before maintenance   -> untouched   (was: revived)
    died 10s before maintenance  -> untouched   (was: revived)
    1h of life left              -> +downtime   exactly
    expired 5s into maintenance  -> +downtime   (correctly revived)

And the guards still hold, each measured: the credit is applied once per real
on→off transition (saving the page again while already on does not restart the
clock), it is safe under two admins turning it off at the same instant (the
"since" marker is read `FOR UPDATE` and cleared in the same transaction as the
key update — 100s credited once, not 200s), a clock that ran backwards credits
nothing (never a subtraction), and a switch left on for 45 days credits exactly
30 (the cap), not 45. Zero log lines across the whole run.

## Faster on a weak phone and a slow line

A pass aimed at first-paint and parse cost, since that is what a low-end device
on mobile data actually feels.

- **Bootstrap CSS purged to what the panel uses: 232 KB → 42 KB raw** (18% of
  the original), the single biggest render-blocking file on every page. Done
  by scanning every view and script for the classes actually used — including
  the ones DataTables emits at runtime — and dropping only rules for classes
  nothing references; every base, element and `:root` rule is kept untouched.
  Verified by pixel-diffing all 11 pages at desktop and phone before and
  after: every static page is identical, and the two DataTables pages differ
  only where the async table happened to be mid-load in one shot. Total CSS
  the browser parses fell from ~303 KB to ~118 KB raw (~38 KB → ~23 KB over
  the wire after brotli).

- Measured under a throttled profile (4× CPU slowdown, 400 kbps, 400 ms
  latency — a weak phone on a bad line): **first contentful paint on the keys
  page fell from 14.1 s to 5.1 s, and the dashboard from 11.8 s to 5.0 s.**

- **The table search now waits for a pause in typing (`searchDelay: 500`)**
  before it queries the server, so a search on a slow link sends one request
  instead of one per keystroke.

- Two small mobile-smoothness rules: `text-size-adjust: 100%` stops a browser
  reflowing the page a beat after paint by inflating the type, and
  `overscroll-behavior` keeps a flick that reaches the end of the key table
  from dragging the whole page.

Repeat visits were already near-instant — `.htaccess` serves the pre-compressed
`.br`/`.gz` twins and marks assets `immutable` for a year — so this pass is
about the first visit and the parse, which is where a weak device spends its
time.

## A fresh injection and privilege sweep — clean

Re-run end to end this round after the changes, against the running panel:

- **SQL injection**: login (`admin' OR '1'='1` and four more) never
  authenticates; the connector (SQLi in user_key / game / serial through the
  encrypted envelope) treats every payload as a literal and leaves the table
  intact; the new `owner` filter is parameterized and validated. No SQL errors
  in the log on any attempt.
- **DataTables** (the hole closed last round): every injected column-name
  payload is still inert — identical row order — and malformed requests answer
  200 with a sane result, not a 500.
- **XSS**: payloads planted directly in the database (a user's fullname, a
  key string, the maintenance message) fire nothing — the DataTables `esc()`
  render helper and the server-side `esc()` on the banner neutralize all of
  them. Confirmed in a real browser: no dialog, no script execution on
  manage-users, the keys list, or the dashboard banner.
- **CSRF**: a POST with a missing or wrong token never reaches an
  authenticated action; only the correct token does.
- **Privilege**: the full admin-vs-seller matrix from last round still holds —
  a seller cannot reach or drive any admin action, edit another seller's key,
  delete a key, or widen their key list with `owner=`.

## Keys now belong to a user id, not a re-usable username

The bug: a key stored its owner as the `registrator` **username**, and MySQL
matches that case-insensitively. So if you added a seller "AliAli", they made
keys, you deleted them, and later anyone re-registered "AliAli", the new
account saw every key the old one had made — the panel could not tell the two
apart because it only had the name to go on.

Keys now carry `registrator_id`, the owner's immutable id. A username can be
deleted and taken again; an auto-increment id never is, so the new holder gets
a new id and never inherits the old one's keys. Every place that scoped a
seller to "their own" keys — the list, the dashboard counters, the per-game
and per-day breakdowns, the key-edit ownership check, and the admin's
per-seller view — now scopes by id. The `registrator` username is still stored
(the connector and the display use it) but is never trusted for "whose key is
this".

The migration (section 10 of `MIGRATION.sql`) adds the column and backfills
every existing key to the current holder of its username, so no seller loses
sight of a key they own. Reproduced and fixed on the running panel: an
"AliAli" (id 11) with two keys, deleted, then re-registered as a new account
(id 12) — the new account's key list, API and dashboard all show **zero** of
the old keys, while every other seller still sees exactly their own.

For a name you have *already* re-used, there is an optional one-line statement
(also in section 10) that detaches the previous holder's keys from the new
account, identifying them as the keys created before that account existed.

## Per-game device limits, set by the admin

New in Admin → Games, per game:

- **Min / Max devices** — the range a seller may choose on the Max Devices
  field when generating a key for that game. Set Min to 10 and no seller can
  make a 1-device key; set Max to 50 and none can make a 100-device one.
- **Lock the device count** — when on, a seller's device count is fixed at
  Min and the field is read-only for them, so nobody can hand themselves extra
  device slots. You are never bound by either — an admin can still set any
  count.

Enforced on the server, never trusting the number the form sends: measured on
the running panel, a seller on a 10–50 game was refused at 5 and at 100 and
accepted at 20; on a locked-at-5 game a posted 999 was stored as 5; an admin
was accepted at 3 and at 99 on those same games. The generate form reflects it
live — picking a game sets the field's range, clamps the value, and shows
"10 to 50 devices" or "Fixed at 5 for this game."

Per-device pricing already existed and is unchanged: each duration tier's price
is per device, so a $0.50 tier on a 10-device key is $5.00, and a $0.00 tier is
free. Set it in the tier editor.

## The key/users list no longer eats a vertical swipe

A regression from last round's performance pass. `overscroll-behavior: contain`
was put on the horizontally-scrolling table wrapper to stop a sideways flick
from rubber-banding the page — but the wrapper has no vertical scroll of its
own, so `contain` swallowed every downward swipe that started on the table, and
the page would only scroll if you touched beside it.

Now only the sideways overscroll is contained (`overscroll-behavior-x`), and
`touch-action: pan-x pan-y` lets the browser route a horizontal drag to the
table and a vertical drag to the page. iOS bounce is left on. Confirmed in a
touch context: the wrapper resolves to `overscroll-behavior-x: contain`,
`-y: auto`, `touch-action: pan-x pan-y`, and the body no longer disables
vertical overscroll.

## Not done — needs a decision from you
- **CSP.** Views contain inline `<script>`. Enabling CSP means adding a
  nonce to each block first, or the panel stops working.
- **Legacy password hashes.** Rehash-on-login is already in place, but
  idle accounts keep the old scheme. Find them with:

      SELECT id_users, username FROM users WHERE password LIKE '$2y$08$%';

  then force a reset on those, and delete `create_password()`.
- **Connect.php** was empty in the archive. It is exempt from CSRF *and*
  auth, so it is the most exposed route in the app and still unreviewed.
