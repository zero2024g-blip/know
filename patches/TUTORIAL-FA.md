# آموزش کاملِ پله ۱ — کانکتور v2 + چکرهای کلاینت + مرگِ مبهم

این راهنما همه‌چیز را قدم‌به‌قدم می‌گوید. `Connect.php` خودت **دست نمی‌خورد**؛ نسخه‌ی
v2 کنارش اجرا می‌شود.

ساختار بسته:
```
keygen/   → روی گوشی (ترموکس) اجرا کن؛ ساختِ کلید امضا
server/   → روی پنل
client/   → سمت اپ (C/C++)
docs/     → مرجع
```

---

## گام ۰ — چه می‌سازیم؟
- اپ به `/(...)/data/zezr_connector_v2` وصل می‌شود، کلید لایسنس می‌فرستد (رمزشده)،
  و سرور یک **کانفیگِ رمزشده‌ی هر سشن** برمی‌گرداند که اپ بدونش کار نمی‌کند.
- پاسخ‌ها با **Ed25519 امضا** می‌شوند؛ کلاینت با کلید عمومی تأیید می‌کند.
- سمت کلاینت، **چکرهای دستکاری** (دیباگر/هوک/امولاتور) و یک **primitive مرگِ مبهم**
  (به‌جای `exit`) اضافه می‌شود.

---

## گام ۱ — ساخت کلید امضا روی گوشی (ترموکس) 🔑
کلید خصوصی **نباید** روی سرور ساخته شود. در ترموکسِ گوشیت:

**راه A (PHP):**
```
pkg update && pkg install php
php genkey-sign.php
```
**راه B (Python):**
```
pkg install python
pip install pynacl
python genkey.py
```

خروجی دو خط است:
```
connect.signKeyV2 = ...............   ← این «مخفی» است، فقط در .env سرور
SIGN_PUBKEY_B64 = "................"   ← این عمومی است، در کلاینت C++
```
> این دو ابزار خروجیِ کاملاً **هم‌فرمت** می‌دهند (تست‌شده: سرور با libsodium امضا،
> کلاینت با OpenSSL تأیید). فرقی نمی‌کند کدام را بزنی.

خطِ `connect.signKeyV2` را **در هیچ چت/بکاپ/اسکرین‌شات** نگذار.

---

## گام ۲ — سرور (پنل)

**۲.۱ کنترلر را بگذار.** `server/ConnectV2.php.new` را به `ConnectV2.php` تغییرِ نام
بده و در `app/Controllers/ConnectV2.php` بگذار.

**۲.۲ مقادیر را پر کن** (بالای فایل، داخل `__construct`):
```php
$this->staticWords = "..."; // همان مقدار Connect.php خودت
$this->Public_Key  = "..."; // همان
$this->setAccess   = "..."; // همان
// و رشته‌های نسخه‌ی هر بازی ($codm_version و ...)
```
اگر با `Connect.php` یکی نباشند، اپ «Please Update / Invalid Public Key» می‌گیرد.

**۲.۳ روت را اضافه کن** در `app/Config/Routes.php` داخل گروه `data`:
```php
$routes->group('data', static function ($routes) {
    $routes->match(['get','post'], 'zezr_connector',    'Connect::index');     // v1 تو (نگه‌دار)
    $routes->match(['get','post'], 'zezr_connector_v2', 'ConnectV2::index');    // اضافه
    $routes->match(['get','post'], 'zezr_activate',      'ConnectV2::decoy');    // اضافه (طعمه)
});
```

**۲.۴ فیلتر را استثنا کن** در `app/Config/Filters.php` — هر دو `csrf` و `auth`:
```php
'csrf' => ['except' => ['data/zezr_connector','data/zezr_connector_v2','data/zezr_activate','download','download/*']],
'auth' => ['except' => ['/','login','login/2fa','register','check',
                        'data/zezr_connector','data/zezr_connector_v2','data/zezr_activate','download','download/*']],
```

**۲.۵ کلیدها در `.env`:**
```
connect.signKeyV2 = <خطی که گوشی داد>
# اختیاری:
# connect.aesKeyV2     = <۶۴ هگز>   (نگذاری، از connect.aesKey استفاده می‌شود)
# connect.payloadKeyV2 = <هر متن>   (نگذاری، از aesKey مشتق می‌شود)
```

**۲.۶ (اختیاری) جدول‌ها:** `server/migration-connector.sql` را در phpMyAdmin اجرا کن.
کانکتور بدونشان هم کار می‌کند؛ این‌ها هانی‌پات (canary/blacklist) و **auto-revoke
سرعتی** (یک کلید روی صدها IP) را روشن می‌کنند.

---

## گام ۳ — کلاینت (اپ C/C++)

**۳.۱ ثابت‌ها** را بالای `client/eagle_connector_v2.cpp` پر کن:
```cpp
AES_KEY_HEX     = "..."   // = connect.aesKeyV2 (یا connect.aesKey)
PUBLIC_KEY      = "..."   // = $Public_Key سرور
STATIC_WORDS    = "..."   // = $staticWords سرور
ENDPOINT        = "https://panel.zeromods.id/data/zezr_connector_v2"
SIGN_PUBKEY_B64 = "..."   // خطِ عمومیِ گوشی
```

**۳.۲ تست روی کامپیوتر** (برای اطمینان از درستی):
```
g++ -std=c++17 eagle_connector_v2.cpp -o eagle_v2 -lcurl -lcrypto -I./third_party
./eagle_v2 CODM "1.2.3:BUILDID" CODM_KEY123 DEVICE-SERIAL-1
```
باید ببینی: `config opened: yes` و مقادیرِ کانفیگ. (نیاز به
`third_party/nlohmann/json.hpp` از github.com/nlohmann/json.)

**۳.۳ چکرها و گارد را به بیلد اضافه کن:**
```
cc -O2 -DHD_TEST    hardening.c -o hdtest    -lcrypto && ./hdtest      # self-test چکرها
cc -O2 -DGUARD_DEMO guard.c     -o guarddemo             && ./guarddemo  # دموی کرشِ مبهم
```
- در `hdtest` باید ببینی «پچِ ۱ بایت → هش عوض می‌شود».
- در `guarddemo` باید ببینی مسیرِ دستکاری‌شده با **SIGSEGV** می‌میرد (بدون exit).

**۳.۴ روی اندروید (NDK):** همین سورس‌ها کامپایل می‌شوند؛ برای هر ABI به libcurl و
BoringSSL لینک کن. **جاوا لازم نیست.**

---

## گام ۴ — استفاده از کانفیگ در فیچرِ واقعی
اپ را طوری بنویس که به `r.config` (که با `kx` رمزگشایی شده) **نیاز داشته باشد**، نه به
یک `if (ok)` محلی. نمونه‌ی کاملِ اجرایی: `client/feature_example.c` +
`client/provision_demo.sh`. کسی که فعال‌سازی را دور بزند `kx` آشغال می‌گیرد و کانفیگ
باز نمی‌شود → اپ می‌شکند.

---

## چک‌لیستِ نهایی
- [ ] کلید امضا روی **گوشی** ساخته شد، نه سرور.
- [ ] `connect.signKeyV2` فقط در `.env` است؛ در هیچ چت/گیت نیست.
- [ ] `staticWords`/`Public_Key`/`setAccess`/نسخه‌ها بین سرور و کلاینت **یکی** است.
- [ ] `AES_KEY_HEX` کلاینت = `connect.aesKeyV2` (یا `connect.aesKey`).
- [ ] `SIGN_PUBKEY_B64` کلاینت = کلید عمومیِ گوشی.
- [ ] `config opened: yes` را در تست دیدی.
- [ ] `hardening.c` و `guard.c` به بیلد اضافه شدند.

بعد از این، بگو تا **پله ۲** (گره‌زدنِ چکرها به گارد و به کلیدِ رمزنگاری) را بسازیم.

---

## امنیت — بکن/نکن
- **نکن:** کلید خصوصی را روی سرور نساز؛ در چت/بکاپ/اسکرین‌شات نگذار.
- **بکن:** `max_devices` را کم بگذار (۱-۲) تا بیلدِ رایگان فقط چند دستگاه کار کند.
- **بکن:** offsetها را هر آپدیت بازی سمت سرور **بچرخان** تا کرکِ ثابت بشکند.
- **یادت باشد:** هیچ چکِ سمت‌کلاینتی روی دستگاهِ روت شکست‌ناپذیر نیست؛ ارزشِ این‌ها
  «کند و شکننده‌کردن» است، و لایه‌ی سرور (revoke/watermark/kx) نتیجه را کوتاه‌عمر و
  ردیابی‌پذیر می‌کند. جزئیات: `docs/ANTICHEAT-REALITY.md`.
