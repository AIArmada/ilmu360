#!/usr/bin/env python3
"""Directly relocate shared Laravel validation adapters to Platform."""

from pathlib import Path
import re

ROOT=Path.cwd()
MOVES={
    "app/Rules/TeamName.php":"app/Platform/Validation/Rules/TeamName.php",
    "app/Rules/ValidAreaAssignmentRoles.php":"app/Platform/Validation/Rules/ValidAreaAssignmentRoles.php",
}
folders=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github")
exts=(".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt")

def class_name(path):
    return "App\\"+path[4:-4].replace("/","\\")

for src,dst in MOVES.items():
    original,relocated=ROOT/src,ROOT/dst
    if not original.is_file() or relocated.exists():raise RuntimeError("Unsafe validator move "+src)
    code=original.read_text()
    old_ns="namespace App\\Rules;"
    if old_ns not in code:raise RuntimeError("Source rule namespace mismatch")
    if "ValidationRule" not in code:raise RuntimeError("Rule no longer implements validation contract")
    relocated.parent.mkdir(parents=True,exist_ok=True)
    relocated.write_text(code.replace(old_ns,"namespace App\\Platform\\Validation\\Rules;",1))
    original.unlink()

for folder in folders:
    directory=ROOT/folder
    if not directory.is_dir():continue
    for file in directory.rglob("*"):
        if not file.is_file() or file.suffix not in exts:continue
        content=file.read_text()
        updated=content
        for old_path,new_path in MOVES.items():
            old_cls=class_name(old_path)
            new_cls=class_name(new_path)
            for left,right in ((old_cls.replace("\\","\\\\"),new_cls.replace("\\","\\\\")),(old_cls,new_cls)):
                updated=re.sub(r"(?<![A-Za-z0-9_])"+re.escape(left)+r"(?![A-Za-z0-9_])",lambda match:right,updated)
            updated=updated.replace(old_path,new_path)
        if updated!=content:file.write_text(updated)

for folder in folders:
    directory=ROOT/folder
    if not directory.is_dir():continue
    for file in directory.rglob("*"):
        if not file.is_file() or file.suffix not in exts:continue
        content=file.read_text()
        for old_path in MOVES:
            old_cls=class_name(old_path)
            if any(re.search(r"(?<![A-Za-z0-9_])"+re.escape(key)+r"(?![A-Za-z0-9_])",content)
                   for key in (old_cls,old_cls.replace("\\","\\\\"))):
                raise RuntimeError(f"Stale validator class {old_cls} in {file}")

if any((ROOT/"app/Rules").rglob("*.php")):
    raise RuntimeError("Old root Rules directory still has PHP sources")
print("Both reusable Laravel validation rule classes moved to Platform; no old rule classes retained.")
