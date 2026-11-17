#!/usr/bin/env python3
"""Move the three Laravel auth notifications into the authentication platform.

The notification classes retain their exact mail templates, dispatch queues and
afterCommit behavior. Delete the original classes; no compatibility aliases.
"""
from pathlib import Path
import re

ROOT=Path.cwd()
MOVES={
    "app/Notifications/Auth/ResetPasswordNotification.php":
        "app/Platform/Auth/Notifications/ResetPasswordNotification.php",
    "app/Notifications/Auth/VerifyEmailNotification.php":
        "app/Platform/Auth/Notifications/VerifyEmailNotification.php",
    "app/Notifications/Auth/WelcomeNotification.php":
        "app/Platform/Auth/Notifications/WelcomeNotification.php",
}
FOLDERS=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github")
SUFFIXES={".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"}
OLD="namespace App\\Notifications\\Auth;"
NEW="namespace App\\Platform\\Auth\\Notifications;"

for source,target in MOVES.items():
    src,dst=ROOT/source,ROOT/target
    if not src.is_file() or dst.exists():raise RuntimeError("Invalid notification cutover: "+source)
    original=src.read_text()
    if OLD not in original or "ShouldQueue" not in original or "afterCommit()" not in original or "'notifications-mail'" not in original:
        raise RuntimeError("Notification delivery semantics changed: "+source)
    dst.parent.mkdir(parents=True,exist_ok=True)
    dst.write_text(original.replace(OLD,NEW,1))
    src.unlink()

mappings={
    "App\\Notifications\\Auth\\"+Path(source).stem:
        "App\\Platform\\Auth\\Notifications\\"+Path(target).stem
    for source,target in MOVES.items()
}
for folder in FOLDERS:
    directory=ROOT/folder
    if not directory.is_dir():continue
    for file in directory.rglob("*"):
        if not file.is_file() or file.suffix not in SUFFIXES:continue
        before=file.read_text()
        after=before
        for old,new in mappings.items():
            for left,right in ((old.replace("\\","\\\\"),new.replace("\\","\\\\")),(old,new)):
                after=re.sub(
                    r"(?<![A-Za-z0-9_])"+re.escape(left)+r"(?![A-Za-z0-9_])",
                    lambda m:right,
                    after
                )
        for old,new in MOVES.items():
            after=after.replace(old,new)
        if after!=before:file.write_text(after)
for source,target in MOVES.items():
    if (ROOT/source).exists() or not (ROOT/target).exists():
        raise RuntimeError("Legacy auth notification survived: "+source)
for folder in FOLDERS:
    directory=ROOT/folder
    if not directory.is_dir():continue
    for file in directory.rglob("*"):
        if not file.is_file() or file.suffix not in SUFFIXES:continue
        content=file.read_text()
        for old in mappings:
            if any(re.search(r"(?<![A-Za-z0-9_])"+re.escape(v)+r"(?![A-Za-z0-9_])",content)
                   for v in (old,old.replace("\\","\\\\"))):
                raise RuntimeError("Stale auth notification FQCN in "+str(file))
print("Three auth notification implementations now live under Platform/Auth/Notifications.")
print("Original app/Notifications/Auth removed; no backward aliases or duplicate notifications.")
