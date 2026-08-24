#!/usr/bin/env python3
import re, shutil, sys, pathlib

HT = pathlib.Path('.htaccess')

# Directives that belong to exactly one mode. Matched on the directive
# itself, with any leading whitespace and any existing "# " preserved.
CF_ONLY = [
    r'RewriteCond %\{HTTP:CF-IPCountry\} \^\$',
    r'RewriteRule \^\.\*\$ - \[F,L\]',
    r'RewriteCond %\{HTTP:X-Forwarded-Proto\} !https',
    r'<RequireAny>', r'</RequireAny>', r'Require ip \S+',
]
DIRECT_ONLY = [
    r'Require all granted',
    r'RewriteCond %\{HTTPS\} off',
    r'RewriteRule \^ https://%\{HTTP_HOST\}%\{REQUEST_URI\} \[L,R=301\]',
]
# The CF HTTPS redirect shares its RewriteRule line with the direct one,
# and its own RewriteCond %{HTTPS} off sits directly under the
# X-Forwarded-Proto line. Treat that pair as CF-only by position.
def classify(lines):
    """Return index -> 'cf' | 'direct' | None."""
    kind = {}
    for i, raw in enumerate(lines):
        body = re.sub(r'^\s*#\s?', '', raw).strip()
        for pat in CF_ONLY:
            if re.fullmatch(pat, body):
                kind[i] = 'cf'; break
        else:
            for pat in DIRECT_ONLY:
                if re.fullmatch(pat, body):
                    kind[i] = 'direct'; break
    # the two lines immediately after the X-Forwarded-Proto line are the
    # CF redirect's own cond+rule, not the direct-mode pair
    for i, raw in enumerate(lines):
        if re.fullmatch(r'RewriteCond %\{HTTP:X-Forwarded-Proto\} !https',
                        re.sub(r'^\s*#\s?', '', raw).strip()):
            for j in (i+1, i+2):
                if j < len(lines) and kind.get(j) == 'direct':
                    kind[j] = 'cf'
    return kind

def is_commented(line): return re.match(r'^\s*#', line) is not None
def comment(line):
    if is_commented(line): return line
    m = re.match(r'^(\s*)(.*)$', line); return f"{m.group(1)}# {m.group(2)}"
def uncomment(line):
    m = re.match(r'^(\s*)#\s?(.*)$', line)
    return f"{m.group(1)}{m.group(2)}" if m else line

def current_mode(lines):
    for i, l in enumerate(lines):
        if re.fullmatch(r'Require ip 104\.16\.0\.0/13', re.sub(r'^\s*#\s?', '', l).strip()):
            return 'cloudflare' if not is_commented(l) else 'direct'
    return 'unknown'

def apply(mode):
    lines = HT.read_text().splitlines()
    kind = classify(lines)
    out = []
    for i, l in enumerate(lines):
        k = kind.get(i)
        if k == 'cf':
            out.append(uncomment(l) if mode == 'cloudflare' else comment(l))
        elif k == 'direct':
            out.append(comment(l) if mode == 'cloudflare' else uncomment(l))
        else:
            out.append(l)
    shutil.copy(HT, str(HT) + '.bak')
    HT.write_text('\n'.join(out) + '\n')

cmd = sys.argv[1] if len(sys.argv) > 1 else 'status'
if cmd in ('cloudflare', 'a', 'A'):
    apply('cloudflare')
    print("switched to MODE A (behind Cloudflare)")
    print("now set in .env:   app.behindCloudflare = true")
elif cmd in ('direct', 'b', 'B'):
    apply('direct')
    print("switched to MODE B (direct, no Cloudflare)")
    print("now set in .env:   app.behindCloudflare = false")
    print("\nread DEPLOYMENT-MODES.md - MODE B loses edge DDoS filtering,")
    print("so the origin IP is public and app-level limits carry the load.")
elif cmd == 'status':
    m = current_mode(HT.read_text().splitlines())
    print(f"active mode: {m}")
    print(f"\n  .env must contain:  app.behindCloudflare = "
          f"{'true' if m == 'cloudflare' else 'false'}")
else:
    print("usage: set-mode.sh {cloudflare|direct|status}"); sys.exit(1)
