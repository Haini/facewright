#!/bin/bash
# Download the Georgia Tech face database (50 people x 15 photos, identity = folder name s01..s50) into
# dev/.work/gtdb/ and check it against the pinned size and SHA-256. The page that hosts it states no licence, so it
# is never committed: .work/ is ignored by git, and nothing here copies it anywhere but the dev stack.
#   https://www.anefian.com/research/face_reco.htm
# Safe to re-run; does nothing when the extracted copy is complete.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
URL=https://www.anefian.com/research/gt_db.zip
SHA256=2c4e379ef7c3cc5580eb409673a1e6eb75c5e5a7efd9bf3beb1de8059e835cbf
SIZE=133192489
dest="$WORK_DIR/gtdb"
count() { find "$dest" -name '*.jpg' 2>/dev/null | wc -l; }
if [ "$(count)" -eq 750 ]; then echo "dataset present: 750 photos in $dest"; exit 0; fi
zip="$WORK_DIR/gt_db.zip"
if [ ! -s "$zip" ]; then curl -fL --retry 3 -m 600 -o "$zip" "$URL" || { echo "download failed"; rm -f "$zip"; exit 1; }; fi
[ "$(stat -c %s "$zip")" -eq "$SIZE" ] || { echo "size mismatch: $(stat -c %s "$zip") bytes, expected $SIZE"; rm -f "$zip"; exit 1; }
[ "$(sha256sum "$zip" | cut -d' ' -f1)" = "$SHA256" ] || { echo "SHA-256 mismatch: the file at the URL has changed"; exit 1; }
rm -rf "$dest.tmp"; mkdir -p "$dest.tmp"
unzip -q "$zip" -d "$dest.tmp" "gt_db/*.jpg" || { echo "unzip failed"; rm -rf "$dest.tmp"; exit 1; }   # jpg only; the archive also holds a stray .jbf
chmod -R u+w "$dest.tmp"   # the archive stores some files read-only; they must stay removable
chmod -R u+w "$dest" 2>/dev/null; rm -rf "$dest"; mv "$dest.tmp/gt_db" "$dest"; rmdir "$dest.tmp"
[ "$(count)" -eq 750 ] || { echo "expected 750 photos, found $(count)"; exit 1; }
echo "dataset ready: 750 photos, 50 people, in $dest"
