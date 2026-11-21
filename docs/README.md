# Docs

Start with `../CLAUDE.md` (architecture, invariants, operations) and `../README.md` (overview, quickstart). The current
work list is `cleanup.md`.

| Doc | What it is | Status |
|---|---|---|
| `cleanup.md` | What is left to tidy after the fork, and the plan for Stages F and H | current |
| `own-tables.md` | Our own tables as the source of truth, Recognize's as a copy (Stages A to H) | A to E and G done; F and H next |
| `memories-fork.md` | The fork of Memories that reads our copy and our WebDAV tree (Stage G, M0 to M12) | live since 2026-11-20; M12 is the standing procedure for each Memories release |
| `memories-fork.patch` | The fork's change to Memories, as a reviewable diff | matches the fork's tag `facewright-v11.1.4` |
| `exapp-clustering.md` | Clustering's matching in the ExApp (`/cluster`, the embedding cache, the `exapp` engine) | live since 2026-11-09 |
| `people-ui.md` | Requirements of the People page | implemented (bridge 0.8.0) |
| `people-ui-review.md` | Review action items for the People page | not worked yet |
| `contact-names.md` | Suggesting names from contact photos: requirements and plan | not started |

`archive/` holds plans that are finished or superseded; see `archive/README.md`.
