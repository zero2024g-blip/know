#!/usr/bin/env python3
# ============================================================================
#  diversify.py — per-release build diversification.
#
#  Every game update, bump --seed. This regenerates the protocol field names,
#  crypto tag, guard constant, build id, string-XOR key and watermark locator —
#  DETERMINISTICALLY, so the server and client agree, but DIFFERENTLY from the
#  last release. An old crack (offsets, patches, a wire parser the attacker
#  built) no longer lines up with the new build, so they must redo the work.
#  That is how you multiply their cost and let each update rot the crack.
#
#  Usage:
#     python diversify.py --seed 2026.09.01-r1 --out build/
#  Produces:
#     build/build_config.h    (include in the C/C++ client)
#     build/build_config.php   (include in ConnectV2.php)
#  The two are guaranteed to match (same seed -> same values).
#
#  Keep the seed list in a private file; never ship it. The seed is not a
#  secret by itself, but reusing one defeats the point.
# ============================================================================
import argparse, hashlib, os

# Logical protocol fields -> get a fresh opaque wire token each release.
FIELDS = [
    "game", "app_ver", "user_key", "serial", "public", "ts", "cnonce", "tamper",
    "status", "data", "gameobj", "reason", "id", "token", "salt", "rng",
    "exp", "access", "payload", "cfg", "ttime",
]
ALPHA = "abcdefghijklmnopqrstuvwxyz0123456789"

def prng(seed: str, label: str, n: int) -> bytes:
    """Deterministic bytes from (seed,label) via SHA-256 counter mode."""
    out = b""
    i = 0
    while len(out) < n:
        out += hashlib.sha256(f"{seed}|{label}|{i}".encode()).digest()
        i += 1
    return out[:n]

def token(seed: str, field: str, length: int, used: set) -> str:
    """A short unique wire token for a field."""
    for attempt in range(64):
        b = prng(seed, f"tok:{field}:{attempt}", length)
        t = "".join(ALPHA[x % len(ALPHA)] for x in b)
        if t not in used:
            used.add(t)
            return t
    raise RuntimeError("token space exhausted")

def gen(seed: str) -> dict:
    used = set()
    # ts/cnonce stay short (1 char) like the current protocol; rest are 2 chars.
    lengths = {"ts": 1, "cnonce": 1}
    fields = {f: token(seed, f, lengths.get(f, 2), used) for f in FIELDS}

    crypto_tag = "E" + token(seed, "cryptotag", 2, used).upper()      # e.g. EG2 -> EK9
    build_id   = "0x" + prng(seed, "buildid", 6).hex().upper()
    guard_salt = "0x" + prng(seed, "guardsalt", 8).hex().upper() + "ULL"
    str_xor    = prng(seed, "strxor", 1)[0] or 0x5A
    wm_loc     = prng(seed, "wmloc", 16)
    return {
        "seed": seed, "fields": fields, "crypto_tag": crypto_tag,
        "build_id": build_id, "guard_salt": guard_salt,
        "str_xor": str_xor, "wm_loc": wm_loc,
    }

def write_h(cfg: dict, path: str):
    f = cfg["fields"]
    wm = ",".join(f"0x{b:02X}" for b in cfg["wm_loc"])
    lines = [
        "// AUTO-GENERATED per release by diversify.py — DO NOT edit by hand.",
        f"// seed: {cfg['seed']}",
        "#ifndef BUILD_CONFIG_H",
        "#define BUILD_CONFIG_H",
        f'#define BC_CRYPTO_TAG "{cfg["crypto_tag"]}"',
        f"#define BC_BUILD_ID   {cfg['build_id']}ULL",
        f"#define BC_GUARD_SALT {cfg['guard_salt']}",
        f"#define BC_STR_XOR    0x{cfg['str_xor']:02X}",
        f"#define BC_WM_LOCATOR {{ {wm} }}",
        "// wire field names (rotate every release):",
    ]
    for k in FIELDS:
        lines.append(f'#define BC_F_{k.upper():<9} "{f[k]}"')
    lines.append("#endif")
    open(path, "w").write("\n".join(lines) + "\n")

def write_php(cfg: dict, path: str):
    f = cfg["fields"]
    wm = ",".join(f"0x{b:02X}" for b in cfg["wm_loc"])
    lines = [
        "<?php",
        "// AUTO-GENERATED per release by diversify.py — DO NOT edit by hand.",
        f"// seed: {cfg['seed']}",
        "return [",
        f"    'crypto_tag' => '{cfg['crypto_tag']}',",
        f"    'build_id'   => '{cfg['build_id']}',",
        f"    'str_xor'    => {cfg['str_xor']},",
        f"    'wm_locator' => [{wm}],",
        "    'fields' => [",
    ]
    for k in FIELDS:
        lines.append(f"        '{k}' => '{f[k]}',")
    lines += ["    ],", "];"]
    open(path, "w").write("\n".join(lines) + "\n")

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--seed", required=True, help="unique per release, e.g. 2026.09.01-r1")
    ap.add_argument("--out", default="build", help="output directory")
    a = ap.parse_args()
    os.makedirs(a.out, exist_ok=True)
    cfg = gen(a.seed)
    write_h(cfg, os.path.join(a.out, "build_config.h"))
    write_php(cfg, os.path.join(a.out, "build_config.php"))
    print(f"generated for seed '{a.seed}':")
    print("  crypto tag:", cfg["crypto_tag"], " build id:", cfg["build_id"])
    print("  a few field tokens:", {k: cfg["fields"][k] for k in ("game","user_key","serial","payload","cfg")})
    print("  ->", os.path.join(a.out, "build_config.h"), "and build_config.php")

if __name__ == "__main__":
    main()
