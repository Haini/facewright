"""Writes request bodies for dev/exapp-cluster.sh. Runs inside the ExApp container (it has NumPy):
    python3 - OUT_DIR N < dev/exapp_cluster_gen.py
put-NNN.json: N synthetic faces (ids from 900,000,000, far above dev's real ids) in batches of 2,000.
cluster.json: a pool of all N, every fifth labelled as one of 500 people, 300 attempts.
cluster-missing.json / cluster-badcrc.json: the same pool with one face never put / one wrong checksum: both must 409.
"""

import base64
import json
import sys
import zlib
from pathlib import Path

import numpy as np

out, n = Path(sys.argv[1]), int(sys.argv[2])
out.mkdir(parents=True, exist_ok=True)
rng = np.random.default_rng(7)
base_id = 900_000_000
centres = rng.normal(size=(2000, 512))
crcs = []
batch = []
part = 0
for i in range(n):
    v = centres[i % 2000] + rng.normal(scale=1.0, size=512)
    blob = (v / np.linalg.norm(v)).astype("<f4").tobytes()
    crc = zlib.crc32(blob)
    crcs.append(crc)
    batch.append({"id": base_id + i, "crc": crc, "embedding": base64.b64encode(blob).decode()})
    if len(batch) == 2000 or i == n - 1:
        (out / f"put-{part:03d}.json").write_text(json.dumps({"faces": batch}))
        part += 1
        batch = []

pool = [[10_000_000 + i, base_id + i, crcs[i], (1 + i % 500) if i % 5 == 0 else None] for i in range(n)]
attempt = [10_000_000 + i for i in range(n) if i % 5 != 0][:300]
body = {"max_distance": 0.5, "min_faces": 3, "pool": pool, "attempt": attempt}
(out / "cluster.json").write_text(json.dumps(body))
missing = json.loads(json.dumps(body))
missing["pool"].append([10_000_000 + n, base_id + n, 12345, None])
(out / "cluster-missing.json").write_text(json.dumps(missing))
bad = json.loads(json.dumps(body))
bad["pool"][3][2] = (bad["pool"][3][2] + 1) % 2**32
(out / "cluster-badcrc.json").write_text(json.dumps(bad))
print(f"{part} put file(s), pool {n}, cluster.json {len(json.dumps(body)) // 1024} KB")
