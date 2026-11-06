#!/usr/bin/env python3
"""Write the member sets of every person (and the pending, unassigned, unclusterable, ignored faces) per user to a
JSON file, independent of how the people are numbered.   usage: members.py OUT.json [user,user]
Environment: DB_CONTAINER, DB_USER, DB_PASS, DB_NAME (dev defaults)."""
import json, os, subprocess, sys
out = sys.argv[1]
users = (sys.argv[2] if len(sys.argv) > 2 else "admin,bob").split(",")
def sql(q):
    r = subprocess.run(["docker", "exec", os.environ.get("DB_CONTAINER", "recognaize-db-1"), "mariadb", "-u" + os.environ.get("DB_USER", "nextcloud"),
                        "-p" + os.environ.get("DB_PASS", "dev-nextcloud-password"), os.environ.get("DB_NAME", "nextcloud"), "-N", "-e", q],
                       capture_output=True, text=True)
    return [l.split("\t") for l in r.stdout.strip().split("\n") if l]
res = {}
for user in users:
    rows = sql(f"SELECT state, IFNULL(cluster_id,''), GROUP_CONCAT(id ORDER BY id) FROM oc_facewright_user_faces WHERE user_id='{user}' GROUP BY state, cluster_id")
    people, other = [], {}
    for state, cluster, ids in rows:
        ids = [int(i) for i in ids.split(",")]
        if state == "1": people.append(ids)
        else: other.setdefault(state, []).extend(ids)
    res[user] = {"people": sorted(people), "other": {k: sorted(v) for k, v in other.items()}}
json.dump(res, open(out, "w"), sort_keys=True)
print({u: (len(v["people"]), {k: len(x) for k, x in v["other"].items()}) for u, v in res.items()})
