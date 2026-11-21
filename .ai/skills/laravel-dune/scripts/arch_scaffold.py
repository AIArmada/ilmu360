#!/usr/bin/env python3
"""Preview-first additive scaffolding CLI for Laravel Dune.

Stdlib-only (Python 3.9+). Refuses to overwrite differing files or escape the project root.
"""
import argparse
import json
import re
import sys
from pathlib import Path


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


def build_feature_files(name: str, with_example: bool) -> dict:
    if not re.fullmatch(r"[A-Z][A-Za-z0-9]*", name):
        raise ValueError("Feature name must be single-word PascalCase (e.g. Orders, Billing).")
    ns = f"App\\Features\\{name}"
    slug = re.sub(r"(?<!^)(?=[A-Z])", "-", name).lower()
    files = {}

    def add(rel: str, text: str) -> None:
        files[f"app/Features/{name}/{rel}"] = text.encode("utf-8")

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

    return files


def build_host_files(name: str) -> dict:
    if not re.fullmatch(r"[A-Z][A-Za-z0-9]*", name):
        raise ValueError("Host integration name must be single-word PascalCase (e.g. Stripe, OpenAi).")
    files = {}
    files[f"app/Shared/Contracts/{name}Gateway.php"] = f"""<?php

declare(strict_types=1);

namespace App\\Shared\\Contracts;

interface {name}Gateway
{{
    public function healthCheck(): bool;
}}
""".encode("utf-8")
    files[f"app/Host/{name}/Live{name}Gateway.php"] = f"""<?php

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
""".encode("utf-8")
    return files


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=["init", "feature", "host"])
    parser.add_argument("--root", required=True, help="Path to Laravel project root")
    parser.add_argument("--name", help="PascalCase Feature or Host integration name")
    parser.add_argument("--apply", action="store_true", help="Write files (omitting previews only)")
    parser.add_argument("--discovery", choices=["auto", "native"], default="auto", help="init only: feature provider discovery mode")
    parser.add_argument("--no-example", action="store_true", help="feature only: omit example GetStatus/StatusController stub")
    args = parser.parse_args()

    root = validate_laravel_root(args.root)

    if args.mode == "init":
        starter = Path(__file__).resolve().parent.parent / "assets" / "starter"
        files = {p.relative_to(starter).as_posix(): p.read_bytes() for p in starter.rglob("*") if p.is_file()}
        cfg = json.loads(files["tools/architecture.json"].decode("utf-8"))
        cfg["discovery"] = args.discovery
        files["tools/architecture.json"] = (json.dumps(cfg, indent=2) + "\n").encode("utf-8")
        write_files(root, files, args.apply)
        print("Next: register App\\Platform\\PlatformServiceProvider::class once in bootstrap/providers.php.")
        print("Install dev tools: composer require --dev 'nikic/php-parser:^5' 'phpstan/phpstan:^2.3' 'larastan/larastan:^3.14'")
        print("Wire composer scripts (arch:boundaries, arch:writes, arch:check) and run `php artisan boost:update`.")
        return 0

    if not args.name:
        parser.error(f"{args.mode} requires --name")

    if args.mode == "feature":
        files = build_feature_files(args.name, with_example=not args.no_example)
        write_files(root, files, args.apply)
        print("Feature auto-discovers via PlatformServiceProvider (in 'auto' mode). Declare owned table:*/store:* in feature.json.")
        return 0

    files = build_host_files(args.name)
    write_files(root, files, args.apply)
    print(f"Bind App\\Shared\\Contracts\\{args.name}Gateway to App\\Host\\{args.name}\\Live{args.name}Gateway in AppServiceProvider.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(2)
