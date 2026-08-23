import { chromium } from 'playwright-core';
import { readdirSync } from 'fs';

const DIR = new URL('./', import.meta.url).pathname;
const PAGES = readdirSync(DIR).filter(f => f.endsWith('.html')).map(f => f.replace('.html',''));

const VIEWS = [
  ['desktop', 1440, 900,  2],
  ['mobile',   390, 844,  3],
];

/* ---- WCAG contrast ---- */
function lum(rgb){
  const a = rgb.map(v => { v/=255; return v<=0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055,2.4); });
  return 0.2126*a[0]+0.7152*a[1]+0.0722*a[2];
}
function ratio(fg,bg){ const L1=lum(fg),L2=lum(bg); const [a,b]=L1>L2?[L1,L2]:[L2,L1]; return (a+0.05)/(b+0.05); }
function parse(c){ const m=c.match(/\d+(\.\d+)?/g); return m ? m.slice(0,3).map(Number) : null; }

const b = await chromium.launch({ executablePath:'/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args:['--no-sandbox'] });
const problems = [];
let shots = 0;

for (const [vname, w, h, dsf] of VIEWS){
  const ctx = await b.newContext({
    viewport:{width:w,height:h}, deviceScaleFactor:dsf,
    hasTouch: vname==='mobile', isMobile: vname==='mobile',
  });
  for (const name of PAGES){
    const p = await ctx.newPage();
    const jsErrs = [];
    p.on('pageerror', e => jsErrs.push(e.message));
    await p.goto(`file://${DIR}${name}.html`, { waitUntil:'networkidle', timeout:40000 });
    await p.waitForTimeout(700);

    const report = await p.evaluate((isMobile) => {
      const out = { overflow:0, tiny:[], clipped:[], contrast:[], fontFallback:false };
      out.overflow = document.documentElement.scrollWidth - document.documentElement.clientWidth;

      // touch targets on mobile
      if (isMobile){
        document.querySelectorAll('button,a,input,select,.seg-opt,.switch,.check,.pg,.copy').forEach(el=>{
          const r = el.getBoundingClientRect();
          if (r.width===0 || r.height===0) return;
          if (!el.checkVisibility || !el.checkVisibility()) return;
          const st = getComputedStyle(el);
          if (st.display==='none' || st.visibility==='hidden') return;
          // hit area may be widened by a ::before overlay; measure the effective box
          const pb = getComputedStyle(el, '::before');
          if (pb && pb.content !== 'none'){
            const gy = Math.abs(parseFloat(pb.top)||0) + Math.abs(parseFloat(pb.bottom)||0);
            const gx = Math.abs(parseFloat(pb.left)||0) + Math.abs(parseFloat(pb.right)||0);
            if (r.height + gy >= 44 && r.width + gx >= 44) return;
          }
          if (r.height < 32 || r.width < 24){
            out.tiny.push(`${el.tagName.toLowerCase()}.${(el.className||'').toString().split(' ')[0]} ${Math.round(r.width)}x${Math.round(r.height)}`);
          }
        });
      }

      // text clipped by its own box
      document.querySelectorAll('.key,.kpi .v,.pill,.btn,.balance,h1,h2').forEach(el=>{
        if (el.scrollWidth > el.clientWidth + 2 && getComputedStyle(el).overflow !== 'visible'
            && getComputedStyle(el).textOverflow !== 'ellipsis'){
          out.clipped.push(`${el.className||el.tagName}: ${el.scrollWidth}>${el.clientWidth}`);
        }
      });

      // contrast of visible text against nearest painted ancestor
      const seen = new Set();
      document.querySelectorAll('body *').forEach(el=>{
        if (!el.childNodes.length) return;
        const hasText = [...el.childNodes].some(n=>n.nodeType===3 && n.textContent.trim().length>1);
        if (!hasText) return;
        const st = getComputedStyle(el);
        if (st.visibility==='hidden' || st.display==='none' || parseFloat(st.opacity)===0) return;
        const fs = parseFloat(st.fontSize), fw = parseInt(st.fontWeight)||400;
        // composite every translucent layer down to the first opaque one
        const layers = [];
        let bgEl = el;
        while (bgEl){
          const c = getComputedStyle(bgEl).backgroundColor;
          const m = c && c.match(/[\d.]+/g);
          if (m){
            const a = m.length > 3 ? parseFloat(m[3]) : 1;
            if (a > 0){ layers.push([+m[0],+m[1],+m[2],a]); if (a >= 1) break; }
          }
          bgEl = bgEl.parentElement;
        }
        if (!layers.length) layers.push([18,13,11,1]);
        if (layers[layers.length-1][3] < 1) layers.push([18,13,11,1]);
        let comp = layers[layers.length-1].slice(0,3);
        for (let i = layers.length-2; i >= 0; i--){
          const [r,g,bl,a] = layers[i];
          comp = [r*a + comp[0]*(1-a), g*a + comp[1]*(1-a), bl*a + comp[2]*(1-a)];
        }
        const bg = `rgb(${comp.map(v=>Math.round(v)).join(', ')})`;
        const key = `${st.color}|${bg}|${fs}|${fw}`;
        if (seen.has(key)) return; seen.add(key);
        out.contrast.push({ color:st.color, bg, fs, fw, sample:(el.textContent||'').trim().slice(0,28) });
      });

      // sized boxes that collapsed to zero (the inline-span trap)
      out.collapsed = [];
      document.querySelectorAll('.meter-fill,.strength i,.switch,.avatar,.game-ic,.rh-av,.empty-ic,.auth-mark').forEach(el=>{
        if (!el.checkVisibility || !el.checkVisibility()) return;   // hidden responsive half
        const r = el.getBoundingClientRect();
        const declaredWidth = el.style.width || getComputedStyle(el).width;
        const wantsWidth = declaredWidth && declaredWidth !== '0px' && declaredWidth !== 'auto';
        if (r.height === 0 || (wantsWidth && r.width === 0 && !/(^|\s)0%/.test(el.style.width||''))){
          out.collapsed.push(`${el.className} ${Math.round(r.width)}x${Math.round(r.height)} (declared ${declaredWidth})`);
        }
      });

      out.fontFallback = !document.fonts.check('16px Poppins');
      return out;
    }, vname==='mobile');

    if (jsErrs.length) problems.push(`[${vname}/${name}] JS error: ${jsErrs[0]}`);
    if (report.overflow > 0) problems.push(`[${vname}/${name}] horizontal overflow ${report.overflow}px`);
    if (report.fontFallback) problems.push(`[${vname}/${name}] Poppins did not load`);
    report.tiny.slice(0,4).forEach(t => problems.push(`[${vname}/${name}] small tap target: ${t}`));
    report.clipped.slice(0,4).forEach(t => problems.push(`[${vname}/${name}] clipped text: ${t}`));
    (report.collapsed||[]).slice(0,4).forEach(t => problems.push(`[${vname}/${name}] collapsed box: ${t}`));

    for (const c of report.contrast){
      const fg = parse(c.color), bg = parse(c.bg);
      if (!fg || !bg) continue;
      const large = c.fs >= 24 || (c.fs >= 18.66 && c.fw >= 700);
      const need = large ? 3 : 4.5;
      const r = ratio(fg,bg);
      if (r < need){
        problems.push(`[${vname}/${name}] contrast ${r.toFixed(2)}:1 (need ${need}) — "${c.sample}" ${c.color} on ${c.bg}`);
      }
    }

    await p.screenshot({ path:`${DIR}shots/${name}--${vname}.png`, fullPage:true });
    shots++;
    await p.close();
  }
  await ctx.close();
}
await b.close();

console.log(`\nrendered ${shots} screenshots across ${PAGES.length} pages x ${VIEWS.length} viewports`);
if (!problems.length){ console.log('CLEAN — no overflow, no tap-target, no contrast, no JS problems'); }
else {
  const uniq = [...new Set(problems)];
  console.log(`\n${uniq.length} PROBLEM(S):`);
  uniq.forEach(p => console.log(' -', p));
}
