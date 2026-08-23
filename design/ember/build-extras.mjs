import { writeFileSync } from 'fs';
const OUT = new URL('./', import.meta.url).pathname;

const P = {
  bolt:'<path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/>',
  check:'<path d="M20 6 9 17l-5-5"/>',
  x:'<path d="M18 6 6 18M6 6l12 12"/>',
  alert:'<circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/>',
  search:'<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  key:'<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2m-4 4 3 3m-6-6 3 3"/>',
  plus:'<path d="M12 5v14M5 12h14"/>',
  gear:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2 2 2 0 1 1-4 0 1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 4.6 15a2 2 0 1 1 0-4 1.7 1.7 0 0 0 1.2-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 11 4.6a2 2 0 1 1 4 0 1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.7 1.7 0 0 0 19.4 11a2 2 0 1 1 0 4z"/>',
  clock:'<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  trash:'<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
  copy:'<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
  sun:'<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  share:'<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
  cal:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
};
const ic = n => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${P[n]}</svg>`;
const icf = n => `<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">${P[n]}</svg>`;

/* deterministic pseudo-QR (visual placeholder, not a real encode) */
function qr(){
  let s=''; const N=21; let seed=7;
  const rnd=()=>{ seed=(seed*1103515245+12345)&0x7fffffff; return seed/0x7fffffff; };
  const finder=(x,y)=>`<rect x="${x}" y="${y}" width="7" height="7" fill="#120D0B"/><rect x="${x+1}" y="${y+1}" width="5" height="5" fill="#F7ECE8"/><rect x="${x+2}" y="${y+2}" width="3" height="3" fill="#120D0B"/>`;
  for(let y=0;y<N;y++)for(let x=0;x<N;x++){
    const inF=(x<8&&y<8)||(x>N-9&&y<8)||(x<8&&y>N-9);
    if(inF) continue;
    if(rnd()>0.52) s+=`<rect x="${x}" y="${y}" width="1" height="1" fill="#120D0B"/>`;
  }
  return `<svg viewBox="0 0 21 21" shape-rendering="crispEdges">${s}${finder(0,0)}${finder(14,0)}${finder(0,14)}</svg>`;
}

/* 30-day expiry histogram */
const BARS = [4,7,3,9,14,6,2,5,11,8,3,6,19,12,5,7,4,9,3,8,15,6,4,10,7,3,5,12,8,4];
const maxB = Math.max(...BARS);
const spark = BARS.map((v,i)=>{
  const cls = i===0 ? 'now' : (v >= 14 ? 'hot' : '');
  return `<i class="${cls}" style="height:${Math.round(v/maxB*100)}%"></i>`;
}).join('');

const body = `
<main class="shell">
  <div class="page-head">
    <div><h1>Proposed additions</h1><p class="sub">Seven things that would make the panel nicer to use — none of them in the current build</p></div>
  </div>

  <section class="demo">
    <div class="demo-lbl"><span class="n">01</span><h2>Toasts instead of modal popups</h2>
      <p>SweetAlert blocks the screen and needs a click to dismiss. A toast confirms and gets out of the way.</p></div>
    <div class="toast-stack">
      <div class="toast">${ic('check')}<div><div class="t">Key copied</div><div class="s">ZM-8QK4-77TC-A19F is on your clipboard</div></div><button class="x hit" aria-label="Dismiss">${ic('x')}</button></div>
      <div class="toast is-warn">${ic('alert')}<div><div class="t">3 keys expire tomorrow</div><div class="s">Free Fire, PUBG M and 1 more</div></div><button class="x hit" aria-label="Dismiss">${ic('x')}</button></div>
      <div class="toast is-bad">${ic('alert')}<div><div class="t">Not enough balance</div><div class="s">$18.00 needed, $4.00 available</div></div><button class="x hit" aria-label="Dismiss">${ic('x')}</button></div>
    </div>
  </section>

  <div class="demo-grid two">
    <section class="demo">
      <div class="demo-lbl"><span class="n">02</span><h2>Command palette</h2></div>
      <p class="hint" style="margin-bottom:var(--s3)">With 1,284 keys, searching beats scrolling. Ctrl&nbsp;+&nbsp;K from anywhere.</p>
      <div class="palette">
        <div class="palette-in">${ic('search')}<span class="q">8QK4</span><span class="esc">ESC</span></div>
        <div class="palette-sec">Keys</div>
        <div class="palette-row on">${ic('key')}<span class="k">ZM-8QK4-77TC-A19F</span><span class="m">PUBG M · 22 d</span></div>
        <div class="palette-row">${ic('key')}<span class="k">ZM-QQ21-XB09-77KL</span><span class="m">PUBG M · 2 d</span></div>
        <div class="palette-sec">Actions</div>
        <div class="palette-row">${ic('plus')}<span class="k">Generate a key</span><span class="m">G then N</span></div>
        <div class="palette-row">${ic('gear')}<span class="k">Settings</span><span class="m">G then S</span></div>
      </div>
    </section>

    <section class="demo">
      <div class="demo-lbl"><span class="n">03</span><h2>Share sheet with QR</h2></div>
      <p class="hint" style="margin-bottom:var(--s3)">Buyers mistype keys. Let them scan it instead.</p>
      <div class="drawer">
        <div class="drawer-h">${ic('share')}<h3>Send this key</h3><button class="x hit" aria-label="Close">${ic('x')}</button></div>
        <div class="drawer-b">
          <div class="qr">${qr()}</div>
          <p class="qr-cap">Scan to load the key on a phone</p>
          <div class="row-key" style="display:flex;align-items:center;gap:var(--s2);background:var(--input);border:1px solid var(--line);border-radius:var(--r-sm);padding:9px 11px;margin-bottom:var(--s3)">
            <span class="key" style="flex:1;overflow:hidden;text-overflow:ellipsis">ZM-8QK4-77TC-A19F</span>
            <button class="copy" aria-label="Copy key">${ic('copy')}</button>
          </div>
          <button class="btn btn-primary btn-block">${ic('share')}Copy share link</button>
        </div>
      </div>
    </section>
  </div>

  <section class="demo">
    <div class="demo-lbl"><span class="n">04</span><h2>Bulk actions</h2>
      <p>Extend or revoke a batch in one go, instead of opening each key.</p></div>
    <div class="bulkbar">
      <span class="cnt"><b>12</b> keys selected</span>
      <div class="grp">
        <button class="btn btn-ghost btn-sm">${ic('clock')}Extend 30 days</button>
        <button class="btn btn-ghost btn-sm">${ic('copy')}Export CSV</button>
        <button class="btn btn-danger btn-sm">${ic('trash')}Revoke</button>
      </div>
    </div>
  </section>

  <div class="demo-grid two">
    <section class="demo">
      <div class="demo-lbl"><span class="n">05</span><h2>Expiry forecast</h2></div>
      <p class="hint" style="margin-bottom:var(--s3)">See the wave of lapses coming before it hits.</p>
      <div class="spark-card">
        <div class="spark-head"><span class="t">Keys expiring</span><span class="s">next 30 days</span></div>
        <div class="spark">${spark}</div>
        <div class="spark-axis"><span>today</span><span>+15 d</span><span>+30 d</span></div>
        <p class="hint" style="margin-top:var(--s3)">Peak of <b style="color:var(--warn)">19 keys</b> on 4 September.</p>
      </div>
    </section>

    <section class="demo">
      <div class="demo-lbl"><span class="n">06</span><h2>Skeleton rows</h2></div>
      <p class="hint" style="margin-bottom:var(--s3)">DataTables fetches server-side; show the shape while it loads, not the word "processing".</p>
      <div class="card"><div class="card-b">
        <div class="skel-row"><span class="skel av"></span><span class="skel l1"></span><span class="skel l2"></span><span class="skel pil"></span></div>
        <div class="skel-row"><span class="skel av"></span><span class="skel l1"></span><span class="skel l2"></span><span class="skel pil"></span></div>
        <div class="skel-row"><span class="skel av"></span><span class="skel l1"></span><span class="skel l2"></span><span class="skel pil"></span></div>
        <div class="skel-row"><span class="skel av"></span><span class="skel l1"></span><span class="skel l3"></span><span class="skel pil"></span></div>
      </div></div>
    </section>
  </div>

  <section class="demo">
    <div class="demo-lbl"><span class="n">07</span><h2>An optional light mode</h2>
      <p>Same tokens, inverted. Resellers working outdoors on a phone can actually read the screen.</p></div>
    <div class="lightbox">
      <div class="lh">${icf('bolt')}<b>ZERO · Keys</b><span style="margin-left:auto;font-size:11.5px;color:#7A625A;font-family:var(--mono)">Balance $248.00</span></div>
      <div class="lrow"><span class="lgame">PUBG M</span><span class="lkey">ZM-8QK4-77TC-A19F</span><span class="lpill">Active</span></div>
      <div class="lrow"><span class="lgame">Free Fire</span><span class="lkey">ZM-3LP9-D2XB-5570</span><span class="lpill w">19 h left</span></div>
      <div class="lrow"><span class="lgame">CODM</span><span class="lkey">ZM-J51W-8FQ2-B803</span><span class="lpill">Active</span></div>
    </div>
  </section>
</main>`;

const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ZERO — Proposed additions</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="./ember.css">
<link rel="stylesheet" href="./extras.css">
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

writeFileSync(`${OUT}extras.html`, html);
console.log('built extras.html');
