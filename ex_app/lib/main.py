"""InsightFace-based /detect endpoint for Facewright."""

import os
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Annotated

import cv2
import numpy as np
import onnxruntime
from fastapi import Depends, FastAPI, HTTPException
from fastapi.responses import JSONResponse
from insightface.app import FaceAnalysis
from insightface.model_zoo.model_zoo import PickableInferenceSession
from nc_py_api import AsyncNextcloudApp, NextcloudApp, NextcloudException
from nc_py_api.ex_app import AppAPIAuthMiddleware, LogLvl, anc_app, run_app, set_handlers
from pydantic import BaseModel

from clustering import ROUTER as CLUSTERING_ROUTER
from clustering import init_cache

# insightface's FaceAnalysis/model_zoo.get_model() only ever forwards `providers`/
# `provider_options` kwargs to onnxruntime - there's no supported way to pass a SessionOptions
# through, so ONNX Runtime falls back to its default of one intra-op thread per detected CPU
# core. On this homelab's host that meant every /detect call spiked all cores at once, which
# correlated with repeated onnxruntime native-code segfaults (kernel log: simultaneous
# `onnxruntime_pybind11_state.so` segfaults across worker threads) and the host hard-rebooting
# shortly after. Patch the session class insightface actually instantiates so every model gets a
# capped thread pool instead.
_ORT_INTRA_OP_THREADS = int(os.environ.get("FACEWRIGHT_ORT_INTRA_OP_THREADS", "2"))
_ORT_INTER_OP_THREADS = int(os.environ.get("FACEWRIGHT_ORT_INTER_OP_THREADS", "1"))
_ORIGINAL_SESSION_INIT = PickableInferenceSession.__init__


def _threadpool_limited_session_init(self, model_path, **kwargs):
    session_options = onnxruntime.SessionOptions()
    session_options.intra_op_num_threads = _ORT_INTRA_OP_THREADS
    session_options.inter_op_num_threads = _ORT_INTER_OP_THREADS
    kwargs.setdefault("sess_options", session_options)
    _ORIGINAL_SESSION_INIT(self, model_path, **kwargs)


PickableInferenceSession.__init__ = _threadpool_limited_session_init

FACE_ANALYSIS: FaceAnalysis | None = None


class DetectRequest(BaseModel):
    path: str


@asynccontextmanager
async def lifespan(app: FastAPI):
    global FACE_ANALYSIS
    set_handlers(app, enabled_handler)
    init_cache()
    # Loading the model is expensive - do it once here, never per-request.
    FACE_ANALYSIS = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
    FACE_ANALYSIS.prepare(ctx_id=-1, det_size=(640, 640))
    yield
    FACE_ANALYSIS = None


APP = FastAPI(lifespan=lifespan)
APP.add_middleware(AppAPIAuthMiddleware)
APP.include_router(CLUSTERING_ROUTER)


@APP.post("/detect")
async def detect(payload: DetectRequest, nc: Annotated[AsyncNextcloudApp, Depends(anc_app)]):
    try:
        image_bytes = await nc.files.download(payload.path)
    except NextcloudException as exc:
        if exc.status_code == 404:
            raise HTTPException(status_code=404, detail=f"No such file: {payload.path}") from None
        raise HTTPException(status_code=502, detail=f"Nextcloud error downloading {payload.path}: {exc}") from exc

    # Guarded separately from the imdecode() below: cv2 does not return None for an empty
    # buffer, it raises cv2.error("!buf.empty()"), which escapes as an uncaught 500. A 500 reads
    # as "the ExApp is broken, retry later" to the caller, so a zero-byte file would be retried
    # forever instead of being recorded as a permanent failure. Zero-byte images are not
    # hypothetical here - this instance has ~2,700 of them.
    if not image_bytes:
        raise HTTPException(status_code=400, detail=f"Empty file: {payload.path}")

    npbuf = np.frombuffer(image_bytes, dtype=np.uint8)
    try:
        img = cv2.imdecode(npbuf, cv2.IMREAD_COLOR)
    except cv2.error as exc:
        # Any other malformed input that trips an assertion inside OpenCV rather than returning
        # None. Still the file's fault, not ours, so keep it a 4xx.
        raise HTTPException(status_code=400, detail=f"Could not decode image: {exc}") from exc
    if img is None:
        raise HTTPException(status_code=400, detail="Could not decode image")

    faces = FACE_ANALYSIS.get(img)
    results = [
        {
            "bbox": face.bbox.tolist(),
            "kps": face.kps.tolist(),
            "det_score": float(face.det_score),
            "embedding": face.normed_embedding.tolist(),
        }
        for face in faces
    ]
    return JSONResponse(
        content={
            "faces": results,
            "image_width": img.shape[1],
            "image_height": img.shape[0],
        }
    )


def enabled_handler(enabled: bool, nc: NextcloudApp) -> str:
    # `user` is unavailable at this step, so NC API calls requiring it will fail as unauthorized.
    print(f"enabled={enabled}", flush=True)
    if enabled:
        nc.log(LogLvl.WARNING, f"Hello from {nc.app_cfg.app_name} :)")
    else:
        nc.log(LogLvl.WARNING, f"Bye bye from {nc.app_cfg.app_name} :(")
    # In case of an error, a non-empty short string should be returned, shown to the NC administrator.
    return ""


if __name__ == "__main__":
    os.chdir(Path(__file__).parent)
    run_app("main:APP", log_level="trace")
