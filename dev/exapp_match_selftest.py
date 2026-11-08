"""Checks ex_app/lib/clustering.py's match_chunk() against a literal port of the bridge's PHP loop.

Runs inside the ExApp container (it imports the module from /ex_app/lib):
    docker exec -i recognaize-facewright-1 python3 - < dev/exapp_match_selftest.py
The port follows ClusteringService::assignClusters()/findMatches() line by line: a dict in pool order, a sequential
dot product in doubles, a stable sort. The data has clusters, existing people, unlabelled noise and exact duplicate
embeddings (ties). Exit 0 when every decision and label agrees; it also plants a fault to show it can fail.
"""

import sys

import numpy as np

sys.path.insert(0, "/ex_app/lib")
from clustering import match_chunk  # noqa: E402


def php_port(pool, attempt, max_distance, min_faces):
    """pool: list of (row_id, vector as list of floats, label or None) in pool order."""
    detections = {row_id: {"vector": vec, "cluster_id": label} for row_id, vec, label in pool}
    next_new = -1
    decisions = []
    for row_id in attempt:
        if detections[row_id]["cluster_id"] is not None:
            decisions.append((row_id, "already", detections[row_id]["cluster_id"]))
            continue
        matches = []
        mine = detections[row_id]["vector"]
        for other_id, other in detections.items():
            if other_id == row_id:
                continue
            dot = 0.0
            for i, v in enumerate(mine):
                dot += v * other["vector"][i]
            distance = 1.0 - dot
            if distance <= max_distance:
                matches.append((other_id, distance))
        matches.sort(key=lambda m: m[1])  # stable, like PHP 8's usort
        if len(matches) + 1 < min_faces:
            decisions.append((row_id, "skip", None))
            continue
        cluster_id = None
        for other_id, _ in matches:
            if detections[other_id]["cluster_id"] is not None:
                cluster_id = detections[other_id]["cluster_id"]
                break
        action = "join"
        if cluster_id is None:
            cluster_id = next_new
            next_new -= 1
            action = "new"
        detections[row_id]["cluster_id"] = cluster_id
        for other_id, _ in matches:
            if detections[other_id]["cluster_id"] is None:
                detections[other_id]["cluster_id"] = cluster_id
        decisions.append((row_id, action, cluster_id))
    return decisions, {row_id: d["cluster_id"] for row_id, d in detections.items()}


def make_pool(rng, people=40, per_person=8, noise=60, existing=10, duplicates=15):
    rows = []
    centres = rng.normal(size=(people, 512))
    for p in range(people):
        for _ in range(per_person):
            v = centres[p] + rng.normal(scale=0.9, size=512)
            rows.append([v, p + 1 if p < existing and rng.random() < 0.5 else None])
    for _ in range(noise):
        rows.append([rng.normal(size=512), None])
    for _ in range(duplicates):
        source = rows[rng.integers(len(rows))]
        rows.append([source[0].copy(), None])
    order = rng.permutation(len(rows))  # pool order is not id order, as in loadDetections()
    pool = []
    for position, index in enumerate(order):
        v = rows[index][0]
        v = (v / np.linalg.norm(v)).astype("<f4")  # float32 like the stored embedding
        pool.append((1000 + int(index), [float(x) for x in v], rows[index][1]))
    return pool


def engine(pool, attempt, max_distance, min_faces, fault=False):
    vectors = np.array([vec for _, vec, _ in pool], dtype=np.float64)
    labels = np.array([label or 0 for _, _, label in pool], dtype=np.int64)
    position_of = {row_id: i for i, (row_id, _, _) in enumerate(pool)}
    if fault:  # planted: drop the stable tie order
        rev = list(range(len(pool)))[::-1]
        vectors, labels = vectors[rev], labels[rev]
        pool = [pool[i] for i in rev]
        position_of = {row_id: i for i, (row_id, _, _) in enumerate(pool)}
    decisions, after = match_chunk(vectors, labels, [position_of[r] for r in attempt], max_distance, min_faces)
    out = [(pool[d["position"]][0], d["action"], d["label"]) for d in decisions]
    return out, {pool[i][0]: (int(after[i]) or None) for i in range(len(pool))}


def run(seed, fault=False):
    rng = np.random.default_rng(seed)
    pool = make_pool(rng)
    pending = sorted(row_id for row_id, _, label in pool if label is None)
    max_distance, min_faces = 0.5, 3
    # Tune the threshold so the data clusters a fair amount (the synthetic spread is arbitrary).
    sims = np.array([v for _, v, _ in pool]) @ np.array(pool[0][1])
    max_distance = float(np.clip(1 - np.quantile(sims, 0.97), 0.2, 0.9))
    bad = 0
    for chunk in (pending[:120], pending[120:]):  # two chunks; the second starts from the first's PHP state
        php_d, php_l = php_port(pool, chunk, max_distance, min_faces)
        np_d, np_l = engine(pool, chunk, max_distance, min_faces, fault)
        if php_d != np_d or php_l != np_l:
            bad += 1
            diff = [(a, b) for a, b in zip(php_d, np_d) if a != b][:3]
            print(f"  seed {seed}: chunk differs, first decisions: {diff}")
        # As production would have created those people: temporary labels become positive stand-in ids.
        pool = [(r, v, (1_000_000 - php_l[r]) if (php_l[r] or 0) < 0 else php_l[r]) for r, v, _ in pool]
    actions = [a for _, a, _ in php_d]
    return bad, {a: actions.count(a) for a in set(actions)}


def tie_case(fault=False):
    """Two people holding the same photo (identical embeddings) and a third copy pending: pool order decides."""
    rng = np.random.default_rng(99)
    v = rng.normal(size=512)
    v = [float(x) for x in (v / np.linalg.norm(v)).astype("<f4")]
    w = rng.normal(size=512)
    w = [float(x) for x in (w / np.linalg.norm(w)).astype("<f4")]
    pool = [(1, v, 7), (2, w, None), (3, v, 9), (4, v, None)]
    php = php_port(pool, [4], 0.5, 3)
    mine = engine(pool, [4], 0.5, 3, fault)
    return php == mine, php[0]


ok, decision = tie_case()
print(f"tie case: {'OK' if ok else 'DIFFERS'} ({decision})")
failures = 0 if ok else 1
tie_fault_caught = not tie_case(fault=True)[0]
print(f"planted fault (pool order reversed) caught in the tie case: {tie_fault_caught}")
for seed in range(5):
    bad, counts = run(seed)
    print(f"seed {seed}: {'OK' if not bad else 'DIFFERS'} (last chunk {counts})")
    failures += bad
if failures or not tie_fault_caught:
    sys.exit(1)
print("match_chunk agrees with the PHP port")
