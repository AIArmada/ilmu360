#!/usr/bin/env python3
"""Compare uncached `php artisan route:list --json` snapshots before & after a migration slice.

Preserves HTTP method, domain, URI, route name, exact middleware order, and multiplicity.
Controller action class names are intentionally ignored so moves into app/Features/ succeed.
"""
import argparse
from collections import Counter
import json
from pathlib import Path
import sys


def signature(route: dict) -> tuple:
    mw = route.get("middleware", [])
    if isinstance(mw, str):
        mw = [line.strip() for line in mw.splitlines() if line.strip()]
    return (
        route.get("domain"),
        route.get("method"),
        route.get("uri"),
        route.get("name"),
        tuple(mw),
    )


def load_snapshot(path: str) -> Counter:
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(data, list) or any(
        not isinstance(row, dict) or not {"method", "uri", "middleware"} <= row.keys()
        for row in data
    ):
        raise ValueError(f"{path}: expected a `route:list --json` array with method, uri, and middleware.")
    return Counter(signature(row) for row in data)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("before", help="Path to before-routes.json")
    parser.add_argument("after", help="Path to after-routes.json")
    args = parser.parse_args()

    before = load_snapshot(args.before)
    after = load_snapshot(args.after)
    removed = before - after
    added = after - before

    report = {
        "same": before == after,
        "removed": [{"signature": list(k), "count": v} for k, v in removed.items()],
        "added": [{"signature": list(k), "count": v} for k, v in added.items()],
    }
    print(json.dumps(report, indent=2))
    return 0 if report["same"] else 1


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(2)
