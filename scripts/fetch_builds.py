#!/usr/bin/env python3
"""
Fetches test run data (including per-suite logs) from the live ReactOS Testman
and stores it locally as JSON for later submission.

For each test run the full suite results are fetched via the XML export
endpoint, then the raw log for each suite is extracted from detail.php.

Usage:
    python fetch_builds.py [--count N] [--output FILE] [--delay SECS]

Requirements:
    pip install requests
"""

import argparse
import html
import json
import re
import time
import xml.etree.ElementTree as ET

import requests

BASE_URL      = "https://reactos.org"
SEARCH_URL    = f"{BASE_URL}/testman/ajax-search.php"
EXPORT_URL    = f"{BASE_URL}/testman/export.php"
DETAIL_URL    = f"{BASE_URL}/testman/detail.php"


def search_runs(page, limit):
    params = {"page": page, "resultlist": "1", "desc": "1", "limit": limit}
    resp = requests.get(SEARCH_URL, params=params, timeout=30)
    resp.raise_for_status()
    return ET.fromstring(resp.text)


def export_run(run_id):
    resp = requests.get(EXPORT_URL, params={"f": "xml", "ids": str(run_id)}, timeout=30)
    resp.raise_for_status()
    return ET.fromstring(resp.text)


def fetch_log(result_id):
    """Fetch detail.php for a suite result and return the raw log text."""
    resp = requests.get(DETAIL_URL, params={"id": str(result_id)}, timeout=30)
    resp.raise_for_status()

    # The log sits in the sole <pre> element on the page.
    match = re.search(r"<pre>(.*?)</pre>", resp.text, re.DOTALL)
    if not match:
        return ""

    # detail.php HTML-escapes the log and adds <a href="..."> links around
    # file:line references. Strip the tags to recover the original text.
    raw = re.sub(r"<[^>]+>", "", match.group(1))
    return html.unescape(raw)


def main():
    parser = argparse.ArgumentParser(
        description="Fetch ReactOS Testman runs (with logs) from the live server."
    )
    parser.add_argument("--count", type=int, default=50,
                        help="Maximum number of test runs to fetch (default: 50)")
    parser.add_argument("--output", default="builds_data.json",
                        help="Output JSON file (default: builds_data.json)")
    parser.add_argument("--delay", type=float, default=0.2,
                        help="Delay between requests in seconds (default: 0.2)")
    args = parser.parse_args()

    runs = []
    page = 1

    while len(runs) < args.count:
        batch = args.count - len(runs)
        root = search_runs(page, batch)
        time.sleep(args.delay)

        result_elements = root.findall("result")
        if not result_elements:
            break

        for result_el in result_elements:
            run_id   = int(result_el.findtext("id"))
            platform = result_el.findtext("platform", "")
            comment  = result_el.findtext("comment", "")

            try:
                export_root = export_run(run_id)
                time.sleep(args.delay)

                run_el = export_root.find("run")
                if run_el is None:
                    print(f"  [{run_id}] no <run> in export, skipping")
                    continue

                suites = []
                suite_elements = run_el.findall("test")
                for i, test_el in enumerate(suite_elements):
                    result_id = int(test_el.get("id"))
                    log = fetch_log(result_id)
                    time.sleep(args.delay)

                    suites.append({
                        "module":   test_el.get("module", ""),
                        "test":     test_el.get("test", ""),
                        "status":   test_el.get("status", "ok"),
                        "log":      log,
                    })

                    print(f"  [{run_id}] suite {i + 1}/{len(suite_elements)}: "
                          f"{test_el.get('module')}:{test_el.get('test')} "
                          f"({len(log)} bytes)")

                runs.append({
                    "id":       run_id,
                    "source":   run_el.get("source", result_el.findtext("source", "")),
                    "revision": run_el.get("revision", ""),
                    "platform": platform,
                    "comment":  comment,
                    "suites":   suites,
                })

            except Exception as exc:
                print(f"  [{run_id}] FAILED: {exc}")

        total = int(root.findtext("resultcount", 0))
        if len(runs) >= total:
            break
        page += 1

    with open(args.output, "w", encoding="utf-8") as f:
        json.dump(runs, f, indent=2, ensure_ascii=False)

    print(f"\nSaved {len(runs)} runs to '{args.output}'")


if __name__ == "__main__":
    main()
