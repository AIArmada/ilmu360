#!/usr/bin/env python3
"""Move Laravel event-mail delivery adapters into Platform/Notifications/Events.

Event payload and Filament links remain unchanged. No compatibility classes.
"""
from pathlib import Path
import re

ROOT=Path.cwd()
NAMES=("EventEscalationNotification","EventOccurrenceReviewedNotification",
       "EventOccurrenceSubmittedNotification","EventSubmittedNotification")
MOVES={f"app/Notifications/{name}.php":f"app/Platform/Notifications/Events/{name}.php" for name in NAMES}
FOLDERS=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github")
EXTS={".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"}

for src,dst in MOVES.items():
    original,target=ROOT/src,ROOT/dst
    if not original.is_file() or target.exists():raise RuntimeError("Source missing or target collision: "+src)
    code=original.read_text()
    if "namespace App\\Notifications;" not in code:
        raise RuntimeError("Unexpected namespace: "+src)
    for guard in ("ShouldQueue", "afterCommit()", "notifications-mail", "MailMessage"):
        if guard not in code:raise RuntimeError("Mail or queue behavior changed: "+src+" missing "+guard)
    if "use App\\Features\\" in code:
        raise RuntimeError("Platform notification would directly depend on a feature: "+src)
    target.parent.mkdir(parents=True,exist_ok=True)
    target.write_text(code.replace("namespace App\\Notifications;",
                                   "namespace App\\Platform\\Notifications\\Events;",1))
    original.unlink()

mapping={f"App\\Notifications\\{n}":f"App\\Platform\\Notifications\\Events\\{n}" for n in NAMES}
for folder in FOLDERS:
    base=ROOT/folder
    if not base.is_dir():continue
    for p in base.rglob("*"):
        if not p.is_file() or p.suffix not in EXTS:continue
        before=p.read_text()
        after=before
        for old,new in mapping.items():
            for a,b in ((old.replace("\\","\\\\"),new.replace("\\","\\\\")),(old,new)):
                after=re.sub(r"(?<![A-Za-z0-9_])"+re.escape(a)+r"(?![A-Za-z0-9_])",
                             lambda _:b,after)
        for old,new in MOVES.items():
            after=after.replace(old,new)
        if after!=before:p.write_text(after)

for folder in FOLDERS:
    base=ROOT/folder
    if not base.is_dir():continue
    for p in base.rglob("*"):
        if not p.is_file() or p.suffix not in EXTS:continue
        code=p.read_text()
        for old in mapping:
            if any(re.search(r"(?<![A-Za-z0-9_])"+re.escape(s)+r"(?![A-Za-z0-9_])",code)
                   for s in (old,old.replace("\\","\\\\"))):
                raise RuntimeError("Legacy event mail FQCN in "+str(p))
for old,new in MOVES.items():
    if (ROOT/old).exists() or not (ROOT/new).exists():
        raise RuntimeError("Cutover postcondition failed: "+old)
if (ROOT/"app/Notifications").exists() and any((ROOT/"app/Notifications").rglob("*.php")):
    raise RuntimeError("Legacy app/Notifications still contains PHP implementations")
print("Four event notification classes moved to Platform mail transport; app/Notifications empty.")
print("All original classes and old FQCNs removed, no aliases.")
