# افزودنِ ConnectV2 به پنل به‌عنوان لینکِ جدید

قوی‌ترین API لاگینِ اپلیکیشن. کنارِ `Connect.php` قدیمیِ تو اجرا می‌شود؛ آن دست‌نخورده می‌ماند.

## این نسخه چه دارد
- **AES-256-GCM** (namespace `EG2`) — شنودگر فقط نویز می‌بیند.
- **پاسخ‌های امضاشده با Ed25519** — سرورِ جعلی/کلاینتِ کلون نمی‌تواند فعال‌سازی جعل کند.
- **نام فیلدهای واقعی** روی سیم (`game`,`user_key`,...)، ولی امنیت به مخفی‌بودنِ نام‌ها نیست — به AES و امضای Ed25519 است.
- **کانفیگِ رمزشده‌ی هر سشن (`kx`/`rc`)** — اپ بدونِ دیتای سرور کار نمی‌کند.
- **هانی‌پات** (کلید طعمه + endpoint طعمه) و **revokeِ سرعتی** (یک کلید روی صدها IP → خودکار مسموم/بن).

مسیرِ عمومی: `POST /data/zezr_connector_v2`

---

## گام ۱ — کلیدِ امضا (روی گوشی، نه سرور)
در ترموکس:
```
pkg install php    &&  php genkey-sign.php
# یا:  pkg install python; pip install pynacl; python genkey.py
```
دو خط می‌دهد:
- `connect.signKeyV2 = ...` → فقط در `.env` سرور (مخفی).
- `SIGN_PUBKEY_B64 = "..."` → در کلاینت C++.

## گام ۲ — فایلِ کنترلر
`ConnectV2.php.new` را به `ConnectV2.php` تغییرِ نام بده و بگذار در:
```
app/Controllers/ConnectV2.php
```
داخلش این‌ها را پر کن (همان مقادیرِ `Connect.php` خودت):
```php
$this->staticWords = "...";
$this->Public_Key  = "...";
$this->setAccess   = "...";
// و رشته‌های نسخه‌ی هر بازی
```

## گام ۳ — لینکِ جدید در Routes
`app/Config/Routes.php` — داخلِ گروهِ `data`:
```php
$routes->group('data', static function ($routes) {
    $routes->match(['get','post'], 'zezr_connector',    'Connect::index');     // v1 تو (نگه‌دار)
    $routes->match(['get','post'], 'zezr_connector_v2', 'ConnectV2::index');    // ← لینکِ جدید
    $routes->match(['get','post'], 'zezr_activate',      'ConnectV2::decoy');    // ← endpoint طعمه
});
```

## گام ۴ — استثنا از CSRF و Auth
`app/Config/Filters.php` — به `except`ِ **هر دو** فیلترِ `csrf` و `auth` اضافه کن:
```php
'csrf' => ['except' => ['data/zezr_connector','data/zezr_connector_v2','data/zezr_activate','download','download/*']],
'auth' => ['except' => ['/','login','login/2fa','register','check',
                        'data/zezr_connector','data/zezr_connector_v2','data/zezr_activate','download','download/*']],
```

## گام ۵ — کلیدها در `.env`
```
connect.signKeyV2 = <خطِ گوشی>
# اختیاری:
# connect.aesKeyV2     = <۶۴ هگز>   (نگذاری → از connect.aesKey)
# connect.payloadKeyV2 = <هر متن>   (نگذاری → از aesKey مشتق می‌شود)
```

## گام ۶ — جدول
این نسخه **بدونِ هانی‌پات** است و فقط به جدولِ `connect_ratelimit` نیاز دارد (که از قبل در دیتابیست هست — همان که Connect.php استفاده می‌کند). هیچ جدول جدیدی لازم نیست.

---

## تستِ سریع
از سمتِ سرور، این باید **بدونِ لاگین** جواب بدهد (چون در `except` است) ولی چون UA و بدنه‌ی درست ندارد به `/` ری‌دایرکت می‌شود — یعنی روت فعال است:
```
curl -i https://panel.zeromods.id/data/zezr_connector_v2
```
تستِ واقعی را کلاینت C++ انجام می‌دهد (زیر).

## کلاینت
`login_client.cpp` — تابعِ لاگین به سبکِ خودت (`doLogin`)، با فیلدهای ساده و
همان هندشیکِ token، ولی با **AES-GCM + امضای Ed25519 + cnonce**. ثابت‌های بالا را پر کن:
```cpp
AES_KEY_HEX      = "..."   // = connect.aesKeyV2 (یا connect.aesKey)
PUBLIC_KEY       = "..."   // = $Public_Key
STATIC_WORDS     = "..."   // = $staticWords
ACCESS           = "..."   // = $setAccess
ENDPOINT         = "https://panel.zeromods.id/data/zezr_connector_v2"
SIGN_PUBKEY_B64  = "..."   // کلید عمومیِ گوشی
PINNED_PUBKEY    = "sha256//..."   // اختیاری: همان cert-pinningِ قبلی‌ات
```
- `deviceSerial()` را به `getSystemProperty("ro.serialno")+...` خودت وصل کن (نمونه در فایل کامنت شده).
- UA همان `EagleA/1.2` است (مثل کلاینت قبلی‌ات).

بیلدِ تست روی کامپیوتر:
```
g++ -std=c++17 -DLOGIN_DEMO login_client.cpp -o login -lcurl -lcrypto -I./third_party
./login CODM "1.2.3:BUILDID" USER_KEY
# انتظار:  [+] Successfully Logged In
```
(`third_party/nlohmann/json.hpp` لازم است — github.com/nlohmann/json)

**بی‌اعتمادیِ دوطرفه (تست‌شده):** لاگینِ درست ✅ ؛ سرورِ جعلی/کلید امضای غلط → رد ✅ ؛
replay (cnonce غلط) → رد ✅ ؛ access غلط → رد ✅.

### ضدتحلیل (در `login_client.cpp` + `obf.h`)
- **نامِ فیلدها واقعی است (هم کلاینت هم سرور):** روی سیم نام‌های درست می‌روند
  (`game`,`app_ver`,`user_key`,`serial`,`public`,`ts`,`cnonce` و پاسخ
  `status`,`reason`,`data`,`id_key`,`token`,`salt`,`rng`,`expired`,`access`).
  سرور همین‌ها را می‌خواند (ثابت‌های `F_*`/`R_*` در ConnectV2). امنیت به مخفی‌بودنِ
  این نام‌ها **نیست** — بدنه با AES-256-GCM مهر شده و پاسخ با Ed25519 امضا شده، پس حتی
  وقتی مهاجم این نام‌ها را بخواند نمی‌تواند درخواستِ معتبر یا پاسخِ «لاگین‌شده» جعل کند.
- **بدون رشته‌ی واقعی در باینری:** همه‌ی رشته‌های حساس (از جمله همین نام‌ها) با `OBF("...")`
  هستند — در باینری بایتِ آشغال، زمانِ اجرا رمزگشایی. **تست‌شده:** `strings`/گرپِ باینری هیچ‌کدام
  از `EagleA/1.2`، `user_key`، `cnonce`، `app_ver`، `zezr_connector`، `YOUR_STATIC_WORDS` را نشان نمی‌دهد
  (با g++ و clang). (تنها رشته‌های عمومیِ کتابخانه مثل `unknown token` از nlohmann/json می‌مانند
  که هیچ ربطی به پروتکل تو ندارند.)
- **بدون `bool`/`if` قابل‌پچ:** تصمیمِ لاگین یک بولین نیست. همه‌ی تأییدها (status، امضا،
  cnonce، زمان، token، access) بدونِ شاخه در یک **تجمیع‌گر** جمع می‌شوند که فقط وقتی
  همه درست‌اند صفر است، و از آن یک **کلیدِ ۳۲ بایتیِ سشن** (`out.session`) مشتق می‌شود.
  **اپت را از روی `out.session` اجرا کن، نه از روی مقدارِ بازگشتی/پیام.** اگر کرکر پیام/بولین
  را «موفق» کند، `out.session` **غلط** می‌ماند (تست: کلیدِ سشنِ لاگینِ ناموفق کاملاً با
  موفق فرق دارد) و اپ می‌شکند.

مثال استفاده در اپ:
```cpp
LoginResult r; std::string msg;
doLogin(userKey, "CODM", version, r, msg);
// درست: قابلیت را با r.session باز کن (مثلاً کلیدِ رمزگشاییِ منابع). غلط: if(ok){...}
useFeature(r.session);   // روی سشنِ غلط، کار نمی‌کند
```

`hardening.c` و `guard.c` را هم به بیلدِ نهایی اضافه کن (لایه‌ی ضدتحلیل).
اندروید NDK: همین سورس‌ها کامپایل می‌شوند؛ به libcurl + BoringSSL لینک کن. جاوا لازم نیست.
