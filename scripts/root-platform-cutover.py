#!/usr/bin/env python3
"""One-shot, direct ownership cutover for root generic integration infrastructure.

No compatibility aliases, forwarded subclasses, duplicate files or temporary shims.
"""
from pathlib import Path

ROOT = Path.cwd()
DIR_MOVES = {
    "app/Support/ApiDocumentation": "app/Platform/ApiDocumentation",
    "app/Support/Location": "app/Platform/Location",
    "app/Support/Language": "app/Platform/Language",
    "app/Support/Diagnostics": "app/Platform/Diagnostics",
    "app/Support/Documentation": "app/Platform/Documentation",
}
FILE_MOVES = {
    "app/Support/Passport/PassportKeyProvisioner.php":
        "app/Platform/Auth/PassportKeyProvisioner.php",
    "app/Support/Filament/ContentVersionedJs.php":
        "app/Platform/Ui/ContentVersionedJs.php",
    "app/Jobs/Media/GenerateResponsiveImagesJob.php":
        "app/Platform/Media/Jobs/GenerateResponsiveImagesJob.php",
}
SOURCE_ROOTS = ["app", "bootstrap", "config", "database", "resources", "routes", "tests", "tools", "docs", "lang", ".github", "scripts"]
TEXT_SUFFIXES = {".php", ".json", ".md", ".neon", ".yml", ".yaml", ".xml", ".js", ".ts", ".txt"}

def fqcn(path: str) -> str:
    if not path.startswith("app/") or not path.endswith(".php"):
        raise RuntimeError("Unexpected PHP class path " + path)
    return "App\\" + path[4:-4].replace("/", "\\")

def all_source_files():
    for prefix in SOURCE_ROOTS:
        p = ROOT / prefix
        if p.is_dir():
            for f in p.rglob("*"):
                if f.is_file() and f.suffix in TEXT_SUFFIXES and not any(x in f.parts for x in ("node_modules", "vendor", ".git")):
                    yield f
    for name in ("composer.json", "phpstan.neon", "rector.php", "README.md", "phpunit.xml"):
        f = ROOT / name
        if f.exists():
            yield f

def main():
    moves = dict(FILE_MOVES)
    for old_dir, new_dir in DIR_MOVES.items():
        if not (ROOT / old_dir).is_dir():
            raise RuntimeError("Missing directory " + old_dir)
        for old_path in sorted((ROOT / old_dir).rglob("*.php")):
            old = old_path.relative_to(ROOT).as_posix()
            new = new_dir + old[len(old_dir):]
            moves[old] = new
    if not moves:
        raise RuntimeError("Empty refactor")
    for old, new in moves.items():
        if not (ROOT / old).is_file():
            raise RuntimeError("Missing source " + old)
        if (ROOT / new).exists():
            raise RuntimeError("Collision: " + new)
        source = (ROOT / old).read_text()
        if "App\\Features\\" in source:
            raise RuntimeError("Platform must not import feature types: " + old)

    replacements = {fqcn(old): fqcn(new) for old, new in moves.items()}
    for old, new in moves.items():
        source = ROOT / old
        destination = ROOT / new
        src = source.read_text()
        old_ns = fqcn(old).rsplit("\\", 1)[0]
        new_ns = fqcn(new).rsplit("\\", 1)[0]
        expected = "namespace " + old_ns + ";"
        if expected not in src:
            raise RuntimeError("Namespace mismatch: " + old)
        src = src.replace(expected, "namespace " + new_ns + ";", 1)
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text(src)
        source.unlink()

    replacements = sorted(replacements.items(), key=lambda kv: len(kv[0]), reverse=True)
    move_paths = sorted(moves.items(), key=lambda kv: len(kv[0]), reverse=True)
    for file in all_source_files():
        content = file.read_text(encoding="utf-8")
        next_content = content
        for old, new in replacements:
            next_content = next_content.replace(old.replace("\\", "\\\\"), new.replace("\\", "\\\\"))
            next_content = next_content.replace(old, new)
        for old, new in move_paths:
            next_content = next_content.replace(old, new)
        if next_content != content:
            file.write_text(next_content, encoding="utf-8")

    for old, new in moves.items():
        if (ROOT / old).exists():
            raise RuntimeError("Old implementation still exists: " + old)
        if not (ROOT / new).is_file():
            raise RuntimeError("Moved implementation missing: " + new)
    for file in all_source_files():
        content = file.read_text(encoding="utf-8")
        for old, _ in replacements:
            if old in content or old.replace("\\", "\\\\") in content:
                raise RuntimeError("Stale FQCN " + old + " in " + str(file))
    print("Migrated " + str(len(moves)) + " classes directly to Platform.")
    for old, new in moves.items():
        print(old + " -> " + new)
    print("No duplicate paths, legacy class names or forwarding adapters remain.")

if __name__ == "__main__":
    main()
