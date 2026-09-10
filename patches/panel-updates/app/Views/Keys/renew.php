<?= $this->extend('Layout/Starter') ?>

<?= $this->section('content') ?>

<?= $this->include('Layout/msgStatus') ?>

<header class="em-head em-rise">
    <div class="em-head-main">
        <span class="em-eyebrow"><i class="bi bi-arrow-clockwise"></i> Admin</span>
        <h1>Renew keys</h1>
        <p>
            Look a key up by its code or ID, see whether it is unused, still
            valid, or expired, then add time. A valid key is <b>extended</b> from
            its current expiry; an expired one is <b>restarted</b> from now and
            switched back on; an unused key gets a longer <b>duration</b> for when
            it is first activated.
        </p>
    </div>
</header>

<div class="rn-wrap">
    <!-- Look-up -->
    <section class="em-panel rn-lookup">
        <div class="em-panel-h">
            <h2><i class="bi bi-search"></i> Find a key</h2>
        </div>
        <div class="em-panel-b">
            <form id="rn-find" autocomplete="off">
                <div class="rn-find-row">
                    <div class="em-field">
                        <label for="rn-q" class="visually-hidden">Key code or ID</label>
                        <input type="text" class="form-control" id="rn-q" name="q"
                               placeholder="Key code (e.g. CODM_ab12cd34) or numeric ID"
                               maxlength="96" autocapitalize="none" spellcheck="false" inputmode="text" required>
                    </div>
                    <button type="submit" class="em-btn is-primary" id="rn-find-btn">
                        <i class="bi bi-search"></i> Find
                    </button>
                </div>
                <p class="rn-msg" id="rn-find-msg" role="status" aria-live="polite"></p>
            </form>
        </div>
    </section>

    <!-- Result + renew (hidden until a key is found) -->
    <section class="em-card rn-result" id="rn-result" hidden aria-live="polite">
        <div class="rn-key-head">
            <div class="rn-key-id">
                <code class="rn-code" id="rn-code">—</code>
                <span class="em-pill" id="rn-game">—</span>
            </div>
            <span class="rn-state" id="rn-state">—</span>
        </div>

        <dl class="rn-facts">
            <div><dt>Time left</dt><dd id="rn-remaining">—</dd></div>
            <div><dt>Expires</dt><dd id="rn-expires">—</dd></div>
            <div><dt>Duration</dt><dd id="rn-duration">—</dd></div>
            <div><dt>Devices</dt><dd id="rn-devices">—</dd></div>
        </dl>

        <div class="rn-renew">
            <h3>Add time</h3>

            <div class="rn-presets" id="rn-presets" role="group" aria-label="Preset amounts">
                <?php foreach ($presets as $p) : ?>
                    <button type="button" class="em-chip" data-hours="<?= (int) $p['h'] ?>">
                        <?= esc($p['label']) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="rn-custom">
                <div class="em-field rn-amt">
                    <label for="rn-amount">Custom amount</label>
                    <input type="number" class="form-control" id="rn-amount" min="1" max="87600" step="1" placeholder="e.g. 45">
                </div>
                <div class="em-field rn-unit">
                    <label for="rn-unit">Unit</label>
                    <select class="form-select" id="rn-unit">
                        <option value="days" selected>Days</option>
                        <option value="hours">Hours</option>
                    </select>
                </div>
            </div>

            <label class="rn-check" id="rn-reactivate-wrap">
                <input type="checkbox" id="rn-reactivate" checked>
                <span>Re-activate the key (turn its status on)</span>
            </label>

            <div class="rn-apply-row">
                <button type="button" class="em-btn is-primary" id="rn-apply" disabled>
                    <i class="bi bi-arrow-clockwise"></i> Renew key
                </button>
                <p class="rn-msg" id="rn-apply-msg" role="status" aria-live="polite"></p>
            </div>
        </div>
    </section>
</div>

<?= $this->endSection() ?>

<?= $this->section('css') ?>
<style>
    .rn-wrap { max-width: 760px; }
    .rn-lookup { margin-bottom: 1rem; }
    .rn-find-row { display: flex; gap: .6rem; align-items: flex-end; }
    .rn-find-row .em-field { flex: 1; margin: 0; }
    .rn-find-row .em-btn { min-height: 44px; white-space: nowrap; }

    .rn-msg { margin: .55rem 0 0; font-size: .85rem; min-height: 1.1em; }
    .rn-msg.is-err { color: var(--em-bad); }
    .rn-msg.is-ok  { color: var(--em-ok); }

    .rn-result { padding: 1.1rem 1.15rem; }
    .rn-key-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; }
    .rn-key-id { display: flex; align-items: center; gap: .55rem; flex-wrap: wrap; min-width: 0; }
    .rn-code {
        font-family: var(--em-mono, ui-monospace, monospace);
        font-size: .95rem; font-weight: 600; color: var(--em-text);
        background: var(--em-surface-3); border: 1px solid var(--em-line);
        padding: .2em .5em; border-radius: 8px; overflow-wrap: anywhere;
    }

    /* state badge */
    .rn-state {
        font-size: .72rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
        padding: .3em .7em; border-radius: 999px; border: 1px solid transparent; white-space: nowrap;
    }
    .rn-state.is-active  { color: var(--em-ok);     background: var(--em-ok-wash);  border-color: color-mix(in srgb, var(--em-ok) 35%, transparent); }
    .rn-state.is-expired { color: var(--em-bad);    background: var(--em-bad-wash); border-color: color-mix(in srgb, var(--em-bad) 35%, transparent); }
    .rn-state.is-unused  { color: var(--em-accent); background: var(--em-wash);     border-color: color-mix(in srgb, var(--em-accent) 35%, transparent); }
    .rn-state.is-blocked { color: var(--em-bad);    background: var(--em-bad-wash); border-color: color-mix(in srgb, var(--em-bad) 35%, transparent); }

    .rn-facts {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: .2rem 1rem; margin: 1rem 0 0; padding: 1rem 0 0; border-top: 1px solid var(--em-line);
    }
    .rn-facts > div { display: flex; flex-direction: column; padding: .35rem 0; }
    .rn-facts dt { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: var(--em-dim); margin: 0 0 .15rem; }
    .rn-facts dd { margin: 0; font-weight: 600; color: var(--em-text); overflow-wrap: anywhere; }

    .rn-renew { margin-top: 1.1rem; padding-top: 1.1rem; border-top: 1px solid var(--em-line); }
    .rn-renew h3 { font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; color: var(--em-dim); margin: 0 0 .7rem; }

    .rn-presets { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .9rem; }
    .em-chip {
        font: inherit; font-size: .85rem; font-weight: 600; cursor: pointer;
        color: var(--em-text-2); background: var(--em-surface-2);
        border: 1px solid var(--em-line); border-radius: 999px;
        padding: .48em 1em; min-height: 40px; transition: border-color .12s, color .12s, background .12s;
    }
    .em-chip:hover { color: var(--em-text); border-color: var(--em-line-2); }
    .em-chip.is-on {
        color: var(--em-accent); background: var(--em-wash);
        border-color: color-mix(in srgb, var(--em-accent) 45%, transparent);
    }
    .em-chip:focus-visible { outline: 2px solid var(--em-accent); outline-offset: 2px; }

    .rn-custom { display: flex; gap: .7rem; margin-bottom: .8rem; }
    .rn-custom .rn-amt { flex: 1; margin: 0; }
    .rn-custom .rn-unit { width: 130px; margin: 0; }

    .rn-check { display: flex; align-items: center; gap: .5rem; margin: 0 0 1rem; font-size: .9rem; color: var(--em-text-2); cursor: pointer; }
    .rn-check input { width: 18px; height: 18px; accent-color: var(--em-accent); }

    .rn-apply-row { display: flex; align-items: center; gap: .9rem; flex-wrap: wrap; }
    .rn-apply-row .em-btn { min-height: 44px; }
    .rn-apply-row .rn-msg { margin: 0; }

    .visually-hidden {
        position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
        overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
    }

    @media (max-width: 520px) {
        .rn-find-row { flex-direction: column; align-items: stretch; }
        .rn-find-row .em-btn { width: 100%; }
        .rn-custom { flex-direction: column; }
        .rn-custom .rn-unit { width: 100%; }
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('js') ?>
<script>
(function () {
    var findForm = document.getElementById('rn-find');
    var qInput   = document.getElementById('rn-q');
    var findBtn  = document.getElementById('rn-find-btn');
    var findMsg  = document.getElementById('rn-find-msg');
    var result   = document.getElementById('rn-result');
    var applyBtn = document.getElementById('rn-apply');
    var applyMsg = document.getElementById('rn-apply-msg');
    var amountEl = document.getElementById('rn-amount');
    var unitEl   = document.getElementById('rn-unit');
    var reactEl  = document.getElementById('rn-reactivate');
    var reactWrap= document.getElementById('rn-reactivate-wrap');
    var presets  = document.getElementById('rn-presets');

    var URL_LOOKUP = <?= json_encode(site_url('admin/keys/renew/lookup'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var URL_APPLY  = <?= json_encode(site_url('admin/keys/renew/apply'),  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    var current = null;           // the loaded key object
    var chosenHours = 0;          // preset selection (0 = use custom field)

    function csrf() {
        var n = document.querySelector('meta[name="csrf-name"]');
        var h = document.querySelector('meta[name="csrf-hash"]');
        var o = {};
        if (n && h) { o[n.getAttribute('content')] = h.getAttribute('content'); }
        return o;
    }
    function refreshCsrf(hash) {
        var h = document.querySelector('meta[name="csrf-hash"]');
        if (h && hash) { h.setAttribute('content', hash); }
    }
    function body(obj) {
        var b = new URLSearchParams();
        var c = csrf(); for (var k in c) { b.set(k, c[k]); }
        for (var k2 in obj) { if (obj[k2] !== undefined && obj[k2] !== null) b.set(k2, obj[k2]); }
        return b.toString();
    }
    function say(el, text, cls) { el.textContent = text || ''; el.className = 'rn-msg' + (cls ? ' ' + cls : ''); }
    function esc(s) { return String(s == null ? '' : s); }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: body(data)
        }).then(function (r) { return r.json().catch(function () { return {}; }); });
    }

    var STATE_LABEL = { active: 'Active', expired: 'Expired', unused: 'Unused' };

    function render(k) {
        current = k;
        document.getElementById('rn-code').textContent = k.user_key + '  ·  #' + k.id;
        document.getElementById('rn-game').textContent = k.game || '—';

        var stateEl = document.getElementById('rn-state');
        var stateCls = k.state, stateTxt = STATE_LABEL[k.state] || k.state;
        if (k.blocked && k.state !== 'expired') { stateCls = 'blocked'; stateTxt = 'Blocked'; }
        stateEl.className = 'rn-state is-' + stateCls;
        stateEl.textContent = stateTxt;

        document.getElementById('rn-remaining').textContent =
            k.state === 'active' ? (k.remaining || '—')
            : k.state === 'unused' ? 'Not started yet'
            : 'None (expired)';
        document.getElementById('rn-expires').textContent  = k.expired_date ? k.expired_date : '—';
        document.getElementById('rn-duration').textContent = k.duration + ' h' + (k.duration % 24 === 0 && k.duration > 0 ? '  (' + (k.duration / 24) + ' d)' : '');
        document.getElementById('rn-devices').textContent  = k.devices_used + ' / ' + (k.max_devices === 0 ? '∞' : k.max_devices);

        // The re-activate toggle only matters for a blocked/expired key.
        var needsReact = k.blocked || k.state === 'expired';
        reactWrap.style.display = needsReact ? '' : 'none';
        reactEl.checked = needsReact;

        result.hidden = false;
        updateApplyState();
    }

    function selectedHours() {
        if (chosenHours > 0) { return chosenHours; }
        var v = parseInt(amountEl.value, 10);
        if (!v || v < 1) { return 0; }
        return unitEl.value === 'days' ? v * 24 : v;
    }
    function updateApplyState() {
        applyBtn.disabled = !(current && selectedHours() > 0);
    }

    // --- look-up ---
    findForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var q = (qInput.value || '').trim();
        if (!q) { say(findMsg, 'Enter a key or ID.', 'is-err'); return; }
        findBtn.disabled = true; say(findMsg, 'Searching…');
        result.hidden = true; current = null;
        post(URL_LOOKUP, { q: q }).then(function (j) {
            findBtn.disabled = false;
            if (j && j.csrf) refreshCsrf(j.csrf);
            if (j && j.ok && j.key) { say(findMsg, ''); render(j.key); }
            else { say(findMsg, (j && j.error) ? j.error : 'Not found.', 'is-err'); }
        }).catch(function () { findBtn.disabled = false; say(findMsg, 'Network error. Try again.', 'is-err'); });
    });

    // --- presets ---
    presets.addEventListener('click', function (e) {
        var b = e.target.closest('.em-chip'); if (!b) return;
        var on = b.classList.contains('is-on');
        presets.querySelectorAll('.em-chip').forEach(function (c) { c.classList.remove('is-on'); });
        if (on) { chosenHours = 0; }
        else { b.classList.add('is-on'); chosenHours = parseInt(b.dataset.hours, 10) || 0; amountEl.value = ''; }
        say(applyMsg, '');
        updateApplyState();
    });
    // Typing a custom amount clears any preset.
    amountEl.addEventListener('input', function () {
        if (amountEl.value) { presets.querySelectorAll('.em-chip').forEach(function (c) { c.classList.remove('is-on'); }); chosenHours = 0; }
        updateApplyState();
    });
    unitEl.addEventListener('change', updateApplyState);

    // --- apply ---
    applyBtn.addEventListener('click', function () {
        if (!current) return;
        var hours = selectedHours();
        if (hours < 1) { say(applyMsg, 'Pick a preset or type an amount.', 'is-err'); return; }
        applyBtn.disabled = true; say(applyMsg, 'Renewing…');
        post(URL_APPLY, {
            id_keys: current.id,
            amount: hours,
            unit: 'hours',
            reactivate: reactEl.checked ? 1 : 0
        }).then(function (j) {
            if (j && j.csrf) refreshCsrf(j.csrf);
            if (j && j.ok) {
                say(applyMsg, j.message || 'Renewed.', 'is-ok');
                chosenHours = 0; amountEl.value = '';
                presets.querySelectorAll('.em-chip').forEach(function (c) { c.classList.remove('is-on'); });
                render(j.key);                 // refresh the card with the new state
                say(applyMsg, j.message || 'Renewed.', 'is-ok');
            } else {
                applyBtn.disabled = false;
                say(applyMsg, (j && j.error) ? j.error : 'Could not renew.', 'is-err');
            }
        }).catch(function () { applyBtn.disabled = false; say(applyMsg, 'Network error. Try again.', 'is-err'); });
    });
})();
</script>
<?= $this->endSection() ?>
