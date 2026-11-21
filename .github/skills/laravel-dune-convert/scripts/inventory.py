#!/usr/bin/env python3
"""Read-only Laravel migration & architecture readiness inventory.

Never boots Laravel. Emits structured JSON covering framework/Boost/ecosystem versions,
directory buckets, and anti-slop hotspot counts.
"""
import argparse
import json
import re
import sys
from pathlib import Path


HOTSPOT_PATTERNS = {
    "env_outside_config": re.compile(r"\benv\s*\("),
    "sleep_calls": re.compile(r"\b(?:sleep|usleep|time_nanosleep)\s*\(|Sleep::(?:for|sleep|usleep)\s*\("),
    "model_unguard": re.compile(r"Model::unguard\s*\("),
    "inline_suppressions": re.compile(r"@phpstan-ignore|phpcs:(?:disable|ignore)|@psalm-suppress", re.I),
    "raw_db_facade": re.compile(r"\bDB::(?:table|statement|unprepared|affectingStatement|raw)\s*\("),
    "service_locator_app": re.compile(r"\b(?:app|resolve)\s*\(\s*[A-Z]"),
}


def scan_inventory(root: Path) -> dict:
    composer_path = root / "composer.json"
    if not composer_path.is_file():
        raise ValueError(f"Missing composer.json at {root}")
    composer = json.loads(composer_path.read_text(encoding="utf-8"))
    if "laravel/framework" not in composer.get("require", {}):
        raise ValueError("Expected a Laravel application (laravel/framework missing).")

    locked = {}
    lock_path = root / "composer.lock"
    if lock_path.is_file():
        lock = json.loads(lock_path.read_text(encoding="utf-8"))
        for pkg in lock.get("packages", []) + lock.get("packages-dev", []):
            locked[pkg["name"]] = pkg["version"]

    buckets = {
        "routes": ["routes"],
        "providers": ["app/Providers", "bootstrap/providers.php"],
        "models": ["app/Models"],
        "controllers": ["app/Http/Controllers"],
        "requests": ["app/Http/Requests"],
        "jobs": ["app/Jobs"],
        "listeners": ["app/Listeners"],
        "observers": ["app/Observers"],
        "policies": ["app/Policies"],
        "console": ["app/Console", "routes/console.php"],
        "livewire": ["app/Livewire", "app/Http/Livewire"],
        "filament": ["app/Filament"],
        "services_or_actions": ["app/Services", "app/Actions", "app/Domains"],
        "platform_core": ["app/Platform"],
        "features": ["app/Features"],
        "host": ["app/Host"],
        "shared": ["app/Shared"],
        "migrations": ["database/migrations"],
        "tests": ["tests"],
        "boost_ai": [".ai"],
    }

    files = {}
    for bucket, locations in buckets.items():
        found = set()
        for loc in locations:
            p = root / loc
            candidates = [p] if p.is_file() else p.rglob("*") if p.is_dir() else []
            for cand in candidates:
                if cand.is_file() and not cand.is_symlink() and cand.suffix in {".php", ".json", ".md"}:
                    found.add(cand.relative_to(root).as_posix())
        files[bucket] = sorted(found)

    hotspots = {k: [] for k in HOTSPOT_PATTERNS}
    app_dir = root / "app"
    if app_dir.is_dir():
        for php_file in sorted(app_dir.rglob("*.php")):
            if php_file.is_symlink():
                continue
            rel = php_file.relative_to(root).as_posix()
            text = php_file.read_text(encoding="utf-8", errors="replace")
            for key, pat in HOTSPOT_PATTERNS.items():
                matches = pat.findall(text)
                if matches:
                    hotspots[key].append({"file": rel, "count": len(matches)})

    ecosystem_prefixes = ("laravel/", "livewire/", "filament/", "inertiajs/", "pestphp/", "larastan/", "phpstan/", "spatie/", "nikic/")
    return {
        "schema": 1,
        "laravel_constraint": composer["require"]["laravel/framework"],
        "laravel_locked": locked.get("laravel/framework"),
        "php_constraint": composer.get("require", {}).get("php"),
        "boost_installed": "laravel/boost" in locked or "laravel/boost" in composer.get("require-dev", {}),
        "architecture_initialized": (root / "tools" / "platform.json").is_file(),
        "autoload": composer.get("autoload", {}),
        "ecosystem_packages": {k: v for k, v in sorted(locked.items()) if k.startswith(ecosystem_prefixes)},
        "bucket_counts": {k: len(v) for k, v in files.items()},
        "files": files,
        "anti_slop_hotspots": {k: {"files": len(v), "examples": v[:15]} for k, v in hotspots.items()},
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", required=True, help="Path to Laravel project root")
    args = parser.parse_args()
    print(json.dumps(scan_inventory(Path(args.root).resolve()), indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(2)
