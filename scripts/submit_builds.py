#!/usr/bin/env python3
"""
Submits locally stored test run data to a Testman instance via its webservice.

Reads the JSON file produced by fetch_builds.py and replays each run through
the normal testman webservice HTTP API:
  gettestid → getsuiteid → submit (per suite) → finish

The actual log text fetched from the live server is submitted as-is.

Usage:
    python submit_builds.py [--input FILE] [--url URL] [--sourceid N] [--password PW]

Requirements:
    pip install requests

The default credentials (sourceid=1, password=testpassword) match the test
source added to the Docker init.sql. Re-run `docker compose down -v && docker
compose up` to reset the database if needed.
"""

import argparse
import json

import requests

WEBSERVICE_URL = "http://localhost/testman/webservice/index.php"
DEFAULT_SOURCE_ID = 1
DEFAULT_PASSWORD = "testpassword"


def ws_post(url, **fields):
    """POST to the webservice and return the response text."""
    resp = requests.post(url, data=fields, timeout=30)
    resp.raise_for_status()
    return resp.text.strip()


def main():
    parser = argparse.ArgumentParser(description="Submit test run data to a local Testman via its webservice.")
    parser.add_argument("--input", default="builds_data.json",
                        help="Input JSON file produced by fetch_builds.py (default: builds_data.json)")
    parser.add_argument("--url", default=WEBSERVICE_URL,
                        help=f"Testman webservice URL (default: {WEBSERVICE_URL})")
    parser.add_argument("--sourceid", type=int, default=DEFAULT_SOURCE_ID,
                        help=f"Source ID to authenticate as (default: {DEFAULT_SOURCE_ID})")
    parser.add_argument("--password", default=DEFAULT_PASSWORD,
                        help=f"Source password (default: {DEFAULT_PASSWORD!r})")
    args = parser.parse_args()

    with open(args.input, encoding="utf-8") as f:
        runs = json.load(f)

    print(f"Loaded {len(runs)} runs from '{args.input}'")

    submitted = 0
    failed = 0

    for run in runs:
        revision = run["revision"]
        platform = run["platform"]
        comment  = run.get("comment", "")
        suites   = run.get("suites", [])

        try:
            # 1. Register the test run and get a local test ID.
            test_id = ws_post(
                args.url,
                sourceid=args.sourceid,
                password=args.password,
                action="gettestid",
                revision=revision,
                platform=platform,
                comment=comment,
            )

            if not test_id.isdigit():
                raise RuntimeError(f"gettestid returned: {test_id!r}")

            # 2. Submit each suite result.
            for suite in suites:
                suite_id = ws_post(
                    args.url,
                    sourceid=args.sourceid,
                    password=args.password,
                    action="getsuiteid",
                    module=suite["module"],
                    test=suite["test"],
                )

                if not suite_id.isdigit():
                    raise RuntimeError(f"getsuiteid returned: {suite_id!r}")

                log = suite["log"]

                result = ws_post(
                    args.url,
                    sourceid=args.sourceid,
                    password=args.password,
                    action="submit",
                    testid=test_id,
                    suiteid=suite_id,
                    log=log,
                )

                if result != "OK":
                    raise RuntimeError(f"submit returned: {result!r}")

            # 3. Mark the run as finished.
            result = ws_post(
                args.url,
                sourceid=args.sourceid,
                password=args.password,
                action="finish",
                testid=test_id,
            )

            if result != "OK":
                raise RuntimeError(f"finish returned: {result!r}")

            submitted += 1
            print(f"  [{run['id']}] {revision} / {platform} — {len(suites)} suites submitted")

        except Exception as exc:
            failed += 1
            print(f"  [{run['id']}] {revision} / {platform} — FAILED: {exc}")

    print(f"\nDone: {submitted} submitted, {failed} failed.")


if __name__ == "__main__":
    main()
