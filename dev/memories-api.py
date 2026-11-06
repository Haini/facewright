#!/usr/bin/env python3
"""Capture what Memories itself shows for people, as the oracle for changes to the tables behind it.

For each user: the people list, every person's days and photos (Memories' own queries over Recognize's tables),
and the unassigned list. Output is canonical JSON (sorted), so two captures of unchanged data are byte-identical.

  memories-api.py capture OUT.json [user:password,...]     default admin and bob (dev passwords)
  memories-api.py compare A.json B.json                      exit 0 when identical; prints what differs
  memories-api.py stable [user:password,...]                 two captures, must be identical
Environment: NC_CONTAINER.
"""
import concurrent.futures, json, os, subprocess, sys, urllib.parse

NC = os.environ.get("NC_CONTAINER", "recognaize-nextcloud-1")
DEFAULT = "admin:dev-admin-password"   # bob has no Memories timeline folder, so Memories refuses him

def get(user, pw, path):
    r = subprocess.run(["docker", "exec", NC, "curl", "-s", "-u", f"{user}:{pw}", "-H", "OCS-APIRequest: true", f"http://localhost/apps/memories/api/{path}"],
                       capture_output=True, text=True)
    try:
        return json.loads(r.stdout)
    except ValueError:
        sys.exit(f"not JSON from {path}: {r.stdout[:120]!r}")

def capture(users):
    out = {}
    for pair in users.split(","):
        user, pw = pair.split(":", 1)
        people = get(user, pw, "clusters/recognize")
        if not isinstance(people, list): sys.exit(f"{user}: people list is not a list: {str(people)[:120]}")
        entry = {"people": sorted(people, key=lambda p: p["id"]), "days": {}}
        keys = [str(p["id"]) for p in entry["people"]] + ["NULL"]
        def one(k):
            q = urllib.parse.quote(k)
            days = get(user, pw, f"days?recognize={user}/{q}")
            if not isinstance(days, list): sys.exit(f"{user}/{k}: days is not a list: {str(days)[:120]}")
            for d in days:   # the preview rows come in arbitrary order, like the photos
                if isinstance(d.get("detail"), list): d["detail"] = sorted(d["detail"], key=lambda x: json.dumps(x, sort_keys=True))
            return k, {"days": days, "photos": {str(d["dayid"]): sorted(get(user, pw, f"days/{d['dayid']}?recognize={user}/{q}"), key=lambda x: (x["fileid"], x.get("faceid", 0), json.dumps(x, sort_keys=True))) for d in days}}
        with concurrent.futures.ThreadPoolExecutor(8) as ex:
            entry["days"] = dict(ex.map(one, keys))
        out[user] = entry
    return out

def summarise(c):
    return "; ".join(f"{u}: {len(e['people'])} people, {sum(len(v['photos']) and sum(len(x) for x in v['photos'].values()) for v in e['days'].values())} photo entries" for u, e in c.items())

def diff(a, b):
    lines = []
    for u in sorted(set(a) | set(b)):
        if u not in a or u not in b: lines.append(f"{u}: only in one capture"); continue
        pa, pb = {p["id"]: p for p in a[u]["people"]}, {p["id"]: p for p in b[u]["people"]}
        for i in sorted(set(pa) | set(pb), key=str):
            if pa.get(i) != pb.get(i): lines.append(f"{u}: person {i} differs: {pa.get(i)} -> {pb.get(i)}")
        for k in sorted(set(a[u]["days"]) | set(b[u]["days"]), key=str):
            if a[u]["days"].get(k) != b[u]["days"].get(k): lines.append(f"{u}: photos of person {k} differ")
    return lines

cmd = sys.argv[1] if len(sys.argv) > 1 else ""
if cmd == "capture":
    c = capture(sys.argv[3] if len(sys.argv) > 3 else DEFAULT); json.dump(c, open(sys.argv[2], "w"), sort_keys=True); print("captured", summarise(c))
elif cmd == "compare":
    d = diff(json.load(open(sys.argv[2])), json.load(open(sys.argv[3])))
    print("\n".join(d[:20]) if d else "identical"); sys.exit(1 if d else 0)
elif cmd == "stable":
    users = sys.argv[2] if len(sys.argv) > 2 else DEFAULT
    a, b = capture(users), capture(users); d = diff(a, b); print(summarise(a)); print("STABLE: two captures identical" if not d else "NOT STABLE:\n" + "\n".join(d[:10])); sys.exit(1 if d else 0)
else:
    print(__doc__); sys.exit(2)
