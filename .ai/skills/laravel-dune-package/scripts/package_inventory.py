#!/usr/bin/env python3
"""Read-only inventory & SemVer public-surface scanner for Laravel Composer packages (src/).

Never boots Orchestra Testbench or Laravel. Emits JSON covering PSR-4 mapping,
extra.laravel providers/aliases, config keys, Facades, Commands, migrations/stubs,
Boost packaging status, and package-safety / anti-slop hotspots.
"""
import argparse
import json
import re
import sys
from pathlib import Path


HOTSPOTS = {
    "app_namespace_leaks": re.compile(r"\bApp\\[A-Z]"),
    "host_global_mutations": re.compile(
        r"Model::(?:shouldBeStrict|unguard|preventLazyLoading|preventSilentlyDiscardingAttributes|preventAccessingMissingAttributes)\s*\("
        r"|DB::prohibitDestructiveCommands\s*\("
        r"|Date::use\s*\("
    ),
    "env_outside_config": re.compile(r"\benv\s*\("),
    "sleep_calls": re.compile(r"\b(?:sleep|usleep|time_nanosleep)\s*\(|Sleep::(?:for|sleep|usleep)\s*\("),
    "inline_suppressions": re.compile(r"@phpstan-ignore|phpcs:(?:disable|ignore)|@psalm-suppress", re.I),
    "debug_calls": re.compile(r"\b(?:dd|dump|ray|var_dump)\s*\("),
}

SIGNATURE_RE = re.compile(r"\$signature\s*=\s*['\"]([^'\"]+)['\"]")


def scan_package(root: Path) -> dict:
    composer_path = root / "composer.json"
    if not composer_path.is_file():
        raise ValueError(f"Missing composer.json at {root}")
    composer = json.loads(composer_path.read_text(encoding="utf-8"))

    locked = {}
    lock_path = root / "composer.lock"
    if lock_path.is_file():
        lock = json.loads(lock_path.read_text(encoding="utf-8"))
        for pkg in lock.get("packages", []) + lock.get("packages-dev", []):
            locked[pkg["name"]] = pkg["version"]

    req = composer.get("require", {})
    req_dev = composer.get("require-dev", {})
    psr4 = composer.get("autoload", {}).get("psr-4", {})
    extra_laravel = composer.get("extra", {}).get("laravel", {})

    src_dir = root / "src"
    src_files = sorted(p.relative_to(root).as_posix() for p in src_dir.rglob("*.php") if not p.is_symlink()) if src_dir.is_dir() else []

    facades = [f for f in src_files if "/Facades/" in f]
    commands = []
    hotspots = {k: [] for k in HOTSPOTS}

    for rel in src_files:
        text = (root / rel).read_text(encoding="utf-8", errors="replace")
        for sig in SIGNATURE_RE.findall(text):
            commands.append({"file": rel, "signature": sig})
        for key, pat in HOTSPOTS.items():
            matches = pat.findall(text)
            if matches:
                hotspots[key].append({"file": rel, "count": len(matches)})

    config_files = sorted(p.relative_to(root).as_posix() for p in (root / "config").glob("*.php")) if (root / "config").is_dir() else []
    migration_files = sorted(
        p.relative_to(root).as_posix()
        for p in (root / "database" / "migrations").rglob("*")
        if p.is_file() and not p.is_symlink()
    ) if (root / "database" / "migrations").is_dir() else []
    boost_files = sorted(
        p.relative_to(root).as_posix()
        for p in (root / "resources" / "boost").rglob("*")
        if p.is_file() and not p.is_symlink()
    ) if (root / "resources" / "boost").is_dir() else []

    arch_cfg = root / "tools" / "architecture.json"
    suggested_layout = "modular" if len(src_files) >= 35 or (src_dir / "Features").is_dir() else "single"

    return {
        "schema": 1,
        "package_name": composer.get("name"),
        "php_constraint": req.get("php"),
        "psr4": psr4,
        "uses_spatie_package_tools": "spatie/laravel-package-tools" in req or "spatie/laravel-package-tools" in locked,
        "uses_orchestra_testbench": "orchestra/testbench" in req_dev or "orchestra/testbench" in locked,
        "uses_phpstan_extension_installer": "phpstan/extension-installer" in req_dev or "phpstan/extension-installer" in locked,
        "has_workbench": (root / "workbench").is_dir(),
        "architecture_initialized": arch_cfg.is_file(),
        "suggested_layout": suggested_layout,
        "public_surface": {
            "providers": extra_laravel.get("providers", []),
            "aliases": extra_laravel.get("aliases", {}),
            "facades": facades,
            "config_files": config_files,
            "artisan_commands": commands,
            "migration_stubs": migration_files,
            "boost_resources": boost_files,
        },
        "src_php_count": len(src_files),
        "anti_slop_and_safety_hotspots": {k: {"files": len(v), "examples": v[:15]} for k, v in hotspots.items()},
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", required=True)
    args = parser.parse_args()
    print(json.dumps(scan_package(Path(args.root).resolve()), indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(2)
