"""InsightFace-based /detect endpoint for recognAIze."""

import os
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Annotated

import cv2
import numpy as np
from fastapi import Depends, FastAPI, HTTPException
from fastapi.responses import JSONResponse
from insightface.app import FaceAnalysis
from nc_py_api import AsyncNextcloudApp, NextcloudApp, NextcloudException
from nc_py_api.ex_app import AppAPIAuthMiddleware, LogLvl, anc_app, run_app, set_handlers
from pydantic import BaseModel

FACE_ANALYSIS: FaceAnalysis | None = None


class DetectRequest(BaseModel):
    path: str


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
async def detect(payload: DetectRequest, nc: Annotated[AsyncNextcloudApp, Depends(anc_app)]):
    try:
        image_bytes = await nc.files.download(payload.path)
    except NextcloudException as exc:
        if exc.status_code == 404:
            raise HTTPException(status_code=404, detail=f"No such file: {payload.path}") from None
        raise HTTPException(status_code=502, detail=f"Nextcloud error downloading {payload.path}: {exc}") from exc

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
