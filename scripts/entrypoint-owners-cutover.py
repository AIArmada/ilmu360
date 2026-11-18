#!/usr/bin/env python3
"""Direct owner cutover for a platform console command and ShareTracking event listener.

No compatibility classes, aliases, duplicate event handlers or extra CLI
registrations. Preserve the existing guard protecting schedule reversal
listener registration, all event types and the command signature.
"""
from pathlib import Path
import re

ROOT = Path.cwd()
MOVES = {
    "app/Console/Commands/ActivateLocalShareExamples.php":
        "app/Platform/Console/Commands/ActivateLocalShareExamples.php",
    "app/Listeners/ShareTracking/ReverseScheduleCancellationShareOutcome.php":
        "app/Features/ShareTracking/EntryPoints/Listeners/ReverseScheduleCancellationShareOutcome.php",
}
app_provider = ROOT/"app/Providers/AppServiceProvider.php"
feature_provider = ROOT/"app/Features/ShareTracking/FeatureServiceProvider.php"
bootstrap = ROOT/"bootstrap/app.php"
for p in (app_provider, feature_provider, bootstrap):
    if not p.is_file(): raise RuntimeError(f"Missing registration source: {p}")

command_old="App\\Console\\Commands\\ActivateLocalShareExamples"
command_new="App\\Platform\\Console\\Commands\\ActivateLocalShareExamples"
listener_old="App\\Listeners\\ShareTracking\\ReverseScheduleCancellationShareOutcome"
listener_new="App\\Features\\ShareTracking\\EntryPoints\\Listeners\\ReverseScheduleCancellationShareOutcome"
old_ns={
    "app/Console/Commands/ActivateLocalShareExamples.php":"namespace App\\Console\\Commands;",
    "app/Listeners/ShareTracking/ReverseScheduleCancellationShareOutcome.php":"namespace App\\Listeners\\ShareTracking;",
}
new_ns={
    "app/Console/Commands/ActivateLocalShareExamples.php":"namespace App\\Platform\\Console\\Commands;",
    "app/Listeners/ShareTracking/ReverseScheduleCancellationShareOutcome.php":"namespace App\\Features\\ShareTracking\\EntryPoints\\Listeners;",
}
for src,dst in MOVES.items():
    source, dest = ROOT/src,ROOT/dst
    if not source.is_file() or dest.exists():
        raise RuntimeError("Missing source or occupied destination: "+src)
    content=source.read_text()
    if old_ns[src] not in content:
        raise RuntimeError("Expected source namespace missing: "+src)
    if src.startswith("app/Console") and "#[Signature('app:share:activate-local-examples')]" not in content:
        raise RuntimeError("Share demo command signature changed")
    if src.startswith("app/Listeners") and "EventCancelled|EventOccurrenceCancelled|EventSessionCancelled" not in content:
        raise RuntimeError("Share reversal handler event types changed")
    dest.parent.mkdir(parents=True,exist_ok=True)
    dest.write_text(content.replace(old_ns[src],new_ns[src],1))
    source.unlink()

# Keep the command's signature unchanged, register it as an explicit Platform command.
boot=bootstrap.read_text()
anchor="        App\\Platform\\Console\\Commands\\SyncPublicSubmissionLocks::class,"
if boot.count(anchor)!=1:raise RuntimeError("Bootstrap command registry changed")
bootstrap.write_text(boot.replace(anchor,anchor+"\n        "+command_new+"::class,",1))

# The old explicit schedule reversal bindings move to their feature provider,
# with their original single-registration flag preserved.
main=app_provider.read_text()
old_import="use "+listener_old+";"
if main.count(old_import)!=1:
    raise RuntimeError("Missing root listener import")
main=main.replace(old_import,"",1)
registered="""        if (! app()->bound('share.schedule_reversal.listeners.registered')) {
            EventFacade::listen(EventCancelled::class, [ReverseScheduleCancellationShareOutcome::class, 'handle']);
            EventFacade::listen(EventOccurrenceCancelled::class, [ReverseScheduleCancellationShareOutcome::class, 'handle']);
            EventFacade::listen(EventSessionCancelled::class, [ReverseScheduleCancellationShareOutcome::class, 'handle']);

            app()->instance('share.schedule_reversal.listeners.registered', true);
        }

"""
if main.count(registered)!=1:
    raise RuntimeError("Original schedule reversal listener block differs")
main=main.replace(registered,"",1)
for event in ("EventCancelled","EventOccurrenceCancelled","EventSessionCancelled"):
    # These app imports are only used by the relocated registration block.
    imported="use AIArmada\\Events\\Events\\"+event+";"
    if main.count(imported)!=1:raise RuntimeError("Missing event import: "+event)
    main=main.replace(imported,"",1)
app_provider.write_text(main)

provider=feature_provider.read_text()
marker="use AIArmada\\Affiliates\\Models\\AffiliateTouchpoint;"
if provider.count(marker)!=1:raise RuntimeError("ShareTracking provider imports changed")
imports="""use AIArmada\\Events\\Events\\EventCancelled;
use AIArmada\\Events\\Events\\EventOccurrenceCancelled;
use AIArmada\\Events\\Events\\EventSessionCancelled;
use App\\Features\\ShareTracking\\EntryPoints\\Listeners\\ReverseScheduleCancellationShareOutcome;
use Illuminate\\Support\\Facades\\Event as EventFacade;"""
provider=provider.replace(marker,marker+"\n"+imports,1)
boot_marker="""        parent::boot();
        AffiliateTouchpoint::observe(CommittedShareTouchpointObserver::class);"""
if provider.count(boot_marker)!=1:raise RuntimeError("ShareTracking boot registration changed")
new_register="""        parent::boot();
        AffiliateTouchpoint::observe(CommittedShareTouchpointObserver::class);

        if (! app()->bound('share.schedule_reversal.listeners.registered')) {
            EventFacade::listen(EventCancelled::class, [ReverseScheduleCancellationShareOutcome::class, 'handle']);
            EventFacade::listen(EventOccurrenceCancelled::class, [ReverseScheduleCancellationShareOutcome::class, 'handle']);
            EventFacade::listen(EventSessionCancelled::class, [ReverseScheduleCancellationShareOutcome::class, 'handle']);

            app()->instance('share.schedule_reversal.listeners.registered', true);
        }"""
feature_provider.write_text(provider.replace(boot_marker,new_register,1))

# Rewrite exact legacy identifiers outside relocated classes.
roots=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github")
extensions={".php",".md",".json",".neon",".xml",".yml",".yaml",".js",".ts",".txt"}
classes={command_old:command_new,listener_old:listener_new}
def paths():
    for root in roots:
        base=ROOT/root
        if base.is_dir():
            for p in base.rglob("*"):
                if p.is_file() and p.suffix in extensions and not {"vendor","node_modules",".git"}.intersection(p.parts):
                    yield p

for path in paths():
    original=path.read_text()
    updated=original
    for old,new in classes.items():
        for before,after in ((old.replace("\\","\\\\"),new.replace("\\","\\\\")),(old,new)):
            updated=re.sub(r"(?<![A-Za-z0-9_])"+re.escape(before)+r"(?![A-Za-z0-9_])",lambda match:after,updated)
    for old,new in MOVES.items():
        updated=updated.replace(old,new)
    if updated!=original:path.write_text(updated)

for path in paths():
    data=path.read_text()
    for cls in classes:
        if any(re.search(r"(?<![A-Za-z0-9_])"+re.escape(k)+r"(?![A-Za-z0-9_])",data)
               for k in (cls,cls.replace("\\","\\\\"))):
            raise RuntimeError("Old FQCN survived in "+str(path))
for src,dst in MOVES.items():
    if (ROOT/src).exists() or not (ROOT/dst).is_file():
        raise RuntimeError("Old implementation survived, or moved file missing: "+src)
if "share.schedule_reversal.listeners.registered" in app_provider.read_text():
    raise RuntimeError("Legacy schedule event registration still in app provider")
if feature_provider.read_text().count("share.schedule_reversal.listeners.registered")!=2:
    raise RuntimeError("Feature registration guard mismatch")
print("Share local-demo command moved to Platform, schedule reversal listener to ShareTracking.")
print("CLI signature and once-only event registration preserved; no legacy files or aliases.")
