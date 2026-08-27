# نصب پنل — صفر تا صد

راهنمای جایگزینی پنل فعلی روی `panel.zeromods.id` با نسخهٔ جدید،
از داخل hPanel، بدون SSH.

این راهنما بر اساس **همان چیزی نوشته شده که الان روی سرور توست** — نه
حدس. قبل از نوشتنش این‌ها بررسی شد:

| چیزی که چک شد | نتیجه |
|---|---|
| سرور | LiteSpeed (Hostinger) |
| جلوی سایت | Cloudflare — `server: cloudflare` |
| ساختار پوشه‌ها | همه‌چیز داخل `public_html` است، `index.php` در ریشه |
| `app.baseURL` فعلی | `https://panel.zeromods.id/public/` — **با `/public/` در آخر** |
| `.env` از بیرون | ✅ ۴۰۳ (بسته است) |
| `/spark` از بیرون | ❌ **۲۰۰ — سورس فایل خوانده می‌شود.** `.htaccess` جدید این را می‌بندد |
| CodeIgniter فعلی | 4.1.5 (سال ۲۰۲۱) — **۱۶ آسیب‌پذیری منتشرشده** |
| CodeIgniter این بسته | **4.7.4** — همهٔ آن ۱۶ مورد بسته شده |

> ⏱ **زمان لازم:** حدود ۳۰ تا ۴۵ دقیقه.
> بهترین وقت: ساعتی که کمترین فروش را داری.

---

## ۰ · چیزهایی که باید آماده داشته باشی

- دسترسی به **hPanel** (File Manager و phpMyAdmin)
- فایل **`zeropanel-2026-08-27.zip`**
- فایل **`Connect.php`** خودت — همانی که الان کار می‌کند
- ۱۵ دقیقه که کسی وسط کار سراغت نیاید

> ⚠️ **نسخهٔ PHP باید ۸.۲ یا بالاتر باشد — این بار اجباری است.**
> این نسخه روی CodeIgniter 4.7.4 است (قبلاً 4.1.5 بود) و خودِ فریم‌ورک
> زیر ۸.۲ بالا نمی‌آید. اگر PHP سرورت پایین‌تر باشد، به‌جای پنل این پیام
> را می‌بینی: *«Your PHP version must be 8.2 or higher»*.
>
> پیشنهادم **۸.۳** است. روی ۸.۵ هم کامل تست شده.
> **قبل از مرحلهٔ ۴ برو سراغ مرحلهٔ ۸ و نسخه را عوض کن.**

---

## ۱ · بک‌آپ کامل — این مرحله را رد نکن

اگر چیزی اشتباه پیش برود، این تنها راه برگشت است.

### ۱.۱ بک‌آپ دیتابیس

1. hPanel → **Databases** → **phpMyAdmin** → روی دیتابیس پنل کلیک کن
2. تب **Export**
3. **Export method: Custom**
4. پایین صفحه، **Compression: gzipped**
5. **Go** → فایل `.sql.gz` دانلود می‌شود

فایل را جایی نگه دار که گمش نکنی.

### ۱.۲ بک‌آپ فایل‌ها

hPanel → **Files** → **Backups** → **Files backup** → بک‌آپ بگیر و دانلود کن.

یا از File Manager: وارد `public_html` شو، همه را انتخاب کن،
راست‌کلیک → **Compress** → دانلود.

### ۱.۳ فایل `Connect.php` خودت را جدا نگه دار

از مسیر `public_html/app/Controllers/Connect.php` دانلودش کن و
جدا از بک‌آپ، جایی که سریع پیدایش کنی، بگذار.
**در مرحلهٔ ۵ لازمش داری.**

---

## ۲ · اجرای `MIGRATION.sql`

سه جدول جدید ساخته می‌شود. **چهار جدول اصلی تو (`users`، `keys_code`،
`referral_code`، `history`) دست نمی‌خورد** — هیچ ستونی اضافه، حذف یا
تغییرنام نمی‌شود. کلیدهای فعلی‌ات کاملاً امن‌اند.

1. phpMyAdmin → دیتابیس پنل
2. تب **Import**
3. **Choose File** → `MIGRATION.sql` را از داخل زیپ انتخاب کن
4. **Go**

اگر فایل بزرگ بود و Import خطا داد: تب **SQL** را باز کن، محتوای فایل را
کپی و paste کن، بعد **Go**.

### تأیید

تب **SQL** و این را اجرا کن:

```sql
SHOW TABLES;
```

باید این ۱۲ جدول را ببینی:

```
auth_ratelimit     connect_ratelimit   keys_deleted     referral_code
balance_log        game_durations      keys_code        users
check_ratelimit    games               history          login_sessions
```

> اجرای دوبارهٔ این فایل خطرناک نیست — همهٔ دستوراتش
> `CREATE TABLE IF NOT EXISTS` هستند. اگر شک کردی، دوباره اجرا کن.

این مرحله را می‌شود **قبل** از آپلود فایل‌ها انجام داد و پنل قدیمی
هم با این جدول‌های اضافه بدون مشکل کار می‌کند.

---

## ۳ · پنل قدیمی را کنار بگذار — پاکش نکن

**پاک کردن الان بزرگ‌ترین اشتباه ممکن است.** جابه‌جایش کن تا اگر لازم شد
در ۳۰ ثانیه برگردی.

1. File Manager → به پوشهٔ **بالاتر** از `public_html` برو
   (معمولاً `domains/panel.zeromods.id/`)
2. یک پوشهٔ جدید بساز: **`old-panel`**
3. برو داخل `public_html`، **همه‌چیز** را انتخاب کن (Ctrl+A)
4. **Move** → مقصد: `../old-panel`

حالا `public_html` خالی است و پنل قدیمی سالم، ولی از اینترنت
دیده نمی‌شود چون بیرون از پوشهٔ وب است.

> ⚠️ `old-panel` را **داخل** `public_html` نساز. اگر داخلش باشد،
> فایل‌های قدیمی — از جمله `.env` قدیمی — از روی اینترنت قابل دسترسی
> می‌مانند.

---

## ۴ · آپلود فایل‌های جدید

1. File Manager → وارد `public_html` شو (که الان خالی است)
2. **Upload** → `zeropanel-2026-08-27.zip`
3. روی فایل زیپ راست‌کلیک → **Extract**
4. حالا یک پوشهٔ `zeropanel` داری. **برو داخلش، همه را انتخاب کن،
   و به `public_html` منتقل کن (Move).**
5. پوشهٔ خالی `zeropanel` و خود فایل زیپ را پاک کن

### تأیید ساختار

`public_html` باید مستقیماً این‌ها را داشته باشد:

```
public_html/
├── .htaccess
├── index.php
├── app/
├── public/
├── vendor/
├── writable/
├── tools/
├── env.template
├── composer.json
└── MIGRATION.sql
```

اگر `public_html/zeropanel/app/` می‌بینی، یک لایه اضافه است — برگرد
مرحلهٔ ۴.۴.

> فایل‌های مخفی را نمی‌بینی؟ در File Manager از منوی بالا
> **Settings → Show hidden files** را روشن کن. `.htaccess` و `.env`
> هر دو مخفی‌اند.

---

## ۵ · فایل `Connect.php` خودت را برگردان

**این مرحله را فراموش نکن — بدون آن هیچ برنامه‌ای نمی‌تواند کلید چک کند.**

در زیپ عمداً فایل `app/Controllers/Connect.php` نگذاشتم. به‌جایش
`Connect.php.new` هست. دلیلش: تو گفتی می‌خواهی فعلاً روی نسخهٔ خودت
بمانی، و اگر فایل من در زیپ بود، آپلود، نسخهٔ تو را پاک می‌کرد.

1. `Connect.php` خودت را در `public_html/app/Controllers/` آپلود کن
2. تمام

اگر یادت رفت، پنل خودش در داشبورد (فقط برای ادمین) یک هشدار قرمز
نشان می‌دهد:

> **The app connector is missing.**

### یک خط تغییر، برای سوییچ maintenance

پنل حالا یک صفحهٔ **Admin → Maintenance** دارد که با آن می‌توانی جلوی
بالا آمدن برنامه‌ها را بگیری، بدون اینکه به فایلی روی سرور دست بزنی.
برای اینکه `Connect.php` خودت هم آن سوییچ را بخواند، این خط را پیدا کن:

```php
$this->maintenance = false;
```

و به این تغییرش بده:

```php
$this->maintenance = \App\Models\SettingModel::maintenance();
```

اختیاری — تا پیامی که در پنل می‌نویسی به خود برنامه هم برسد: به‌جای
متن ثابت `"Server is under Maintenance."` بگذار
`\App\Models\SettingModel::maintenanceMessage()`.

صفحهٔ Maintenance خودش فایلت را می‌خواند و می‌گوید وصل هست یا نه —
اگر **Not wired up yet** نوشت، یعنی این خط را هنوز عوض نکرده‌ای و
سوییچ کاری نمی‌کند.

اگر این کار را نکنی هیچ‌چیز خراب نمی‌شود؛ فقط سوییچ بی‌اثر می‌ماند.

### وقتی خواستی نسخهٔ جدید را بگذاری

فایل `Connect.php.new` را به `Connect.php` تغییر نام بده. رمزنگاری‌اش
AES-256-GCM است و **با نسخهٔ قدیمی سازگار نیست** — یعنی برنامه‌هایت را
هم باید همان روز آپدیت کنی. جزئیات پروتکل در `CONNECTOR-PROTOCOL.md`.

عجله نکن. نسخهٔ خودت تا هر وقت بخواهی کار می‌کند.

---

## ۶ · ساختن فایل `.env`

1. File Manager → روی `env.template` راست‌کلیک → **Copy**
2. اسم کپی را به **`.env`** تغییر بده (نقطه در اول، بدون پسوند)
3. راست‌کلیک → **Edit** و مقادیر زیر را پر کن

> ⚠️ بخش `/public` در آخر `app.baseURL` حتماً باید باشد. اگر برش داری،
> CSS و JS بالا نمی‌آید و صفحه بی‌ریخت می‌شود. اسلش بعد از `public`
> اختیاری است — هر دو حالت را تست کردم، یکی است.

```ini
CI_ENVIRONMENT = production
app.baseURL = 'https://panel.zeromods.id/public'

app.forceGlobalSecureRequests = true
app.behindCloudflare          = true

database.default.hostname = localhost
database.default.database = YOUR_DB_NAME
database.default.username = YOUR_DB_USER
database.default.password = YOUR_NEW_PASSWORD
database.default.DBDriver = MySQLi
database.default.port     = 3306

encryption.key  = hex2bin:xxxxxxxx...
connect.aesKey  = xxxxxxxx...

app.sessionExpiration = 604800
cookie.expires        = 604800
cookie.secure         = true
```

`encryption.key` و `connect.aesKey` هر کدام ۶۴ کاراکتر hex هستند —
ساختنشان همین پایین.

`env.template` همهٔ این خط‌ها را از قبل دارد — فقط جاهای
`PUT_..._HERE` را پر کن.

مقادیر دیتابیس را از `.env` قدیمی (داخل `old-panel/`) بردار — همان‌ها
درست‌اند، به‌جز رمز که پایین‌تر می‌گویم عوضش کن.

### ساختن دو کلید

هر کدام یک رشتهٔ ۶۴ کاراکتری hex (فقط `0-9` و `a-f`) است.

- اگر hPanel تو **Terminal** دارد (Advanced → Terminal):
  ```bash
  cd ~/domains/panel.zeromods.id/public_html
  php spark key:generate
  php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
  ```
- اگر ندارد: از هر تولیدکنندهٔ رشتهٔ تصادفی ۶۴ کاراکتری hex استفاده کن،
  یا از من بخواه برایت بسازم.

⚠️ `connect.aesKey` باید **دقیقاً همانی باشد که در برنامه‌ات هست**.
اگر با نسخهٔ `Connect.php` خودت کار می‌کنی و کلید داخل خود فایل است،
این خط را می‌توانی خالی بگذاری.

### رمز دیتابیس را عوض کن

رمز فعلی دیتابیس داخل فایلی بود که قبلاً برایم فرستادی. از
hPanel → Databases → Change Password عوضش کن و مقدار جدید را در `.env`
بگذار.

---

## ۷ · مجوز فایل‌ها (Permissions)

در File Manager، راست‌کلیک → **Permissions**:

| مسیر | مقدار |
|---|---|
| `writable/` و همهٔ زیرپوشه‌هایش | **755** (اگر کار نکرد: 775) |
| بقیهٔ پوشه‌ها | 755 |
| فایل‌ها | 644 |
| `.env` | **600** اگر امکانش هست، وگرنه 644 |

`writable/` تنها پوشه‌ای است که پنل داخلش می‌نویسد (لاگ، کش، سشن).
اگر قابل نوشتن نباشد، پنل با خطای ۵۰۰ بالا نمی‌آید.

---

## ۸ · نسخهٔ PHP

hPanel → **Advanced** → **PHP Configuration**

- نسخه: **8.2 یا بالاتر** — اجباری. پیشنهاد: **8.3**
- در تب **PHP extensions** این‌ها باید تیک داشته باشند:
  `intl` · `mbstring` · `mysqlnd` · `curl` · `json` · `openssl`

`intl` و `mbstring` اجباری‌اند — بدون آن‌ها CodeIgniter بالا نمی‌آید.

---

## ۹ · تست — این چک‌لیست را کامل برو

### ۹.۱ صفحات

| آدرس | انتظار |
|---|---|
| `https://panel.zeromods.id/login` | صفحهٔ ورود، با ظاهر جدید |
| ورود با اکانت ادمین | داشبورد، و **یک بار** پیام Welcome |
| رفتن به صفحهٔ دیگر | دیگر Welcome نباید بیاید |
| `/keys` | لیست کلیدها، همان تعداد قبلی |
| `/settings` | «Your sign-ins» با صفحه‌بندی |
| `/admin/maintenance` | سوییچ maintenance — و باید بگوید «Wired up» |
| `/admin/balance-history` | تاریخچهٔ موجودی همه |
| `/admin/deleted-keys` | آرشیو کلیدهای حذف‌شده |
| داشبورد ادمین | **نباید** هشدار قرمز connector بدهد |

### ۹.۲ امنیت — هر کدام باید ۴۰۳ بدهد

این‌ها را در مرورگر باز کن. **همه باید 403 Forbidden بدهند:**

```
https://panel.zeromods.id/.env
https://panel.zeromods.id/spark
https://panel.zeromods.id/composer.json
https://panel.zeromods.id/env.template
https://panel.zeromods.id/MIGRATION.sql
https://panel.zeromods.id/app/Config/App.php
https://panel.zeromods.id/vendor/autoload.php
https://panel.zeromods.id/writable/logs/
https://panel.zeromods.id/tools/genkey.php
```

اگر حتی یکی از این‌ها محتوایی نشان داد، `.htaccess` خوانده نمی‌شود.
**همان‌جا متوقف شو** و خبرم کن — با یک `.htaccess` غیرفعال، همه‌چیز باز است.

> `/spark` روی نسخهٔ فعلی‌ات ۲۰۰ می‌دهد. با `.htaccess` جدید ۴۰۳ می‌شود.
> این یکی از چیزهایی است که همین آپدیت درست می‌کند.

### ۹.۳ برنامه‌های خودت

با یک کلید واقعی، از داخل برنامه‌ات یک بار چک کن. اگر جواب داد،
`Connect.php` درست سر جایش است.

---

## ۱۰ · حذف پنل قدیمی

**فقط بعد از اینکه کل مرحلهٔ ۹ سبز شد.** پیشنهادم: یک هفته صبر کن.

File Manager → پوشهٔ `old-panel` → Delete.

بک‌آپ‌های مرحلهٔ ۱ را نگه دار.

---

## ۱۱ · اگر خراب شد — برگشت در ۳۰ ثانیه

1. File Manager → `public_html` → همه را انتخاب و **Delete**
2. `old-panel` → همه را انتخاب و **Move** به `public_html`
3. تمام — پنل قدیمی برگشت

دیتابیس نیازی به برگشت ندارد: `MIGRATION.sql` فقط جدول اضافه کرده و
پنل قدیمی به آن‌ها کاری ندارد.

---

## مشکلات رایج

**خطای ۵۰۰ روی همهٔ صفحات**
`writable/` قابل نوشتن نیست. مجوزش را 755 (یا 775) کن.
اگر باز هم بود، `writable/logs/` را باز کن و آخرین فایل لاگ را بخوان.

**صفحه بالا می‌آید ولی بدون طرح، همه‌چیز سیاه‌وسفید**
`app.baseURL` غلط است — تقریباً همیشه یعنی `/public` از آخرش افتاده:
```
app.baseURL = 'https://panel.zeromods.id/public'
```

**صفحهٔ سفید کامل**
نسخهٔ PHP زیر ۸.۱ است، یا `intl`/`mbstring` خاموش‌اند. مرحلهٔ ۸.

**«Please login first» بعد از هر ورود**
سشن ذخیره نمی‌شود. `writable/session/` باید وجود داشته باشد و
قابل نوشتن باشد.

**ورود می‌زنم و بلافاصله بیرون می‌اندازد**
`cookie.secure = true` است ولی سایت روی http باز شده. با `https://`
باز کن.

**«Your session was ended for security»**
این محافظت جدید سرقت کوکی است. اگر بی‌دلیل تکرار شد، یعنی
User-Agent مرورگرت وسط کار عوض شده — خبرم کن.

**همهٔ صفحات ۴۰۳ می‌دهند**
`.htaccess` روی MODE A است (فقط از Cloudflare جواب می‌دهد) ولی سایت از
Cloudflare خارج شده. یا دامنه را به Cloudflare برگردان، یا در `.env`:
```ini
app.behindCloudflare = false
```
و در `.htaccess` طبق `DEPLOYMENT-MODES.md` به MODE B سوییچ کن.

**کدهای معرف (referral) قدیمی دوباره قابل استفاده شده‌اند**
نشده — مرحلهٔ ۲ فایل `MIGRATION.sql` همه را می‌بندد. اگر کسی منتظر کد
است، از پنل ادمین کد جدید بساز.

---

## بعد از نصب — دو کار که خوب است انجام دهی

1. **رمز خودت را عوض کن.** رمز فعلی در فایل‌هایی بوده که رد و بدل شده.
2. **رمز دیتابیس را عوض کن**، اگر در مرحلهٔ ۶ انجام ندادی.

---

اگر هر جای این مسیر گیر کردی، بگو کدام مرحله و چه پیامی می‌بینی.
