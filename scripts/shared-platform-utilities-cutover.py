#!/usr/bin/env python3
"""Directly rehome cross-domain platform utilities and framework-free shared values.

Do not move root business coordinators across ownership boundaries. No aliases,
stub subclasses, classmap fallbacks, duplicate sources or stale FQCNs.
"""
from pathlib import Path
import re

ROOT=Path.cwd()
MOVES={
    "app/Services/ShareTracking/ShareTrackingUrlService.php":
        "app/Platform/Routing/ShareTrackingUrlService.php",
    "app/Support/Mcp/McpFilePayloadNormalizer.php":
        "app/Platform/Mcp/McpFilePayloadNormalizer.php",
    "app/Support/Mcp/McpWriteSchemaFormatter.php":
        "app/Platform/Mcp/McpWriteSchemaFormatter.php",
    "app/Support/ShareTracking/SealedGuestIdentityException.php":
        "app/Shared/ShareTracking/SealedGuestIdentityException.php",
    "app/Support/ShareTracking/ShareProfileNotEligibleException.php":
        "app/Shared/ShareTracking/ShareProfileNotEligibleException.php",
    "app/Support/Discovery/SearchSignal.php":
        "app/Shared/Discovery/SearchSignal.php",
    "app/Support/Api/Admin/AdminValidateOnlyRemediationPlanner.php":
        "app/Platform/Admin/AdminValidateOnlyRemediationPlanner.php",
}
ROOTS=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github")
EXT={".php",".md",".json",".xml",".neon",".yml",".yaml",".js",".ts",".txt"}

def cls(p:str)->str:
    return "App\\"+p[4:-4].replace("/","\\")

def corpus():
    for folder in ROOTS:
        d=ROOT/folder
        if not d.is_dir():continue
        for f in d.rglob("*"):
            if f.is_file() and f.suffix in EXT and not {"vendor","node_modules",".git"}.intersection(f.parts):
                yield f

classes={cls(k):cls(v) for k,v in MOVES.items()}
for src,dst in MOVES.items():
    before,after=ROOT/src,ROOT/dst
    if not before.is_file() or after.exists():
        raise RuntimeError("Missing source or occupied destination: "+src)
    text=before.read_text()
    old_ns="namespace "+cls(src).rsplit("\\",1)[0]+";"
    if old_ns not in text:raise RuntimeError("Source namespace mismatch: "+src)
    if "use App\\Features\\" in text:raise RuntimeError("Platform/shared type depends on feature: "+src)
    if dst.startswith("app/Shared/") and ("use App\\Models\\" in text or "use Illuminate\\" in text):
        raise RuntimeError("Shared value is framework/model dependent: "+src)

# Commit the move in the temporary runner checkout.
for src,dst in MOVES.items():
    before,after=ROOT/src,ROOT/dst
    contents=before.read_text()
    old="namespace "+cls(src).rsplit("\\",1)[0]+";"
    new="namespace "+cls(dst).rsplit("\\",1)[0]+";"
    after.parent.mkdir(parents=True,exist_ok=True)
    after.write_text(contents.replace(old,new,1))
    before.unlink()

# Full-identifier rewrite, with support for PHP and escaped JSON/JS FQCNs.
for f in corpus():
    content=f.read_text()
    updated=content
    for old,new in sorted(classes.items(),key=lambda item:-len(item[0])):
        for src,dst in ((old.replace("\\","\\\\"),new.replace("\\","\\\\")),(old,new)):
            updated=re.sub(r"(?<![A-Za-z0-9_])"+re.escape(src)+r"(?![A-Za-z0-9_])",
                lambda match:dst,updated)
    for old,new in MOVES.items():
        updated=updated.replace(old,new)
    if updated!=content:f.write_text(updated)

# In the original namespace, a short class identifier formerly resolved
# implicitly. Recreate that compile-time dependency as an explicit new import.
for f in corpus():
    if f.suffix!=".php":continue
    text=f.read_text()
    original=text
    for old,new in classes.items():
        old_ns,short=old.rsplit("\\",1)
        new_ns=new.rsplit("\\",1)[0]
        if new_ns==old_ns:continue
        declaration="namespace "+old_ns+";"
        if declaration not in text:continue
        if "use "+new+";" in text:continue
        if re.search(r"(?<![A-Za-z0-9_\\\\])"+re.escape(short)+r"\b",text) is None:continue
        # Existing imports under other aliases should not be shadowed.
        if re.search(r"^use [^;]*\\\\"+re.escape(short)+r"(?: as \\w+)?;",text,re.MULTILINE):
            continue
        text=text.replace(declaration,declaration+"\n\nuse "+new+";",1)
    if text!=original:f.write_text(text)

for src,dst in MOVES.items():
    if (ROOT/src).exists() or not (ROOT/dst).is_file():
        raise RuntimeError("Legacy source survived or target missing: "+src)

for f in corpus():
    s=f.read_text()
    for old in classes:
        for val in (old,old.replace("\\","\\\\")):
            if re.search(r"(?<![A-Za-z0-9_])"+re.escape(val)+r"(?![A-Za-z0-9_])",s):
                raise RuntimeError("Stale class name "+old+" in "+str(f))

print("Moved 7 utility and shared-value classes to canonical owners.")
for src,dst in MOVES.items():print(src+" => "+dst)
print("No old files, aliases or stale class imports remain.")
