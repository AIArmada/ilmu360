#!/usr/bin/env python3
"""Relocate Laravel auth-event transport listeners to Platform with explicit registration.

Laravel auto-discovers app/Listeners by convention; after relocating these
listeners, AppServiceProvider registers the same event handlers once.
"""
from pathlib import Path
import re

ROOT=Path.cwd()
MOVES={
    "app/Listeners/Auth/ClaimGuestSubmissionsOnVerified.php":"app/Platform/Auth/Listeners/ClaimGuestSubmissionsOnVerified.php",
    "app/Listeners/Auth/RecordVerifiedEmail.php":"app/Platform/Auth/Listeners/RecordVerifiedEmail.php",
    "app/Listeners/Auth/SendRegisteredUserEmails.php":"app/Platform/Auth/Listeners/SendRegisteredUserEmails.php",
}
PROVIDER=ROOT/"app/Providers/AppServiceProvider.php"
ROOTS=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github")
EXTS={".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"}
if not PROVIDER.is_file():raise RuntimeError("AppServiceProvider missing")
for old,new in MOVES.items():
    src,dst=ROOT/old,ROOT/new
    if not src.is_file() or dst.exists():raise RuntimeError("Invalid listener move: "+old)
    code=src.read_text()
    if "namespace App\\Listeners\\Auth;" not in code or "ShouldHandleEventsAfterCommit" not in code:
        raise RuntimeError("Listener shape changed: "+old)
    code=code.replace("namespace App\\Listeners\\Auth;",
                      "namespace App\\Platform\\Auth\\Listeners;",1)
    if old.endswith("ClaimGuestSubmissionsOnVerified.php"):
        dep="App\\Features\\Events\\Api\\GuestSubmissionClaims"
        if code.count(dep)!=1 or code.count("app(GuestSubmissionClaims::class)")!=1:
            raise RuntimeError("Guest claim listener direct dependency changed")
        code=code.replace(dep,"App\\Shared\\Auth\\GuestEventSubmissionClaims").replace(
            "app(GuestSubmissionClaims::class)","app(GuestEventSubmissionClaims::class)"
        )
    dst.parent.mkdir(parents=True,exist_ok=True)
    dst.write_text(code)
    src.unlink()

provider=PROVIDER.read_text()
ns="namespace App\\Providers;"
if ns not in provider:raise RuntimeError("App provider namespace absent")
imports="""use App\\Platform\\Auth\\Listeners\\ClaimGuestSubmissionsOnVerified;
use App\\Platform\\Auth\\Listeners\\RecordVerifiedEmail;
use App\\Platform\\Auth\\Listeners\\SendRegisteredUserEmails;
use Illuminate\\Auth\\Events\\Registered;
use Illuminate\\Auth\\Events\\Verified;
"""
provider=provider.replace(ns,ns+"\n\n"+imports,1)
boot="    public function boot(): void\n    {\n"
if boot not in provider:raise RuntimeError("App provider boot changed")
provider=provider.replace(boot,boot+"""
        // Explicit registration replaces Laravel's old app/Listeners/Auth discovery.
        // Preserve registered-email and verified-email after-commit listeners.
        EventFacade::listen(Registered::class, SendRegisteredUserEmails::class);
        EventFacade::listen(Verified::class, ClaimGuestSubmissionsOnVerified::class);
        EventFacade::listen(Verified::class, RecordVerifiedEmail::class);

""",1)
PROVIDER.write_text(provider)

mapping={
    "App\\Listeners\\Auth\\"+Path(old).stem:
    "App\\Platform\\Auth\\Listeners\\"+Path(new).stem
    for old,new in MOVES.items()
}
for dirname in ROOTS:
    base=ROOT/dirname
    if not base.is_dir():continue
    for p in base.rglob("*"):
        if not p.is_file() or p.suffix not in EXTS:continue
        before=p.read_text()
        after=before
        for old,new in mapping.items():
            for left,right in ((old.replace("\\","\\\\"),new.replace("\\","\\\\")),(old,new)):
                after=re.sub(r"(?<![A-Za-z0-9_])"+re.escape(left)+r"(?![A-Za-z0-9_])",
                            lambda match:right,after)
        for old,new in MOVES.items():after=after.replace(old,new)
        if after!=before:p.write_text(after)

for dirname in ROOTS:
    base=ROOT/dirname
    if not base.is_dir():continue
    for p in base.rglob("*"):
        if not p.is_file() or p.suffix not in EXTS:continue
        text=p.read_text()
        for old in mapping:
            for value in (old,old.replace("\\","\\\\")):
                if re.search(r"(?<![A-Za-z0-9_])"+re.escape(value)+r"(?![A-Za-z0-9_])",text):
                    raise RuntimeError(f"Legacy auth listener {old} in {p}")

for old,new in MOVES.items():
    if (ROOT/old).exists() or not (ROOT/new).is_file():
        raise RuntimeError("Listener move postcondition failed: "+old)
print("Moved three auth listeners into Platform; explicitly registered Registered/Verified event hooks.")
print("All three original classes are deleted; no old names or compatibility listeners remain.")
