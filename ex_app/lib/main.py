"""InsightFace-based /detect endpoint for recognAIze."""

import os
from contextlib import asynccontextmanager
from pathlib import Path

import cv2
import numpy as np
from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
from insightface.app import FaceAnalysis
from nc_py_api import NextcloudApp
from nc_py_api.ex_app import AppAPIAuthMiddleware, LogLvl, run_app, set_handlers

FACE_ANALYSIS: FaceAnalysis | None = None


@asynccontextmanager
async def lifespan(app: FastAPI):
    global FACE_ANALYSIS
    set_handlers(app, enabled_handler)
    # Loading the model is expensive - do it once here, never per-request.
    FACE_ANALYSIS = FaceAnalysis(name="buffalo_l", providers=["CPUExecutionProvider"])
    FACE_ANALYSIS.prepare(ctx_id=-1, det_size=(640, 640))
    yield
    FACE_ANALYSIS = None


APP = FastAPI(lifespan=lifespan)
APP.add_middleware(AppAPIAuthMiddleware)


@APP.post("/detect")
async def detect(request: Request):
    image_bytes = await request.body()
    if not image_bytes:
        raise HTTPException(status_code=400, detail="Empty request body, expected raw image bytes")

    npbuf = np.frombuffer(image_bytes, dtype=np.uint8)
    img = cv2.imdecode(npbuf, cv2.IMREAD_COLOR)
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
    return JSONResponse(content={"faces": results})


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
