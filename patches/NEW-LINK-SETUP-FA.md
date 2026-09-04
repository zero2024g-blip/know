# افزودنِ ConnectV2 به پنل به‌عنوان لینکِ جدید

قوی‌ترین API لاگینِ اپلیکیشن. کنارِ `Connect.php` قدیمیِ تو اجرا می‌شود؛ آن دست‌نخورده می‌ماند.

## این نسخه چه دارد
- **AES-256-GCM** (namespace `EG2`) — شنودگر فقط نویز می‌بیند.
- **پاسخ‌های امضاشده با Ed25519** — سرورِ جعلی/کلاینتِ کلون نمی‌تواند فعال‌سازی جعل کند.
- **نام فیلدهای نامفهوم** روی سیم.
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

## گام ۶ — (اختیاری) جدول‌ها
`migration-connector.sql` را در phpMyAdmin اجرا کن (هانی‌پات + revokeِ سرعتی). کانکتور بدونشان هم کار می‌کند.

---

## تستِ سریع
از سمتِ سرور، این باید **بدونِ لاگین** جواب بدهد (چون در `except` است) ولی چون UA و بدنه‌ی درست ندارد به `/` ری‌دایرکت می‌شود — یعنی روت فعال است:
```
curl -i https://panel.zeromods.id/data/zezr_connector_v2
```
تستِ واقعی را کلاینت C++ انجام می‌دهد (زیر).

## کلاینت
`eagle_connector_v2.cpp` — چهار ثابتِ بالا را پر کن:
```cpp
AES_KEY_HEX     = "..."   // = connect.aesKeyV2 (یا connect.aesKey)
PUBLIC_KEY      = "..."   // = $Public_Key
STATIC_WORDS    = "..."   // = $staticWords
ENDPOINT        = "https://panel.zeromods.id/data/zezr_connector_v2"
SIGN_PUBKEY_B64 = "..."   // کلید عمومیِ گوشی
```
بیلدِ تست روی کامپیوتر:
```
g++ -std=c++17 eagle_connector_v2.cpp -o eagle_v2 -lcurl -lcrypto -I./third_party
./eagle_v2 CODM "1.2.3:BUILDID" USER_KEY DEVICE-SERIAL
# انتظار:  config opened: yes
```
(`third_party/nlohmann/json.hpp` لازم است — github.com/nlohmann/json)

`hardening.c` و `guard.c` را هم به بیلدِ نهایی اضافه کن (لایه‌ی ضدتحلیل).

اندروید NDK: همین سورس‌ها کامپایل می‌شوند؛ به libcurl + BoringSSL لینک کن. جاوا لازم نیست.
