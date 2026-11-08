"""Embedding cache and /cluster matching (docs/exapp-clustering.md).

The ExApp holds no assignment state: the bridge sends the pool's state with every request, and this module keeps only a
cache of embeddings keyed by facewright_faces.id, each with the CRC32 of its blob. A face id's embedding can change
(the bridge updates facewright_faces in place on a forced re-detection), so a cached entry only counts when its
checksum is the one the bridge sends. The cache is disposable: losing it costs one slow first pass.

The matching mirrors the bridge's ClusteringService::assignClusters() and findMatches() exactly; see match_chunk().
"""

import base64
import os
import sqlite3
import time
import zlib
from pathlib import Path

import numpy as np
from fastapi import APIRouter, HTTPException
from fastapi.responses import JSONResponse
from pydantic import BaseModel

DIMENSIONS = 512
BYTES = DIMENSIONS * 4  # float32 little-endian, as the bridge's FaceVectorCodec packs it ('g*')
DATA_DIR = Path(os.environ.get("FACEWRIGHT_DATA_DIR", "/data"))
DB_PATH = DATA_DIR / "embeddings.sqlite"
# Below SQLite's default SQLITE_MAX_VARIABLE_NUMBER (32766 since 3.32).
SQL_BATCH = 5000
PUT_MAX = 2000

ROUTER = APIRouter()


def init_cache() -> None:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    with sqlite3.connect(DB_PATH) as conn:
        conn.execute("PRAGMA journal_mode=WAL")
        conn.execute(
            "CREATE TABLE IF NOT EXISTS embeddings ("
            " id INTEGER PRIMARY KEY, crc INTEGER NOT NULL, vec BLOB NOT NULL, seen_at INTEGER NOT NULL)"
        )


def _connect() -> sqlite3.Connection:
    # One connection per request: the endpoints are plain functions, so FastAPI runs them in its thread pool.
    return sqlite3.connect(DB_PATH, timeout=60)


def _batches(items: list, size: int = SQL_BATCH):
    for start in range(0, len(items), size):
        yield items[start : start + size]


def _cached(conn: sqlite3.Connection, face_ids: list[int], with_vectors: bool) -> dict[int, tuple]:
    """face id -> (crc,) or (crc, vec) for the ids that are in the cache at all."""
    columns = "id, crc, vec" if with_vectors else "id, crc"
    found: dict[int, tuple] = {}
    for batch in _batches(face_ids):
        marks = ",".join("?" * len(batch))
        for row in conn.execute(f"SELECT {columns} FROM embeddings WHERE id IN ({marks})", batch):
            found[row[0]] = row[1:]
    return found


def _touch(conn: sqlite3.Connection, face_ids: list[int]) -> None:
    now = int(time.time())
    for batch in _batches(face_ids):
        marks = ",".join("?" * len(batch))
        conn.execute(f"UPDATE embeddings SET seen_at = ? WHERE id IN ({marks})", [now, *batch])


class CheckRequest(BaseModel):
    faces: list[tuple[int, int]]  # (face id, crc32 of the embedding blob)


class PutFace(BaseModel):
    id: int
    crc: int
    embedding: str  # base64 of BYTES bytes


class PutRequest(BaseModel):
    faces: list[PutFace]


class ClusterRequest(BaseModel):
    max_distance: float
    min_faces: int
    # (row id, face id, crc32 of the face's embedding, label): label is None for a pending or unclusterable row, or a
    # person id > 0. In the bridge's pool order, which decides tie order exactly as it does in PHP.
    pool: list[tuple[int, int, int, int | None]]
    attempt: list[int]  # row ids, in the order PHP attempts them


@ROUTER.post("/cluster/cache/check")
def cache_check(payload: CheckRequest):
    """The ids that are not cached, or cached with another checksum. Matching ones are marked as seen."""
    wanted = dict(payload.faces)
    with _connect() as conn:
        found = _cached(conn, list(wanted), with_vectors=False)
        current = [face_id for face_id, crc in wanted.items() if face_id in found and found[face_id][0] == crc]
        _touch(conn, current)
    current_set = set(current)
    return {"missing": [face_id for face_id in wanted if face_id not in current_set]}


@ROUTER.post("/cluster/cache/put")
def cache_put(payload: PutRequest):
    """Stores embeddings. All or nothing: one bad blob rejects the request and stores none of it."""
    if len(payload.faces) > PUT_MAX:
        raise HTTPException(status_code=400, detail=f"At most {PUT_MAX} faces per request")
    rows = []
    now = int(time.time())
    for face in payload.faces:
        try:
            blob = base64.b64decode(face.embedding, validate=True)
        except ValueError:
            raise HTTPException(status_code=400, detail=f"Face {face.id}: embedding is not valid base64") from None
        if len(blob) != BYTES:
            raise HTTPException(status_code=400, detail=f"Face {face.id}: expected {BYTES} bytes, got {len(blob)}")
        if zlib.crc32(blob) != face.crc:
            raise HTTPException(status_code=400, detail=f"Face {face.id}: checksum does not match the embedding")
        rows.append((face.id, face.crc, blob, now))
    with _connect() as conn:
        conn.executemany(
            "INSERT INTO embeddings (id, crc, vec, seen_at) VALUES (?, ?, ?, ?)"
            " ON CONFLICT(id) DO UPDATE SET crc = excluded.crc, vec = excluded.vec, seen_at = excluded.seen_at",
            rows,
        )
    return {"stored": len(rows)}


@ROUTER.post("/cluster/cache/clear")
def cache_clear():
    """Empties the cache. Embeddings are biometric data: this is the operator's and the reset path's way out."""
    conn = _connect()
    try:
        with conn:
            removed = conn.execute("DELETE FROM embeddings").rowcount
        # A DELETE leaves the blobs in free pages and in the WAL. Rewrite the file and truncate the WAL so a clear
        # actually removes them from disk.
        conn.execute("VACUUM")
        conn.execute("PRAGMA wal_checkpoint(TRUNCATE)")
    finally:
        conn.close()
    print(f"embedding cache cleared, {removed} entries removed", flush=True)
    return {"removed": removed}


def match_chunk(
    vectors: np.ndarray, labels: np.ndarray, attempt: list[int], max_distance: float, min_faces: int
) -> tuple[list[dict], np.ndarray]:
    """ClusteringService::assignClusters() and findMatches(), on pool positions.

    vectors: (n, 512) float64, rows in pool order. labels: int64 per row, 0 = no label (pending or unclusterable), > 0 a
    person id; new people get -1, -2, ... in the order they are made. attempt: pool positions, in attempt order.
    Returns the decisions and the labels after the chunk (a new array).

    Mirrors PHP: a row labelled earlier in this call is skipped ("already"); candidates are every other row at cosine
    distance <= max_distance (the vectors are L2-normalized, so the distance is 1 - dot), sorted by distance with ties
    in pool order (PHP's usort is stable and iterates $detections in pool order); fewer than min_faces including the
    row itself is a skip; otherwise the row takes the label of the first labelled candidate ("join") or a new one
    ("new"), and so does every unlabelled candidate.
    """
    labels = labels.copy()
    positions = np.arange(len(labels))
    next_new = -1
    decisions = []
    for position in attempt:
        if labels[position] != 0:
            decisions.append({"position": position, "action": "already", "label": int(labels[position]), "matches": 0})
            continue
        distances = 1.0 - vectors @ vectors[position]
        candidates = np.flatnonzero(distances <= max_distance)
        candidates = candidates[candidates != position]
        candidates = candidates[np.lexsort((positions[candidates], distances[candidates]))]
        if len(candidates) + 1 < min_faces:
            decisions.append({"position": position, "action": "skip", "label": None, "matches": len(candidates)})
            continue
        candidate_labels = labels[candidates]
        labelled = np.flatnonzero(candidate_labels)
        if len(labelled) > 0:
            label = int(candidate_labels[labelled[0]])
            action = "join"
        else:
            label = next_new
            next_new -= 1
            action = "new"
        labels[position] = label
        labels[candidates[candidate_labels == 0]] = label
        decisions.append({"position": position, "action": action, "label": label, "matches": len(candidates)})
    return decisions, labels


@ROUTER.post("/cluster")
def cluster(payload: ClusterRequest):
    started = time.monotonic()
    row_ids = [entry[0] for entry in payload.pool]
    position_of = {row_id: position for position, row_id in enumerate(row_ids)}
    if len(position_of) != len(row_ids):
        raise HTTPException(status_code=400, detail="A row id appears twice in the pool")
    try:
        attempt = [position_of[row_id] for row_id in payload.attempt]
    except KeyError as exc:
        raise HTTPException(status_code=400, detail=f"Attempted row {exc.args[0]} is not in the pool") from None
    for _, _, _, label in payload.pool:
        if label is not None and label <= 0:
            raise HTTPException(status_code=400, detail=f"Label {label} is not a person id")

    wanted = {face_id: crc for _, face_id, crc, _ in payload.pool}
    with _connect() as conn:
        found = _cached(conn, list(wanted), with_vectors=True)
    missing = [face_id for face_id, crc in wanted.items() if face_id not in found or found[face_id][0] != crc]
    if missing:
        return JSONResponse(status_code=409, content={"missing": missing})

    # float64, as PHP computes: the float32 values widen exactly, and only the summation order differs.
    blob = b"".join(found[face_id][1] for _, face_id, _, _ in payload.pool)
    vectors = np.frombuffer(blob, dtype="<f4").reshape(len(row_ids), DIMENSIONS).astype(np.float64)
    del blob, found
    labels = np.array([label or 0 for _, _, _, label in payload.pool], dtype=np.int64)

    loaded = time.monotonic()
    decisions, after = match_chunk(vectors, labels, attempt, payload.max_distance, payload.min_faces)
    changed = np.flatnonzero(after != labels)
    compute_ms = round((time.monotonic() - loaded) * 1000)
    total_ms = round((time.monotonic() - started) * 1000)
    # Counts and times only: never log embeddings.
    print(
        f"cluster: pool {len(row_ids)}, attempted {len(attempt)}, changed {len(changed)},"
        f" load {total_ms - compute_ms} ms, compute {compute_ms} ms",
        flush=True,
    )
    return {
        "decisions": [
            {"id": row_ids[d["position"]], "action": d["action"], "label": d["label"], "matches": d["matches"]}
            for d in decisions
        ],
        "labels": [[row_ids[position], int(after[position])] for position in changed],
        "compute_ms": compute_ms,
        "total_ms": total_ms,
    }
