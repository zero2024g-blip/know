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
  clock:'<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  trash:'<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
  copy:'<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
  shield:'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
  lock:'<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
  phone:'<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
  desktop:'<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8m-4-4v4"/>',
  wallet:'<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5"/><path d="M17 13h.01"/>',
  list:'<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
  filter:'<path d="M3 4h18l-7 8v7l-4 2v-9z"/>',
  user:'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  users:'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
  cal:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
  gear:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2 2 2 0 1 1-4 0 1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 4.6 15a2 2 0 1 1 0-4 1.7 1.7 0 0 0 1.2-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 11 4.6a2 2 0 1 1 4 0 1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.7 1.7 0 0 0 19.4 11a2 2 0 1 1 0 4z"/>',
  down:'<path d="M12 3v13m0 0 5-5m-5 5-5-5M4 21h16"/>',
  up:'<path d="M12 21V8m0 0 5 5m-5-5-5 5M4 3h16"/>',
  globe:'<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/>',
  logout:'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
  refresh:'<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/>',
};
const ic = n => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${P[n]}</svg>`;
const icf = n => `<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">${P[n]}</svg>`;

/* ---------- shell ---------- */
function shell({title, active, body, tab}){
  const links = [['keys','Keys','/keys'],['generate','Generate','/keys/generate'],
                 ['check','Check Key','/check'],['dashboard','Dashboard','/dashboard']]
    .map(([id,l,h])=>`<li><a href="${h}"${id===active?' aria-current="page"':''}>${l}</a></li>`).join('');
  const tabs = [['keys','Keys','key','/keys'],['generate','New','plus','/keys/generate'],
                ['check','Check','search','/check'],['dashboard','Home','desktop','/dashboard'],
                ['settings','Settings','gear','/settings']]
    .map(([id,l,i,h])=>`<a href="${h}"${id===tab?' aria-current="page"':''}>${ic(i)}<span>${l}</span></a>`).join('');
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ZERO — ${title}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="./ember.css">
<link rel="stylesheet" href="./extras.css">
<link rel="stylesheet" href="./extras2.css">
<link rel="stylesheet" href="./extras3.css">
</head>
<body>
<nav class="nav"><div class="nav-in">
  <a class="brand" href="/">${icf('bolt')}ZERO</a>
  <ul class="nav-links">${links}</ul>
  <div class="nav-right"><span class="balance">Balance <b>$248.00</b></span><span class="avatar">Z</span></div>
</div></nav>
${body}
<footer class="foot">© 2026 ZERO · All rights reserved.</footer>
<nav class="tabbar" aria-label="Main">${tabs}</nav>
</body>
</html>`;
}

const setnav = (cur) => `
<nav class="setnav" aria-label="Settings">
  <span class="grp">Account</span>
  <a href="/settings"${cur==='profile'?' aria-current="page"':''}>${ic('user')}Profile</a>
  <a href="/settings/security"${cur==='security'?' aria-current="page"':''}>${ic('lock')}Password &amp; 2FA</a>
  <a href="/settings/sessions"${cur==='sessions'?' aria-current="page"':''}>${ic('desktop')}Active sessions<span class="badge">3</span></a>
  <span class="grp">Admin</span>
  <a href="/admin/activity"${cur==='activity'?' aria-current="page"':''}>${ic('list')}Activity log</a>
  <a href="/admin/manage-users"${cur==='users'?' aria-current="page"':''}>${ic('users')}Manage users</a>
  <a href="/admin/balance"${cur==='balance'?' aria-current="page"':''}>${ic('wallet')}Balance history</a>
</nav>`;

/* =================================================================
   1 · ACTIVITY LOG
   ================================================================= */
const LOGS = [
  ['warn','clock','<b>zeroadmin</b> extended <code>12 keys</code> by 720 h','203.0.113.44','Chrome · Windows','2 min ago'],
  ['ok','plus','<b>reseller_id</b> generated <code>ZM-W7YH-****-****</code>','198.51.100.7','Safari · iPhone','18 min ago'],
  ['info','copy','<b>reseller_id</b> created a share link for <code>ZM-8QK4-****-****</code>','198.51.100.7','Safari · iPhone','22 min ago'],
  ['bad','trash','<b>zeroadmin</b> revoked <code>ZM-0RT6-****-****</code> — reason: chargeback','203.0.113.44','Chrome · Windows','1 h ago'],
  ['bad','lock','4 failed sign-ins for <b>budi_x</b> — locked 15 min','192.0.2.19','Firefox · Linux','3 h ago'],
  ['info','user','<b>zeroadmin</b> changed <b>andi_99</b> role to Reseller','203.0.113.44','Chrome · Windows','5 h ago'],
  ['ok','wallet','<b>zeroadmin</b> topped up <b>reseller_id</b> by $100.00','203.0.113.44','Chrome · Windows','1 d ago'],
  ['warn','shield','<b>zeroadmin</b> signed out a session on Firefox · Linux','203.0.113.44','Chrome · Windows','1 d ago'],
];
const logRows = LOGS.map(([tone,icon,text,ip,ua,when])=>`
<div class="logrow">
  <span class="logic ${tone}">${ic(icon)}</span>
  <div class="logb">
    <div class="t">${text}</div>
    <div class="m"><span>${ic('globe')}${ip}</span><span>${ic('desktop')}${ua}</span></div>
  </div>
  <div class="logend">${when}</div>
</div>`).join('');

const activity = shell({title:'Activity log', active:null, tab:'settings', body:`
<main class="shell">
  <div class="page-head">
    <div><h1>Settings</h1><p class="sub">Account, security and admin tools</p></div>
    <div class="spacer"><span class="rolebar">${ic('shield')}Administrator</span></div>
  </div>
  <div class="set">
    ${setnav('activity')}
    <div>
      <section class="card">
        <div class="card-h">
          <h2>${ic('list')}Activity log</h2>
          <div class="actions">
            <button class="btn btn-ghost btn-sm">${ic('down')}Export</button>
            <button class="btn btn-ghost btn-sm btn-icon" aria-label="Refresh">${ic('refresh')}</button>
          </div>
        </div>

        <div class="toolbar">
          <div class="grow searchbox">${ic('search')}<input type="search" placeholder="Search user, key or IP…" aria-label="Search the log"></div>
          <div class="right">
            <div class="range">
              <span class="dt">${ic('cal')}17 Aug</span><span class="sep">→</span><span class="dt">${ic('cal')}23 Aug</span>
            </div>
          </div>
        </div>

        <div class="frow">
          <span class="flabel">Event</span>
          <span class="fchip on">All <span class="n">1842</span></span>
          <span class="fchip">Keys <span class="n">1204</span></span>
          <span class="fchip">Balance <span class="n">318</span></span>
          <span class="fchip">Accounts <span class="n">246</span></span>
          <span class="fchip">Sign-in <span class="n">74</span></span>
        </div>
        <div class="frow">
          <span class="flabel">Actor</span>
          <span class="fchip">${ic('user')}Any user</span>
          <span class="fchip">${ic('shield')}Admins only</span>
          <span class="fchip on">${ic('alert')}Failures <span class="n">12</span></span>
          <button class="clearall hit">Clear filters</button>
        </div>

        <div class="card-b flush">${logRows}</div>

        <div class="pager">
          <span class="count">1–8 of 1,842</span>
          <button class="pg">Prev</button><button class="pg on">1</button><button class="pg">2</button>
          <button class="pg">3</button><span class="pg gap">…</span><button class="pg">231</button>
          <button class="pg">Next</button>
        </div>
      </section>

      <div class="seclist" style="margin-top:var(--s4)">
        <div class="secitem"><span class="si">${ic('check')}</span><div><b>Keys are logged masked</b>
          Only the first block is stored — <code>ZM-8QK4-****-****</code>. A leaked or shoulder-surfed log never yields a working key.</div></div>
        <div class="secitem"><span class="si">${ic('check')}</span><div><b>Append-only</b>
          No edit or delete in the UI or the model. Retention is a scheduled purge, not a button, so nobody can quietly erase their own trail.</div></div>
        <div class="secitem"><span class="si">${ic('check')}</span><div><b>Admin-only, enforced server-side</b>
          The route sits behind a role filter. Hiding the nav item is cosmetic; the filter is the control.</div></div>
      </div>
    </div>
  </div>
</main>`});

/* =================================================================
   2 · ACTIVE SESSIONS
   ================================================================= */
const sessions = shell({title:'Active sessions', active:null, tab:'settings', body:`
<main class="shell">
  <div class="page-head">
    <div><h1>Settings</h1><p class="sub">Account, security and admin tools</p></div>
    <div class="spacer"><span class="rolebar">${ic('shield')}Administrator</span></div>
  </div>
  <div class="set">
    ${setnav('sessions')}
    <div>
      <section class="card">
        <div class="card-h"><h2>${ic('desktop')}Active sessions</h2>
          <div class="actions"><button class="btn btn-danger btn-sm">${ic('logout')}Sign out everywhere else</button></div>
        </div>
        <div class="card-b flush">
          <div class="sesslist">
            <div class="sessrow current">
              <span class="sessic">${ic('desktop')}</span>
              <div class="sessb">
                <div class="t">Chrome on Windows <span class="pill pill-ok"><i></i>This device</span></div>
                <div class="m"><span>203.0.113.44</span><span>Jakarta, ID</span><span>active now</span></div>
              </div>
              <div class="act"></div>
            </div>
            <div class="sessrow">
              <span class="sessic">${ic('phone')}</span>
              <div class="sessb">
                <div class="t">Safari on iPhone</div>
                <div class="m"><span>198.51.100.7</span><span>Jakarta, ID</span><span>2 h ago</span></div>
              </div>
              <div class="act"><button class="btn btn-ghost btn-sm">Sign out</button></div>
            </div>
            <div class="sessrow">
              <span class="sessic">${ic('desktop')}</span>
              <div class="sessb">
                <div class="t">Firefox on Linux <span class="pill pill-warn"><i></i>Unrecognised</span></div>
                <div class="m"><span>192.0.2.88</span><span>location unknown</span><span>6 d ago</span></div>
              </div>
              <div class="act"><button class="btn btn-danger btn-sm">Sign out</button></div>
            </div>
          </div>
        </div>
      </section>

      <div class="seclist" style="margin-top:var(--s4)">
        <div class="secitem"><span class="si">${ic('check')}</span><div><b>No session ID is ever rendered</b>
          The table shows device, IP and last-seen only. Each row's revoke button carries a per-row CSRF-signed handle, not the session token — so the page source can't be scraped for a live session.</div></div>
        <div class="secitem"><span class="si">${ic('check')}</span><div><b>Revoke deletes server-side</b>
          The row disappears because the session record is gone, not because a cookie was cleared. A stolen cookie stops working immediately.</div></div>
        <div class="secitem"><span class="si">${ic('check')}</span><div><b>Rotate the session ID on login</b>
          CodeIgniter's <code>session()->regenerate()</code> on successful sign-in. Without it, a fixation attack works: set a victim's session ID before they log in, then reuse it.</div></div>
        <div class="secitem"><span class="si">${ic('check')}</span><div><b>Cookie flags</b>
          <code>Secure</code>, <code>HttpOnly</code>, <code>SameSite=Lax</code>. HttpOnly is what stops an XSS payload reading the session — which matters given the unescaped output I found in your views.</div></div>
      </div>
    </div>
  </div>
</main>`});

/* =================================================================
   3 · BALANCE HISTORY
   ================================================================= */
const TX = [
  ['23 Aug 14:02','Generated CODM key','key','#K-15062','−$18.00','neg','$248.00'],
  ['23 Aug 11:40','Generated FF key','key','#K-15061','−$6.00','neg','$266.00'],
  ['22 Aug 19:15','Top-up by zeroadmin','up','#T-0442','+$100.00','pos','$272.00'],
  ['22 Aug 15:31','Generated PUBG M key ×3','key','#K-15058','−$54.00','neg','$172.00'],
  ['22 Aug 09:03','Refund — revoked key','refresh','#K-15044','+$18.00','pos','$226.00'],
  ['21 Aug 22:47','Generated Standoff 2 key','key','#K-15039','−$18.00','neg','$208.00'],
];
const txTone = t => t==='up'||t==='refresh' ? 'background:var(--ok-wash);color:var(--ok)' : 'background:var(--accent-wash);color:var(--accent)';
const ledRows = TX.map(([when,what,icon,ref,amt,tone,bal])=>`
<tr><td class="r" style="text-align:left">${when}</td>
  <td><span class="what"><span class="ti" style="${txTone(icon)}">${ic(icon)}</span>${what}</span></td>
  <td class="ref">${ref}</td>
  <td class="r amt ${tone}">${amt}</td>
  <td class="r bal">${bal}</td></tr>`).join('');
const ledCards = TX.map(([when,what,icon,ref,amt,tone,bal])=>`
<div class="ledcard">
  <div class="top"><span class="what"><span class="ti" style="${txTone(icon)}">${ic(icon)}</span>${what}</span>
    <span class="amt ${tone}" style="color:var(--${tone==='neg'?'bad':'ok'})">${amt}</span></div>
  <div class="bot"><span>${when}</span><span>${ref}</span><span>bal ${bal}</span></div>
</div>`).join('');

const balance = shell({title:'Balance history', active:null, tab:'settings', body:`
<main class="shell">
  <div class="page-head">
    <div><h1>Settings</h1><p class="sub">Account, security and admin tools</p></div>
    <div class="spacer"><span class="rolebar">${ic('shield')}Administrator</span></div>
  </div>
  <div class="set">
    ${setnav('balance')}
    <div>
      <div class="balstrip">
        <div class="kpi"><span class="k"><i style="color:var(--accent)"></i>Balance</span><span class="v">$248</span><span class="d">as of now</span></div>
        <div class="kpi"><span class="k"><i style="color:var(--bad)"></i>Spent 7 d</span><span class="v">$96</span><span class="d">16 keys</span></div>
        <div class="kpi"><span class="k"><i style="color:var(--ok)"></i>Added 7 d</span><span class="v">$118</span><span class="d">2 entries</span></div>
        <div class="kpi"><span class="k"><i style="color:var(--info)"></i>Avg / key</span><span class="v">$6</span><span class="d">last 30 d</span></div>
      </div>

      <section class="card">
        <div class="card-h"><h2>${ic('wallet')}Balance history</h2>
          <div class="actions"><button class="btn btn-ghost btn-sm">${ic('down')}Export CSV</button></div>
        </div>

        <div class="toolbar">
          <div class="grow searchbox">${ic('search')}<input type="search" placeholder="Search reference or description…" aria-label="Search transactions"></div>
          <div class="right"><div class="range">
            <span class="dt">${ic('cal')}17 Aug</span><span class="sep">→</span><span class="dt">${ic('cal')}23 Aug</span>
          </div></div>
        </div>

        <div class="frow">
          <span class="flabel">Type</span>
          <span class="fchip on">All <span class="n">318</span></span>
          <span class="fchip">Key spend <span class="n">286</span></span>
          <span class="fchip">Top-ups <span class="n">18</span></span>
          <span class="fchip">Refunds <span class="n">14</span></span>
          <button class="clearall hit">Clear filters</button>
        </div>

        <div class="card-b flush">
          <div class="ledscroll scrollx">
            <table class="ledger2">
              <thead><tr><th>When</th><th>What</th><th>Reference</th><th style="text-align:right">Amount</th><th style="text-align:right">Balance after</th></tr></thead>
              <tbody>${ledRows}</tbody>
            </table>
          </div>
          <div class="ledcards">${ledCards}</div>
        </div>

        <div class="pager">
          <span class="count">1–6 of 318</span>
          <button class="pg">Prev</button><button class="pg on">1</button><button class="pg">2</button>
          <button class="pg">3</button><span class="pg gap">…</span><button class="pg">53</button>
          <button class="pg">Next</button>
        </div>
      </section>

      <div class="seclist" style="margin-top:var(--s4)">
        <div class="secitem"><span class="si">${ic('check')}</span><div><b>Every row carries a reference</b>
          <code>#K-15062</code> ties the debit to the key it created. A balance that is only a number can't be audited when a reseller disputes it.</div></div>
        <div class="secitem"><span class="si">${ic('check')}</span><div><b>Balance-after is stored, not computed on read</b>
          Recomputing a running total from a filtered query gives wrong numbers the moment anyone filters. Store it at write time inside the same transaction.</div></div>
        <div class="secitem"><span class="si">${ic('check')}</span><div><b>Debit and key-insert in one transaction</b>
          Otherwise a failure between them either gives a free key or takes money for nothing. This is the single most valuable fix on this page.</div></div>
      </div>
    </div>
  </div>
</main>`});

/* =================================================================
   4 · KEYS WITH REAL FILTERS
   ================================================================= */
const GAMES = {PB:['PUBG M','rgba(244,114,90,.18)','#F4725A'],FF:['Free Fire','rgba(167,139,250,.18)','#A78BFA'],
  CD:['CODM','rgba(74,222,128,.18)','#4ADE80'],ML:['Mobile L','rgba(251,191,36,.18)','#FBBF24'],
  ST:['Standoff 2','rgba(125,211,252,.18)','#7DD3FC']};
const game = k=>{const [n,bg,fg]=GAMES[k];return `<span class="game"><span class="game-ic" style="background:${bg};color:${fg}">${k}</span>${n}</span>`};
const KEYS = [
  ['FF','ZM-3LP9-D2XB-5570',1,1,'168 h','24 Aug 2026','warn','19 h left'],
  ['PB','ZM-QQ21-XB09-77KL',2,2,'336 h','25 Aug 2026','warn','2 d left'],
  ['ML','ZM-4TG8-PP31-B7QW',1,3,'720 h','26 Aug 2026','warn','3 d left'],
  ['CD','ZM-9HJ2-LM55-X01T',3,3,'168 h','27 Aug 2026','warn','4 d left'],
];
const meter=(u,t)=>{const pct=t?Math.round(u/t*100):0;const c=u===0?'none':(u>=t?'full':'');
  return `<span class="meter"><span class="meter-bar"><span class="meter-fill ${c}" style="width:${pct}%"></span></span><span class="meter-txt">${u}/${t}</span></span>`};
const cp = `<button class="copy" type="button" aria-label="Copy key">${ic('copy')}</button>`;

const keysFiltered = shell({title:'Keys', active:'keys', tab:'keys', body:`
<main class="shell">
  <div class="page-head">
    <div><h1>Keys</h1><p class="sub">1,284 active · 37 expiring soon</p></div>
    <div class="spacer">
      <button class="btn btn-ghost btn-sm">${ic('down')}Export</button>
      <a class="btn btn-primary btn-sm" href="/keys/generate">${ic('plus')}New key</a>
    </div>
  </div>

  <section class="card">
    <div class="card-h"><h2>${ic('list')}Registered keys</h2></div>

    <div class="toolbar">
      <div class="grow searchbox">${ic('search')}<input type="search" placeholder="Search key or game…" aria-label="Search keys"></div>
      <div class="right"><div class="range">
        <span class="dt">${ic('cal')}Any date</span>
      </div></div>
    </div>

    <div class="frow">
      <span class="flabel">Status</span>
      <span class="fchip">All <span class="n">1704</span></span>
      <span class="fchip">Active <span class="n">1284</span></span>
      <span class="fchip on">${ic('clock')}Expiring <span class="n">37</span></span>
      <span class="fchip">Expired <span class="n">402</span></span>
      <span class="fchip">Revoked <span class="n">18</span></span>
    </div>
    <div class="frow">
      <span class="flabel">Game</span>
      <span class="fchip">All games</span>
      <span class="fchip">PUBG M <span class="n">512</span></span>
      <span class="fchip">Free Fire <span class="n">388</span></span>
      <span class="fchip">CODM <span class="n">274</span></span>
      <span class="fchip">Mobile L <span class="n">96</span></span>
      <button class="clearall hit">Clear filters</button>
    </div>

    <div class="applied">
      Showing <b>37</b> of 1,704 keys
      <span class="atag">Expiring within 7 d <button class="hit" aria-label="Remove filter">${ic('x')}</button></span>
      <span class="atag">Sorted by soonest <button class="hit" aria-label="Remove sort">${ic('x')}</button></span>
      <span class="fchip on" style="margin-left:auto">${ic('filter')}Save this view</span>
    </div>

    <div class="card-b flush">
      <div class="table-wrap"><table class="tbl">
        <thead><tr><th>Game</th><th>User key</th><th>Devices</th><th>Duration</th><th>Expires</th><th>Status</th><th class="right">Edit</th></tr></thead>
        <tbody>${KEYS.map(([g,k,u,t,d,e,st,l])=>`<tr>
          <td>${game(g)}</td>
          <td><span class="keycell"><span class="key">${k}</span>${cp}</span></td>
          <td>${meter(u,t)}</td><td class="num">${d}</td><td class="num">${e}</td>
          <td><span class="pill pill-${st}"><i></i>${l}</span></td>
          <td class="right"><button class="btn btn-ghost btn-sm btn-icon" aria-label="Edit key">${ic('shield')}</button></td>
        </tr>`).join('')}</tbody>
      </table></div>
      <div class="rows">${KEYS.map(([g,k,u,t,d,e,st,l])=>`<div class="row-card">
        <div class="row-top">${game(g)}<span class="pill pill-${st}"><i></i>${l}</span></div>
        <div class="row-key"><span class="key">${k}</span>${cp}</div>
        <div class="row-meta"><span>${ic('phone')} <b>${u}/${t}</b></span><span>${ic('clock')} <b>${d}</b></span><span>Expires <b>${e}</b></span></div>
      </div>`).join('')}</div>
      <div class="pager">
        <span class="count">1–4 of 37</span>
        <button class="pg">Prev</button><button class="pg on">1</button>
        <button class="pg">2</button><button class="pg">3</button><button class="pg">Next</button>
      </div>
    </div>
  </section>

  <div class="seclist" style="margin-top:var(--s4)">
    <div class="secitem"><span class="si">${ic('check')}</span><div><b>Filters are a server-side whitelist</b>
      <code>status</code>, <code>game</code> and <code>range</code> map to fixed enum values before they touch a query. Never interpolate a filter string into SQL — the current <code>keys/api</code> endpoint takes DataTables' raw search parameter, which is exactly the shape this has to avoid.</div></div>
    <div class="secitem"><span class="si">${ic('check')}</span><div><b>Counts respect scope</b>
      A reseller's "1,284 active" counts only their own keys. Counts computed over the whole table leak how large the business is.</div></div>
    <div class="secitem"><span class="si">${ic('check')}</span><div><b>Saved views store filters, never results</b>
      A view is a set of parameters re-run under the current user's permissions, so a saved view can't become a way to read someone else's rows later.</div></div>
  </div>
</main>`});

const pages = {'admin-activity':activity,'admin-sessions':sessions,'admin-balance':balance,'keys-filters':keysFiltered};
for (const [n,h] of Object.entries(pages)) writeFileSync(`${OUT}${n}.html`, h);
console.log('built', Object.keys(pages).join(', '));
