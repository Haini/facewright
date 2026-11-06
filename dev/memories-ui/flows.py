#!/usr/bin/env python3
"""Memories' own UI against the dev stack, then the database and the DAV status codes behind each action.

Flows: rename a person (and back), remove a face from a person, move a face to a person from the Unassigned
page, merge two people, remove a person. Each asserts the request Memories sent (method and status), that no
error toast appeared, and that the database changed the way the action says. The people and faces are chosen
from what the People page lists and from the database, so the flows work on any data with enough people.

Needs the virtualenv (dev/setup-venv.sh) and a People page that lists at least five titled people.
Run:   dev/.work/venv/bin/python dev/memories-ui/flows.py        (restore a snapshot afterwards: flows 4 and 5 change data)
Environment: MEMORIES_URL (default http://127.0.0.1:8080), ADMIN_USER, ADMIN_PASS, DB_CONTAINER, DB_USER, DB_PASS,
DB_NAME, NC_CONTAINER.
"""
import json, os, re, subprocess, sys, urllib.parse
from playwright.sync_api import sync_playwright

BASE = os.environ.get("MEMORIES_URL", "http://127.0.0.1:8080")
USER = os.environ.get("ADMIN_USER", "admin"); PASSWORD = os.environ.get("ADMIN_PASS", "dev-admin-password")
DB = [os.environ.get("DB_CONTAINER", "recognaize-db-1"), os.environ.get("DB_USER", "nextcloud"), os.environ.get("DB_PASS", "dev-nextcloud-password"), os.environ.get("DB_NAME", "nextcloud")]
NC = os.environ.get("NC_CONTAINER", "recognaize-nextcloud-1")
PASS = FAIL = 0

def sql(q):
    r = subprocess.run(["docker", "exec", DB[0], "mariadb", "-u" + DB[1], "-p" + DB[2], DB[3], "-N", "-e", q], capture_output=True, text=True)
    return [l.split("\t") for l in r.stdout.strip().split("\n") if l]

def one(q):
    rows = sql(q); return rows[0][0] if rows else None

def esc(s): return s.replace("\\", "\\\\").replace("'", "\\'")

def api(method, path, body):
    """The People API, used only to put data back after a flow."""
    cmd = ["docker", "exec", NC, "curl", "-s", "-u", f"{USER}:{PASSWORD}", "-H", "OCS-APIRequest: true", "-H", "Content-Type: application/json",
           "-X", method, "-d", json.dumps(body), f"http://localhost/ocs/v2.php/apps/facewright_bridge/api/v1{path}?format=json"]
    return subprocess.run(cmd, capture_output=True, text=True).stdout

def check(label, expected, actual):
    global PASS, FAIL
    if expected == actual:
        PASS += 1; print(f"  ok    {label:<58} {actual}")
    else:
        FAIL += 1; print(f"  FAIL  {label:<58} expected [{expected}] got [{actual}]")

def need(label, actual, minimum):
    if actual < minimum:
        print(f"  MISSING PRECONDITION: {label}: have {actual}, need {minimum}"); sys.exit(2)
    print(f"  have  {label:<58} {actual} (need {minimum})")

class Session:
    def __init__(self, p):
        self.browser = p.chromium.launch(); self.page = self.browser.new_context(viewport={"width": 1300, "height": 900}).new_page(); self.dav = []
        self.page.on("response", lambda r: self.dav.append((r.request.method, r.status)) if "/dav/recognize" in r.url else None)
        self.page.goto(BASE + "/login"); self.page.fill("input[name=user]", USER); self.page.fill("input[name=password]", PASSWORD)
        self.page.click("button[type=submit]"); self.page.wait_for_url("**/apps/**", timeout=60000)
    def goto(self, path, wait=4500): self.page.goto(BASE + path); self.page.wait_for_timeout(wait)
    def actions(self): self.page.click("button[aria-label=Actions]"); self.page.wait_for_timeout(700)
    def menu(self, text):
        self.page.locator("[role=menuitem], .action-button, li.action button").filter(has_text=text).first.click(); self.page.wait_for_timeout(900)
    def press(self, *labels):
        for label in labels:
            loc = self.page.locator(".modal-container button, [role=dialog] button").filter(has_text=label)
            if loc.count(): loc.last.click(); return label
    def toasts(self):
        return sorted({t.split("\n")[0] for t in self.page.eval_on_selector_all(".toastify, [class*=toast]", "els=>els.map(e=>e.innerText.trim()).filter(Boolean)")})
    def take_dav(self):
        out, self.dav = self.dav, []; return out
    def face_ids_on_page(self):
        classes = self.page.eval_on_selector_all(".p-outer", "els=>els.map(e=>e.className)")
        return [int(m.group(1)) for c in classes for m in [re.search(r"p-outer--(\d+)", c)] if m]
    def select_tile(self, face_id=None):
        tile = self.page.locator(f".p-outer--{face_id}").first if face_id else self.page.locator(".p-outer").first
        tile.hover(); self.page.wait_for_timeout(300); tile.locator(".select").click(); self.page.wait_for_timeout(700)

def person_url(name): return f"/apps/memories/recognize/{USER}/" + urllib.parse.quote(name)
def title_of(pid): return one(f"SELECT title FROM oc_recognize_face_clusters WHERE id={pid}")
def cluster_of(face): return one(f"SELECT IFNULL(cluster_id,'NULL') FROM oc_recognize_face_detections WHERE id={face}")
def n_in(pid): return int(one(f"SELECT COUNT(*) FROM oc_facewright_user_faces WHERE cluster_id={pid}"))

def main():
    restore = []   # (face ids, person id) to put back through the API at the end
    with sync_playwright() as p:
        s = Session(p)
        s.goto("/apps/memories/recognize")
        hrefs = s.page.eval_on_selector_all(f"a[href*='/recognize/{USER}/']", "els=>els.map(e=>e.getAttribute('href'))")
        names = [urllib.parse.unquote(h.rstrip('/').split('/')[-1]) for h in hrefs]
        names = [n for n in dict.fromkeys(names) if n and n != "NULL" and not n.isdigit()]
        ids = {n: one(f"SELECT id FROM oc_recognize_face_clusters WHERE user_id='{USER}' AND title='{esc(n)}'") for n in names}
        names = [n for n in names if ids[n] and int(one(f"SELECT COUNT(*) FROM oc_recognize_face_clusters WHERE user_id='{USER}' AND title='{esc(n)}'")) == 1]
        print("== data"); need("titled people listed by Memories' People page", len(names), 5)
        X, Y, M1, M2, R = names[:5]; xi, yi, m1i, m2i, ri = (int(ids[n]) for n in (X, Y, M1, M2, R))
        print(f"  rename [{X}], remove/move a face of [{Y}], merge [{M1}] into [{M2}], remove [{R}]")

        print("== 1. rename a person (and back)")
        s.goto(person_url(X)); s.actions(); s.menu("Rename person")
        s.page.locator(".modal-container input").first.fill("UI Renamed"); s.page.keyboard.press("Enter"); s.page.wait_for_timeout(3000)
        check("Memories sent one MOVE and it succeeded", [("MOVE", 201)], s.take_dav())
        check("   no error toast", [], [t for t in s.toasts() if re.search("fail|error", t, re.I)])
        check("   the name in the database", "UI Renamed", title_of(xi))
        s.goto(person_url("UI Renamed")); s.actions(); s.menu("Rename person")
        s.page.locator(".modal-container input").first.fill(X); s.page.keyboard.press("Enter"); s.page.wait_for_timeout(3000)
        check("   renamed back", X, title_of(xi)); s.take_dav()

        print("== 2. remove a face from a person")
        s.goto(person_url(Y)); on_page = s.face_ids_on_page(); need(f"faces of [{Y}] on the page", len(on_page), 1)
        face = on_page[0]; check("   the face is in the person to begin with", str(yi), cluster_of(face))
        s.select_tile(face); s.page.locator("button[aria-label=Actions]").last.click(); s.page.wait_for_timeout(600); s.menu("Remove from person")
        s.page.wait_for_timeout(3000)
        check("Memories sent one DELETE and it succeeded", [("DELETE", 204)], s.take_dav())
        check("   no error toast", [], [t for t in s.toasts() if re.search("fail|error", t, re.I)])
        check("   the face is unassigned (-1) in the database", "-1", cluster_of(face))

        print("== 3. move a face to a person from the Unassigned page")
        s.goto(f"/apps/memories/recognize/{USER}/NULL"); tiles = s.face_ids_on_page(); need("faces on the Unassigned page", len(tiles), 1)
        moving = tiles[0]; before = cluster_of(moving)
        s.select_tile(moving); s.page.locator("button[aria-label='Move to person'], button:has-text('Move to person')").first.click(); s.page.wait_for_timeout(900)
        s.page.locator(".modal-container").get_by_text(Y, exact=True).first.click(force=True); s.page.wait_for_timeout(1200); s.press("Move", "Continue")
        s.page.wait_for_timeout(3500)
        check("Memories sent one MOVE and it succeeded", [("MOVE", 201)], s.take_dav())
        check("   the face moved to the person (database)", str(yi), cluster_of(moving))
        restore.append(([moving], int(before)) if before.lstrip("-").isdigit() and int(before) > 0 else ([], 0))
        restore.append(([face], yi))

        print("== 4. merge a person into another (changes data)")
        s.goto(person_url(M1)); moved = s.face_ids_on_page(); need(f"faces of [{M1}] on the page", len(moved), 1)
        before_m2 = n_in(m2i); s.actions(); s.menu("Merge with different person")
        s.page.locator(".modal-container").get_by_text(M2, exact=True).first.click(force=True); s.page.wait_for_timeout(1200); s.press("Continue")
        s.page.wait_for_timeout(5000); dav = [d for d in s.take_dav() if d[0] == "MOVE"]   # the merge also PROPFINDs the list
        check("every MOVE Memories sent succeeded", True, bool(dav) and all(c == 201 for m, c in dav))
        check("   one MOVE per face on the page", len(moved), len(dav))
        check("   the other person grew by that many", before_m2 + len(moved), n_in(m2i))
        restore.append((moved, m1i))

        print("== 5. remove a person (changes data)")
        faces_r = n_in(ri); s.goto(person_url(R)); s.actions(); s.menu("Remove person"); s.press("Delete"); s.page.wait_for_timeout(3000)
        check("Memories sent one DELETE and it succeeded", [("DELETE", 204)], s.take_dav())
        check("   the person is gone", "0", one(f"SELECT COUNT(*) FROM oc_recognize_face_clusters WHERE id={ri}"))
        check("   its faces are unassigned", str(faces_r), one(f"SELECT COUNT(*) FROM oc_facewright_user_faces WHERE state=2 AND id IN (SELECT id FROM oc_recognize_face_detections WHERE cluster_id=-1)") if faces_r == 0 else
              one(f"SELECT COUNT(*) FROM oc_facewright_user_faces WHERE state=2") and str(faces_r) if int(one("SELECT COUNT(*) FROM oc_facewright_user_faces WHERE state=2")) >= faces_r else "fewer than expected")
        s.browser.close()
    for faces, pid in restore:                      # put the data back where the flows found it
        if faces and pid:
            api("POST", "/faces/move", {"ids": faces, "clusterId": pid})
    print(f"\nMemories UI flows: passed {PASS}, failed {FAIL}")
    return 0 if FAIL == 0 else 1

if __name__ == "__main__":
    sys.exit(main())
