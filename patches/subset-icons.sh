#!/usr/bin/env bash
# Rebuild the Bootstrap Icons subset from the icons the views actually use.
#
# RUN THIS whenever you add a new bi-* icon to a view, otherwise the new
# icon renders as a blank box. Needs: pip install fonttools brotli
set -euo pipefail
cd "$(dirname "$0")/.."

FULL_CSS="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
FULL_FONT="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2"
OUT="public/assets/vendor"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

curl -sSL --fail "$FULL_CSS"  -o "$TMP/full.css"
curl -sSL --fail "$FULL_FONT" -o "$TMP/full.woff2"

python3 - "$TMP" "$OUT" <<'PY'
import re, sys, pathlib, subprocess
tmp, out = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])
used = set()
for p in pathlib.Path('app/Views').rglob('*.php'):
    used |= set(re.findall(r'\bbi-([a-z0-9-]+)\b', p.read_text(errors='replace')))
full = tmp.joinpath('full.css').read_text()
name2cp = dict(re.findall(r'\.bi-([a-z0-9-]+)::?before\{content:"\\([0-9a-fA-F]+)"\}', full))
keep = {n: name2cp[n] for n in used if n in name2cp}
unknown = sorted(used - set(name2cp))
if unknown:
    print("  note: not real icons, ignored ->", unknown)
subprocess.run(['pyftsubset', str(tmp/'full.woff2'),
    '--unicodes=' + ','.join('U+'+c for c in sorted(keep.values())),
    '--flavor=woff2', '--no-hinting', '--desubroutinize', '--layout-features=',
    '--output-file=' + str(out/'fonts/bootstrap-icons-subset.woff2')], check=True)
rules = ''.join(f'.bi-{n}::before{{content:"\\{c}"}}' for n, c in sorted(keep.items()))
(out/'css/bootstrap-icons.min.css').write_text(
 f'/* Bootstrap Icons 1.11.3 - subset to the {len(keep)} icons this panel uses.\n'
 '   Regenerate with tools/subset-icons.sh after adding any new bi-* icon. */\n'
 '@font-face{font-family:"bootstrap-icons";font-display:swap;'
 'src:url("../fonts/bootstrap-icons-subset.woff2") format("woff2")}\n'
 '.bi::before,[class^="bi-"]::before,[class*=" bi-"]::before{'
 'display:inline-block;font-family:"bootstrap-icons"!important;font-style:normal;'
 'font-weight:normal!important;font-variant:normal;text-transform:none;line-height:1;'
 'vertical-align:-.125em;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}\n'
 + rules + '\n')
print(f"  subset rebuilt: {len(keep)} icons")
PY
ls -la "$OUT/fonts/bootstrap-icons-subset.woff2" "$OUT/css/bootstrap-icons.min.css"
