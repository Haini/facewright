#!/usr/bin/env python3
"""Ground-truth oracle: do the people in the tables match the people in the photos?

For one user whose photos come from the Georgia Tech set (file names carry the identity: .../s07_03.jpg), checks
OUR tables and RECOGNIZE'S tables, which are what Memories shows:
  pure       no person holds faces of two different identities
  complete   no identity is spread over two people
  agree      every face has the same person (or state) in Recognize's table as in ours
Pure and complete are the property the tables cannot show about themselves: a wrong face under a wrong person is
consistent in both tables and passes every row-for-row comparison.

  truth.py USER [--people N] [--each K] [--allow-split] [--quiet]
Exit 0 when all hold (and, with --people/--each, when there are exactly N people of K faces); 1 otherwise.
Read-only. Environment as for dev/lib.sh (DB_CONTAINER, DB_USER, DB_PASS, DB_NAME).
"""
import argparse, collections, os, re, subprocess, sys

def sql(q):
    r = subprocess.run(["docker", "exec", os.environ.get("DB_CONTAINER", "recognaize-db-1"), "mariadb", "-u" + os.environ.get("DB_USER", "nextcloud"),
                        "-p" + os.environ.get("DB_PASS", "dev-nextcloud-password"), os.environ.get("DB_NAME", "nextcloud"), "-N", "-e", q],
                       capture_output=True, text=True)
    if r.returncode: sys.exit("database error: " + r.stderr.strip()[:200])
    return [l.split("\t") for l in r.stdout.strip().split("\n") if l]

ap = argparse.ArgumentParser(); ap.add_argument("user"); ap.add_argument("--people", type=int); ap.add_argument("--each", type=int)
ap.add_argument("--allow-split", action="store_true"); ap.add_argument("--quiet", action="store_true"); a = ap.parse_args()
u = a.user.replace("'", "")
rows = sql(f"SELECT x.id, x.state, IFNULL(x.cluster_id,'NULL'), c.path, IFNULL(d.cluster_id,'MISSING') "
           f"FROM oc_facewright_user_faces x JOIN oc_facewright_faces f ON f.id=x.face_id JOIN oc_filecache c ON c.fileid=f.file_id "
           f"LEFT JOIN oc_recognize_face_detections d ON d.id=x.id WHERE x.user_id='{u}'")
EXPECT = {"0": "NULL", "2": "-1", "3": "-2", "4": "-3"}          # what Recognize holds for each hidden or non-person state
people = collections.defaultdict(list); spread = collections.defaultdict(set); disagree = []; states = collections.Counter(); unlabeled = 0
for fid, state, cluster, path, theirs in rows:
    m = re.search(r"(s\d\d)_\d+\.jpg$", path)
    if not m: unlabeled += 1; continue
    label = m.group(1); states[state] += 1
    want = cluster if state == "1" else EXPECT.get(state)
    if theirs != want: disagree.append((fid, state, want, theirs))
    if state == "1": people[cluster].append(label); spread[label].add(cluster)
impure = {c: collections.Counter(l) for c, l in people.items() if len(set(l)) > 1}
split = {l: sorted(cs) for l, cs in spread.items() if len(cs) > 1}
sizes = collections.Counter(len(l) for l in people.values())
fails = []
if not rows: fails.append("no faces for this user")
if impure: fails.append(f"{len(impure)} impure person(s)")
if split and not a.allow_split: fails.append(f"{len(split)} identity(ies) split over several people")
if disagree: fails.append(f"{len(disagree)} face(s) where Recognize's table disagrees with ours")
if a.people is not None and len(people) != a.people: fails.append(f"{len(people)} people, expected {a.people}")
if a.each is not None and any(len(l) != a.each for l in people.values()): fails.append(f"person sizes {dict(sizes)}, expected all {a.each}")
if not a.quiet:
    print(f"  {u}: {len(rows)-unlabeled} labelled faces; states {dict(sorted(states.items()))}; {len(people)} people; sizes {dict(sorted(sizes.items()))}")
    for c, cnt in list(impure.items())[:5]: print(f"  impure: person {c} holds {dict(cnt)}")
    for l, cs in list(split.items())[:5]: print(f"  split: {l} is in people {cs}")
    for d in disagree[:5]: print(f"  disagree: face {d[0]} state {d[1]}: ours {d[2]}, Recognize {d[3]}")
print("TRUTH " + ("OK" if not fails else "FAIL: " + "; ".join(fails)))
sys.exit(1 if fails else 0)
