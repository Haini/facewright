#!/bin/bash
# One Python virtualenv under dev/.work/venv for the two things that need extra packages: the test photo
# (scikit-image) and the Memories UI flows (Playwright and its Chromium). Safe to re-run.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
venv="$WORK_DIR/venv"
[ -x "$venv/bin/python" ] || python3 -m venv "$venv" || { echo "python3 -m venv failed (is python3-venv installed?)"; exit 1; }
"$venv/bin/pip" install -q -r "$DEV_DIR/requirements.txt" || exit 1
"$venv/bin/playwright" install chromium 2>&1 | tail -1
echo "venv ready: $venv"
