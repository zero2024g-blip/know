<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="dark">
<title>Downloads &middot; ZERO</title>

<?php
$fmt = static function ($b) {
    $b = (int) $b;
    if ($b <= 0) return '';
    if ($b < 1024) return $b . ' B';
    $u = ['KB', 'MB', 'GB']; $i = -1;
    do { $b /= 1024; $i++; } while ($b >= 1024 && $i < 2);
    return round($b, 1) . ' ' . $u[$i];
};
// Same-origin theme assets (Poppins + icon font), so the public page matches
// the panel. Both are self-hosted; a system-font stack is the fallback.
$poppins = base_url('assets/vendor/css/poppins.css');
$icons   = base_url('assets/vendor/css/bootstrap-icons.min.css');
?>
<link rel="preconnect" href="<?= esc(base_url(), 'attr') ?>">
<link href="<?= esc($poppins, 'attr') ?>" rel="stylesheet">
<link href="<?= esc($icons, 'attr') ?>" rel="stylesheet">
<style>
    :root {
        color-scheme: dark;
        /* Ember palette — the panel's own tokens, so this reads as one product. */
        --bg:#120D0B; --surface:#1C1512; --surface-2:#221913; --surface-3:#2A1E18;
        --input:#150F0D; --line:#33241F; --line-2:#432F28;
        --text:#F7ECE8; --text-2:#D8C6C0; --dim:#B29A91;
        --accent:#F4725A; --accent-hi:#FB8A72; --on-accent:#2A0E06;
        --wash:rgba(244,114,90,.12); --ring:rgba(244,114,90,.34);
        --ok:#4ADE80; --ok-wash:rgba(74,222,128,.13);
        --bad:#F87171; --bad-wash:rgba(248,113,113,.13);
        --font:"Poppins",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif;
        --r:12px; --r-lg:16px;
        --sh-2:0 4px 16px -6px rgba(0,0,0,.55);
        --sh-3:0 18px 44px -20px rgba(0,0,0,.75);
    }
    * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
    html { -webkit-text-size-adjust:100%; scroll-behavior:smooth; }
    body {
        margin:0; background:
            radial-gradient(1100px 620px at 82% -8%, rgba(244,114,90,.10), transparent 60%),
            radial-gradient(900px 520px at -6% 4%, rgba(251,138,114,.06), transparent 55%),
            var(--bg);
        color:var(--text); font-family:var(--font); font-size:16px; line-height:1.6;
        min-height:100dvh; display:flex; justify-content:center;
        padding:clamp(1.1rem,4vw,2.4rem) 1rem calc(2.4rem + env(safe-area-inset-bottom));
    }
    .wrap { width:100%; max-width:600px; }

    /* header */
    .brand { display:flex; align-items:center; gap:.6rem; margin-bottom:1.6rem; }
    .brand .mark {
        width:38px; height:38px; border-radius:11px; display:grid; place-items:center;
        color:var(--on-accent); font-size:1.15rem;
        background:linear-gradient(150deg,var(--accent-hi),var(--accent));
        box-shadow:0 6px 18px -6px rgba(244,114,90,.6);
    }
    .brand .name { font-weight:700; letter-spacing:.22em; font-size:1.05rem; }
    h1 { font-size:clamp(1.6rem,6vw,2rem); font-weight:700; margin:.1rem 0 .35rem; letter-spacing:-.01em; }
    .sub { color:var(--dim); margin:0 0 1.6rem; font-size:.95rem; max-inline-size:46ch; }

    /* card */
    .card {
        background:linear-gradient(180deg,var(--surface),var(--surface) 40%,#1a1310);
        border:1px solid var(--line); border-radius:var(--r-lg);
        padding:1.15rem 1.15rem .9rem; margin-bottom:1.15rem; box-shadow:var(--sh-2);
    }
    .card-h { display:flex; align-items:center; gap:.5rem; margin:.1rem 0 1rem; }
    .card-h .ic { color:var(--accent); font-size:1rem; display:grid; place-items:center; }
    .card-h h2 { font-size:.72rem; text-transform:uppercase; letter-spacing:.09em; color:var(--dim); font-weight:600; margin:0; }
    .card-h .count { margin-left:auto; font-size:.72rem; color:var(--dim); }

    /* file row */
    .row { display:flex; align-items:center; gap:.85rem; padding:.8rem 0; }
    .row + .row { border-top:1px solid var(--line); }
    .row .fic {
        flex:none; width:40px; height:40px; border-radius:11px; display:grid; place-items:center;
        background:var(--surface-3); border:1px solid var(--line); color:var(--accent-hi); font-size:1.1rem;
    }
    .row .info { flex:1; min-width:0; }
    .row .name { font-weight:600; overflow-wrap:anywhere; line-height:1.35; }
    .row .meta { font-size:.76rem; color:var(--dim); margin-top:.2rem; display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; }
    .pill {
        display:inline-flex; align-items:center; font-size:.66rem; font-weight:600; letter-spacing:.02em;
        padding:.14em .6em; border-radius:20px; background:var(--wash); color:var(--accent);
        border:1px solid rgba(244,114,90,.3);
    }
    .row .act { display:flex; align-items:center; gap:.35rem; flex:none; }

    /* buttons */
    button, .btn {
        font:inherit; font-weight:600; border:0; border-radius:var(--r); cursor:pointer;
        display:inline-flex; align-items:center; justify-content:center; gap:.4rem;
        min-height:44px; padding:.55rem 1rem; white-space:nowrap;
        transition:transform .05s ease, opacity .15s ease, background .15s ease, border-color .15s ease;
    }
    button:active, .btn:active { transform:scale(.97); }
    .btn-primary { background:linear-gradient(150deg,var(--accent-hi),var(--accent)); color:var(--on-accent);
        box-shadow:0 6px 18px -8px rgba(244,114,90,.7); }
    .btn-primary:hover { filter:brightness(1.04); }
    .btn-ghost { background:transparent; color:var(--text); border:1px solid var(--line-2); }
    .btn-ghost:hover { border-color:var(--accent); color:var(--accent); }
    .btn-icon { padding:0; width:44px; }
    button:disabled { opacity:.5; cursor:default; transform:none; }
    button:focus-visible, .btn:focus-visible, input:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
    .btn .spin { width:15px; height:15px; border-radius:50%; border:2px solid rgba(255,255,255,.4); border-top-color:currentColor; animation:sp .6s linear infinite; }
    @keyframes sp { to { transform:rotate(360deg); } }

    /* key input */
    .keybox { position:relative; margin-bottom:.85rem; }
    .keybox .ic { position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:var(--dim); font-size:1rem; pointer-events:none; }
    input[type=text] {
        width:100%; padding:.85rem .9rem .85rem 2.5rem; border-radius:var(--r);
        border:1px solid var(--line); background:var(--input); color:var(--text);
        font:inherit; font-size:.95rem; transition:border-color .15s, box-shadow .15s;
    }
    input[type=text]::placeholder { color:#7c675e; }
    input[type=text]:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px var(--ring); }
    .fullw { width:100%; }

    .hint { font-size:.76rem; color:var(--dim); margin:.55rem 0 0; }
    .msg { font-size:.85rem; margin-top:.7rem; min-height:1.15em; }
    .msg.err { color:var(--bad); } .msg.ok { color:var(--ok); }
    .empty { color:var(--dim); font-size:.92rem; padding:.5rem 0 .8rem; text-align:center; }
    .empty .bi { display:block; font-size:1.6rem; color:var(--line-2); margin-bottom:.4rem; }

    /* revealed section + skeletons */
    #unlocked { margin-top:.2rem; }
    #unlocked.has { margin-top:.6rem; padding-top:.3rem; border-top:1px solid var(--line); }
    .sk { display:flex; align-items:center; gap:.85rem; padding:.8rem 0; }
    .sk + .sk { border-top:1px solid var(--line); }
    .sk .b { background:linear-gradient(90deg,var(--surface-2),var(--surface-3),var(--surface-2)); background-size:200% 100%; animation:sh 1.2s ease infinite; border-radius:8px; }
    .sk .sq { width:40px; height:40px; border-radius:11px; flex:none; }
    .sk .l1 { height:12px; width:60%; margin-bottom:8px; } .sk .l2 { height:10px; width:35%; }
    @keyframes sh { to { background-position:-200% 0; } }

    footer { text-align:center; color:var(--dim); font-size:.75rem; margin-top:1rem; }
    footer .bi { color:var(--accent); }

    /* entrance */
    .rise { opacity:0; transform:translateY(10px); animation:rise .5s cubic-bezier(.2,.7,.2,1) forwards; }
    .rise:nth-child(2){animation-delay:.04s}.rise:nth-child(3){animation-delay:.08s}.rise:nth-child(4){animation-delay:.12s}
    @keyframes rise { to { opacity:1; transform:none; } }
    @media (prefers-reduced-motion: reduce) {
        html { scroll-behavior:auto; }
        .rise { animation:none; opacity:1; transform:none; }
        .btn .spin, .sk .b { animation:none; }
    }

    .toast {
        position:fixed; left:50%; bottom:calc(1.2rem + env(safe-area-inset-bottom)); transform:translateX(-50%) translateY(20px);
        background:var(--surface-3); color:var(--text); border:1px solid var(--line-2);
        padding:.7rem 1.1rem; border-radius:999px; font-size:.85rem; box-shadow:var(--sh-3);
        opacity:0; pointer-events:none; transition:opacity .2s, transform .2s; z-index:50; display:flex; align-items:center; gap:.45rem;
    }
    .toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
    .toast .bi { color:var(--ok); }
</style>
</head>
<body>
<div class="wrap">
    <div class="brand rise">
        <span class="mark"><i class="bi bi-lightning-charge-fill"></i></span>
        <span class="name">ZERO</span>
    </div>
    <h1 class="rise">Downloads</h1>
    <p class="sub rise">Free files are open to everyone. Everything else unlocks with your licence key &mdash; enter it once and your files appear.</p>

    <?php if (! empty($free)) : ?>
    <div class="card rise">
        <div class="card-h">
            <span class="ic"><i class="bi bi-unlock-fill"></i></span>
            <h2>Free files</h2>
            <span class="count"><?= count($free) ?></span>
        </div>
        <?php foreach ($free as $f) : ?>
            <div class="row">
                <span class="fic"><i class="bi bi-file-earmark-arrow-down"></i></span>
                <div class="info">
                    <div class="name"><?= esc($f['title']) ?></div>
                    <div class="meta"><span class="pill"><?= esc($f['game']) ?></span> <?php $s = $fmt($f['size']); if ($s) : ?><span><?= esc($s) ?></span><?php endif; ?></div>
                </div>
                <div class="act">
                    <button class="btn-ghost btn-icon copy" data-slug="<?= esc($f['slug'], 'attr') ?>" title="Copy shareable link" aria-label="Copy link"><i class="bi bi-link-45deg"></i></button>
                    <button class="btn-primary dl" data-slug="<?= esc($f['slug'], 'attr') ?>"><i class="bi bi-download"></i> Get</button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (! empty($anyGated)) : ?>
    <div class="card rise">
        <div class="card-h">
            <span class="ic"><i class="bi bi-key-fill"></i></span>
            <h2>Unlock with your key</h2>
        </div>
        <div class="keybox">
            <span class="ic"><i class="bi bi-key"></i></span>
            <input type="text" id="key" placeholder="e.g. CODM_xxxxxxxx" autocomplete="off" autocapitalize="none" spellcheck="false" inputmode="text" aria-label="Your licence key">
        </div>
        <button class="btn-primary fullw" id="unlock"><i class="bi bi-unlock"></i> Show my files</button>
        <p class="hint" id="hint">Your key stays on this device. Links are one-time and expire.</p>
        <div id="unlocked" aria-live="polite"></div>
        <div class="msg" id="msg" role="status" aria-live="polite"></div>
    </div>
    <?php elseif (empty($free)) : ?>
    <div class="card rise">
        <p class="empty"><i class="bi bi-inbox"></i> Nothing to download right now.</p>
    </div>
    <?php endif; ?>

    <footer class="rise"><i class="bi bi-shield-lock-fill"></i> &copy; <?= date('Y') ?> ZERO &middot; every link is one-time and expires</footer>
</div>

<div class="toast" id="toast"><i class="bi bi-check-circle-fill"></i> <span id="toast-t"></span></div>

<script>
    var API_GET    = <?= json_encode(base_url('download/get'),    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var API_UNLOCK = <?= json_encode(base_url('download/unlock'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var DIRECT     = <?= json_encode(rtrim(base_url('download/direct'), '/') . '/', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var LS_KEY     = 'zero_dl_key';

    var msg = document.getElementById('msg');
    function say(t, cls) { if (!msg) return; msg.textContent = t || ''; msg.className = 'msg' + (cls ? ' ' + cls : ''); }
    function fmt(b){ if(!b) return ''; var u=['B','KB','MB','GB'],i=0; while(b>=1024&&i<3){b/=1024;i++;} return (Math.round(b*10)/10)+' '+u[i]; }

    var toast = document.getElementById('toast'), toastT = document.getElementById('toast-t'), toastTimer;
    function showToast(t) {
        toastT.textContent = t; toast.classList.add('show');
        clearTimeout(toastTimer); toastTimer = setTimeout(function(){ toast.classList.remove('show'); }, 2200);
    }

    function busy(btn, on) {
        if (on) {
            btn.dataset.html = btn.innerHTML; btn.disabled = true;
            btn.innerHTML = '<span class="spin"></span>';
        } else if (btn.dataset.html !== undefined) {
            btn.innerHTML = btn.dataset.html; btn.disabled = false; delete btn.dataset.html;
        }
    }

    // Ask the server for a one-time link, then navigate to it (browser downloads).
    function fetchAndGo(btn, slug, key) {
        busy(btn, true); say('');
        var body = new URLSearchParams(); body.set('file', slug); if (key) body.set('key', key);
        fetch(API_GET, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body: body.toString() })
            .then(function (r) { return r.json().catch(function(){return {};}); })
            .then(function (j) {
                busy(btn, false);
                if (j && j.status === 'ok' && j.link) { showToast('Starting download…'); window.location = j.link; }
                else { say((j && j.message) ? j.message : 'Could not get the file.','err'); }
            }).catch(function(){ busy(btn, false); say('Network error. Try again.','err'); });
    }

    document.querySelectorAll('button.dl').forEach(function (b) {
        b.addEventListener('click', function () { fetchAndGo(b, b.dataset.slug, ''); });
    });
    document.querySelectorAll('button.copy').forEach(function (b) {
        b.addEventListener('click', function () {
            var url = DIRECT + b.dataset.slug;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function(){ showToast('Link copied'); }, function(){ prompt('Copy this link:', url); });
            } else { prompt('Copy this link:', url); }
        });
    });

    var unlockBtn = document.getElementById('unlock');
    var keyInput  = document.getElementById('key');
    if (unlockBtn) {
        // Remember the last key on this device (a convenience, never sent anywhere else).
        try { var saved = localStorage.getItem(LS_KEY); if (saved) keyInput.value = saved; } catch (e) {}

        function skeleton(n) {
            var box = document.getElementById('unlocked'); box.className = 'has'; box.innerHTML = '';
            for (var i=0;i<n;i++){
                var s=document.createElement('div'); s.className='sk';
                s.innerHTML='<div class="b sq"></div><div style="flex:1"><div class="b l1"></div><div class="b l2"></div></div>';
                box.appendChild(s);
            }
        }

        function doUnlock() {
            var key = (keyInput.value || '').trim();
            if (!key) { say('Enter your key first.','err'); keyInput.focus(); return; }
            try { localStorage.setItem(LS_KEY, key); } catch (e) {}
            busy(unlockBtn, true); say(''); skeleton(3);
            var body = new URLSearchParams(); body.set('key', key);
            fetch(API_UNLOCK, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body: body.toString() })
                .then(function (r) { return r.json().catch(function(){return {};}); })
                .then(function (j) {
                    busy(unlockBtn, false);
                    var box = document.getElementById('unlocked'); box.innerHTML = ''; box.className = '';
                    var files = (j && j.files) || [];
                    if (!files.length) {
                        box.className = 'has';
                        box.innerHTML = '<p class="empty"><i class="bi bi-search"></i> No files for this key.</p>';
                        say('');
                        return;
                    }
                    box.className = 'has';
                    say(files.length + ' file' + (files.length>1?'s':'') + ' unlocked.','ok');
                    files.forEach(function (f) {
                        var row = document.createElement('div'); row.className = 'row';
                        var ic = document.createElement('span'); ic.className='fic'; ic.innerHTML='<i class="bi bi-file-earmark-lock2"></i>';
                        var info = document.createElement('div'); info.className = 'info';
                        var nm = document.createElement('div'); nm.className='name'; nm.textContent = f.title;
                        var mt = document.createElement('div'); mt.className='meta';
                        var pill=document.createElement('span'); pill.className='pill'; pill.textContent=f.game; mt.appendChild(pill);
                        if (f.size){ var sz=document.createElement('span'); sz.textContent=fmt(f.size); mt.appendChild(sz); }
                        info.appendChild(nm); info.appendChild(mt);
                        var act=document.createElement('div'); act.className='act';
                        var btn = document.createElement('button'); btn.className='btn-primary'; btn.innerHTML='<i class="bi bi-download"></i> Get';
                        btn.addEventListener('click', function(){ fetchAndGo(btn, f.slug, key); });
                        act.appendChild(btn);
                        row.appendChild(ic); row.appendChild(info); row.appendChild(act); box.appendChild(row);
                    });
                }).catch(function(){
                    busy(unlockBtn, false);
                    var box=document.getElementById('unlocked'); box.innerHTML=''; box.className='';
                    say('Network error. Try again.','err');
                });
        }

        unlockBtn.addEventListener('click', doUnlock);
        keyInput.addEventListener('keyup', function(e){ if(e.key==='Enter') doUnlock(); });
    }
</script>
</body>
</html>
