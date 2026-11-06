#!/usr/bin/env python3
"""Write dev/.work/portrait.jpg: a public-domain photo of a real face (scikit-image's bundled astronaut portrait).

The face detector needs a real face; synthetic images find none, and a detection test that finds nothing would
pass without testing anything. Run it with the virtualenv's Python:   dev/.work/venv/bin/python dev/make-portrait.py
"""
import pathlib, sys
try:
    from PIL import Image
    from skimage import data
except ImportError:
    sys.exit("scikit-image and pillow are needed: run dev/setup-venv.sh, then dev/.work/venv/bin/python dev/make-portrait.py")
out = pathlib.Path(__file__).resolve().parent / ".work" / "portrait.jpg"
out.parent.mkdir(exist_ok=True)
Image.fromarray(data.astronaut()).save(out, quality=92)
print(f"wrote {out} ({out.stat().st_size} bytes)")
