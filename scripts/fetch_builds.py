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
import threading
import time
import xml.etree.ElementTree as ET
from concurrent.futures import ThreadPoolExecutor, as_completed

import requests

BASE_URL      = "https://reactos.org"
SEARCH_URL    = f"{BASE_URL}/testman/ajax-search.php"
EXPORT_URL    = f"{BASE_URL}/testman/export.php"
DETAIL_URL    = f"{BASE_URL}/testman/detail.php"


_request_count = 0
_request_lock  = threading.Lock()
_RATE_LIMIT_EVERY = 400   # pause after this many requests
_RATE_LIMIT_WAIT  = 60    # seconds to wait


def get(url, params, retries=5, backoff=5):
    """GET with retry on timeout or connection error, and global rate limiting."""
    global _request_count
    with _request_lock:
        _request_count += 1
        count = _request_count

    if count % _RATE_LIMIT_EVERY == 0:
        print(f"  [{count} requests made] pausing {_RATE_LIMIT_WAIT}s to avoid rate limiting...")
        time.sleep(_RATE_LIMIT_WAIT)

    for attempt in range(1, retries + 1):
        try:
            resp = requests.get(url, params=params, timeout=30)
            resp.raise_for_status()
            return resp
        except (requests.Timeout, requests.ConnectionError) as exc:
            if attempt == retries:
                raise
            wait = backoff * attempt
            print(f"    timeout/connection error ({exc}), retrying in {wait}s "
                  f"(attempt {attempt}/{retries})")
            time.sleep(wait)


def search_runs(page, limit):
    params = {"page": page, "resultlist": "1", "desc": "1", "limit": limit}
    return ET.fromstring(get(SEARCH_URL, params).text)


def export_run(run_id):
    resp = get(EXPORT_URL, {"f": "xml", "ids": str(run_id)})
    return ET.fromstring(resp.text)


def fetch_log(result_id):
    """Fetch detail.php for a suite result and return the raw log text."""
    resp = get(DETAIL_URL, {"id": str(result_id)})

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
    parser.add_argument("--workers", type=int, default=5,
                        help="Parallel workers for fetching suite logs (default: 5)")
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
                run_el = export_root.find("run")
                if run_el is None:
                    print(f"  [{run_id}] no <run> in export, skipping")
                    continue

                suite_elements = run_el.findall("test")
                total_suites = len(suite_elements)

                # Build a map of result_id → metadata so we can reassemble
                # results in original order after parallel fetching.
                suite_meta = {
                    int(el.get("id")): {
                        "module": el.get("module", ""),
                        "test":   el.get("test", ""),
                        "status": el.get("status", "ok"),
                    }
                    for el in suite_elements
                }
                result_ids = [int(el.get("id")) for el in suite_elements]

                logs = {}
                done = 0
                with ThreadPoolExecutor(max_workers=args.workers) as pool:
                    futures = {pool.submit(fetch_log, rid): rid for rid in result_ids}
                    for future in as_completed(futures):
                        rid = futures[future]
                        logs[rid] = future.result()
                        done += 1
                        meta = suite_meta[rid]
                        print(f"  [{run_id}] {done}/{total_suites} "
                              f"{meta['module']}:{meta['test']} "
                              f"({len(logs[rid])} bytes)")

                suites = [
                    {**suite_meta[rid], "log": logs[rid]}
                    for rid in result_ids
                ]

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
