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

# The Platform transport must call feature ownership via Shared contracts,
# never import concrete Identity/Events feature APIs.
new_account_port=ROOT/"app/Shared/Auth/NewAccountAttribution.php"
guest_port=ROOT/"app/Shared/Auth/GuestEventSubmissionClaims.php"
identity_adapter=ROOT/"app/Features/Identity/Actions/RecordNewAccountAttributionAction.php"
for file in (new_account_port,guest_port,identity_adapter):
    if file.exists():raise RuntimeError("Shared port or feature adapter already exists: "+str(file))
for file in (new_account_port,guest_port,identity_adapter):
    file.parent.mkdir(parents=True,exist_ok=True)

new_account_port.write_text("""<?php

declare(strict_types=1);

namespace App\\Shared\\Auth;

/** Registration transport integration for the Identity signup workflow. */
interface NewAccountAttribution
{
    public function recordForNewAccount(
        string $accountId,
        SignupAttributionContext $context,
        bool $wasRecentlyCreated,
    ): void;
}
""")
guest_port.write_text("""<?php

declare(strict_types=1);

namespace App\\Shared\\Auth;

/** Account-login integration for the Events-owned guest submission claim. */
interface GuestEventSubmissionClaims
{
    public function claimForAccount(string $accountId): int;
}
""")
identity_adapter.write_text("""<?php

declare(strict_types=1);

namespace App\\Features\\Identity\\Actions;

use App\\Features\\Identity\\Api\\SignupAttributionWorkflow;
use App\\Shared\\Auth\\NewAccountAttribution;
use App\\Shared\\Auth\\SignupAttributionContext;

/** Consume Identity's canonical signup workflow from a generic auth transport. */
final readonly class RecordNewAccountAttributionAction implements NewAccountAttribution
{
    public function __construct(private SignupAttributionWorkflow $workflow) {}

    public function recordForNewAccount(
        string $accountId,
        SignupAttributionContext $context,
        bool $wasRecentlyCreated,
    ): void {
        $this->workflow->recordForNewAccount($accountId, $context, $wasRecentlyCreated);
    }
}
""")

creator=ROOT/"app/Platform/Auth/Fortify/CreateNewUser.php"
text=creator.read_text()
old_import="use App\\Features\\Identity\\Api\\SignupAttributionWorkflow;"
if text.count(old_import)!=1 or text.count("app(SignupAttributionWorkflow::class)")!=1:
    raise RuntimeError("Signup feature direct call not found")
creator.write_text(text.replace(old_import,"use App\\Shared\\Auth\\NewAccountAttribution;").replace(
    "app(SignupAttributionWorkflow::class)", "app(NewAccountAttribution::class)"
))

login=ROOT/"app/Platform/Auth/Fortify/RecordSuccessfulLogin.php"
text=login.read_text()
old_import="use App\\Features\\Events\\Api\\GuestSubmissionClaims;"
if text.count(old_import)!=1 or text.count("app(GuestSubmissionClaims::class)")!=1:
    raise RuntimeError("Events feature direct call not found")
login.write_text(text.replace(old_import,"use App\\Shared\\Auth\\GuestEventSubmissionClaims;").replace(
    "app(GuestSubmissionClaims::class)", "app(GuestEventSubmissionClaims::class)"
))

id_provider=ROOT/"app/Features/Identity/FeatureServiceProvider.php"
text=id_provider.read_text()
old_import="use App\\Features\\Identity\\Api\\SignupAttributionWorkflow;"
assert old_import in text
text=text.replace(old_import,
    old_import+"\nuse App\\Features\\Identity\\Actions\\RecordNewAccountAttributionAction;",1)
old_import="use App\\Shared\\Auth\\AccountLifecycleContributor;"
assert old_import in text
text=text.replace(old_import,old_import+"\nuse App\\Shared\\Auth\\NewAccountAttribution;",1)
binder="$this->app->bind(SignupAttributionWorkflow::class, SignupAttributionWorkflowService::class);"
assert binder in text
text=text.replace(binder,binder+"\n        $this->app->bind(NewAccountAttribution::class, RecordNewAccountAttributionAction::class);",1)
id_provider.write_text(text)

event_action=ROOT/"app/Features/Events/Actions/Submission/ClaimGuestSubmissionsAction.php"
text=event_action.read_text()
old_import="use App\\Features\\Events\\Api\\GuestSubmissionClaims;"
assert old_import in text and "implements GuestSubmissionClaims" in text
text=text.replace(old_import,old_import+"\nuse App\\Shared\\Auth\\GuestEventSubmissionClaims;",1)
text=text.replace("implements GuestSubmissionClaims","implements GuestSubmissionClaims, GuestEventSubmissionClaims",1)
event_action.write_text(text)

events_provider=ROOT/"app/Features/Events/FeatureServiceProvider.php"
text=events_provider.read_text()
old_import="use App\\Platform\\FeatureModule;"
assert old_import in text
text=text.replace(old_import,old_import+"\nuse App\\Shared\\Auth\\GuestEventSubmissionClaims;",1)
binder="$this->app->bind(GuestSubmissionClaims::class, ClaimGuestSubmissionsAction::class);"
assert binder in text
text=text.replace(binder,binder+"\n        $this->app->bind(GuestEventSubmissionClaims::class, ClaimGuestSubmissionsAction::class);",1)
events_provider.write_text(text)

for file in (creator,login):
    if "use App\\Features\\" in file.read_text():
        raise RuntimeError("Platform Fortify depends on concrete feature: "+str(file))

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
