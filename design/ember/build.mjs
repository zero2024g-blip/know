import { writeFileSync } from 'fs';
const OUT = new URL('./', import.meta.url).pathname;

/* ---------- icons (Lucide paths, no emoji anywhere) ---------- */
const I = {
  bolt:'<path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/>',
  key:'<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2m-4 4 3 3m-6-6 3 3"/>',
  plus:'<path d="M12 5v14M5 12h14"/>',
  search:'<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  gauge:'<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M12 2a10 10 0 1 0 10 10"/><path d="m14.1 9.9 4.6-4.6"/>',
  gear:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2 2 2 0 1 1-4 0 1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 4.6 15a2 2 0 1 1 0-4 1.7 1.7 0 0 0 1.2-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 11 4.6a2 2 0 1 1 4 0 1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.7 1.7 0 0 0 19.4 11a2 2 0 1 1 0 4z"/>',
  users:'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
  copy:'<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
  eyeoff:'<path d="M10.7 5.1A9 9 0 0 1 12 5c5 0 9 4.5 9 7a12 12 0 0 1-2.2 3.1M6.6 6.6A12.9 12.9 0 0 0 3 12c0 2.5 4 7 9 7a9.7 9.7 0 0 0 4.4-1"/><path d="m2 2 20 20"/>',
  check:'<path d="M20 6 9 17l-5-5"/>',
  x:'<path d="M18 6 6 18M6 6l12 12"/>',
  alert:'<circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/>',
  info:'<circle cx="12" cy="12" r="10"/><path d="M12 16v-4m0-4h.01"/>',
  clock:'<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  trash:'<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
  edit:'<path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/>',
  shield:'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
  wallet:'<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5"/><path d="M17 13h.01"/>',
  device:'<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
  logout:'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
  list:'<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
};
const ic = (n, cls='') => `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${I[n]}</svg>`;
const icf = (n) => `<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">${I[n]}</svg>`;

/* ---------- shell ---------- */
const NAV_ITEMS = [
  ['keys','Keys','key','/keys'],
  ['generate','Generate','plus','/keys/generate'],
  ['check','Check Key','search','/check'],
  ['dashboard','Dashboard','gauge','/dashboard'],
];

function nav(active, signedIn = true){
  if(!signedIn){
    return `<nav class="nav"><div class="nav-in">
      <a class="brand" href="/">${icf('bolt')}ZERO</a>
      <div class="nav-right">
        <a class="btn btn-ghost btn-sm" href="/check">Check a key</a>
        <a class="btn btn-primary btn-sm" href="/register">Create account</a>
      </div>
    </div></nav>`;
  }
  const links = NAV_ITEMS.map(([id,label,,href]) =>
    `<li><a href="${href}"${id===active?' aria-current="page"':''}>${label}</a></li>`).join('');
  return `<nav class="nav"><div class="nav-in">
    <a class="brand" href="/">${icf('bolt')}ZERO</a>
    <ul class="nav-links">${links}</ul>
    <div class="nav-right">
      <span class="balance">Balance <b>$248.00</b></span>
      <span class="avatar" title="zeroadmin">Z</span>
    </div>
  </div></nav>`;
}

function tabbar(active){
  const items = [
    ['keys','Keys','key','/keys'],
    ['generate','New','plus','/keys/generate'],
    ['check','Check','search','/check'],
    ['dashboard','Home','gauge','/dashboard'],
    ['settings','Settings','gear','/settings'],
  ];
  return `<nav class="tabbar" aria-label="Main">${items.map(([id,label,icon,href])=>
    `<a href="${href}"${id===active?' aria-current="page"':''}>${ic(icon)}<span>${label}</span></a>`).join('')}</nav>`;
}

function page({title, active, signedIn=true, body, bare=false}){
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
</head>
<body>
${nav(active, signedIn)}
${body}
<footer class="foot">© 2026 ZERO · All rights reserved.</footer>
${signedIn && !bare ? tabbar(active) : ''}
</body>
</html>`;
}

/* ---------- shared data ---------- */
const GAMES = {
  PB:['PUBG M','rgba(244,114,90,.18)','#F4725A'],
  FF:['Free Fire','rgba(167,139,250,.18)','#A78BFA'],
  CD:['CODM','rgba(74,222,128,.18)','#4ADE80'],
  ML:['Mobile L','rgba(251,191,36,.18)','#FBBF24'],
  ST:['Standoff 2','rgba(125,211,252,.18)','#7DD3FC'],
};
const game = k => { const [n,bg,fg]=GAMES[k];
  return `<span class="game"><span class="game-ic" style="background:${bg};color:${fg}">${k}</span>${n}</span>`; };

const KEYS = [
  ['PB','ZM-8QK4-77TC-A19F',2,3,'720 h','14 Sep 2026','ok','Active'],
  ['FF','ZM-3LP9-D2XB-5570',1,1,'168 h','24 Aug 2026','warn','19 h left'],
  ['CD','ZM-J51W-8FQ2-B803',3,3,'2160 h','02 Nov 2026','ok','Active'],
  ['ML','ZM-0RT6-KC44-91DA',0,2,'24 h','21 Aug 2026','bad','Expired'],
  ['ST','ZM-W7YH-3NM8-C4E1',1,5,'720 h','30 Sep 2026','ok','Active'],
  ['PB','ZM-QQ21-XB09-77KL',2,2,'336 h','25 Aug 2026','warn','2 d left'],
];

function meter(used,total){
  const pct = total? Math.round(used/total*100) : 0;
  const cls = used===0 ? 'none' : (used>=total ? 'full' : '');
  return `<span class="meter"><span class="meter-bar"><span class="meter-fill ${cls}" style="width:${pct}%"></span></span><span class="meter-txt">${used}/${total}</span></span>`;
}
const copyBtn = `<button class="copy" type="button" aria-label="Copy key">${ic('copy')}</button>`;

function keyRowsDesktop(){
  return KEYS.map(([g,k,u,t,d,e,st,lbl]) => `<tr>
    <td>${game(g)}</td>
    <td><span class="keycell"><span class="key">${k}</span>${copyBtn}</span></td>
    <td>${meter(u,t)}</td>
    <td class="num">${d}</td>
    <td class="num">${e}</td>
    <td><span class="pill pill-${st}"><i></i>${lbl}</span></td>
    <td class="right"><button class="btn btn-ghost btn-sm btn-icon" aria-label="Edit key">${ic('edit')}</button></td>
  </tr>`).join('');
}
function keyRowsMobile(){
  return KEYS.map(([g,k,u,t,d,e,st,lbl]) => `<div class="row-card">
    <div class="row-top">${game(g)}<span class="pill pill-${st}"><i></i>${lbl}</span></div>
    <div class="row-key"><span class="key">${k}</span>${copyBtn}</div>
    <div class="row-meta">
      <span>${ic('device')} <b>${u}/${t}</b></span>
      <span>${ic('clock')} <b>${d}</b></span>
      <span>Expires <b>${e}</b></span>
    </div>
  </div>`).join('');
}

/* =================================================================
   PAGES
   ================================================================= */
const pages = {};

/* ---------- login ---------- */
pages['login'] = page({title:'Sign in', active:null, signedIn:false, bare:true, body:`
<main class="auth">
  <div class="auth-card">
    <div class="auth-mark">${icf('bolt')}</div>
    <h1>Welcome back</h1>
    <p class="lede">Sign in to manage your keys.</p>
    <form class="form">
      <div class="field">
        <label for="u">Username</label>
        <input class="control" id="u" name="username" type="text" value="zeroadmin" autocomplete="username">
      </div>
      <div class="field">
        <label for="p">Password</label>
        <input class="control" id="p" name="password" type="password" value="supersecret" autocomplete="current-password">
      </div>
      <label class="check on"><span class="box">${ic('check')}</span><span>Keep me signed in</span></label>
      <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form>
    <p class="auth-foot">No account yet? <a href="/register">Create one</a></p>
  </div>
</main>`});

/* ---------- login with error ---------- */
pages['login-error'] = page({title:'Sign in', active:null, signedIn:false, bare:true, body:`
<main class="auth">
  <div class="auth-card">
    <div class="auth-mark">${icf('bolt')}</div>
    <h1>Welcome back</h1>
    <p class="lede">Sign in to manage your keys.</p>
    <div class="alert alert-bad">${ic('alert')}<div class="a-body"><b>That username and password don't match</b><span>Check both, or reset your password.</span></div></div>
    <form class="form">
      <div class="field">
        <label for="u2">Username</label>
        <input class="control" id="u2" type="text" value="zeroadmin">
      </div>
      <div class="field">
        <label for="p2">Password</label>
        <input class="control" id="p2" type="password" value="wrongpass" aria-invalid="true" aria-describedby="pe">
        <span class="err" id="pe">${ic('alert')}Password is incorrect</span>
      </div>
      <label class="check"><span class="box">${ic('check')}</span><span>Keep me signed in</span></label>
      <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form>
    <p class="auth-foot">No account yet? <a href="/register">Create one</a></p>
  </div>
</main>`});

/* ---------- register ---------- */
pages['register'] = page({title:'Create account', active:null, signedIn:false, bare:true, body:`
<main class="auth">
  <div class="auth-card">
    <div class="auth-mark">${icf('bolt')}</div>
    <h1>Create your account</h1>
    <p class="lede">You'll need a referral code from an existing member.</p>
    <form class="form">
      <div class="field">
        <label for="r1">Username <span class="req">*</span></label>
        <input class="control" id="r1" type="text" placeholder="Pick a username">
      </div>
      <div class="field">
        <label for="r2">Password <span class="req">*</span></label>
        <input class="control" id="r2" type="password" value="hunter2024x">
        <span class="strength"><i class="s3"></i><i class="s3"></i><i class="s3"></i><i></i></span>
        <span class="hint">Strong — 11 characters, mixed case and digits.</span>
      </div>
      <div class="field">
        <label for="r3">Referral code <span class="req">*</span></label>
        <input class="control mono" id="r3" type="text" value="ZRF-4K2P-9XQD" style="font-family:var(--mono)">
        <span class="hint">Ask whoever invited you for this.</span>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Create account</button>
    </form>
    <p class="auth-foot">Already registered? <a href="/login">Sign in</a></p>
  </div>
</main>`});

/* ---------- dashboard ---------- */
pages['dashboard'] = page({title:'Dashboard', active:'dashboard', body:`
<main class="shell">
  <div class="page-head">
    <div><h1>Dashboard</h1><p class="sub">Signed in 2 hours ago · auto logout in 4 hours</p></div>
  </div>

  <div class="alert alert-ok">${ic('check')}<div class="a-body"><b>Key generated</b><span>ZM-W7YH-3NM8-C4E1 is active for 720 hours on 5 devices.</span></div></div>

  <div class="kpis">
    <div class="kpi"><span class="k"><i style="color:var(--ok)"></i>Active keys</span><span class="v">1,284</span><span class="d"><b class="trend-up">+42</b> this week</span></div>
    <div class="kpi is-warn"><span class="k"><i></i>Expiring in 24 h</span><span class="v">37</span><span class="d">worth reminding buyers</span></div>
    <div class="kpi"><span class="k"><i style="color:var(--info)"></i>Devices bound</span><span class="v">2,911</span><span class="d">across all live keys</span></div>
    <div class="kpi"><span class="k"><i style="color:var(--accent)"></i>Balance</span><span class="v">$248</span><span class="d"><b class="trend-down">−$36</b> spent today</span></div>
  </div>

  <div class="grid-2" style="align-items:start">
    <section class="card">
      <div class="card-h"><h2>${ic('clock')}Recent registrations</h2>
        <div class="actions"><a class="btn btn-ghost btn-sm" href="/keys">View all</a></div></div>
      <div class="card-b">
        <div class="rh">
          <div class="rh-item"><span class="rh-av">P</span><div class="rh-main"><div class="t">ZM-8QK4-77TC**</div><div class="s">720 hours · 3 devices</div></div><div class="rh-end"><div class="t">12 min ago</div><div class="s">PUBG M</div></div></div>
          <div class="rh-item"><span class="rh-av">F</span><div class="rh-main"><div class="t">ZM-3LP9-D2XB**</div><div class="s">168 hours · 1 device</div></div><div class="rh-end"><div class="t">1 hour ago</div><div class="s">Free Fire</div></div></div>
          <div class="rh-item"><span class="rh-av">C</span><div class="rh-main"><div class="t">ZM-J51W-8FQ2**</div><div class="s">2160 hours · 3 devices</div></div><div class="rh-end"><div class="t">3 hours ago</div><div class="s">CODM</div></div></div>
          <div class="rh-item"><span class="rh-av">S</span><div class="rh-main"><div class="t">ZM-W7YH-3NM8**</div><div class="s">720 hours · 5 devices</div></div><div class="rh-end"><div class="t">Yesterday</div><div class="s">Standoff 2</div></div></div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-h"><h2>${ic('shield')}Your account</h2></div>
      <div class="card-b">
        <dl class="deflist">
          <div><dt>Role</dt><dd><span class="pill pill-info"><i></i>Administrator</span></dd></div>
          <div><dt>Balance</dt><dd>$248.00</dd></div>
          <div><dt>Keys created</dt><dd>1,506</dd></div>
          <div><dt>Signed in</dt><dd>2 h ago</dd></div>
          <div><dt>Auto logout</dt><dd>in 4 h</dd></div>
        </dl>
        <div style="display:flex;gap:var(--s2);margin-top:var(--s4)">
          <a class="btn btn-ghost btn-sm" href="/settings" style="flex:1">${ic('gear')}Settings</a>
          <a class="btn btn-danger btn-sm" href="/logout" style="flex:1">${ic('logout')}Sign out</a>
        </div>
      </div>
    </section>
  </div>
</main>`});

/* ---------- keys list ---------- */
pages['keys'] = page({title:'Keys', active:'keys', body:`
<main class="shell">
  <div class="page-head">
    <div><h1>Keys</h1><p class="sub">1,284 active · 37 expiring soon</p></div>
    <div class="spacer">
      <button class="btn btn-ghost btn-sm">${ic('eyeoff')}Hide keys</button>
      <a class="btn btn-primary btn-sm" href="/keys/generate">${ic('plus')}New key</a>
    </div>
  </div>

  <section class="card">
    <div class="card-h">
      <h2>${ic('list')}Registered keys</h2>
      <div class="actions">
        <input class="control" type="search" placeholder="Search key or game…" style="min-height:32px;font-size:13px;width:200px">
      </div>
    </div>
    <div class="card-b flush">
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr>
            <th>Game</th><th>User key</th><th>Devices</th><th>Duration</th><th>Expires</th><th>Status</th><th class="right">Edit</th>
          </tr></thead>
          <tbody>${keyRowsDesktop()}</tbody>
        </table>
      </div>
      <div class="rows">${keyRowsMobile()}</div>
      <div class="pager">
        <span class="count">1–6 of 1,284</span>
        <button class="pg">Prev</button>
        <button class="pg on">1</button><button class="pg">2</button><button class="pg">3</button>
        <span class="pg gap">…</span><button class="pg">214</button>
        <button class="pg">Next</button>
      </div>
    </div>
  </section>
</main>`});

/* ---------- keys empty ---------- */
pages['keys-empty'] = page({title:'Keys', active:'keys', body:`
<main class="shell">
  <div class="page-head">
    <div><h1>Keys</h1><p class="sub">Nothing registered yet</p></div>
    <div class="spacer"><a class="btn btn-primary btn-sm" href="/keys/generate">${ic('plus')}New key</a></div>
  </div>
  <section class="card">
    <div class="card-h"><h2>${ic('list')}Registered keys</h2></div>
    <div class="card-b flush">
      <div class="empty">
        <div class="empty-ic">${ic('key')}</div>
        <h3>No keys yet</h3>
        <p>Generate your first key and it will show up here with its devices, duration and expiry.</p>
        <a class="btn btn-primary" href="/keys/generate">${ic('plus')}Generate a key</a>
      </div>
    </div>
  </section>
</main>`});

/* ---------- generate ---------- */
pages['generate'] = page({title:'Generate', active:'generate', body:`
<main class="shell">
  <div class="page-head"><div><h1>Generate a key</h1><p class="sub">Costs are taken from your balance when you create the key</p></div></div>

  <div class="grid-2" style="align-items:start">
    <section class="card">
      <div class="card-h"><h2>${ic('key')}Key details</h2></div>
      <div class="card-b">
        <form class="form">
          <div class="field">
            <label for="g1">Game</label>
            <select class="control" id="g1">
              <option>PUBG Mobile</option><option>Free Fire</option><option selected>Call of Duty Mobile</option><option>Mobile Legends</option><option>Standoff 2</option>
            </select>
          </div>
          <div class="field">
            <label>Duration</label>
            <div class="seg">
              <span class="seg-opt"><span class="t">24 h</span><span class="s">$1.50</span></span>
              <span class="seg-opt"><span class="t">168 h</span><span class="s">$6.00</span></span>
              <span class="seg-opt on"><span class="t">720 h</span><span class="s">$18.00</span></span>
              <span class="seg-opt"><span class="t">2160 h</span><span class="s">$45.00</span></span>
            </div>
          </div>
          <div class="field">
            <label>Devices allowed</label>
            <div class="seg">
              <span class="seg-opt"><span class="t">1</span></span>
              <span class="seg-opt on"><span class="t">2</span></span>
              <span class="seg-opt"><span class="t">3</span></span>
              <span class="seg-opt"><span class="t">5</span></span>
            </div>
          </div>
          <div class="field">
            <label for="g4">How many keys</label>
            <input class="control" id="g4" type="number" value="1" min="1" max="50">
            <span class="hint">Up to 50 at a time. Each is generated separately.</span>
          </div>
          <div class="field">
            <label for="g5">Note <span class="hint" style="text-transform:none;letter-spacing:0">optional</span></label>
            <input class="control" id="g5" type="text" placeholder="Who is this for?">
          </div>
        </form>
      </div>
    </section>

    <section class="card">
      <div class="card-h"><h2>${ic('wallet')}Summary</h2></div>
      <div class="card-b">
        <dl class="deflist">
          <div><dt>Game</dt><dd>CODM</dd></div>
          <div><dt>Duration</dt><dd>720 h</dd></div>
          <div><dt>Devices</dt><dd>2</dd></div>
          <div><dt>Quantity</dt><dd>1</dd></div>
          <div><dt>Cost</dt><dd style="color:var(--accent);font-size:15px;font-weight:700">$18.00</dd></div>
          <div><dt>Balance after</dt><dd>$230.00</dd></div>
        </dl>
        <button class="btn btn-primary btn-block" style="margin-top:var(--s4)">${ic('plus')}Generate key — $18.00</button>
        <p class="hint" style="text-align:center;margin-top:var(--s3)">The key appears immediately and is billed once.</p>
      </div>
    </section>
  </div>
</main>`});

/* ---------- check ---------- */
pages['check'] = page({title:'Check a key', active:'check', body:`
<main class="shell">
  <div class="page-head"><div><h1>Check a key</h1><p class="sub">Look up any key's status, devices and expiry</p></div></div>

  <div class="grid-2" style="align-items:start">
    <section class="card">
      <div class="card-h"><h2>${ic('search')}Look up</h2></div>
      <div class="card-b">
        <div class="field">
          <label for="c1">Key</label>
          <div class="input-group">
            <input class="control" id="c1" type="text" value="ZM-8QK4-77TC-A19F" style="font-family:var(--mono)">
            <button class="btn btn-primary">${ic('search')}Check</button>
          </div>
          <span class="hint">Paste the full key including the ZM- prefix.</span>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-h"><h2>${ic('check')}Result</h2>
        <div class="actions"><span class="pill pill-ok"><i></i>Active</span></div></div>
      <div class="card-b">
        <dl class="deflist">
          <div><dt>Key</dt><dd>ZM-8QK4-77TC-A19F</dd></div>
          <div><dt>Game</dt><dd>PUBG Mobile</dd></div>
          <div><dt>Devices</dt><dd>2 of 3 used</dd></div>
          <div><dt>Duration</dt><dd>720 h</dd></div>
          <div><dt>Registered</dt><dd>15 Aug 2026</dd></div>
          <div><dt>Expires</dt><dd>14 Sep 2026</dd></div>
          <div><dt>Time left</dt><dd style="color:var(--ok)">22 d 4 h</dd></div>
        </dl>
      </div>
    </section>
  </div>
</main>`});

/* ---------- settings ---------- */
pages['settings'] = page({title:'Settings', active:'settings', body:`
<main class="shell">
  <div class="page-head"><div><h1>Settings</h1><p class="sub">Account and panel preferences</p></div></div>

  <div class="grid-2" style="align-items:start">
    <section class="card">
      <div class="card-h"><h2>${ic('shield')}Change password</h2></div>
      <div class="card-b">
        <form class="form">
          <div class="field"><label for="s1">Current password</label><input class="control" id="s1" type="password" value="supersecret"></div>
          <div class="field"><label for="s2">New password</label><input class="control" id="s2" type="password" value="newpass2026"><span class="strength"><i class="s3"></i><i class="s3"></i><i class="s3"></i><i></i></span></div>
          <div class="field"><label for="s3">Confirm new password</label><input class="control" id="s3" type="password" value="newpass2026"></div>
          <button class="btn btn-primary">Update password</button>
        </form>
      </div>
    </section>

    <section class="card">
      <div class="card-h"><h2>${ic('gear')}Preferences</h2></div>
      <div class="card-b">
        <div class="switch-row"><div class="sw-txt"><div class="t">Blur keys by default</div><div class="s">Hide key strings until you hover</div></div><span class="switch on"></span></div>
        <div class="switch-row"><div class="sw-txt"><div class="t">Expiry reminders</div><div class="s">Flag keys 24 hours before they lapse</div></div><span class="switch on"></span></div>
        <div class="switch-row"><div class="sw-txt"><div class="t">Compact rows</div><div class="s">Fit more keys on screen</div></div><span class="switch"></span></div>
        <div class="switch-row"><div class="sw-txt"><div class="t">Sign out when idle</div><div class="s">End the session after 6 hours</div></div><span class="switch on"></span></div>
      </div>
    </section>
  </div>
</main>`});

/* ---------- admin users ---------- */
pages['admin-users'] = page({title:'Manage users', active:null, body:`
<main class="shell">
  <div class="page-head">
    <div><h1>Manage users</h1><p class="sub">412 accounts · 8 administrators</p></div>
    <div class="spacer"><a class="btn btn-primary btn-sm" href="/admin/create-referral">${ic('plus')}Create referral</a></div>
  </div>

  <section class="card">
    <div class="card-h"><h2>${ic('users')}Accounts</h2>
      <div class="actions"><input class="control" type="search" placeholder="Search users…" style="min-height:32px;font-size:13px;width:200px"></div>
    </div>
    <div class="card-b flush">
      <div class="table-wrap">
        <table class="tbl">
          <thead><tr><th>User</th><th>Role</th><th>Balance</th><th>Keys</th><th>Last seen</th><th>Status</th><th class="right">Edit</th></tr></thead>
          <tbody>
            <tr><td><span class="game"><span class="game-ic" style="background:rgba(244,114,90,.18);color:#F4725A">Z</span>zeroadmin</span></td><td><span class="pill pill-info"><i></i>Admin</span></td><td class="num">$248.00</td><td class="num">1,506</td><td class="num">2 h ago</td><td><span class="pill pill-ok"><i></i>Active</span></td><td class="right"><button class="btn btn-ghost btn-sm btn-icon" aria-label="Edit user">${ic('edit')}</button></td></tr>
            <tr><td><span class="game"><span class="game-ic" style="background:rgba(167,139,250,.18);color:#A78BFA">R</span>reseller_id</span></td><td><span class="pill pill-mute"><i></i>Reseller</span></td><td class="num">$92.50</td><td class="num">338</td><td class="num">20 min ago</td><td><span class="pill pill-ok"><i></i>Active</span></td><td class="right"><button class="btn btn-ghost btn-sm btn-icon" aria-label="Edit user">${ic('edit')}</button></td></tr>
            <tr><td><span class="game"><span class="game-ic" style="background:rgba(74,222,128,.18);color:#4ADE80">A</span>andi_99</span></td><td><span class="pill pill-mute"><i></i>Member</span></td><td class="num">$4.00</td><td class="num">12</td><td class="num">3 d ago</td><td><span class="pill pill-ok"><i></i>Active</span></td><td class="right"><button class="btn btn-ghost btn-sm btn-icon" aria-label="Edit user">${ic('edit')}</button></td></tr>
            <tr><td><span class="game"><span class="game-ic" style="background:rgba(248,113,113,.18);color:#F87171">B</span>budi_x</span></td><td><span class="pill pill-mute"><i></i>Member</span></td><td class="num">$0.00</td><td class="num">3</td><td class="num">28 d ago</td><td><span class="pill pill-bad"><i></i>Suspended</span></td><td class="right"><button class="btn btn-ghost btn-sm btn-icon" aria-label="Edit user">${ic('edit')}</button></td></tr>
          </tbody>
        </table>
      </div>
      <div class="rows">
        <div class="row-card"><div class="row-top"><span class="game"><span class="game-ic" style="background:rgba(244,114,90,.18);color:#F4725A">Z</span>zeroadmin</span><span class="pill pill-info"><i></i>Admin</span></div><div class="row-meta"><span>Balance <b>$248.00</b></span><span>Keys <b>1,506</b></span><span>Seen <b>2 h ago</b></span></div></div>
        <div class="row-card"><div class="row-top"><span class="game"><span class="game-ic" style="background:rgba(167,139,250,.18);color:#A78BFA">R</span>reseller_id</span><span class="pill pill-mute"><i></i>Reseller</span></div><div class="row-meta"><span>Balance <b>$92.50</b></span><span>Keys <b>338</b></span><span>Seen <b>20 min ago</b></span></div></div>
        <div class="row-card"><div class="row-top"><span class="game"><span class="game-ic" style="background:rgba(74,222,128,.18);color:#4ADE80">A</span>andi_99</span><span class="pill pill-mute"><i></i>Member</span></div><div class="row-meta"><span>Balance <b>$4.00</b></span><span>Keys <b>12</b></span><span>Seen <b>3 d ago</b></span></div></div>
        <div class="row-card"><div class="row-top"><span class="game"><span class="game-ic" style="background:rgba(248,113,113,.18);color:#F87171">B</span>budi_x</span><span class="pill pill-bad"><i></i>Suspended</span></div><div class="row-meta"><span>Balance <b>$0.00</b></span><span>Keys <b>3</b></span><span>Seen <b>28 d ago</b></span></div></div>
      </div>
    </div>
  </section>
</main>`});

for (const [name, html] of Object.entries(pages)) {
  writeFileSync(`${OUT}${name}.html`, html);
}
console.log('built', Object.keys(pages).length, 'pages:', Object.keys(pages).join(', '));
