<?php

/*
 * Setup check — runs before the framework boots.
 *
 * Without this, a missing or unreadable .env fails in the most confusing way
 * possible: the framework falls back to the stock Config/App.php, whose
 * baseURL is 'http://localhost:8080/'. The panel then *appears* to work —
 * pages render — but every link, redirect and stylesheet URL points at
 * localhost, so you land on "localhost refused to connect" and the design is
 * gone. Nothing on screen mentions .env.
 *
 * So the check happens here, where the answer can be stated plainly.
 * It costs one file_exists() on a configured install and nothing else.
 */

(static function (): void {
    // __DIR__ is this file's directory - the project root - whichever
    // front controller included it.
    $root = __DIR__;
    $env  = $root . DIRECTORY_SEPARATOR . '.env';

    $problem = null;

    // A near miss is the likeliest mistake by a distance. File managers fight
    // dot-files, so the copy of env.template ends up as `env.`, `env`, or
    // `.env.txt` - a file that is plainly right to a person and invisible to
    // CodeIgniter, which reads the name `.env` and nothing else. Saying "not
    // found" to someone looking straight at their env file is useless, so
    // look for the near miss and name it.
    $nearMiss = null;
    if (! is_file($env)) {
        foreach ((array) @scandir($root) as $entry) {
            if ($entry === '.env' || $entry === 'env.template' || ! is_file($root . DIRECTORY_SEPARATOR . $entry)) {
                continue;
            }
            // .env.txt, env., env, ENV.bak, ".env " - anything that is the
            // word "env" wearing a disguise.
            if (preg_match('/^\.?env\.?(txt|bak|old|save|template\.txt)?$/i', trim($entry))) {
                $nearMiss = $entry;
                break;
            }
        }
    }

    if (! is_file($env)) {
        $problem = $nearMiss === null ? 'missing' : 'nearmiss';
    } elseif (! is_readable($env)) {
        $problem = 'unreadable';
    } else {
        // The file is there — but is the one line that matters actually in it?
        // A .env copied from env.template and never edited fails exactly the
        // same way as no .env at all, and looks identical from the browser.
        //
        // Read app.baseURL by scanning the lines ourselves, NOT with
        // parse_ini_file(). PHP's INI parser fails on the WHOLE file the moment
        // any *other* line holds a value the INI grammar dislikes — a database
        // password with a `!`, `&`, `$` or `"` in it is enough — and then it
        // returns false, app.baseURL reads as empty, and this check wrongly
        // shouts "baseURL not set" at someone whose baseURL is perfectly fine.
        // A line scan only cares about the one line it is looking for, so no
        // other value can break it. The last uncommented assignment wins.
        $url = '';
        foreach (@file($env, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $trim = ltrim($line);
            if ($trim === '' || $trim[0] === '#' || $trim[0] === ';') {
                continue;   // comment or blank
            }
            if (preg_match('/^\s*app\.baseURL\s*=\s*(.*)$/', $line, $m)) {
                // Strip surrounding quotes and whitespace from the value only.
                $url = trim(trim($m[1]), " \t'\"");
            }
        }

        if ($url === '' || str_contains($url, 'localhost')) {
            $problem = 'baseurl';
        }
    }

    if ($problem === null) {
        return;
    }

    $title = [
        'nearmiss'   => 'اسم فایل غلط است',
        'missing'    => 'فایل ‎.env پیدا نشد',
        'unreadable' => 'فایل ‎.env خوانده نمی‌شود',
        'baseurl'    => 'مقدار app.baseURL تنظیم نشده',
    ][$problem];

    $body = [
        'nearmiss' => '
            <p>یک فایل به اسم <code>' . htmlspecialchars($nearMiss ?? '', ENT_QUOTES, 'UTF-8') . '</code>
               اینجا هست — ولی کدایگنایتر <b>فقط</b> فایلی به اسم دقیقاً <code>.env</code> را
               می‌خواند. نه <code>env.</code>، نه <code>env</code>، نه <code>.env.txt</code>.</p>
            <p>نقطه باید <b>اولِ</b> اسم باشد و بعدش هیچی نیاید:</p>
            <pre>.env</pre>
            <p>در File Manager روی همان فایل راست‌کلیک کن ← <b>Rename</b> ←
               بنویس <code>.env</code> ← ذخیره. محتوایش درست است، فقط اسمش.</p>
            <p class="hint">اگر File Manager اجازهٔ اسمی که با نقطه شروع می‌شود نداد:
               فایل را روی کامپیوترت با اسم <code>.env</code> بساز و
               <b>Upload</b> کن — آپلود این محدودیت را ندارد.</p>',
        'missing' => '
            <p>پنل بدون این فایل بالا نمی‌آید. باید <b>دقیقاً کنار</b>
               <code>index.php</code> باشد — نه داخل <code>public/</code>.</p>
            <ol>
              <li>در File Manager روی <code>env.template</code> راست‌کلیک کن ← <b>Copy</b></li>
              <li>اسم کپی را به <code>.env</code> تغییر بده — با نقطه در اول و <b>بدون</b> ‎<code>.txt</code></li>
              <li>بازش کن و جاهای <code>PUT_..._HERE</code> را پر کن</li>
            </ol>
            <p class="hint">فایل‌های مخفی را نمی‌بینی؟ در File Manager:
               <b>Settings ← Show hidden files</b> را روشن کن. <code>.env</code> مخفی است.</p>',
        'unreadable' => '
            <p>فایل هست ولی PHP اجازهٔ خواندنش را ندارد.</p>
            <p>در File Manager روی <code>.env</code> راست‌کلیک کن ←
               <b>Permissions</b> ← مقدار <b>644</b> (یا 600) بگذار.</p>',
        'baseurl' => '
            <p>فایل <code>.env</code> هست، ولی خط <code>app.baseURL</code> یا خالی است
               یا هنوز روی مقدار پیش‌فرض <code>localhost</code> مانده.</p>
            <p>بازش کن و این خط را بگذار — بخش <code>/public</code> در آخر لازم است:</p>
            <pre>app.baseURL = \'https://YOUR-DOMAIN/public\'</pre>
            <p class="hint">اگر این را درست نکنی، پنل بالا می‌آید ولی همهٔ لینک‌ها و
               فایل‌های CSS به <code>localhost</code> اشاره می‌کنند: صفحه بی‌ریخت
               می‌شود و ورود، تو را به «localhost refused to connect» می‌برد.</p>',
    ][$problem];

    // Show only a web-root-relative hint, never the absolute server path. The
    // full path ("/home/u450370133/domains/.../public_html/eagle_panel/.env")
    // leaks the hosting account id and the server layout to anyone who trips
    // this page. Keep just the part from the web root down, which is all the
    // reader needs to find the file.
    $shown = str_replace('\\', '/', $env);
    if (preg_match('#(?:^|/)(?:public_html|htdocs|httpdocs|wwwroot|www)/(.+)$#i', $shown, $m)) {
        $shown = $m[1];                        // e.g. "eagle_panel/.env"
    } else {
        $parts = array_values(array_filter(explode('/', $shown), 'strlen'));
        $shown = implode('/', array_slice($parts, -2));   // last two segments
    }

    header('HTTP/1.1 503 Service Unavailable', true, 503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');

    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
       , '<meta name="viewport" content="width=device-width, initial-scale=1">'
       , '<title>', $title, '</title><style>'
       , 'body{margin:0;background:#15100f;color:#f1e7e2;'
       , 'font:16px/1.9 Vazirmatn,"Segoe UI",Tahoma,sans-serif;'
       , 'display:flex;min-height:100vh;align-items:center;justify-content:center;padding:1.2rem}'
       , '.card{max-width:640px;width:100%;background:#1e1715;border:1px solid #33251f;'
       , 'border-radius:16px;padding:clamp(1.2rem,4vw,2rem)}'
       , 'h1{margin:0 0 .3rem;font-size:clamp(1.3rem,4vw,1.7rem);color:#f4725a}'
       , '.sub{margin:0 0 1.4rem;color:#937d74;font-size:.9rem}'
       , 'code,pre{font-family:ui-monospace,Menlo,monospace;font-size:.85em;direction:ltr;'
       , 'unicode-bidi:isolate;background:#261d1a;border-radius:6px;display:inline-block;padding:.08em .4em}'
       , 'pre{display:block;padding:.8rem 1rem;margin:.6rem 0;overflow-x:auto;text-align:left}'
       , 'ol{padding-right:1.3rem;padding-left:0;margin:0 0 1rem}li{margin-bottom:.5rem}'
       , 'p{margin:0 0 1rem}.hint{color:#c4b0a7;font-size:.9rem}'
       , '.path{margin-top:1.4rem;padding-top:1.1rem;border-top:1px solid #33251f;'
       , 'color:#937d74;font-size:.85rem}'
       , '</style></head><body><div class="card">'
       , '<h1>', $title, '</h1>'
       , '<p class="sub">پنل تا وقتی این درست نشود بالا نمی‌آید.</p>'
       , $body
       , '<div class="path">فایل باید اینجا باشد:<br><code>'
       , htmlspecialchars($shown, ENT_QUOTES, 'UTF-8')
       , '</code></div>'
       , '</div></body></html>';

    exit(1);
})();
