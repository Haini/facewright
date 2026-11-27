#!/usr/bin/env python3
"""The face provider dropdown on Memories' admin page (docs/memories-fork-admin-ui.md, step 3 checks 1 to 3).

1. The select shows the provider that is set, and the card says it provides faces.
2. Choosing the other provider stores it (`occ config:system:get`), and the card follows without a reload.
3. Choosing `recognize` deletes the key from config.php (`occ config:system:get` exits 1), it is not stored as "recognize".
Then: a new page of Memories gets the chosen provider in its frontend config, and with `nosuchapp` set by `occ` the select
shows it and the card is the error. The system setting is put back to what it was when the script started.

Needs the virtualenv (dev/setup-venv.sh) and the fork of Memories deployed (dev/fork-swap.sh fork).
Run:   dev/.work/venv/bin/python dev/memories-ui/admin_provider.py
Environment: MEMORIES_URL (default http://127.0.0.1:8080), ADMIN_USER, ADMIN_PASS, NC_CONTAINER.
"""
import os, subprocess, sys
from playwright.sync_api import sync_playwright

BASE = os.environ.get("MEMORIES_URL", "http://127.0.0.1:8080")
USER = os.environ.get("ADMIN_USER", "admin"); PASSWORD = os.environ.get("ADMIN_PASS", "dev-admin-password")
NC = os.environ.get("NC_CONTAINER", "recognaize-nextcloud-1")
KEY = "memories.recognize.provider"
OTHER = "facewright_bridge"
PASS = FAIL = 0

def occ(*args):
    r = subprocess.run(["docker", "exec", "-u", "www-data", NC, "php", "occ", *args], capture_output=True, text=True)
    return r.returncode, r.stdout.strip()

def stored():
    rc, out = occ("config:system:get", KEY)
    return None if rc != 0 else out

def restore(value):
    if value is None: occ("config:system:delete", KEY)
    else: occ("config:system:set", KEY, "--value=" + value)

def check(label, expected, actual):
    global PASS, FAIL
    if expected == actual:
        PASS += 1; print(f"  ok    {label:<62} {actual}")
    else:
        FAIL += 1; print(f"  FAIL  {label:<62} expected [{expected}] got [{actual}]")

def select_text(page): return page.locator("#face-provider-select").locator("xpath=ancestor::div[contains(@class,'v-select')][1]").inner_text().strip().split("\n")[0]
def card(page):
    loc = page.locator(".face-provider .notecard")
    return (loc.inner_text().strip(), loc.get_attribute("class")) if loc.count() else (None, None)

def settle(page): page.wait_for_timeout(1500)   # the status is refreshed 500 ms after an update

def choose(page, text):
    page.locator("#face-provider-select").locator("xpath=ancestor::div[contains(@class,'v-select')][1]").click(); page.wait_for_timeout(500)
    page.locator("li.vs__dropdown-option").filter(has_text=text).first.click(); settle(page)

def main():
    start = stored(); print(f"  the setting at the start: {start!r}")
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(); ctx = browser.new_context(viewport={"width": 1300, "height": 900}); page = ctx.new_page()
            page.goto(BASE + "/login"); page.fill("input[name=user]", USER); page.fill("input[name=password]", PASSWORD)
            page.click("button[type=submit]"); page.wait_for_url("**/apps/**", timeout=60000)

            print("== 0. start from the default")
            occ("config:system:delete", KEY)
            page.goto(BASE + "/settings/admin/memories"); page.wait_for_selector("#face-provider-select", timeout=30000); settle(page)

            print("== 1. the select shows the provider that is set, the card says it works")
            check("the select shows Recognize", True, select_text(page).startswith("Recognize"))
            text, cls = card(page)
            check("the card says Recognize provides faces", "Recognize provides faces to Memories.", text)
            check("   and is a success card", True, "notecard--success" in (cls or ""))

            print("== 2. choose the other provider")
            choose(page, f"({OTHER})")
            check("the setting is stored", OTHER, stored())
            check("the select follows", True, select_text(page).startswith("Facewright"))
            text, cls = card(page)
            check("the card follows without a reload", True, bool(text) and text.endswith("provides faces to Memories.") and "Facewright" in text)
            check("   and is a success card", True, "notecard--success" in (cls or ""))

            print("== 3. choose recognize again: the key is deleted, not set to recognize")
            choose(page, "Recognize")
            check("occ config:system:get has no value (the key is gone)", None, stored())
            text, _ = card(page)
            check("the card says Recognize provides faces", "Recognize provides faces to Memories.", text)

            print("== 4. a new page of Memories gets the chosen provider")
            occ("config:system:set", KEY, "--value=" + OTHER)
            fresh = ctx.new_page()
            fresh.goto(BASE + "/apps/memories/"); fresh.wait_for_timeout(4000)
            body = fresh.evaluate("async () => (await (await fetch('/apps/memories/api/config', {headers: {requesttoken: OC.requestToken}})).json())")
            check("the frontend config names the chosen provider", OTHER, body.get("recognize_provider"))
            fresh.close()

            print("== 5. an app that does not exist: listed, and the card is the error")
            occ("config:system:set", KEY, "--value=nosuchapp")
            page.goto(BASE + "/settings/admin/memories"); page.wait_for_selector("#face-provider-select", timeout=30000); settle(page)
            check("the select shows nosuchapp", "nosuchapp", select_text(page))
            text, cls = card(page)
            check("the card says it is not enabled", "nosuchapp is not enabled. The People view is unavailable.", text)
            check("   and is an error card", True, "notecard--error" in (cls or ""))
            browser.close()
    finally:
        restore(start)
    check("the setting is back to what it was", start, stored())
    print(f"\n{PASS} passed, {FAIL} failed")
    sys.exit(1 if FAIL else 0)

main()
