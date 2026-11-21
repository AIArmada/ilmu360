#!/usr/bin/env python3
"""Preview-first scaffolding & promotion CLI for Laravel Composer packages.

Supports both focused single-capability packages (`--layout single`) and large multi-feature
packages (`--layout modular`), custom PSR-4 source directories (`--source-dir`, default `src`),
non-destructive `tools/architecture.json` exception merging, safe `.ai/rules/index.md` merging,
and `promote --to modular` upgrades.
"""
import argparse
import difflib
import json
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Dict, List, Optional, Tuple


@dataclass
class PlannedFile:
    relative: str
    content: bytes
    kind: str  # "managed", "user_owned", "merge_json", "merge_index"
    allow_update: bool = False
    note: str = ""


def normalize_source_dir(raw: str) -> str:
    cleaned = raw.strip().strip("/")
    if not cleaned or ".." in Path(cleaned).parts or cleaned.startswith("/") or "\\" in cleaned:
        raise ValueError(f"Invalid --source-dir '{raw}'. Provide a clean relative directory such as 'src' or 'lib'.")
    return cleaned


def read_existing_source_dir(root: Path) -> Optional[str]:
    cfg_path = root / "tools" / "architecture.json"
    if not cfg_path.is_file():
        return None
    try:
        cfg = json.loads(cfg_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    val = cfg.get("sourceDir")
    if isinstance(val, str) and val.strip():
        return normalize_source_dir(val)
    return None


def inspect_package_root(path: str, requested_source_dir: Optional[str]) -> Tuple[Path, str, str, str, str]:
    root = Path(path).resolve()
    composer_path = root / "composer.json"
    if not composer_path.is_file():
        raise ValueError(f"Missing composer.json at {root}")
    data = json.loads(composer_path.read_text(encoding="utf-8"))
    if "laravel/framework" in data.get("require", {}) and (root / "artisan").is_file():
        raise ValueError("This looks like a Laravel application (artisan + laravel/framework). Use laravel-dune instead.")

    psr4 = data.get("autoload", {}).get("psr-4", {})
    if not isinstance(psr4, dict) or not psr4:
        raise ValueError(
            'No autoload.psr-4 entries found in composer.json. Add a PSR-4 mapping (e.g. "Vendor\\\\Package\\\\": "src/").'
        )

    source_dir = normalize_source_dir(requested_source_dir) if requested_source_dir else (read_existing_source_dir(root) or "src")
    pkg_ns = None
    found_mappings: List[str] = []

    for ns, mapped in psr4.items():
        dirs = mapped if isinstance(mapped, list) else [mapped]
        for entry in dirs:
            if not isinstance(entry, str):
                continue
            norm = entry.strip().strip("/")
            found_mappings.append(f"{ns} => {entry}")
            if norm == source_dir and pkg_ns is None:
                pkg_ns = ns.strip("\\")

    if not pkg_ns:
        found_str = ", ".join(found_mappings) if found_mappings else "(none)"
        raise ValueError(
            f"No autoload.psr-4 entry in composer.json maps to '{source_dir}/'. "
            f"Found: {found_str}. Pass --source-dir <dir> to match your package's PSR-4 directory."
        )

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


def format_diff(relative: str, old: bytes, new: bytes) -> str:
    old_lines = old.decode("utf-8", errors="replace").splitlines(keepends=True)
    new_lines = new.decode("utf-8", errors="replace").splitlines(keepends=True)
    return "".join(difflib.unified_diff(old_lines, new_lines, fromfile=f"a/{relative}", tofile=f"b/{relative}"))


def apply_plan(root: Path, plan: Dict[str, PlannedFile], apply: bool, force: bool, show_diff: bool) -> None:
    pending = []
    conflicts = []
    retained = 0

    for relative in sorted(plan):
        item = plan[relative]
        target = safe_target(root, relative)
        if not target.exists():
            pending.append(("CREATE", relative, target, item.content, None, item.note))
            continue

        existing = target.read_bytes()
        if existing == item.content:
            retained += 1
            continue

        if item.allow_update or force:
            note = item.note or ("(--force)" if force and not item.allow_update else "")
            pending.append(("UPDATE", relative, target, item.content, existing, note))
        else:
            conflicts.append((relative, item.kind, existing, item.content))

    if conflicts:
        if show_diff:
            for relative, _kind, existing, desired in conflicts:
                diff = format_diff(relative, existing, desired)
                if diff:
                    print(diff, end="" if diff.endswith("\n") else "\n", file=sys.stderr)
        listing = "\n".join(f"  {rel} [{kind}]" for rel, kind, _, _ in conflicts)
        raise ValueError(
            f"Refusing to overwrite {len(conflicts)} differing file(s):\n{listing}\n"
            "Re-run with --diff to inspect diffs, or --force to overwrite generated files."
        )

    created = 0
    updated = 0
    for action, relative, target, content, existing, note in pending:
        prefix = f"{'' if apply else 'WOULD '}{action} "
        suffix = f" {note}" if note else ""
        print(prefix + relative + suffix)
        if action == "UPDATE" and show_diff and existing is not None:
            diff = format_diff(relative, existing, content)
            if diff:
                print(diff, end="" if diff.endswith("\n") else "\n")
        if apply:
            target.parent.mkdir(parents=True, exist_ok=True)
            if action == "CREATE":
                with target.open("xb") as fh:
                    fh.write(content)
            else:
                target.write_bytes(content)
        if action == "CREATE":
            created += 1
        else:
            updated += 1

    print(f"{created} new file(s), {updated} updated file(s); {retained} identical file(s) retained.")


def render_template_bytes(raw: bytes, pkg_ns: str, source_dir: str) -> bytes:
    text = raw.decode("utf-8")
    text = text.replace("__PACKAGE_NAMESPACE__", pkg_ns)
    text = text.replace("__SOURCE_DIR__", source_dir)
    return text.encode("utf-8")


def plan_architecture_json(
    root: Path,
    template_bytes: bytes,
    pkg_ns: str,
    layout: str,
    source_dir: str,
    force: bool,
) -> PlannedFile:
    template_cfg = json.loads(template_bytes.decode("utf-8"))
    template_cfg["layout"] = layout
    template_cfg["namespace"] = pkg_ns
    template_cfg["sourceDir"] = source_dir

    target = safe_target(root, "tools/architecture.json")
    if not target.exists():
        content = (json.dumps(template_cfg, indent=2) + "\n").encode("utf-8")
        return PlannedFile("tools/architecture.json", content, kind="merge_json", allow_update=False)

    try:
        existing = json.loads(target.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        content = (json.dumps(template_cfg, indent=2) + "\n").encode("utf-8")
        return PlannedFile(
            "tools/architecture.json",
            content,
            kind="merge_json",
            allow_update=force,
            note="(replaced invalid JSON)" if force else "",
        )

    if not isinstance(existing, dict):
        content = (json.dumps(template_cfg, indent=2) + "\n").encode("utf-8")
        return PlannedFile("tools/architecture.json", content, kind="merge_json", allow_update=force)

    default_anti = dict(template_cfg.get("antiSlop", {}))
    existing_anti = existing.get("antiSlop")
    if isinstance(existing_anti, dict):
        for key in default_anti:
            if isinstance(existing_anti.get(key), bool):
                default_anti[key] = existing_anti[key]

    existing_exceptions = existing.get("exceptions")
    exceptions = existing_exceptions if isinstance(existing_exceptions, list) else []

    merged = {
        "schema": 1,
        "kind": "package",
        "layout": layout,
        "namespace": pkg_ns,
        "sourceDir": source_dir,
        "antiSlop": default_anti,
        "exceptions": exceptions,
    }

    content = (json.dumps(merged, indent=2) + "\n").encode("utf-8")
    exc_count = len(exceptions)
    note = f"(merged; preserved {exc_count} exception(s))"
    return PlannedFile("tools/architecture.json", content, kind="merge_json", allow_update=True, note=note)


def plan_rules_index(root: Path, source_dir: str) -> PlannedFile:
    row = f"| {source_dir}/**, config/**, tests/** | .ai/rules/package-architecture.md |"
    default_content = (
        "# Project Rules Index\n\n"
        "Before planning or editing, find the row whose globs match the file's path and read that rule file.\n\n"
        "| Applies to | Rule file |\n"
        "| --- | --- |\n"
        f"{row}\n"
    ).encode("utf-8")

    target = safe_target(root, ".ai/rules/index.md")
    if not target.exists():
        return PlannedFile(".ai/rules/index.md", default_content, kind="merge_index", allow_update=False)

    existing_text = target.read_text(encoding="utf-8", errors="replace")
    if ".ai/rules/package-architecture.md" in existing_text:
        return PlannedFile(".ai/rules/index.md", existing_text.encode("utf-8"), kind="merge_index", allow_update=True)

    lines = existing_text.splitlines()
    last_table_idx = None
    for idx, line in enumerate(lines):
        if line.strip().startswith("|") and line.strip().endswith("|"):
            last_table_idx = idx

    if last_table_idx is not None:
        lines.insert(last_table_idx + 1, row)
        merged_text = "\n".join(lines) + "\n"
    else:
        sep = "\n\n" if existing_text.strip() else ""
        merged_text = (
            existing_text.rstrip()
            + sep
            + "| Applies to | Rule file |\n| --- | --- |\n"
            + row
            + "\n"
        )

    return PlannedFile(
        ".ai/rules/index.md",
        merged_text.encode("utf-8"),
        kind="merge_index",
        allow_update=True,
        note="(appended package rule row)",
    )


def build_package_rule_file(layout: str, pkg_ns: str, source_dir: str, allow_update: bool = False) -> PlannedFile:
    layout_note = (
        f"This package uses **modular layout (`{source_dir}/Features/*`)**. Every feature declares `feature.json`; cross-feature imports go strictly through `Features/<Name>/Api/`."
        if layout == "modular"
        else f"This package uses **focused single-capability layout (`{source_dir}/`)**. Keep `Contracts/` and `Data/` pure, external SDKs in `Drivers/`, writes in `Actions/`, and thin adapters in `Commands/`/`EntryPoints/`."
    )
    content = f"""---
paths:
  - {source_dir}/**
  - config/**
  - tests/**
---

# Laravel Package Architecture Rules (`{pkg_ns}`)

## Layout
{layout_note}

## Package-Safety Laws
- Never import host application code (`App\\*`) inside `{source_dir}/`.
- Never mutate host globals (`Model::shouldBeStrict()`, `Model::unguard()`, `DB::prohibitDestructiveCommands()`, `Date::use()`).
- Never call `env()` outside `config/`, `sleep()`/`Sleep::sleep()`, `dd()`/`dump()`/`ray()`, or add inline `@phpstan-ignore` suppressions.
- Never keep backward-compatibility shims, legacy wrappers, or leftover dead files; update callers and delete superseded code in the same change.
- Keep `{source_dir}/Contracts/` and `{source_dir}/Data/` free of Eloquent models, `Illuminate\\Http\\*`, Actions, and Drivers.
- Run `composer arch:check`, `vendor/bin/phpstan analyse`, `vendor/bin/pest`, and `vendor/bin/pint --test` before declaring done.
""".encode("utf-8")
    return PlannedFile(".ai/rules/package-architecture.md", content, kind="managed", allow_update=allow_update)


def build_modular_platform_files(assets_root: Path, pkg_ns: str, source_dir: str) -> Dict[str, PlannedFile]:
    plan: Dict[str, PlannedFile] = {}
    modular = assets_root / "starter-modular"
    for p in modular.rglob("*"):
        if not p.is_file():
            continue
        rel = p.relative_to(modular).as_posix()
        if rel.startswith("src/"):
            rel = f"{source_dir}/{rel[4:]}"
        plan[rel] = PlannedFile(rel, render_template_bytes(p.read_bytes(), pkg_ns, source_dir), kind="managed")
    return plan


def build_feature_files(pkg_ns: str, source_dir: str, name: str) -> Dict[str, PlannedFile]:
    if not re.fullmatch(r"[A-Z][A-Za-z0-9]*", name):
        raise ValueError("Feature name must be single-word PascalCase (e.g. Billing, Webhooks).")
    ns = f"{pkg_ns}\\Features\\{name}"
    plan: Dict[str, PlannedFile] = {}
    base = f"{source_dir}/Features/{name}"
    plan[f"{base}/feature.json"] = PlannedFile(
        f"{base}/feature.json",
        (json.dumps({"schema": 1, "name": name, "owns": {}, "extensions": {}}, indent=2) + "\n").encode("utf-8"),
        kind="user_owned",
    )
    plan[f"{base}/FeatureModule.php"] = PlannedFile(
        f"{base}/FeatureModule.php",
        f"""<?php

declare(strict_types=1);

namespace {ns};

use Illuminate\\Contracts\\Foundation\\Application;
use {pkg_ns}\\Platform\\FeatureModule as FeatureModuleContract;

final class FeatureModule implements FeatureModuleContract
{{
    public function register(Application $app): void {{}}

    public function boot(Application $app): void {{}}
}}
""".encode("utf-8"),
        kind="user_owned",
    )
    plan[f"{base}/Actions/GetStatus.php"] = PlannedFile(
        f"{base}/Actions/GetStatus.php",
        f"""<?php

declare(strict_types=1);

namespace {ns}\\Actions;

final readonly class GetStatus
{{
    public function handle(): string
    {{
        return '{name}';
    }}
}}
""".encode("utf-8"),
        kind="user_owned",
    )
    return plan


def build_driver_files(pkg_ns: str, source_dir: str, name: str) -> Dict[str, PlannedFile]:
    if not re.fullmatch(r"[A-Z][A-Za-z0-9]*", name):
        raise ValueError("Driver name must be single-word PascalCase (e.g. Stripe, Http, Null).")
    plan: Dict[str, PlannedFile] = {}
    plan[f"{source_dir}/Contracts/{name}Driver.php"] = PlannedFile(
        f"{source_dir}/Contracts/{name}Driver.php",
        f"""<?php

declare(strict_types=1);

namespace {pkg_ns}\\Contracts;

interface {name}Driver
{{
    public function healthCheck(): bool;
}}
""".encode("utf-8"),
        kind="user_owned",
    )
    plan[f"{source_dir}/Drivers/Live{name}Driver.php"] = PlannedFile(
        f"{source_dir}/Drivers/Live{name}Driver.php",
        f"""<?php

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
""".encode("utf-8"),
        kind="user_owned",
    )
    return plan


def build_boost_docs(pkg_ns: str, pkg_slug: str, composer_name: str) -> Dict[str, PlannedFile]:
    title = pkg_ns.split("\\")[-1]
    plan: Dict[str, PlannedFile] = {}
    plan["resources/boost/guidelines/core.blade.php"] = PlannedFile(
        "resources/boost/guidelines/core.blade.php",
        f"""## {title} (`{composer_name}`)

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
""".encode("utf-8"),
        kind="user_owned",
    )
    plan[f"resources/boost/skills/{pkg_slug}-development/SKILL.md"] = PlannedFile(
        f"resources/boost/skills/{pkg_slug}-development/SKILL.md",
        f"""---
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
""".encode("utf-8"),
        kind="user_owned",
    )
    return plan


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["init", "promote", "feature", "driver", "boost-docs"])
    parser.add_argument("--root", required=True, help="Path to Laravel package root")
    parser.add_argument("--layout", choices=["single", "modular"], default="single", help="init only: focused single-capability vs multi-feature modular package")
    parser.add_argument("--source-dir", help="PSR-4 source directory (default: existing sourceDir or 'src')")
    parser.add_argument("--name", help="PascalCase Feature or Driver name")
    parser.add_argument("--apply", action="store_true", help="Write files (omitting previews only)")
    parser.add_argument("--diff", action="store_true", help="Print unified diffs for updated or conflicting files")
    parser.add_argument("--force", action="store_true", help="Overwrite differing generated files (architecture.json exceptions and rules index are still merged)")
    args = parser.parse_args()

    root, pkg_ns, source_dir, pkg_slug, composer_name = inspect_package_root(args.root, args.source_dir)
    assets_root = Path(__file__).resolve().parent.parent / "assets"
    common = assets_root / "starter-common"

    if args.mode == "init":
        cfg_path = root / "tools" / "architecture.json"
        if cfg_path.is_file() and not args.force:
            try:
                existing_cfg = json.loads(cfg_path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                existing_cfg = None
            if isinstance(existing_cfg, dict) and existing_cfg.get("kind") == "package":
                existing_layout = existing_cfg.get("layout")
                if existing_layout == "single" and args.layout == "modular":
                    raise ValueError(
                        f"Package is already initialized with layout='single'. "
                        f"Run `pkg_scaffold.py promote --root {args.root} [--apply]` "
                        "to upgrade to 'modular' cleanly while preserving exceptions, or pass --force."
                    )
                if existing_layout in ("single", "modular") and existing_layout != args.layout:
                    raise ValueError(
                        f"Package is already initialized with layout='{existing_layout}'. Pass --force to replace managed layout files."
                    )

        plan: Dict[str, PlannedFile] = {}
        for p in common.rglob("*"):
            if not p.is_file():
                continue
            rel = p.relative_to(common).as_posix()
            if rel == "tools/architecture.json":
                plan[rel] = plan_architecture_json(root, p.read_bytes(), pkg_ns, args.layout, source_dir, args.force)
            else:
                plan[rel] = PlannedFile(rel, render_template_bytes(p.read_bytes(), pkg_ns, source_dir), kind="managed")

        if args.layout == "modular":
            plan.update(build_modular_platform_files(assets_root, pkg_ns, source_dir))

        plan[".ai/rules/index.md"] = plan_rules_index(root, source_dir)
        plan[".ai/rules/package-architecture.md"] = build_package_rule_file(args.layout, pkg_ns, source_dir)

        apply_plan(root, plan, apply=args.apply, force=args.force, show_diff=args.diff)
        print(f"Detected package namespace '{pkg_ns}' (sourceDir: '{source_dir}', layout: {args.layout}).")
        print("Ensure `nikic/php-parser:^5` is in require-dev and add composer scripts: arch:boundaries, arch:writes, arch:check.")
        return 0

    if args.mode == "promote":
        cfg_path = root / "tools" / "architecture.json"
        if not cfg_path.is_file():
            raise ValueError("Missing tools/architecture.json. Run `pkg_scaffold.py init --apply` first.")
        existing_cfg = json.loads(cfg_path.read_text(encoding="utf-8"))
        current_layout = existing_cfg.get("layout")
        if current_layout not in ("single", "modular"):
            raise ValueError(f"Unsupported layout '{current_layout}' in tools/architecture.json.")
        if current_layout == "modular":
            print("Package is already using layout='modular'. Nothing to promote.")
            return 0

        plan = {}
        arch_tpl = (common / "tools" / "architecture.json").read_bytes()
        plan["tools/architecture.json"] = plan_architecture_json(root, arch_tpl, pkg_ns, "modular", source_dir, args.force)
        plan[".ai/rules/index.md"] = plan_rules_index(root, source_dir)
        plan[".ai/rules/package-architecture.md"] = build_package_rule_file("modular", pkg_ns, source_dir, allow_update=True)
        plan.update(build_modular_platform_files(assets_root, pkg_ns, source_dir))

        apply_plan(root, plan, apply=args.apply, force=args.force, show_diff=True if not args.apply else args.diff)
        print(f"Promoted '{pkg_ns}' from 'single' to 'modular'. Add features with `pkg_scaffold.py feature --root {args.root} --name <Capability> --apply`.")
        return 0

    if args.mode == "feature":
        if not args.name:
            parser.error("feature requires --name")
        cfg_path = root / "tools" / "architecture.json"
        if cfg_path.is_file():
            cfg = json.loads(cfg_path.read_text(encoding="utf-8"))
            if cfg.get("layout") != "modular":
                raise ValueError(
                    f"`feature` is only for layout='modular' packages. For focused packages, add classes directly under {source_dir}/Actions/, {source_dir}/Contracts/, {source_dir}/Drivers/ "
                    f"(or promote first with `pkg_scaffold.py promote --root {args.root} --apply`)."
                )
        plan = build_feature_files(pkg_ns, source_dir, args.name)
        apply_plan(root, plan, apply=args.apply, force=args.force, show_diff=args.diff)
        return 0

    if args.mode == "driver":
        if not args.name:
            parser.error("driver requires --name")
        plan = build_driver_files(pkg_ns, source_dir, args.name)
        apply_plan(root, plan, apply=args.apply, force=args.force, show_diff=args.diff)
        return 0

    plan = build_boost_docs(pkg_ns, pkg_slug, composer_name)
    apply_plan(root, plan, apply=args.apply, force=args.force, show_diff=args.diff)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(2)
