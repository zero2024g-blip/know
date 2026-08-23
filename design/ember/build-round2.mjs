import { writeFileSync } from 'fs';
const OUT = new URL('./', import.meta.url).pathname;

const P = {
  bolt:'<path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/>',
  check:'<path d="M20 6 9 17l-5-5"/>',
  x:'<path d="M18 6 6 18M6 6l12 12"/>',
  alert:'<circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/>',
  tri:'<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4m0 4h.01"/>',
  search:'<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  key:'<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2m-4 4 3 3m-6-6 3 3"/>',
  plus:'<path d="M12 5v14M5 12h14"/>',
  clock:'<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  trash:'<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
  copy:'<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
  shield:'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
  lock:'<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
  share:'<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
  phone:'<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
  desktop:'<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8m-4-4v4"/>',
  down:'<path d="M12 3v13m0 0 5-5m-5 5-5-5M4 21h16"/>',
  wallet:'<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5"/><path d="M17 13h.01"/>',
  list:'<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
  filter:'<path d="M3 4h18l-7 8v7l-4 2v-9z"/>',
  undo:'<path d="M3 7v6h6"/><path d="M3.5 13a9 9 0 1 0 2.1-9.4L3 7"/>',
  eye:'<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
  user:'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  cal:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
  zap:'<path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/>',
};
const ic = n => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${P[n]}</svg>`;
const icf = n => `<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">${P[n]}</svg>`;

function qr(seed0=7){
  let s=''; const N=21; let seed=seed0;
  const rnd=()=>{ seed=(seed*1103515245+12345)&0x7fffffff; return seed/0x7fffffff; };
  const f=(x,y)=>`<rect x="${x}" y="${y}" width="7" height="7" fill="#120D0B"/><rect x="${x+1}" y="${y+1}" width="5" height="5" fill="#F7ECE8"/><rect x="${x+2}" y="${y+2}" width="3" height="3" fill="#120D0B"/>`;
  for(let y=0;y<N;y++)for(let x=0;x<N;x++){
    if((x<8&&y<8)||(x>N-9&&y<8)||(x<8&&y>N-9)) continue;
    if(rnd()>0.52) s+=`<rect x="${x}" y="${y}" width="1" height="1" fill="#120D0B"/>`;
  }
  return `<svg viewBox="0 0 21 21" shape-rendering="crispEdges">${s}${f(0,0)}${f(14,0)}${f(0,14)}</svg>`;
}

const sec = (n, title, lede, inner) => `
<section class="demo" id="s${n}">
  <div class="demo-lbl"><span class="n">${n}</span><h2>${title}</h2>${lede?`<p>${lede}</p>`:''}</div>
  ${inner}
</section>`;

/* ===================== 04 · BULK ACTIONS ===================== */
const bulk = sec('04','Bulk actions — admin only',
  'Adjustable hours, an explicit confirmation, and invisible to resellers.',`
<div style="display:flex;gap:var(--s3);flex-wrap:wrap;margin-bottom:var(--s4)">
  <span class="rolebar">${ic('shield')}Visible to Administrator</span>
  <span class="rolebar seller">${ic('user')}Hidden from Reseller and Member</span>
</div>

<div class="bulk2" style="margin-bottom:var(--s4)">
  <div class="bulk2-top">
    <span class="cnt"><b>12</b> keys selected</span>
    <button class="clr hit">Clear selection</button>
    <div class="right"><span class="pill pill-info"><i></i>Admin action</span></div>
  </div>
  <div class="bulk2-body">
    <div class="fgroup">
      <label>Extend by</label>
      <div class="hours">
        <span class="hour-opt">24 h</span>
        <span class="hour-opt">72 h</span>
        <span class="hour-opt">168 h</span>
        <span class="hour-opt on">720 h</span>
        <span class="hour-opt">2160 h</span>
      </div>
    </div>
    <div class="fgroup">
      <label>Or exact hours</label>
      <div class="hour-custom">
        <input type="number" value="720" min="1" max="8760" aria-label="Custom hours">
        <span class="u">= 30 days</span>
      </div>
    </div>
  </div>
  <div class="bulk2-foot">
    <span class="prev">Applies to <b>12</b> keys · new expiry <b>13 Oct 2026</b> · latest becomes <b>02 Dec 2026</b></span>
    <div class="acts">
      <button class="btn btn-ghost btn-sm">${ic('copy')}Export CSV</button>
      <button class="btn btn-danger btn-sm">${ic('trash')}Revoke</button>
      <button class="btn btn-primary btn-sm">${ic('clock')}Extend 12 keys</button>
    </div>
  </div>
</div>

<div class="two">
  <div>
    <div class="scrim">
      <div class="dialog">
        <div class="dialog-h">
          <span class="dialog-ic warn">${ic('tri')}</span>
          <div><h3>Extend 12 keys by 720 hours?</h3><p>This changes live keys that buyers are already using.</p></div>
        </div>
        <div class="dialog-b">
          <dl class="recap">
            <div><dt>Keys affected</dt><dd>12</dd></div>
            <div><dt>Extension</dt><dd class="hot">+720 h (30 days)</dd></div>
            <div><dt>Games</dt><dd>PUBG M, CODM, FF</dd></div>
            <div><dt>Earliest new expiry</dt><dd>13 Oct 2026</dd></div>
            <div><dt>Acting as</dt><dd>zeroadmin</dd></div>
          </dl>
          <div class="typebox">
            <label>Type <b>EXTEND 12</b> to confirm</label>
            <input class="control" type="text" value="EXTEND 12" aria-label="Confirmation phrase">
          </div>
        </div>
        <div class="dialog-f">
          <button class="btn btn-ghost">Cancel</button>
          <button class="btn btn-primary">${ic('check')}Yes, extend</button>
        </div>
      </div>
    </div>
    <p class="note"><b>Why type-to-confirm.</b> A plain OK button gets clicked by reflex. Typing the count forces you to read it, and makes an accidental bulk change on 1,284 live keys effectively impossible.</p>
  </div>

  <div>
    <div class="scrim">
      <div class="dialog">
        <div class="dialog-h">
          <span class="dialog-ic">${ic('tri')}</span>
          <div><h3>Revoke 12 keys?</h3><p>Buyers lose access immediately. This cannot be undone.</p></div>
        </div>
        <div class="dialog-b">
          <dl class="recap">
            <div><dt>Keys affected</dt><dd>12</dd></div>
            <div><dt>Still active</dt><dd class="hot">9 of 12</dd></div>
            <div><dt>Devices cut off</dt><dd>19</dd></div>
            <div><dt>Refund to balance</dt><dd>$0.00</dd></div>
          </dl>
          <div class="typebox">
            <label>Type <b>REVOKE 12</b> to confirm</label>
            <input class="control" type="text" placeholder="REVOKE 12" aria-label="Confirmation phrase">
          </div>
        </div>
        <div class="dialog-f">
          <button class="btn btn-ghost">Cancel</button>
          <button class="btn btn-danger" aria-disabled="true">${ic('trash')}Revoke</button>
        </div>
      </div>
    </div>
    <p class="note"><b>The destructive button stays disabled</b> until the phrase matches. It also tells you 9 of the 12 are still live and 19 devices get cut — the consequence, not just the count.</p>
  </div>
</div>

<div class="gate" style="margin-top:var(--s4)">
  ${ic('lock')}
  <div><b>What a reseller sees instead</b>
  No checkboxes in the table, no bulk bar, and <code style="font-family:var(--mono);font-size:12px">POST /keys/bulk</code> rejects them server-side. Hiding the control is presentation; the server check is the actual protection — a hidden button is still reachable with curl.</div>
</div>`);

/* ===================== 06 · SKELETON ===================== */
const skeleton = sec('06','Skeleton rows','Confirmed in. One detail matters more than the look.',`
<div class="two">
  <div class="card"><div class="card-h"><h2>${ic('list')}Loading state</h2></div><div class="card-b">
    <div class="skel-row"><span class="skel av"></span><span class="skel l1"></span><span class="skel l2"></span><span class="skel pil"></span></div>
    <div class="skel-row"><span class="skel av"></span><span class="skel l1"></span><span class="skel l2"></span><span class="skel pil"></span></div>
    <div class="skel-row"><span class="skel av"></span><span class="skel l1"></span><span class="skel l2"></span><span class="skel pil"></span></div>
    <div class="skel-row"><span class="skel av"></span><span class="skel l1"></span><span class="skel l3"></span><span class="skel pil"></span></div>
  </div></div>
  <div>
    <p class="note" style="margin-top:0"><b>Delay it by 200 ms.</b> If the server answers in 80 ms — which it usually will — a skeleton that appears instantly just flashes, and a flash reads as slower than showing nothing. Show it only once the wait passes 200 ms, then keep it up for at least 400 ms so it never blinks out mid-appearance.</p>
    <p class="note"><b>Match the real row.</b> The skeleton uses the same row height, avatar size and column positions as a loaded row, so nothing jumps when data arrives. Different heights cause layout shift, which is the thing that actually feels broken.</p>
    <p class="note"><b>Replaces <code style="font-family:var(--mono);font-size:12px">processing: true</code></b> in the DataTables config, which currently prints the word "Processing…" over the table.</p>
  </div>
</div>`);

/* ===================== 03 · SHARE & REDEEM ===================== */
const share = sec('03','Share sheet with QR — the full flow',
  'What the buyer actually does after you send a QR or a link.',`
<div class="flowrow">

  <div>
    <div class="phone"><div class="phone-screen">
      <div class="claim">
        <div class="claim-brand">${icf('bolt')}ZERO</div>
        <h4>Send this key</h4>
        <p class="who">PUBG Mobile · 720 h · 3 devices</p>
        <div class="qr" style="margin:0 auto var(--s3)">${qr(7)}</div>
        <div class="urlbar" style="margin-bottom:var(--s3)">
          <span class="sc">zeromods.id/k/</span><span class="tok">7f3a9c2e</span>
          <span class="cp">${ic('copy')}</span>
        </div>
        <div class="fgroup" style="display:flex;flex-direction:column;gap:6px;margin-bottom:var(--s3)">
          <label style="font-family:var(--mono);font-size:9.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--dim)">Link expires</label>
          <div class="hours">
            <span class="hour-opt" style="min-width:0;flex:1;font-size:11.5px;min-height:32px">1 h</span>
            <span class="hour-opt on" style="min-width:0;flex:1;font-size:11.5px;min-height:32px">24 h</span>
            <span class="hour-opt" style="min-width:0;flex:1;font-size:11.5px;min-height:32px">7 d</span>
          </div>
        </div>
        <label class="check on" style="margin-bottom:var(--s3)"><span class="box">${ic('check')}</span><span style="font-size:12px">Single use — dies after first open</span></label>
        <div class="claim-cta"><span class="btn btn-primary btn-block">${ic('share')}Copy link</span></div>
      </div>
    </div></div>
    <p class="phone-cap">1 · you share</p>
  </div>

  <div>
    <div class="phone"><div class="phone-screen">
      <div class="claim">
        <div class="claim-brand">${icf('bolt')}ZERO</div>
        <h4>Your key is ready</h4>
        <p class="who">Sent by <b style="color:var(--text-2)">reseller_id</b></p>
        <div class="claim-key">
          <div class="lbl">Your licence key</div>
          <div class="val">ZM-8QK4-77TC-A19F</div>
        </div>
        <div class="claim-meta">
          <span>PUBG Mobile</span><span>720 h</span><span>3 devices</span>
        </div>
        <ol class="steps">
          <li><span class="sn">1</span><span>Tap <b style="color:var(--text)">Copy key</b> below.</span></li>
          <li><span class="sn">2</span><span>Open the ZERO loader on this phone.</span></li>
          <li><span class="sn">3</span><span>Paste into the key box and tap Activate.</span></li>
        </ol>
        <div class="claim-cta">
          <span class="btn btn-primary btn-block">${ic('copy')}Copy key</span>
          <span class="btn btn-ghost btn-block">${ic('down')}Open in loader</span>
        </div>
        <p class="claim-exp">Link expires in 23 h 41 m</p>
      </div>
    </div></div>
    <p class="phone-cap">2 · buyer opens it</p>
  </div>

  <div>
    <div class="phone"><div class="phone-screen">
      <div class="deadstate">
        <span class="di">${ic('alert')}</span>
        <h4>This link has already been used</h4>
        <p>It was opened on 23 Aug at 14:12. For security a single-use link only works once.</p>
        <p style="margin-top:var(--s4);font-size:11.5px;color:var(--text-2)">Ask <b>reseller_id</b> for a new link.</p>
      </div>
    </div></div>
    <p class="phone-cap">3 · used or expired</p>
  </div>

</div>

<div class="two" style="margin-top:var(--s4)">
  <div>
    <p class="note" style="margin-top:0"><b>The QR holds a URL, not the key.</b> It encodes <code style="font-family:var(--mono);font-size:12px">https://panel.zeromods.id/k/7f3a9c2e</code> — an opaque token. Any phone camera opens it; no app needed to read it.</p>
    <p class="note"><b>Why not put the key in the QR.</b> A QR containing the raw key can be screenshotted, forwarded and reused forever, and you would have no idea it happened. A token can expire, be single-use, and be revoked.</p>
    <p class="note"><b>The claim page is public.</b> No login — the buyer has no account. The token is the only credential, which is exactly why it must be short-lived.</p>
  </div>
  <div>
    <p class="note" style="margin-top:0"><b>"Open in loader" is a deep link.</b> <code style="font-family:var(--mono);font-size:12px">zeromods://activate?k=…</code> hands the key straight to your app so the buyer never types it. If the app isn't installed, the button falls back to the store page — that fallback needs building on the app side too.</p>
    <p class="note"><b>You see when it lands.</b> The key row shows <span class="pill pill-ok" style="font-size:10px"><i></i>Claimed 2 min ago</span> once opened, so a buyer saying "I never got it" is settled by looking.</p>
    <p class="note"><b>Rate limit <code style="font-family:var(--mono);font-size:12px">/k/&lt;token&gt;</code></b> by IP. Tokens are short; without a limit they can be guessed at volume.</p>
  </div>
</div>`);

/* ===================== 02 · PALETTE ===================== */
const palette = sec('02','Command palette — desktop only','',`
<div style="display:flex;gap:var(--s3);flex-wrap:wrap;margin-bottom:var(--s4)">
  <span class="rolebar">${ic('desktop')}Enabled ≥ 1024px</span>
  <span class="rolebar seller">${ic('phone')}Not loaded on phones</span>
</div>
<div class="two">
  <div class="palette">
    <div class="palette-in">${ic('search')}<span class="q">8QK4</span><span class="esc">ESC</span></div>
    <div class="palette-sec">Keys</div>
    <div class="palette-row on">${ic('key')}<span class="k">ZM-8QK4-77TC-A19F</span><span class="m">PUBG M · 22 d</span></div>
    <div class="palette-row">${ic('key')}<span class="k">ZM-QQ21-XB09-77KL</span><span class="m">PUBG M · 2 d</span></div>
    <div class="palette-sec">Actions</div>
    <div class="palette-row">${ic('plus')}<span class="k">Generate a key</span><span class="m">G then N</span></div>
    <div class="palette-row">${ic('shield')}<span class="k">Manage users</span><span class="m">admin</span></div>
  </div>
  <div>
    <p class="note" style="margin-top:0"><b>Gated on pointer, not width alone.</b> <code style="font-family:var(--mono);font-size:12px">(min-width:1024px) and (pointer:fine)</code> — a tablet with a keyboard gets it, a large phone doesn't. The script isn't even fetched below that, so phones pay nothing for a feature they can't reach.</p>
    <p class="note"><b>The bottom tab bar already covers phones.</b> Four destinations one thumb-tap away is faster than a search box you'd have to type into.</p>
    <p class="note"><b>Search stays in the toolbar too.</b> The palette is the shortcut, never the only way in — the Keys page keeps its own search field so nothing is reachable only by keyboard.</p>
  </div>
</div>`);

/* ===================== MOTION ===================== */
const motion = sec('08','Making it feel smooth',
  'The iOS feel is mostly restraint: few things move, they move on the right curve, and nothing blocks the main thread.',`
<div class="ease-demo" style="margin-bottom:var(--s4)">
  <div class="ease-row"><span class="ease-lbl">ease-out — panels, menus, page changes</span>
    <div class="ease-track"><span class="ease-dot e1"></span></div></div>
  <div class="ease-row"><span class="ease-lbl">spring — toggles, checkboxes, small confirmations</span>
    <div class="ease-track"><span class="ease-dot e2"></span></div></div>
  <div class="ease-row"><span class="ease-lbl">linear — the one that always feels mechanical</span>
    <div class="ease-track"><span class="ease-dot e3"></span></div></div>
</div>

<div class="scrollx card" style="margin-bottom:var(--s4)">
  <table class="motion-tbl">
    <thead><tr><th>What moves</th><th>Duration</th><th>Curve</th><th>Why</th></tr></thead>
    <tbody>
      <tr><td>Hover, focus</td><td>120 ms</td><td><code>ease-out</code></td><td>Must feel instant. Past ~150 ms a hover feels sticky.</td></tr>
      <tr><td>Toggle, checkbox</td><td>220 ms</td><td><code>cubic-bezier(.34,1.56,.64,1)</code></td><td>Slight overshoot reads as physical. This is the iOS switch.</td></tr>
      <tr><td>Dropdown, drawer</td><td>260 ms in / 180 ms out</td><td><code>cubic-bezier(.22,1,.36,1)</code></td><td>Leaving faster than arriving is the single biggest "feels snappy" trick.</td></tr>
      <tr><td>Dialog</td><td>240 ms</td><td><code>cubic-bezier(.22,1,.36,1)</code></td><td>Scale 0.96 → 1 with opacity. Never slide a centred dialog.</td></tr>
      <tr><td>Toast</td><td>300 ms</td><td><code>cubic-bezier(.22,1,.36,1)</code></td><td>Enters from the edge it will leave by.</td></tr>
      <tr><td>Row stagger</td><td>250 ms, 25 ms apart</td><td><code>ease-out</code></td><td>First 8 rows only — staggering 50 rows is a slideshow.</td></tr>
      <tr><td>Page change</td><td>200 ms</td><td><code>ease-out</code></td><td>Cross-fade via <code>@view-transition</code>. Falls back to no animation.</td></tr>
    </tbody>
  </table>
</div>

<div class="two">
  <div>
    <p class="note" style="margin-top:0"><b>Only <code style="font-family:var(--mono);font-size:12px">transform</code> and <code style="font-family:var(--mono);font-size:12px">opacity</code>.</b> Those two are handled by the compositor. Animating width, height, top or margin makes the browser re-run layout every frame, which is where jank on a long key table comes from.</p>
    <p class="note"><b>Cap the table at 25 rows.</b> The current DataTables setup is already server-side, which is right. The jank risk is rendering hundreds of DOM rows at once — pagination keeps the tree small.</p>
    <p class="note"><b>Debounce search by 250 ms.</b> Today every keystroke can fire a request. Debouncing turns eight requests into one and stops results flickering as you type.</p>
    <p class="note"><b>Preload the fonts.</b> Poppins currently arrives late and the page re-flows when it lands. A <code style="font-family:var(--mono);font-size:12px">preload</code> plus <code style="font-family:var(--mono);font-size:12px">font-display:swap</code> removes that jump.</p>
  </div>
  <div>
    <p class="note" style="margin-top:0"><b>Optimistic UI on copy and toggle.</b> Show the checkmark the instant it's tapped, reconcile with the server after. Waiting on a round trip to tick a switch is what makes a panel feel slow even when it's fast.</p>
    <p class="note"><b>Prefetch on hover.</b> When the cursor rests on a nav link for 65 ms, fetch that page. By the time the click lands it's usually already there.</p>
    <p class="note"><b>Self-host Bootstrap and jQuery.</b> Four CDNs (jsDelivr, cdnjs, code.jquery, fonts.googleapis) means four DNS lookups and four TLS handshakes before anything renders. Self-hosted and bundled, that's one.</p>
    <p class="note"><b>Honour reduced motion.</b> Everything above collapses to instant under <code style="font-family:var(--mono);font-size:12px">prefers-reduced-motion</code>. Already wired into the stylesheet.</p>
  </div>
</div>`);

/* ===================== NEW PROPOSALS ===================== */
const audit = `
<div class="card"><div class="card-h"><h2>${ic('list')}Activity log</h2>
  <div class="actions"><span class="pill pill-info"><i></i>Admin</span></div></div>
  <div class="card-b"><div class="audit">
    <div class="audit-row"><span class="audit-ic warn">${ic('clock')}</span><div class="audit-b">
      <div class="t"><b>zeroadmin</b> extended <code>12 keys</code> by 720 h</div>
      <div class="s">203.0.113.44 · Chrome on Windows</div></div>
      <div class="audit-end">2 min ago</div></div>
    <div class="audit-row"><span class="audit-ic ok">${ic('plus')}</span><div class="audit-b">
      <div class="t"><b>reseller_id</b> generated <code>ZM-W7YH-3NM8</code></div>
      <div class="s">198.51.100.7 · Safari on iPhone</div></div>
      <div class="audit-end">18 min ago</div></div>
    <div class="audit-row"><span class="audit-ic bad">${ic('trash')}</span><div class="audit-b">
      <div class="t"><b>zeroadmin</b> revoked <code>ZM-0RT6-KC44</code></div>
      <div class="s">203.0.113.44 · reason: chargeback</div></div>
      <div class="audit-end">1 h ago</div></div>
    <div class="audit-row"><span class="audit-ic bad">${ic('lock')}</span><div class="audit-b">
      <div class="t">4 failed sign-ins for <b>budi_x</b></div>
      <div class="s">192.0.2.19 · locked for 15 min</div></div>
      <div class="audit-end">3 h ago</div></div>
  </div></div>
</div>`;

const ledger = `
<div class="card"><div class="card-h"><h2>${ic('wallet')}Balance history</h2></div>
  <div class="card-b flush"><div class="scrollx"><table class="ledger">
    <thead><tr><th>When</th><th>What</th><th>Ref</th><th style="text-align:right">Amount</th><th style="text-align:right">Balance</th></tr></thead>
    <tbody>
      <tr><td>23 Aug 14:02</td><td>Generated CODM key</td><td class="ref">#K-15062</td><td class="amt neg">−$18.00</td><td class="bal">$248.00</td></tr>
      <tr><td>23 Aug 11:40</td><td>Generated FF key</td><td class="ref">#K-15061</td><td class="amt neg">−$6.00</td><td class="bal">$266.00</td></tr>
      <tr><td>22 Aug 19:15</td><td>Top-up</td><td class="ref">#T-0442</td><td class="amt pos">+$100.00</td><td class="bal">$272.00</td></tr>
      <tr><td>22 Aug 09:03</td><td>Refund — revoked key</td><td class="ref">#K-15044</td><td class="amt pos">+$18.00</td><td class="bal">$172.00</td></tr>
    </tbody>
  </table></div></div>
</div>`;

const proposals = sec('09','What else a panel like this needs',
  'Beyond looks — these are the gaps I would fill next.',`
<div class="two" style="margin-bottom:var(--s4)">
  ${audit}
  ${ledger}
</div>

<div class="two" style="margin-bottom:var(--s4)">
  <div class="card"><div class="card-h"><h2>${ic('filter')}Real filters</h2></div><div class="card-b">
    <div class="filters" style="margin-bottom:var(--s3)">
      <span class="fchip on">${ic('check')}Active <span class="n">1284</span></span>
      <span class="fchip">Expiring <span class="n">37</span></span>
      <span class="fchip">Expired <span class="n">402</span></span>
      <span class="fchip">Revoked <span class="n">18</span></span>
    </div>
    <div class="filters">
      <span class="fchip">${ic('key')}All games</span>
      <span class="fchip">${ic('cal')}Any date</span>
      <span class="fchip">${ic('user')}Any seller</span>
      <span class="fchip on">${ic('filter')}Saved: expiring this week</span>
    </div>
    <p class="note">Counts sit on the filter itself, so you see the size of a problem before clicking into it. Saved views mean the check you run every morning is one tap.</p>
  </div></div>

  <div class="card"><div class="card-h"><h2>${ic('shield')}Two-factor on admin accounts</h2></div><div class="card-b">
    <p style="font-size:13.5px;color:var(--text-2);margin-bottom:var(--s2)">Enter the 6-digit code from your authenticator.</p>
    <div class="otp"><span>4</span><span>9</span><span>2</span><span class="on">1</span><span class="empty">–</span><span class="empty">–</span></div>
    <p class="note" style="margin-top:0">An admin account here can revoke every key and move balance. Password-only is thin for that. Resellers can stay password-only if you'd rather not push it on everyone.</p>
  </div></div>
</div>

<div class="two">
  <div class="card"><div class="card-h"><h2>${ic('phone')}Active sessions</h2></div><div class="card-b">
    <div class="sess"><span class="sess-ic">${ic('desktop')}</span><div class="sess-b">
      <div class="t">Chrome on Windows <span class="pill pill-ok" style="margin-left:6px"><i></i>This device</span></div>
      <div class="s">203.0.113.44 · Jakarta · active now</div></div></div>
    <div class="sess"><span class="sess-ic">${ic('phone')}</span><div class="sess-b">
      <div class="t">Safari on iPhone</div>
      <div class="s">198.51.100.7 · Jakarta · 2 h ago</div></div>
      <button class="btn btn-ghost btn-sm">Sign out</button></div>
    <div class="sess"><span class="sess-ic">${ic('desktop')}</span><div class="sess-b">
      <div class="t">Firefox on Linux</div>
      <div class="s">192.0.2.88 · unknown · 6 d ago</div></div>
      <button class="btn btn-danger btn-sm">Sign out</button></div>
    <p class="note">The panel already auto-logs-out on a timer. This shows <em>where</em> you're signed in — the thing you need when an account looks compromised.</p>
  </div></div>

  <div class="card"><div class="card-h"><h2>${ic('undo')}Undo instead of another dialog</h2></div><div class="card-b">
    <div class="toast" style="border-left-color:var(--warn)">
      ${ic('trash')}
      <div style="flex:1"><div class="t">1 key revoked</div><div class="s">ZM-0RT6-KC44-91DA</div></div>
      <button class="btn btn-ghost btn-sm">${ic('undo')}Undo</button>
    </div>
    <p class="note">For single, reversible actions an undo window beats a confirm dialog — no interruption, and a mistake costs one click. Keep type-to-confirm for the bulk and irreversible ones.</p>
    <p class="note"><b>Also worth doing:</b> a revoke reason field (chargeback, abuse, mistake) so the activity log explains itself months later, and rate limiting on Check Key so the endpoint can't be used to enumerate valid keys.</p>
  </div></div>
</div>`);

const body = `
<main class="shell">
  <div class="page-head">
    <div><h1>Round two</h1><p class="sub">Your notes worked through, plus what I'd add next</p></div>
  </div>
  ${bulk}${share}${skeleton}${palette}${motion}${proposals}
</main>`;

const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ZERO — Round two</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="./ember.css">
<link rel="stylesheet" href="./extras.css">
<link rel="stylesheet" href="./extras2.css">
</head>
<body>
<nav class="nav"><div class="nav-in">
  <a class="brand" href="/">${icf('bolt')}ZERO</a>
  <ul class="nav-links">
    <li><a href="/keys">Keys</a></li><li><a href="/keys/generate">Generate</a></li>
    <li><a href="/check">Check Key</a></li><li><a href="/dashboard">Dashboard</a></li>
  </ul>
  <div class="nav-right"><span class="balance">Balance <b>$248.00</b></span><span class="avatar">Z</span></div>
</div></nav>
${body}
<footer class="foot">© 2026 ZERO · All rights reserved.</footer>
</body>
</html>`;

writeFileSync(`${OUT}round2.html`, html);
console.log('built round2.html');
