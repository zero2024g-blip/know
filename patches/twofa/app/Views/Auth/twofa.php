<?= $this->extend('Layout/Starter') ?>

<?= $this->section('content') ?>
<link href="<?= asset_ver('assets/vendor/css/poppins.css') ?>" rel="stylesheet">

<style>
    :root {
        --bg-dark:#120d0b; --card-bg:rgba(31,23,20,.88); --card-border:#332420;
        --accent:#f4725a; --accent-soft:#fb8a72; --text-main:#f7ece8;
        --text-muted:#a4897f; --input-bg:#16100e; --input-border:#332420;
    }
    body { background:var(--bg-dark)!important;
        font-family:'Poppins',-apple-system,"Segoe UI",Roboto,sans-serif; min-height:100vh; }
    .auth-wrapper { min-height:90vh; display:flex; align-items:center; justify-content:center; padding:24px 16px; }
    .auth-stack { width:100%; max-width:420px; }
    .auth-card { background:var(--card-bg); border:1px solid var(--card-border); border-radius:20px;
        padding:30px 26px; backdrop-filter:blur(8px); box-shadow:0 20px 50px rgba(0,0,0,.45); }
    .auth-head { text-align:center; margin-bottom:22px; }
    .auth-ic { width:56px; height:56px; margin:0 auto 14px; border-radius:16px;
        display:grid; place-items:center; background:rgba(244,114,90,.14); border:1px solid rgba(244,114,90,.3); }
    .auth-ic svg { width:28px; height:28px; fill:var(--accent); }
    .auth-head h1 { font-size:1.35rem; color:var(--text-main); margin:0 0 6px; font-weight:700; }
    .auth-head p { color:var(--text-muted); font-size:.9rem; margin:0; }
    .form-label { color:var(--text-main); font-size:.85rem; font-weight:600; margin-bottom:6px; display:block; }
    .code-input { width:100%; background:var(--input-bg); border:1px solid var(--input-border); border-radius:12px;
        color:var(--text-main); font:inherit; padding:.8rem 1rem; letter-spacing:.35em; text-align:center;
        font-size:1.4rem; font-weight:700; }
    .code-input.rec { letter-spacing:.08em; font-size:1.05rem; text-transform:lowercase; }
    .code-input:focus { outline:none; border-color:var(--accent); }
    .btn-auth { width:100%; margin-top:18px; border:0; border-radius:12px; padding:.85rem 1rem;
        background:var(--accent); color:#1a0f0c; font-weight:700; font-size:1rem; cursor:pointer;
        transition:transform .05s ease, background .15s ease; }
    .btn-auth:hover { background:var(--accent-soft); } .btn-auth:active { transform:scale(.98); }
    .switch { text-align:center; margin-top:16px; }
    .switch a { color:var(--accent); font-size:.85rem; text-decoration:none; cursor:pointer; }
    .switch a:hover { text-decoration:underline; }
    .hint { color:var(--text-muted); font-size:.78rem; text-align:center; margin-top:10px; }
    .back { text-align:center; margin-top:18px; }
    .back a { color:var(--text-muted); font-size:.82rem; text-decoration:none; }
    .back a:hover { color:var(--accent); }
</style>

<div class="auth-wrapper">
    <div class="auth-stack">
        <?= $this->include('Layout/msgStatus') ?>

        <div class="auth-card">
            <div class="auth-head">
                <div class="auth-ic">
                    <svg viewBox="0 0 24 24"><path d="M12 1 3 5v6c0 5.5 3.8 10.7 9 12 5.2-1.3 9-6.5 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11V11.99z"/></svg>
                </div>
                <h1>Verify it's you</h1>
                <p id="lead">Enter the 6-digit code from your authenticator app</p>
            </div>

            <?= form_open('login/2fa') ?>
                <input type="hidden" name="recovery" id="recovery" value="0">

                <div id="totpBox">
                    <label class="form-label" for="code">Authenticator code</label>
                    <input class="code-input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
                           pattern="[0-9]*" maxlength="6" placeholder="000000" autofocus>
                </div>

                <div id="recBox" style="display:none">
                    <label class="form-label" for="reccode">Recovery code</label>
                    <input class="code-input rec" id="reccode" name="code" autocomplete="off"
                           maxlength="11" placeholder="xxxxx-xxxxx" disabled>
                    <p class="hint">Each recovery code works once.</p>
                </div>

                <button type="submit" class="btn-auth">Verify</button>
            <?= form_close() ?>

            <div class="switch">
                <a id="toRec">Can't use your app? Use a recovery code</a>
            </div>
        </div>

        <div class="back"><a href="<?= site_url('login') ?>">&larr; Back to login</a></div>
    </div>
</div>

<script>
(function () {
    var totpBox = document.getElementById('totpBox'),
        recBox  = document.getElementById('recBox'),
        totp    = document.getElementById('code'),
        rec     = document.getElementById('reccode'),
        flag    = document.getElementById('recovery'),
        lead    = document.getElementById('lead'),
        link    = document.getElementById('toRec'),
        recMode = false;

    // Only one of the two inputs is enabled at a time, so exactly one "code"
    // value is posted.
    link.addEventListener('click', function () {
        recMode = !recMode;
        if (recMode) {
            totpBox.style.display = 'none'; totp.disabled = true;
            recBox.style.display = ''; rec.disabled = false; rec.focus();
            flag.value = '1'; lead.textContent = 'Enter one of your recovery codes';
            link.textContent = 'Use your authenticator app instead';
        } else {
            recBox.style.display = 'none'; rec.disabled = true;
            totpBox.style.display = ''; totp.disabled = false; totp.focus();
            flag.value = '0'; lead.textContent = 'Enter the 6-digit code from your authenticator app';
            link.textContent = "Can't use your app? Use a recovery code";
        }
    });
})();
</script>
<?= $this->endSection() ?>
