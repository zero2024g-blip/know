<?= $this->extend('Layout/Starter') ?>

<?= $this->section('content') ?>

<?= $this->include('Layout/msgStatus') ?>

<header class="em-head em-rise">
    <div class="em-head-main">
        <span class="em-eyebrow"><i class="bi bi-shield-lock"></i> Security</span>
        <h1>Two-factor authentication</h1>
        <p>Add a second step to sign-in: a code from an app on your phone. Even if your password leaks, no one gets in without your phone.</p>
    </div>
    <div class="em-head-act">
        <a class="em-btn" href="<?= site_url('settings') ?>"><i class="bi bi-gear me-1"></i>Back to settings</a>
    </div>
</header>

<?php $codes = $recovery ?? null; ?>

<?php if (! empty($codes)) : ?>
    <section class="em-panel em-rise em-rise-1" style="border-color:#f4725a">
        <div class="em-panel-h"><h2><i class="bi bi-key"></i> Your recovery codes</h2></div>
        <div class="em-panel-b">
            <p>Save these somewhere safe. Each one works <strong>once</strong> if you ever lose your phone. This is the only time they are shown.</p>
            <div class="tfa-codes">
                <?php foreach ($codes as $c) : ?><code><?= esc($c) ?></code><?php endforeach; ?>
            </div>
            <button type="button" class="em-btn mt-3" id="copyCodes"><i class="bi bi-clipboard me-1"></i>Copy all</button>
        </div>
    </section>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">

        <?php if ($enabled) : ?>
            <!-- ON -->
            <section class="em-panel em-rise em-rise-2">
                <div class="em-panel-h"><h2><i class="bi bi-check2-circle"></i> Status: On</h2></div>
                <div class="em-panel-b">
                    <p>Two-factor is protecting your account. You have <strong><?= (int) $remaining ?></strong> recovery code<?= $remaining == 1 ? '' : 's' ?> left.</p>

                    <hr class="tfa-sep">

                    <form method="post" action="<?= site_url('settings/2fa') ?>" class="mb-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="regen">
                        <label for="rc1">Regenerate recovery codes</label>
                        <p class="tfa-dim">Enter a current app code to get a fresh set. The old codes stop working.</p>
                        <div class="tfa-inline">
                            <input type="text" name="code" id="rc1" class="form-control" inputmode="numeric" autocomplete="off" placeholder="6-digit code">
                            <button type="submit" class="em-btn is-primary"><i class="bi bi-key me-1"></i>Regenerate</button>
                        </div>
                    </form>

                    <hr class="tfa-sep">

                    <form method="post" action="<?= site_url('settings/2fa') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="disable">
                        <label for="dc1">Turn off two-factor</label>
                        <p class="tfa-dim">Enter a current app code (or a recovery code) to confirm.</p>
                        <div class="tfa-inline">
                            <input type="text" name="code" id="dc1" class="form-control" autocomplete="off" placeholder="Current code">
                            <button type="submit" class="em-btn is-danger"><i class="bi bi-x-circle me-1"></i>Turn off</button>
                        </div>
                    </form>
                </div>
            </section>

        <?php elseif ($pending) : ?>
            <!-- ENROLLING -->
            <section class="em-panel em-rise em-rise-2">
                <div class="em-panel-h"><h2><i class="bi bi-shield-lock"></i> Set up your app</h2></div>
                <div class="em-panel-b">
                    <p><strong>1.</strong> In Google Authenticator, Authy, or 1Password, add an account and enter this key:</p>

                    <div class="tfa-key" id="secretKey"><?= esc(trim(chunk_split($pending, 4, ' '))) ?></div>
                    <button type="button" class="em-btn mb-3" id="copyKey" data-secret="<?= esc($pending, 'attr') ?>"><i class="bi bi-clipboard me-1"></i>Copy key</button>

                    <p class="tfa-dim">On the same phone you can also tap
                        <a href="<?= esc($otpauthUri, 'attr') ?>">this link</a> to add it automatically.
                        Issuer <strong>ZERO Panel</strong>, account <strong><?= esc($user->username) ?></strong>, 6 digits, 30s.</p>

                    <hr class="tfa-sep">

                    <p><strong>2.</strong> Enter the 6-digit code the app shows to confirm:</p>
                    <form method="post" action="<?= site_url('settings/2fa') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="enable">
                        <div class="tfa-inline">
                            <input type="text" name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code"
                                   pattern="[0-9]*" maxlength="6" placeholder="000000" autofocus>
                            <button type="submit" class="em-btn is-primary"><i class="bi bi-check2-circle me-1"></i>Verify &amp; turn on</button>
                        </div>
                    </form>

                    <form method="post" action="<?= site_url('settings/2fa') ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel">
                        <button type="submit" class="em-btn"><i class="bi bi-x-circle me-1"></i>Cancel</button>
                    </form>
                </div>
            </section>

        <?php else : ?>
            <!-- OFF -->
            <section class="em-panel em-rise em-rise-2">
                <div class="em-panel-h"><h2><i class="bi bi-shield-exclamation"></i> Status: Off</h2></div>
                <div class="em-panel-b">
                    <p>Your account is protected by your password only. Turn on two-factor for bank-grade sign-in security.</p>
                    <p class="tfa-dim">You'll need a free authenticator app (Google Authenticator, Authy, 1Password, Microsoft Authenticator…).</p>
                    <form method="post" action="<?= site_url('settings/2fa') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="begin">
                        <button type="submit" class="em-btn is-primary"><i class="bi bi-shield-lock me-1"></i>Turn on two-factor</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

    </div>

    <div class="col-lg-5">
        <section class="em-panel em-rise em-rise-3">
            <div class="em-panel-h"><h2><i class="bi bi-clock-history"></i> How it works</h2></div>
            <div class="em-panel-b tfa-dim">
                <p>After your password, we ask for a 6-digit code that changes every 30 seconds. Only your phone can produce it.</p>
                <p>Recovery codes are your backup if you lose the phone — each works once. Keep them offline.</p>
                <p>We store the app secret <strong>encrypted</strong>, so a database leak alone can't reproduce your codes.</p>
            </div>
        </section>
    </div>
</div>

<style>
    .tfa-key { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:1.25rem; letter-spacing:.06em;
        background:#16100e; border:1px solid #332420; border-radius:12px; padding:.7rem .9rem; margin:.4rem 0 .7rem;
        word-break:break-all; color:#f7ece8; }
    .tfa-codes { display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr)); gap:.5rem; margin-top:.6rem; }
    .tfa-codes code { background:#16100e; border:1px solid #332420; border-radius:8px; padding:.5rem .6rem;
        text-align:center; font-size:1rem; letter-spacing:.05em; color:#f7ece8; }
    .tfa-inline { display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; }
    .tfa-inline .form-control { max-width:220px; }
    .tfa-inline .em-btn { white-space:nowrap; }
    .tfa-dim { color:#a4897f; font-size:.9rem; }
    .tfa-sep { border:0; border-top:1px solid #2a201c; margin:1.1rem 0; }
    .em-btn.is-danger { background:#f87171; color:#1a0f0c; border-color:#f87171; }
    @media (max-width:520px){ .tfa-inline .form-control{ max-width:none; width:100%; } }
</style>

<script>
(function () {
    function copyText(text, btn, doneLabel) {
        var reset = btn.innerHTML;
        var ok = function () {
            btn.innerHTML = '<i class="bi bi-clipboard-check me-1"></i>' + doneLabel;
            setTimeout(function () { btn.innerHTML = reset; }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(ok, function () {});
        } else {
            var ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); ok(); } catch (e) {}
            document.body.removeChild(ta);
        }
    }
    var ck = document.getElementById('copyKey');
    if (ck) ck.addEventListener('click', function () { copyText(ck.getAttribute('data-secret'), ck, 'Copied'); });

    var cc = document.getElementById('copyCodes');
    if (cc) cc.addEventListener('click', function () {
        var codes = Array.prototype.map.call(document.querySelectorAll('.tfa-codes code'), function (e) { return e.textContent; }).join('\n');
        copyText(codes, cc, 'Copied');
    });
})();
</script>
<?= $this->endSection() ?>
