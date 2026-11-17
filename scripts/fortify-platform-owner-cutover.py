#!/usr/bin/env python3
"""One-time Fortify integration cutover to Platform/Auth/Fortify.

The existing Fortify registration, authenticating pipeline, password rules,
account workflows, and redirects remain unchanged. No legacy aliases.
"""
from pathlib import Path
import re

ROOT=Path.cwd()
MOVES={
    "app/Actions/Fortify/CreateNewUser.php":"app/Platform/Auth/Fortify/CreateNewUser.php",
    "app/Actions/Fortify/ResetUserPassword.php":"app/Platform/Auth/Fortify/ResetUserPassword.php",
    "app/Actions/Fortify/RecordSuccessfulLogin.php":"app/Platform/Auth/Fortify/RecordSuccessfulLogin.php",
}
ROOTS=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github")
SUFFIXES=(".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt")
old_ns="namespace App\\Actions\\Fortify;"
new_ns="namespace App\\Platform\\Auth\\Fortify;"

for source,dest in MOVES.items():
    a,b=ROOT/source,ROOT/dest
    if not a.is_file() or b.exists(): raise RuntimeError(f"Unsafe file migration: {source} => {dest}")
    original=a.read_text()
    if old_ns not in original:raise RuntimeError(f"Original action namespace changed: {source}")
    if source.endswith(("CreateNewUser.php","ResetUserPassword.php")) and "PasswordValidationRules" not in original:
        raise RuntimeError(f"Fortify password rule integration missing: {source}")
    if source.endswith("RecordSuccessfulLogin.php") and "GuestSubmissionClaims" not in original:
        raise RuntimeError(f"Auth pipeline post-login integration missing: {source}")
    b.parent.mkdir(parents=True,exist_ok=True)
    b.write_text(original.replace(old_ns,new_ns,1))
    a.unlink()

old="App\\Actions\\Fortify\\"
new="App\\Platform\\Auth\\Fortify\\"
for name in ROOTS:
    directory=ROOT/name
    if not directory.is_dir():continue
    for p in directory.rglob("*"):
        if not p.is_file() or p.suffix not in SUFFIXES:continue
        before=p.read_text()
        after=before
        for base_old,base_new in ((old.replace("\\","\\\\"),new.replace("\\","\\\\")),(old,new)):
            after=re.sub(
                r"(?<![A-Za-z0-9_])"+re.escape(base_old)+r"(?=[A-Za-z_])",
                lambda match:base_new,after
            )
        after=after.replace("app/Actions/Fortify/","app/Platform/Auth/Fortify/")
        if after!=before:p.write_text(after)

# The provider must still point Fortify to its original *concrete action*
# classes, only at the new owning namespace.
provider=(ROOT/"app/Providers/FortifyServiceProvider.php").read_text()
for name in ("CreateNewUser","ResetUserPassword","RecordSuccessfulLogin"):
    if f"use App\\Platform\\Auth\\Fortify\\{name};" not in provider:
        raise RuntimeError(f"Fortify provider did not update {name}")
for p in (ROOT/"app/Platform/Auth/Fortify").glob("*.php"):
    if new_ns not in p.read_text():raise RuntimeError("Incorrect target namespace")

for source,dest in MOVES.items():
    if (ROOT/source).exists() or not (ROOT/dest).exists():
        raise RuntimeError("Legacy class still exists or destination missing")

for name in ROOTS:
    directory=ROOT/name
    if not directory.is_dir():continue
    for p in directory.rglob("*"):
        if not p.is_file() or p.suffix not in SUFFIXES:continue
        text=p.read_text()
        if old in text or old.replace("\\","\\\\") in text:
            raise RuntimeError(f"Stale old Fortify class namespace in {p}")

if (ROOT/"app/Actions").exists() and any((ROOT/"app/Actions").rglob("*.php")):
    raise RuntimeError("Unexpected leftover root Actions implementation")
print("Migrated Fortify create/reset/login actions into Platform auth; all three original classes removed.")
print("Existing Fortify boot registration preserved. Root app/Actions now empty.")
