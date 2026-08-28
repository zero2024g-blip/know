# Cloudflare settings for the panel — security and speed

The panel already assumes it sits behind Cloudflare (MODE A in `.htaccess`):
it trusts `CF-Connecting-IP` only from Cloudflare ranges, and the origin only
accepts connections from those ranges. These settings make that setup safe and
fast. Dashboard paths are for the current Cloudflare UI; names move around, but
the section headings are stable.

## SSL/TLS
- **Overview → mode: Full (strict).** Not Flexible — Flexible leaves the hop
  from Cloudflare to your origin unencrypted and lets anyone who reaches the
  origin downgrade to HTTP. Full (strict) needs a valid cert on the origin;
  Hostinger gives you one, or use a Cloudflare Origin Certificate.
- **Edge Certificates → Always Use HTTPS: On.**
- **Minimum TLS Version: 1.2** (1.3 is negotiated automatically).
- **Automatic HTTPS Rewrites: On.**
- **HSTS: enable** once you are sure everything is HTTPS — max-age 6–12 months,
  include subdomains. The panel already sends the HSTS header itself; turning it
  on at the edge too is belt and braces. Do not enable HSTS preload until you
  are certain no subdomain will ever need plain HTTP.

## DNS
- The record for `panel.zeromods.id` must be **Proxied (orange cloud)**. Grey
  cloud exposes the origin IP and every protection below is bypassed.
- Delete or proxy any stray record (a `mail`, `ftp`, old `cpanel` A record)
  that points at the same origin IP — an attacker who finds the IP through one
  of those reaches the origin directly. This is the single most common way a
  Cloudflare setup is defeated.

## Speed / caching
- **Speed → Optimization → Brotli: On.** This is what compresses your HTML on
  the way to the visitor; the panel ships pre-compressed `.br` assets, and
  Brotli covers the rest.
- **Caching → Tiered Cache: On** (free), and **Caching Level: Standard.**
- **Browser Cache TTL: Respect Existing Headers** — the panel already sends
  `immutable, max-age=1 year` on assets and `no-store` on pages; let it.
- **Do NOT enable "Cache Everything" for the panel.** It would cache logged-in
  HTML at the edge and hand one person's page to another. The panel's own
  headers stop this, but a Cache-Everything Page Rule can override them, so
  just don't add one for this hostname.
- **Auto Minify: leave OFF** for CSS/JS — the assets are already minified and
  Cloudflare's minifier has broken working files before. HTML minify is safe
  if you want it.
- **Rocket Loader: OFF.** It reorders and defers scripts and reliably breaks
  jQuery/DataTables pages like the key list.
- **Early Hints: On** is safe and helps first paint.
- **HTTP/2 and HTTP/3 (with QUIC): On** — big win on high-latency mobile links,
  which is most of your traffic.
- **0-RTT Connection Resumption: On** — faster repeat connections on mobile.

## Security
- **Security → Settings → Security Level: Medium** (High if you are under
  active abuse; it challenges more visitors).
- **Bot Fight Mode: On** (free) — knocks out the dumb scanners that hammer
  `/login` and `/check`.
- **Challenge Passage: 30 minutes** so a real visitor is not re-challenged
  constantly.
- **WAF → Managed Rules: On** (the free Cloudflare ruleset). It stops most
  generic SQLi/XSS probes at the edge before they ever reach PHP.
- **Rate limiting rules** (one free rule is included — spend it on login):
  - Match: URI Path equals `/login` (or `contains /login`), Method POST.
  - When rate exceeds **5 requests per minute per IP** → Action: Block (or
    Managed Challenge) for 10 minutes.
  - This sits in front of the panel's own login limiter, so an attacker is
    stopped at the edge and never costs your origin a request. If you can add
    more rules, add the same for `/register` and `/data/zezr_connector`.
- **Scrape Shield → Email Address Obfuscation: On.**

## WAF custom rules (optional, high value)
- **Block by country** if your customers are all in a few countries — Security →
  WAF → Custom rules → `ip.geoip.country` not in {your countries} → Managed
  Challenge. Cuts a huge amount of scanner noise. Do NOT hard-block if any
  legitimate seller travels or uses a VPN.
- **Protect the connector path**: allow `/data/zezr_connector` only for POST,
  challenge everything else — it should never be opened in a browser.
- **Lock the admin area harder** if your own IP is stable: challenge
  `/admin/*` for any IP that is not yours. Skip this if your IP changes (most
  Iranian mobile connections do — it would lock you out).

## What NOT to do
- Do not turn on **Cache Everything** for this hostname (leaks logged-in pages).
- Do not turn on **Rocket Loader** (breaks the tables).
- Do not put the DNS record on **grey cloud** (exposes the origin).
- Do not set SSL to **Flexible** (unencrypted origin hop).
- Do not **Auto-Minify JS/CSS** (already minified; risks breakage).

## After you set this up
Re-check that the origin only answers Cloudflare: from a machine that is not
Cloudflare, `curl -I https://<your-origin-ip>/` should hang or be refused, and
only `https://panel.zeromods.id/` should work. If the raw IP answers, the
grey-cloud/stray-record problem above is still open.
