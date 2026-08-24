#!/usr/bin/env bash
# Pre-compress static CSS/JS so Apache serves a ready-made .br/.gz twin
# instead of compressing on every request: zero CPU per hit, and brotli
# at max level beats anything done on the fly.
#
# Uses the brotli CLI if present, otherwise falls back to python3 -m brotli
# (pip install brotli). Re-run after changing any asset.
set -euo pipefail
cd "$(dirname "$0")/.."
python3 - <<'PY'
import gzip, pathlib, shutil, subprocess, sys
try:
    import brotli
    have_br = True
except ImportError:
    have_br = shutil.which('brotli') is not None
    brotli = None

files = [p for p in pathlib.Path('public/assets').rglob('*')
         if p.suffix in ('.css', '.js') and p.is_file()]
n_br = n_gz = 0
for p in files:
    data = p.read_bytes()
    gz = p.with_suffix(p.suffix + '.gz')
    gz.write_bytes(gzip.compress(data, 9)); n_gz += 1
    if brotli:
        p.with_suffix(p.suffix + '.br').write_bytes(brotli.compress(data, quality=11)); n_br += 1
    elif have_br:
        subprocess.run(['brotli','-f','-q','11','-o',str(p)+'.br',str(p)], check=False); n_br += 1
print(f"{len(files)} assets -> {n_gz} .gz, {n_br} .br")
if not brotli and not have_br:
    print("  note: no brotli available; gzip only. pip install brotli for ~15% more.")
PY
