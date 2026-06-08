#!/usr/bin/env python3
"""
MapleBoost integrity check.
Run BEFORE every FTP upload and after any bulk/site-wide edit.

Flags two kinds of corruption seen in this project:
  1. NUL bytes  (\\x00) anywhere in an .html file  -> usually trailing, repairable
  2. Truncated  -> file doesn't end with the footer include or </html>

Usage:
    python3 check_integrity.py            # scan + report
    python3 check_integrity.py --fix-nul  # also strip trailing NULs in place

Exit code 0 = clean, 1 = problems found (handy for blocking an upload script).
"""
import os, sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "public_html")
FIX = "--fix-nul" in sys.argv

nul, trunc, ok = [], [], 0
for dp, _, fs in os.walk(ROOT):
    for fn in fs:
        if not fn.endswith(".html"):
            continue
        p = os.path.join(dp, fn)
        rel = os.path.relpath(p, ROOT)
        b = open(p, "rb").read()
        if b"\x00" in b:
            nul.append(rel)
            if FIX:
                open(p, "wb").write(b.replace(b"\x00", b""))
            continue
        content = b.strip()
        head = content[:200].lstrip().lower()
        is_full_page = head.startswith(b"<!doctype") or head.startswith(b"<html")
        tail = content[-60:]
        if is_full_page:
            # full pages must end at the footer include or </html>
            ok_end = tail.rstrip().endswith(b"-->") or b"</html>" in tail
        else:
            # partial includes (inc/*.html fragments) just need to end on a closed tag
            ok_end = content.endswith(b">")
        if ok_end:
            ok += 1
        else:
            trunc.append((rel, tail[-45:].decode("utf-8", "replace")))

print(f"Scanned {ROOT}")
print(f"  OK: {ok}   NUL: {len(nul)}   Truncated: {len(trunc)}\n")

if nul:
    print(f"NUL-byte files ({'FIXED' if FIX else 'run with --fix-nul to repair'}):")
    for r in sorted(nul):
        print("   ", r)
    print()
if trunc:
    print("TRUNCATED files (content lost - restore from backup/git, cannot auto-fix):")
    for r, t in sorted(trunc):
        print(f"    {r}\n        ...ends: {t!r}")
    print()

if not nul and not trunc:
    print("All clean. Safe to upload.")
    sys.exit(0)
sys.exit(1)
