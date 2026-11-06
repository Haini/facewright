#!/usr/bin/env python3
"""Compare two outputs of reads_direct.php. Exit 1 if they differ.  usage: compare_direct.py A.json B.json"""
import json, sys
a, b = json.load(open(sys.argv[1])), json.load(open(sys.argv[2]))
diffs = [(f, k, v, b["files"].get(f, {}).get(k)) for f in a["files"] for k, v in a["files"][f].items() if v != b["files"].get(f, {}).get(k)]
total = sum(len(v) for v in a["files"].values())
shared = sum(1 for v in a["files"].values() if len(v["usersWithRowsFor"]) > 1)
print(f"{len(a['files'])} files ({shared} held by more than one user), {total} per-file results compared, {len(diffs)} differ")
bad = False
for f, k, x, y in diffs[:8]:
    bad = True
    print(f"  file {f} {k}\n    before: {str(x)[:150]}\n    after:  {str(y)[:150]}")
for k in a:
    if k in ("files", "sample"):
        continue
    same = a[k] == b[k]
    print(f"{k}: {'identical' if same else 'DIFFERENT'}")
    if not same:
        bad = True
        if isinstance(a[k], dict):
            for kk in a[k]:
                if a[k][kk] != b[k].get(kk):
                    print(f"    {kk}: before={str(a[k][kk])[:120]}  after={str(b[k].get(kk))[:120]}")
        else:
            print(f"    before={a[k]}  after={b[k]}")
sys.exit(1 if bad or diffs else 0)
