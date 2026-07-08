Yes — and it's a better reuse story than you might expect, because Memories doesn't actually do face detection itself. It just reads Recognize's results. That distinction matters a lot for scoping the prototype.

**What "reuse" actually means here**

Memories' People page has no face-detection logic of its own — it queries two tables that Recognize's PHP side creates and populates: `oc_recognize_face_detections` (bounding boxes, embedding vectors, per-face records) and `oc_recognize_face_clusters` (the grouped "persons"). As long as those tables get populated correctly, Memories displays them — it doesn't care whether the embeddings came from face-api.js or InsightFace.

That means the reusable surface isn't "Memories' code," it's Recognize's DB schema + the Memories integration point that already reads it. Your ExApp doesn't need to touch Memories at all. It needs to produce rows that look like what Recognize already produces.

**Two ways to wire that up**

1. **Patch Recognize's PHP to call your ExApp instead of spawning its Node subprocess.** Keep Recognize's existing queueing, clustering, DB writes, and Memories integration entirely intact; just replace the one seam where it currently shells out to `tfjs-node` with an HTTP call to your ExApp (AppAPI gives PHP apps a defined way to call ExApp endpoints through the deploy daemon). Smallest possible diff, but you inherit Recognize's existing clustering algorithm and thresholds, tuned for the old embedding space.

2. **A companion app that writes into the same tables directly**, leaving Recognize installed (so its migrations create the schema and Memories keeps recognizing the People tab exists) but with its own face-classifier toggle switched off so it doesn't fight your writes. Your companion PHP code owns the file-listener trigger, calls your ExApp for detection+embedding, and does its own clustering. More code to write, but you're not forking/rebasing against upstream Recognize forever, which matters for long-term maintenance.

I'd lean toward option 2 for a homelab project — a fork you have to keep rebasing against upstream changes gets old fast, and Recognize's non-face features (object/landmark tagging) keep working normally this way.

**Sizing the actual pieces**

- **InsightFace wrapper itself: trivial.** The `insightface` Python package's `FaceAnalysis` class does detection, landmarks, and embedding in one call — realistically an afternoon to get a working `/detect` endpoint returning bounding boxes + 512-d embeddings as JSON.
- **Packaging it as an ExApp: a few evenings, mostly learning curve.** AppAPI ships an example skeleton template (`cloud-py-api/skeleton`) specifically so you don't start from zero — Dockerfile, `info.xml` with the `<external-app>` block, registration via `occ app_api:app:register`. Mechanical once you've done it once.
- **Writing into Recognize's DB tables: moderate.** Straightforward with Nextcloud's QueryBuilder once you know the schema, but you're depending on undocumented internal tables owned by another app — fragile across Recognize updates, and worth a comment in your code saying exactly why.
- **The part that's actually hard: re-tuning clustering.** This is the piece people underestimate. Recognize's existing cluster-distance thresholds were calibrated for its old embedding space; InsightFace's ArcFace-style embeddings live in a different space with different typical cosine-similarity margins between same-person and different-person faces. Reusing the old thresholds verbatim will likely give you either one giant cluster or hundreds of singletons — recognizable from the "shit cluster" complaints in Recognize's own issue tracker, which is exactly the failure mode you'd risk reintroducing if you don't recalibrate. This needs actual experimentation against a labeled slice of your own library, not just plumbing.

**Rough estimate**

For a minimal prototype — proving detection+clustering works better than Recognize's current model on your own library, single user, manually triggered rather than fully wired into background jobs — I'd budget a small handful of weekends for someone with your background: comfortable in Python and Docker already, new territory being AppAPI's conventions and enough of Recognize's PHP internals to know what to write into. Getting it production-solid for multi-user, incremental updates, and not breaking on Recognize updates is a separate, larger step after that.

One side note given your compliance background: Recognize is AGPL-licensed, so depending on its DB schema/tables directly is fine for personal use, but worth keeping in mind if this ever left your homelab.

Want to start by scoping the minimal `/detect` endpoint and ExApp skeleton, or by reverse-engineering Recognize's exact clustering algorithm first so you know what you're replacing?
