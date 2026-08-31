<?= $this->extend('Layout/Starter') ?>

<?= $this->section('content') ?>

<?= $this->include('Layout/msgStatus') ?>

<header class="em-head em-rise">
    <div class="em-head-main">
        <span class="em-eyebrow"><i class="bi bi-gear"></i> Settings</span>
        <h1>Your settings</h1>
        <p>Change your password or the name shown next to your account.</p>
    </div>
    <div class="em-head-act">
        <a class="em-btn" href="<?= site_url('settings/2fa') ?>">
            <i class="bi bi-shield-lock"></i> Two-factor
        </a>
        <a class="em-btn" href="<?= site_url('account') ?>">
            <i class="bi bi-person-badge"></i> My account
        </a>
    </div>
</header>

<div class="row g-3">
    <div class="col-lg-6">
        <section class="em-panel em-rise em-rise-1">
            <div class="em-panel-h">
                <h2><i class="bi bi-shield-lock"></i> Change password</h2>
            </div>
            <div class="em-panel-b">
                <?= form_open() ?>

                <input type="hidden" name="password_form" value="1">
                <div class="form-group mb-2">
                    <label for="current">Current Password</label>
                    <input type="password" name="current" id="current" class="form-control mt-2" placeholder="Current Password">
                    <?php if ($validation->hasError('current')) : ?>
                        <small id="help-current" class="text-danger"><?= esc($validation->getError('current')) ?></small>
                    <?php endif; ?>
                </div>
                <div class="form-group mb-2">
                    <label for="password">New Password</label>
                    <input type="password" name="password" id="password" class="form-control mt-2" placeholder="New Password" aria-describedby="help-password">
                    <?php if ($validation->hasError('password')) : ?>
                        <small id="help-password" class="text-danger"><?= esc($validation->getError('password')) ?></small>
                    <?php endif; ?>
                </div>
                <div class="form-group mb-2">
                    <label for="password2">Confirm Password</label>
                    <input type="password" name="password2" id="password2" class="form-control mt-2" placeholder="Password" aria-describedby="help-password2">
                    <?php if ($validation->hasError('password2')) : ?>
                        <small id="help-password2" class="text-danger"><?= esc($validation->getError('password2')) ?></small>
                    <?php endif; ?>
                </div>
                <div class="form-group my-2">
                    <button type="submit" class="em-btn is-primary">
                        <i class="bi bi-key me-1"></i>Change Password
                    </button>
                </div>
                <?= form_close() ?>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="em-panel em-rise em-rise-2">
            <div class="em-panel-h">
                <h2><i class="bi bi-person-lines-fill"></i> Account information</h2>
            </div>
            <div class="em-panel-b">
                <?= form_open() ?>

                <input type="hidden" name="fullname_form" value="1">
                <div class="form-group mb-3">
                    <label for="fullname">Full Name</label>
                    <input type="text" name="fullname" id="fullname" class="form-control mt-2" placeholder="Maru-kun" aria-describedby="help-fullname" value="<?= old('fullname') ?: ($user->fullname ?: '') ?>">
                    <?php if ($validation->hasError('fullname')) : ?>
                        <small id="help-fullname" class="text-danger"><?= esc($validation->getError('fullname')) ?></small>
                    <?php endif; ?>
                </div>
                <div class="form-group my-2">
                    <button type="submit" class="em-btn is-primary">
                        <i class="bi bi-check2-circle me-1"></i>Update Account
                    </button>
                </div>
                <?= form_close() ?>
            </div>
        </section>
    </div>
</div>

<?= $this->include('User/_signins') ?>

<?= $this->endSection() ?>

<?= $this->section('css') ?>
<style>
    .em-note { color: var(--em-text-2); font-size: .86rem; margin: 0 0 .9rem; }

    .em-sess td { vertical-align: top; }
    .em-sess small { display: block; margin-top: 2px; font-size: .72rem; color: var(--em-dim); }
    .em-sess-time b { font-family: var(--em-mono); font-size: .84rem; }
    .em-sess-time small { font-family: var(--em-mono); }

    /* A user agent is one long unbroken string. Left alone it forces the
       table wider than the screen; clamped it stays one line and the whole
       value is still on the title attribute. */
    .em-ua {
        max-width: 34ch; overflow: hidden;
        text-overflow: ellipsis; white-space: nowrap;
    }

    .em-sess-current { background: var(--em-wash); }
    .em-sess .em-pill { margin-left: .4rem; }

    @media (max-width: 700px) {
        /* The user agent and the sign-out time are the two that can wait for
           a wider screen; the device, the time you signed in and the state
           are what the row is for - with the user agent left in, the state
           pill was pushed off the right edge of a phone.

           Both selectors are qualified with .em-sess deliberately. A media
           query adds no specificity, so a bare `.em-ua` loses to the
           `.em-sess small` rule above it and the row stays visible. */
        .em-sess .em-ua      { display: none; }
        .em-sess .em-sess-out { display: none; }

        /* Tighten the cells so the remaining columns fit the width of a
           phone instead of scrolling sideways inside the panel.

           Qualified with the table's own class on purpose. ember.css sets
           `.em-panel .table tbody td { padding: ... !important }` at this
           width, and the page's <style> is rendered BEFORE ember.css (see
           Layout/Starter.php), so anything less specific loses the tie and
           silently changes nothing. */
        .em-panel .table.em-sess thead th,
        .em-panel .table.em-sess tbody td { padding: .5rem .4rem !important; }
    }

    /* A 375px phone is 16px short of fitting the admin table's five columns.
       Trimming the state pill rather than dropping a column keeps every
       piece of the row on screen. */
    @media (max-width: 480px) {
        .em-panel .table.em-sess .em-pill {
            padding: .18rem .38rem;
            font-size: .7rem;
        }
        .em-sess .em-sess-time b { font-size: .78rem; }
        /* The header, not the values, sets the floor on these columns:
           "SIGNED IN" at ember.css's tracking is wider than 23:44:35. */
        .em-panel .table.em-sess thead th { letter-spacing: .05em; }
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('css') ?>
<style>
    .em-signin-bar { display:flex; justify-content:flex-end; margin:0 0 .4rem; }
    .em-sess-ip .em-ip { font-family:var(--em-mono); font-size:.8rem; }
    .em-ip.is-hidden { filter:blur(5px); user-select:none; }
    #sign-ins-wrap.is-loading { opacity:.5; transition:opacity .1s; }
    @media (max-width:820px){ .em-sess-ip{ display:none } }
</style>
<?= $this->endSection() ?>

<?= $this->section('js') ?>
<script>
    // Sign-ins: page and resize without a reload, and mask the IPs behind a
    // toggle like the keys list. All delegated, because the whole block is
    // swapped out when a pager link is followed.
    (function () {
        var wrap = function () { return document.getElementById('sign-ins-wrap'); };
        var ipHidden = true;

        function paintIp() {
            document.querySelectorAll('#sign-ins-wrap .sessIp').forEach(function (el) {
                el.classList.toggle('is-hidden', ipHidden);
            });
            var btn = document.getElementById('ip-toggle');
            if (btn) {
                btn.setAttribute('aria-pressed', String(!ipHidden));
                btn.innerHTML = ipHidden
                    ? '<i class="bi bi-eye-slash"></i> <span class="em-blur-label">IP hidden</span>'
                    : '<i class="bi bi-eye"></i> <span class="em-blur-label">IP shown</span>';
            }
        }

        function load(href) {
            var w = wrap();
            if (!w) return;
            w.classList.add('is-loading');
            var url = href.split('#')[0];
            url += (url.indexOf('?') > -1 ? '&' : '?') + 'frag=1';
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    var fresh = tmp.querySelector('#sign-ins-wrap');
                    if (fresh) {
                        w.replaceWith(fresh);
                        try { history.replaceState(null, '', href); } catch (e) {}
                        paintIp();
                    } else { w.classList.remove('is-loading'); }
                })
                .catch(function () { w.classList.remove('is-loading'); });
        }

        // Delegated click handling on the document, filtered to the wrap.
        document.addEventListener('click', function (e) {
            var w = wrap();
            if (!w) return;
            var t = e.target.closest('#sign-ins-wrap #ip-toggle');
            if (t) { ipHidden = !ipHidden; paintIp(); return; }
            var a = e.target.closest('#sign-ins-wrap .em-pager a[href]');
            if (a) { e.preventDefault(); load(a.getAttribute('href')); }
        });

        paintIp();
    })();
</script>
<?= $this->endSection() ?>
