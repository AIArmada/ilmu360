#!/usr/bin/env python3
"""Preview-first scaffolding CLI for Laravel Dune applications (app/).

Stdlib-only (Python 3.9+). Preserves existing `tools/architecture.json` exceptions on merge,
reports all conflicting files together, and supports `--diff` and `--force`.
"""
import argparse
import difflib
import json
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Dict


@dataclass
class PlannedFile:
    relative: str
    content: bytes
    kind: str  # "managed", "user_owned", "merge_json"
    allow_update: bool = False
    note: str = ""


def validate_laravel_root(path: str) -> Path:
    root = Path(path).resolve()
    composer_path = root / "composer.json"
    if not composer_path.is_file():
        raise ValueError(f"Missing composer.json at {root}")
    data = json.loads(composer_path.read_text(encoding="utf-8"))
    if "laravel/framework" not in data.get("require", {}):
        raise ValueError("Expected a Laravel application (laravel/framework missing from composer.json require).")
    if data.get("autoload", {}).get("psr-4", {}).get("App\\") != "app/":
        raise ValueError("Scaffold requires PSR-4 mapping App\\ => app/. Adapt manually for custom namespaces.")
    return root


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


def plan_app_architecture_json(root: Path, template_bytes: bytes, discovery: str, force: bool) -> PlannedFile:
    template_cfg = json.loads(template_bytes.decode("utf-8"))
    template_cfg["discovery"] = discovery

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
        "discovery": discovery,
        "antiSlop": default_anti,
        "exceptions": exceptions,
    }

    content = (json.dumps(merged, indent=2) + "\n").encode("utf-8")
    note = f"(merged; preserved {len(exceptions)} exception(s))"
    return PlannedFile("tools/architecture.json", content, kind="merge_json", allow_update=True, note=note)


def build_feature_files(name: str, with_example: bool) -> Dict[str, PlannedFile]:
    if not re.fullmatch(r"[A-Z][A-Za-z0-9]*", name):
        raise ValueError("Feature name must be single-word PascalCase (e.g. Orders, Billing).")
    ns = f"App\\Features\\{name}"
    slug = re.sub(r"(?<!^)(?=[A-Z])", "-", name).lower()
    plan: Dict[str, PlannedFile] = {}

    def add(rel: str, text: str) -> None:
        full = f"app/Features/{name}/{rel}"
        plan[full] = PlannedFile(full, text.encode("utf-8"), kind="user_owned")

    manifest = {
        "schema": 1,
        "name": name,
        "owns": {},
        "extensions": {},
    }
    add("feature.json", json.dumps(manifest, indent=2) + "\n")
    add(
        "FeatureServiceProvider.php",
        f"""<?php

declare(strict_types=1);

namespace {ns};

use App\\Platform\\FeatureModule;

final class FeatureServiceProvider extends FeatureModule {{}}
""",
    )

    if with_example:
        add(
            "Actions/GetStatus.php",
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
""",
        )
        add(
            "EntryPoints/Http/StatusController.php",
            f"""<?php

declare(strict_types=1);

namespace {ns}\\EntryPoints\\Http;

use {ns}\\Actions\\GetStatus;
use Illuminate\\Http\\JsonResponse;

final readonly class StatusController
{{
    public function __invoke(GetStatus $query): JsonResponse
    {{
        return new JsonResponse(['feature' => $query->handle()]);
    }}
}}
""",
        )
        add(
            "Routes/api.php",
            f"""<?php

declare(strict_types=1);

use {ns}\\EntryPoints\\Http\\StatusController;
use Illuminate\\Support\\Facades\\Route;

Route::middleware('api')->prefix('api/{slug}')->name('{slug}.')->group(function (): void {{
    Route::get('/status', StatusController::class)->name('status');
}});
""",
        )

    return plan


def build_host_files(name: str) -> Dict[str, PlannedFile]:
    if not re.fullmatch(r"[A-Z][A-Za-z0-9]*", name):
        raise ValueError("Host integration name must be single-word PascalCase (e.g. Stripe, OpenAi).")
    plan: Dict[str, PlannedFile] = {}
    plan[f"app/Shared/Contracts/{name}Gateway.php"] = PlannedFile(
        f"app/Shared/Contracts/{name}Gateway.php",
        f"""<?php

declare(strict_types=1);

namespace App\\Shared\\Contracts;

interface {name}Gateway
{{
    public function healthCheck(): bool;
}}
""".encode("utf-8"),
        kind="user_owned",
    )
    plan[f"app/Host/{name}/Live{name}Gateway.php"] = PlannedFile(
        f"app/Host/{name}/Live{name}Gateway.php",
        f"""<?php

declare(strict_types=1);

namespace App\\Host\\{name};

use App\\Shared\\Contracts\\{name}Gateway;

final readonly class Live{name}Gateway implements {name}Gateway
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


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["init", "feature", "host"])
    parser.add_argument("--root", required=True, help="Path to Laravel project root")
    parser.add_argument("--name", help="PascalCase Feature or Host integration name")
    parser.add_argument("--apply", action="store_true", help="Write files (omitting previews only)")
    parser.add_argument("--diff", action="store_true", help="Print unified diffs for updated or conflicting files")
    parser.add_argument("--force", action="store_true", help="Overwrite differing generated files (architecture.json exceptions are still merged)")
    parser.add_argument("--discovery", choices=["auto", "native"], default="auto", help="init only: feature provider discovery mode")
    parser.add_argument("--no-example", action="store_true", help="feature only: omit example GetStatus/StatusController stub")
    args = parser.parse_args()

    root = validate_laravel_root(args.root)

    if args.mode == "init":
        starter = Path(__file__).resolve().parent.parent / "assets" / "starter"
        plan: Dict[str, PlannedFile] = {}
        for p in starter.rglob("*"):
            if not p.is_file():
                continue
            rel = p.relative_to(starter).as_posix()
            if rel == "tools/architecture.json":
                plan[rel] = plan_app_architecture_json(root, p.read_bytes(), args.discovery, args.force)
            else:
                plan[rel] = PlannedFile(rel, p.read_bytes(), kind="managed")
        apply_plan(root, plan, apply=args.apply, force=args.force, show_diff=args.diff)
        print("Next: register App\\Platform\\PlatformServiceProvider::class once in bootstrap/providers.php.")
        print("Install dev tools: composer require --dev 'nikic/php-parser:^5' 'phpstan/phpstan:^2.3' 'larastan/larastan:^3.14'")
        print("Wire composer scripts (arch:boundaries, arch:writes, arch:check) and run `php artisan boost:update`.")
        return 0

    if not args.name:
        parser.error(f"{args.mode} requires --name")

    if args.mode == "feature":
        plan = build_feature_files(args.name, with_example=not args.no_example)
        apply_plan(root, plan, apply=args.apply, force=args.force, show_diff=args.diff)
        print("Feature auto-discovers via PlatformServiceProvider (in 'auto' mode). Declare owned table:*/store:* in feature.json.")
        return 0

    plan = build_host_files(args.name)
    apply_plan(root, plan, apply=args.apply, force=args.force, show_diff=args.diff)
    print(f"Bind App\\Shared\\Contracts\\{args.name}Gateway to App\\Host\\{args.name}\\Live{args.name}Gateway in AppServiceProvider.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(2)
