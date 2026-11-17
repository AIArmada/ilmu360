#!/usr/bin/env python3
"""One-time no-alias cutover of feature commands and generic infrastructure."""
from __future__ import annotations
from collections import defaultdict
from pathlib import Path
import re

ROOT = Path.cwd()
COMMANDS = {
    "DispatchRewardDigestsCommand.php": "Rewards",
    "DrainCommittedShareSignalsCommand.php": "ShareTracking",
    "IndexEventsToTypesense.php": "Events",
    "IndexInstitutionsToTypesense.php": "Institutions",
    "IndexPersonsToTypesense.php": "Persons",
    "IndexReferencesToTypesense.php": "References",
    "IssueMcpToken.php": "Identity",
    "PruneShareCards.php": "Events",
    "ResolveDigestMailAmbiguityCommand.php": "Communications",
    "ShareTracking/DispatchShareEffectReceiptsCommand.php": "ShareTracking",
    "ShareTracking/PruneGuestShareProfilesCommand.php": "ShareTracking",
}
EXTRA_MOVES = {
    "app/Console/Commands/MigrateMediaToNewStructure.php":
        "app/Platform/Media/Commands/MigrateMediaToNewStructure.php",
    "app/Services/Captcha/TurnstileVerifier.php":
        "app/Platform/Captcha/TurnstileVerifier.php",
    "app/Services/Diagnostics/NetworkDiagnosticsService.php":
        "app/Platform/Diagnostics/NetworkDiagnosticsService.php",
    "app/Support/Mcp/McpTokenManager.php":
        "app/Platform/Mcp/McpTokenManager.php",
}
ROOTS = ["app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github","scripts"]
TEXT_SUFFIXES = {".php",".json",".md",".neon",".yml",".yaml",".xml",".js",".ts",".txt"}
TEXT_ROOT_FILES = ["composer.json","phpstan.neon","rector.php","README.md"]
PROVIDER = "app/Features/{}/FeatureServiceProvider.php"

def class_name(path: str) -> str:
    assert path.startswith("app/") and path.endswith(".php")
    return "App\\" + path[4:-4].replace("/", "\\")

def rewrite(content: str, mapping: dict[str, str], paths: dict[str, str]) -> str:
    for old, new in sorted(mapping.items(), key=lambda kv: -len(kv[0])):
        content = content.replace(old.replace("\\", "\\\\"), new.replace("\\", "\\\\"))
        content = content.replace(old, new)
    for old, new in sorted(paths.items(), key=lambda kv: -len(kv[0])):
        content = content.replace(old, new)
    return content

def main() -> None:
    moves = dict(EXTRA_MOVES)
    commands_by_feature: dict[str, list[str]] = defaultdict(list)
    for filename, feature in COMMANDS.items():
        old = "app/Console/Commands/" + filename
        new = f"app/Features/{feature}/EntryPoints/Console/Commands/" + Path(filename).name
        moves[old] = new
        commands_by_feature[feature].append(class_name(new))
    classes = {class_name(old): class_name(new) for old, new in moves.items()}
    for old, new in moves.items():
        if not (ROOT / old).is_file():
            raise RuntimeError(f"Missing source: {old}")
        if (ROOT / new).exists():
            raise RuntimeError(f"Destination already exists: {new}")

    for old, new in moves.items():
        source, dest = ROOT / old, ROOT / new
        contents = source.read_text()
        old_ns = class_name(old).rsplit("\\", 1)[0]
        new_ns = class_name(new).rsplit("\\", 1)[0]
        needle = "namespace " + old_ns + ";"
        if needle not in contents:
            raise RuntimeError(f"Cannot find namespace in {old}")
        contents = contents.replace(needle, "namespace " + new_ns + ";", 1)
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(contents)
        source.unlink()

    for root in ROOTS:
        base = ROOT / root
        if base.is_dir():
            for file in base.rglob("*"):
                if not file.is_file() or file.suffix not in TEXT_SUFFIXES:
                    continue
                if ".git" in file.parts or "vendor" in file.parts or "node_modules" in file.parts:
                    continue
                original = file.read_text(encoding="utf-8")
                updated = rewrite(original, classes, moves)
                if original != updated:
                    file.write_text(updated, encoding="utf-8")
    for name in TEXT_ROOT_FILES:
        file = ROOT / name
        if file.is_file():
            original = file.read_text()
            updated = rewrite(original, classes, moves)
            if original != updated:
                file.write_text(updated)

    for feature, names in commands_by_feature.items():
        provider = ROOT / PROVIDER.format(feature)
        content = provider.read_text()
        registrations = "\n".join(f"            \\{name}::class," for name in sorted(names))
        statement = f"\n        $this->commands([\n{registrations}\n        ]);\n"
        pattern = r"(public function register\s*\(\s*\)\s*:\s*void\s*\{)"
        if re.search(pattern, content):
            content, count = re.subn(pattern, lambda m: m.group(1) + statement, content, count=1)
            assert count == 1
        else:
            if not re.search(r"class FeatureServiceProvider extends FeatureModule\s*\{\s*\}", content):
                raise RuntimeError(f"No safe register insertion point in {provider}")
            content = re.sub(
                r"(class FeatureServiceProvider extends FeatureModule\s*\{)\s*(\})",
                lambda m: m.group(1) + "\n    public function register(): void\n    {" + statement + "    }\n" + m.group(2),
                content, count=1,
            )
        provider.write_text(content)

    bootstrap = ROOT / "bootstrap/app.php"
    content = bootstrap.read_text()
    command = r"\App\Platform\Media\Commands\MigrateMediaToNewStructure::class"
    needle = "__DIR__.'/../app/Console/Commands',"
    if needle not in content:
        raise RuntimeError("Root command registration has changed")
    bootstrap.write_text(content.replace(needle, needle + "\n        " + command + ","))

    for feature in ("Rewards", "ShareTracking"):
        provider = ROOT / PROVIDER.format(feature)
        content = provider.read_text()
        content = content.replace(
            "DrainCommittedShareSignalsCommand stays root.",
            "DrainCommittedShareSignalsCommand is registered through this feature provider.",
        ).replace(
            "ResolveDigestMailAmbiguityCommand stays a root transport adapter",
            "ResolveDigestMailAmbiguityCommand is a feature-owned console transport adapter",
        )
        provider.write_text(content)


    # Communication operations authorize an Identity actor through the existing
    # public AccountDirectory boundary, never a foreign mutable User model.
    command_path = ROOT / "app/Features/Communications/EntryPoints/Console/Commands/ResolveDigestMailAmbiguityCommand.php"
    command_src = command_path.read_text()
    substitutions = [
        (r"use App\Models\User;", r"use App\Shared\Auth\AccountDirectory;"),
        ("handle(ManagedNotificationDelivery $communications): int", "handle(ManagedNotificationDelivery $communications, AccountDirectory $accounts): int"),
        ("$operator = User::query()->whereKey($actorOption)->first();", ""),
        ("if (! $operator instanceof User)", "if (! $accounts->exists($actorOption))"),
        ("if (! $operator->hasAnyRole(['super_admin', 'admin']))", "if (! array_intersect(['super_admin', 'admin'], $accounts->roles($actorOption)))"),
        ("$operator->getKey()", "$actorOption"),
    ]
    for old, new in substitutions:
        if old not in command_src:
            raise RuntimeError("Expected Identity boundary usage not found: " + old)
        command_src = command_src.replace(old, new)
    command_path.write_text(command_src)

    for old in moves:
        if (ROOT / old).exists():
            raise RuntimeError(f"Old implementation survives: {old}")
    for old in classes:
        for directory in ("app", "bootstrap", "config", "routes", "tests", "tools"):
            for path in (ROOT / directory).rglob("*"):
                if path.is_file() and path.suffix in TEXT_SUFFIXES:
                    content = path.read_text()
                    if old in content or old.replace("\\", "\\\\") in content:
                        raise RuntimeError(f"Stale class {old} in {path}")
    print(f"Rehomed {len(moves)} classes ({len(COMMANDS)} feature commands; {len(EXTRA_MOVES)} infrastructure adapters).")
    for feature, commands in sorted(commands_by_feature.items()):
        print(f"{feature}: {', '.join(sorted(commands))}")
    print("No old implementation paths or class names remain in checked production/test paths.")

if __name__ == "__main__":
    main()
