#!/usr/bin/env python3
"""Preview-first scaffolding CLI for Laravel Composer packages (src/ + Spatie skeleton).

Supports both focused single-capability packages (`--layout single`) and large multi-feature
packages (`--layout modular`). Auto-detects PSR-4 namespace and package slug from composer.json.
"""
import argparse
import json
import re
import sys
from pathlib import Path


def inspect_package_root(path: str) -> tuple:
    root = Path(path).resolve()
    composer_path = root / "composer.json"
    if not composer_path.is_file():
        raise ValueError(f"Missing composer.json at {root}")
    data = json.loads(composer_path.read_text(encoding="utf-8"))
    if "laravel/framework" in data.get("require", {}) and (root / "artisan").is_file():
        raise ValueError("This looks like a Laravel application (artisan + laravel/framework). Use laravel-dune instead.")

    psr4 = data.get("autoload", {}).get("psr-4", {})
    pkg_ns = None
    source_dir = "src"
    for ns, mapped in psr4.items():
        if isinstance(mapped, str) and mapped.rstrip("/") == "src":
            pkg_ns = ns.strip("\\")
            break
    if not pkg_ns:
        raise ValueError("Expected a PSR-4 autoload entry mapping a namespace to 'src/' in composer.json.")

    composer_name = data.get("name", "vendor/package")
    pkg_slug = composer_name.split("/")[-1] if "/" in composer_name else composer_name
    pkg_slug = re.sub(r"[^a-z0-9-]+", "-", pkg_slug.lower()).strip("-") or "package"
    return root, pkg_ns, source_dir, pkg_slug, composer_name


def safe_target(root: Path, relative: str) -> Path:
    target = root / relative
    resolved = target.resolve()
    if not str(resolved).startswith(str(root) + "/") and resolved != root:
        raise ValueError(f"Path escapes project root via symlink: {relative}")
    return target


def write_files(root: Path, files: dict, apply: bool) -> None:
    pending = []
    for relative, content in sorted(files.items()):
        target = safe_target(root, relative)
        if target.exists():
            if target.read_bytes() != content:
                raise ValueError(f"Refusing to overwrite differing file: {relative}")
        else:
            pending.append((relative, target, content))

    for relative, target, content in pending:
        print(("CREATE " if apply else "WOULD CREATE ") + relative)
        if apply:
            target.parent.mkdir(parents=True, exist_ok=True)
            with target.open("xb") as fh:
                fh.write(content)

    print(f"{len(pending)} new file(s); existing identical files retained.")


def render_template_bytes(raw: bytes, rel: str, pkg_ns: str, layout: str) -> bytes:
    text = raw.decode("utf-8")
    if rel.endswith(".json"):
        data = json.loads(text)
        if data.get("namespace") == "__PACKAGE_NAMESPACE__":
            data["namespace"] = pkg_ns
        if "layout" in data:
            data["layout"] = layout
        return (json.dumps(data, indent=2) + "\n").encode("utf-8")
    text = text.replace("__PACKAGE_NAMESPACE__", pkg_ns)
    return text.encode("utf-8")


def build_package_ai_rules(layout: str, pkg_ns: str) -> dict:
    files = {}
    files[".ai/rules/index.md"] = (
        "# Project Rules Index\n\n"
        "Before planning or editing, find the row whose globs match the file's path and read that rule file.\n\n"
        "| Applies to | Rule file |\n"
        "| --- | --- |\n"
        "| src/**, config/**, tests/** | .ai/rules/package-architecture.md |\n"
    ).encode("utf-8")

    layout_note = (
        "This package uses **modular layout (`src/Features/*`)**. Every feature declares `feature.json`; cross-feature imports go strictly through `Features/<Name>/Api/`."
        if layout == "modular"
        else "This package uses **focused single-capability layout (`src/`)**. Keep `Contracts/` and `Data/` pure, external SDKs in `Drivers/`, writes in `Actions/`, and thin adapters in `Commands/`/`EntryPoints/`."
    )
    files[".ai/rules/package-architecture.md"] = f"""---
paths:
  - src/**
  - config/**
  - tests/**
---

# Laravel Package Architecture Rules (`{pkg_ns}`)

## Layout
{layout_note}

## Package-Safety Laws
- Never import host application code (`App\\*`) inside `src/`.
- Never mutate host globals (`Model::shouldBeStrict()`, `Model::unguard()`, `DB::prohibitDestructiveCommands()`, `Date::use()`).
- Never call `env()` outside `config/`, `sleep()`/`Sleep::sleep()`, `dd()`/`dump()`/`ray()`, or add inline `@phpstan-ignore` suppressions.
- Keep `src/Contracts/` and `src/Data/` free of Eloquent models, `Illuminate\\Http\\*`, Actions, and Drivers.
- Run `composer arch:check`, `vendor/bin/phpstan analyse`, `vendor/bin/pest`, and `vendor/bin/pint --test` before declaring done.
""".encode("utf-8")
    return files


def build_feature_files(pkg_ns: str, name: str) -> dict:
    if not re.fullmatch(r"[A-Z][A-Za-z0-9]*", name):
        raise ValueError("Feature name must be single-word PascalCase (e.g. Billing, Webhooks).")
    ns = f"{pkg_ns}\\Features\\{name}"
    files = {}
    files[f"src/Features/{name}/feature.json"] = (
        json.dumps({"schema": 1, "name": name, "owns": {}, "extensions": {}}, indent=2) + "\n"
    ).encode("utf-8")
    files[f"src/Features/{name}/FeatureModule.php"] = f"""<?php

declare(strict_types=1);

namespace {ns};

use Illuminate\\Contracts\\Foundation\\Application;
use {pkg_ns}\\Platform\\FeatureModule as FeatureModuleContract;

final class FeatureModule implements FeatureModuleContract
{{
    public function register(Application $app): void {{}}

    public function boot(Application $app): void {{}}
}}
""".encode("utf-8")
    files[f"src/Features/{name}/Actions/GetStatus.php"] = f"""<?php

declare(strict_types=1);

namespace {ns}\\Actions;

final readonly class GetStatus
{{
    public function handle(): string
    {{
        return '{name}';
    }}
}}
""".encode("utf-8")
    return files


def build_driver_files(pkg_ns: str, name: str) -> dict:
    if not re.fullmatch(r"[A-Z][A-Za-z0-9]*", name):
        raise ValueError("Driver name must be single-word PascalCase (e.g. Stripe, Http, Null).")
    files = {}
    files[f"src/Contracts/{name}Driver.php"] = f"""<?php

declare(strict_types=1);

namespace {pkg_ns}\\Contracts;

interface {name}Driver
{{
    public function healthCheck(): bool;
}}
""".encode("utf-8")
    files[f"src/Drivers/Live{name}Driver.php"] = f"""<?php

declare(strict_types=1);

namespace {pkg_ns}\\Drivers;

use {pkg_ns}\\Contracts\\{name}Driver;

final readonly class Live{name}Driver implements {name}Driver
{{
    public function healthCheck(): bool
    {{
        return true;
    }}
}}
""".encode("utf-8")
    return files


def build_boost_docs(pkg_ns: str, pkg_slug: str, composer_name: str) -> dict:
    title = pkg_ns.split("\\")[-1]
    files = {}
    files["resources/boost/guidelines/core.blade.php"] = f"""## {title} (`{composer_name}`)

Provides `{title}` functionality for Laravel applications.

### Key Conventions

- Configure package options in `config/{pkg_slug}.php` rather than calling `env()` directly.
- Depend on public contracts under `{pkg_ns}\\Contracts\\` and immutable DTOs under `{pkg_ns}\\Data\\` instead of internal classes.
- Never bypass package Actions to mutate package-owned tables directly.

@verbatim
<code-snippet name="Resolve {title} via Laravel container" lang="php">
use {pkg_ns}\\{title};

$service = app({title}::class);
</code-snippet>
@endverbatim
""".encode("utf-8")

    files[f"resources/boost/skills/{pkg_slug}-development/SKILL.md"] = f"""---
name: {pkg_slug}-development
description: Build and test integrations using {composer_name} ({pkg_ns}), including configuration, public contracts, drivers, and Testbench/Pest verification.
---

# {title} Development

## When to use this skill

Use this skill when installing, configuring, extending, or testing `{composer_name}` inside a Laravel application.

## Conventions

- Read package configuration via `config('{pkg_slug}.*')`.
- Type-hint interfaces from `{pkg_ns}\\Contracts\\` so drivers remain swappable in tests.
- Prefer immutable value objects from `{pkg_ns}\\Data\\` across application boundaries.
- Run package-related Pest/PHPUnit feature tests after any integration change.
""".encode("utf-8")
    return files


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["init", "feature", "driver", "boost-docs"])
    parser.add_argument("--root", required=True, help="Path to Laravel package root")
    parser.add_argument("--layout", choices=["single", "modular"], default="single", help="init only: focused single-capability vs multi-feature modular package")
    parser.add_argument("--name", help="PascalCase Feature or Driver name")
    parser.add_argument("--apply", action="store_true", help="Write files (omitting previews only)")
    args = parser.parse_args()

    root, pkg_ns, _source_dir, pkg_slug, composer_name = inspect_package_root(args.root)
    assets_root = Path(__file__).resolve().parent.parent / "assets"

    if args.mode == "init":
        files = {}
        common = assets_root / "starter-common"
        for p in common.rglob("*"):
            if p.is_file():
                rel = p.relative_to(common).as_posix()
                files[rel] = render_template_bytes(p.read_bytes(), rel, pkg_ns, args.layout)

        if args.layout == "modular":
            modular = assets_root / "starter-modular"
            for p in modular.rglob("*"):
                if p.is_file():
                    rel = p.relative_to(modular).as_posix()
                    files[rel] = render_template_bytes(p.read_bytes(), rel, pkg_ns, args.layout)

        files.update(build_package_ai_rules(args.layout, pkg_ns))
        write_files(root, files, args.apply)
        print(f"Detected package namespace '{pkg_ns}' (layout: {args.layout}).")
        print("Ensure `nikic/php-parser:^5` is in require-dev and add composer scripts: arch:boundaries, arch:writes, arch:check.")
        return 0

    if args.mode == "feature":
        if not args.name:
            parser.error("feature requires --name")
        cfg_path = root / "tools" / "architecture.json"
        if cfg_path.is_file():
            cfg = json.loads(cfg_path.read_text(encoding="utf-8"))
            if cfg.get("layout") != "modular":
                raise ValueError("`feature` is only for layout='modular' packages. For focused packages, add classes directly under src/Actions/, src/Contracts/, src/Drivers/.")
        files = build_feature_files(pkg_ns, args.name)
        write_files(root, files, args.apply)
        return 0

    if args.mode == "driver":
        if not args.name:
            parser.error("driver requires --name")
        files = build_driver_files(pkg_ns, args.name)
        write_files(root, files, args.apply)
        return 0

    files = build_boost_docs(pkg_ns, pkg_slug, composer_name)
    write_files(root, files, args.apply)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(2)
