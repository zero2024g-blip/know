# حالت‌های استقرار پنل — Deployment Modes

پنل دو حالت دارد. هر دو امن‌اند، ولی **از چیزهای متفاوتی محافظت می‌کنند**.
انتخاب اشتباه یعنی یا سایت برای همه بسته می‌شود، یا محدودیت لاگین از کار می‌افتد.

---

## خلاصه‌ی عملی

| | MODE A | MODE B |
|---|---|---|
| جلوی سایت | Cloudflare | مستقیم، بدون CDN |
| پیش‌فرض | ✅ فعال | خاموش |
| IP سرور | مخفی | عمومی |
| TLS کجا تمام می‌شود | لبه‌ی Cloudflare | خود سرور |
| فیلتر DDoS | لبه | فقط سطح اپ |

### تعویض حالت

```bash
./tools/set-mode.sh direct       # رفتن به حالت بدون Cloudflare
./tools/set-mode.sh cloudflare   # برگشت
./tools/set-mode.sh status       # الان کدام حالت فعال است؟
```

اسکریپت `.htaccess` را ویرایش می‌کند و یک `.bak` نگه می‌دارد.
رفت‌وبرگشت تست شده و **بدون تغییر ناخواسته** است.

### ⚠️ دو جا باید با هم بخوانند

بعد از هر تعویض، **حتماً** `.env` را هم عوض کن:

```ini
# MODE A
app.behindCloudflare = true

# MODE B
app.behindCloudflare = false
```

اگر Apache در حالت B باشد ولی `.env` روی `true` بماند، PHP هدر
`CF-Connecting-IP` را باور می‌کند در حالی که هیچ Cloudflare‌ای وجود ندارد —
یعنی هر کسی می‌تواند آن هدر را جعل کند و **محدودیت لاگین کاملاً دور زده
می‌شود**. برای همین مقدار پیش‌فرض در کد `false` است: اگر تنظیم را فراموش
کنی یا اشتباه بنویسی، به حالت **امن** می‌افتد نه حالت خطرناک.

---

## در MODE B چه چیزی را از دست می‌دهی و جایگزینش چیست

| از دست می‌رود | جایگزین در همین کد |
|---|---|
| مخفی‌بودن IP سرور | ندارد — IP عمومی می‌شود. فایروال سرور را سفت کن |
| فیلتر DDoS لبه | `LimitRequestBody`، فیلتر متد، محدودیت لاگین روی دیتابیس |
| Bot Fight Mode | honeypot که از قبل فعال است |
| کش لبه | فشرده‌سازی + کش یک‌ساله‌ی asset‌ها در `.htaccess` |
| TLS رایگان لبه | باید خودت گواهی نصب کنی (Let's Encrypt) |

**نکته‌ی مهم:** در MODE B هدر `X-Forwarded-Proto` دیگر قابل اعتماد نیست،
چون هیچ پراکسی‌ای جلوی سرور نیست و هر کسی می‌تواند بفرستدش. برای همین
اسکریپت آن قاعده را خاموش و به‌جایش `%{HTTPS}` را روشن می‌کند.

---

## سرعت روی اینترنت ضعیف

اینها در **هر دو حالت** کار می‌کنند و به Cloudflare وابسته نیستند:

| کار | قبل | بعد |
|---|---|---|
| فونت آیکون | ۸۸.۴ KB (۲۰۵۰ آیکون) | **۴ KB** (۴۱ آیکونی که واقعاً استفاده می‌شود) |
| jQuery | نسخه‌ی dev، ۲۸۲ KB | نسخه‌ی min، ۸۵ KB |
| مبدأ خارجی | ۴ تا (۴ بار DNS + TLS) | **۰** |
| کل حجم روی سیم | ~۲۱۵ KB | **~۱۳۰ KB** |
| بازدید دوم | دوباره دانلود | **~۰ بایت** (کش یک‌ساله) |

### چرا این‌ها روی اینترنت ضعیف بیشتر اثر دارد

روی خط کند، **تعداد رفت‌وبرگشت‌ها** بیشتر از حجم اذیت می‌کند. هر مبدأ
خارجی یعنی یک DNS و یک TLS handshake پیش از اینکه حتی یک بایت مفید
بیاید. چهار مبدأ یعنی چهار بار این هزینه، سریالی. حالا همه‌چیز از خود
دامنه می‌آید روی همان اتصالی که صفحه با آن آمده.

### فایل‌های از پیش فشرده

`tools/precompress.sh` کنار هر CSS/JS یک `.br` و `.gz` می‌سازد.
`.htaccess` اگر مرورگر پشتیبانی کند همان را می‌فرستد — یعنی صفر CPU
موقع درخواست، و brotli با کیفیت ۱۱ که از فشرده‌سازی لحظه‌ای بهتر است.

بعد از هر تغییر در asset‌ها دوباره اجرا کن:

```bash
./tools/precompress.sh
```

### اگر آیکون جدید اضافه کردی

فونت آیکون فقط شامل همان ۴۱ آیکونی است که الان در ویوها هست. اگر
`bi-something-new` اضافه کنی، **مربع خالی** نشان می‌دهد. حل:

```bash
./tools/subset-icons.sh
```

دوباره ویوها را می‌خواند و فونت و CSS را از نو می‌سازد.

---

# English reference

## The two modes

**MODE A — behind Cloudflare (default).** The origin accepts connections
only from Cloudflare's published ranges (`Require ip`), and rejects any
request without a `CF-IPCountry` header. TLS terminates at the edge, so
the real scheme arrives in `X-Forwarded-Proto` and the real client IP in
`CF-Connecting-IP`. PHP verifies the peer is genuinely a Cloudflare node
before honouring that header.

**MODE B — direct.** No CDN. The origin IP is public. `CF-*` headers are
attacker-controlled and are ignored completely. `%{HTTPS}` is the only
scheme signal. Origin access control opens to `Require all granted`.

## Switching

`tools/set-mode.sh` classifies each directive as CF-only or direct-only
and comments the other set. It preserves indentation and is verified
lossless across a full A → B → A round trip. A `.bak` is written each time.

## The one thing that must agree

Apache decides *who can connect*. PHP decides *whose IP to believe*.
They read different config, so they can disagree — and the dangerous
disagreement is Apache in MODE B with PHP still in MODE A. `clientIp()`
defaults to `false` (safe) when `app.behindCloudflare` is missing,
empty, or unparseable, so a forgotten setting degrades safely.

## Performance notes that apply in both modes

- **Compression**: brotli then deflate, with pre-compressed twins served
  when present. Bootstrap CSS goes 227 KB → 22 KB on the wire.
- **Immutable caching**: everything under `/assets/` gets a one-year
  `max-age` plus `immutable`. Change a file's *name* to bust it.
- **`Set-Cookie` stripped from static assets** — a public file should
  never carry a session cookie, and carrying one makes it uncacheable.
- **Authenticated pages are `no-store`** so no proxy, CDN or shared
  browser can hand a signed-in page to the wrong person. This matters
  most in MODE A, where Cloudflare caches aggressively.
- **Icon font subset to 41 glyphs**, regenerate with `tools/subset-icons.sh`.

## Still open

- **CSP** is set in `.htaccess` but contains `'unsafe-inline'`, and
  `app.CSPEnabled` is `false`. The views use inline `<script>`, so
  tightening it means adding nonces first.
- **Legacy password hashes** on accounts that have not logged in since
  the Argon2id migration. Find with
  `SELECT id_users, username FROM users WHERE password LIKE '$2y$08$%';`
- **`Connect.php`** is exempt from both CSRF and auth and was empty in
  the archive — unreviewed, and the most exposed route in the app.
