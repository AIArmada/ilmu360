#!/usr/bin/env python3
"""Direct, one-time job ownership cutover. No aliases or delegating shims."""
from pathlib import Path
from collections import Counter
import re

ROOT=Path.cwd()
MAPPING={
    "app/Jobs/Referrals/QualifyReferralFromShareOutcomeJob.php":
        "app/Features/Referrals/EntryPoints/Jobs/QualifyReferralFromShareOutcomeJob.php",
    "app/Jobs/ShareTracking/ProduceCommittedShareSignalJob.php":
        "app/Features/ShareTracking/EntryPoints/Jobs/ProduceCommittedShareSignalJob.php",
    "app/Support/Share/CommittedShareSignalProducer.php":
        "app/Features/ShareTracking/Services/CommittedShareSignalProducer.php",
    "app/Support/ShareTracking/ShareTrackingMeasurement.php":
        "app/Shared/ShareTracking/ShareTrackingMeasurement.php",
}
SUFFIXES={".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"}
SCAN_FOLDERS=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github","scripts")

def class_from_path(name):
    return "App\\"+name.removeprefix("app/").removesuffix(".php").replace("/","\\")

def files():
    for folder in SCAN_FOLDERS:
        base=ROOT/folder
        if not base.is_dir():
            continue
        for path in base.rglob("*"):
            if path.is_file() and path.suffix in SUFFIXES and not {"vendor","node_modules",".git"}.intersection(path.parts):
                yield path
    for name in ("composer.json","phpunit.xml","rector.php","phpstan.neon","README.md"):
        f=ROOT/name
        if f.exists(): yield f

def main():
    for src,dst in MAPPING.items():
        if not (ROOT/src).is_file(): raise RuntimeError("Missing source: "+src)
        if (ROOT/dst).exists(): raise RuntimeError("Destination exists: "+dst)

    classes={class_from_path(src):class_from_path(dst) for src,dst in MAPPING.items()}
    for src,dst in MAPPING.items():
        source,dest=ROOT/src,ROOT/dst
        current=source.read_text()
        old_namespace=class_from_path(src).rsplit("\\",1)[0]
        new_namespace=class_from_path(dst).rsplit("\\",1)[0]
        needle="namespace "+old_namespace+";"
        if needle not in current: raise RuntimeError("Unexpected namespace: "+src)
        current=current.replace(needle,"namespace "+new_namespace+";",1)
        dest.parent.mkdir(parents=True,exist_ok=True)
        dest.write_text(current)
        source.unlink()

    # Update all production references, explicit class strings and test fixtures.
    # Replaced FQCNs never resolve to compatibility wrappers.
    for path in files():
        original=path.read_text()
        content=original
        for old,new in sorted(classes.items(),key=lambda kv:-len(kv[0])):
            content=content.replace(old.replace("\\","\\\\"),new.replace("\\","\\\\"))
            content=content.replace(old,new)
        for old,new in sorted(MAPPING.items(),key=lambda kv:-len(kv[0])):
            content=content.replace(old,new)
        if original!=content: path.write_text(content)


    # Move the observer with the feature so neither its job nor its private
    # signal producer leaks into the composition root.
    original_observer=ROOT/"app/Observers/CommittedShareTouchpointObserver.php"
    target_observer=ROOT/"app/Features/ShareTracking/EntryPoints/Observers/CommittedShareTouchpointObserver.php"
    if not original_observer.is_file() or target_observer.exists():
        raise RuntimeError("Observer in unexpected location")
    observer_text=original_observer.read_text().replace(
        "namespace App\\Observers;",
        "namespace App\\Features\\ShareTracking\\EntryPoints\\Observers;",
        1,
    )
    target_observer.parent.mkdir(parents=True,exist_ok=True)
    target_observer.write_text(observer_text)
    original_observer.unlink()

    for path in files():
        original=path.read_text()
        modified=original.replace(
            r"App\Observers\CommittedShareTouchpointObserver",
            r"App\Features\ShareTracking\EntryPoints\Observers\CommittedShareTouchpointObserver",
        )
        modified=modified.replace(
            r"App\\Observers\\CommittedShareTouchpointObserver",
            r"App\\Features\\ShareTracking\\EntryPoints\\Observers\\CommittedShareTouchpointObserver",
        )
        if original!=modified: path.write_text(modified)

    # Root AppServiceProvider must not depend on feature-private observers.
    app_provider=ROOT/"app/Providers/AppServiceProvider.php"
    app_text=app_provider.read_text()
    observer_reference=r"use App\Features\ShareTracking\EntryPoints\Observers\CommittedShareTouchpointObserver;"
    if observer_reference not in app_text or "AffiliateTouchpoint::observe(CommittedShareTouchpointObserver::class);" not in app_text:
        raise RuntimeError("Root observer registration not found")
    app_text=app_text.replace(observer_reference+"\n","")
    app_text=app_text.replace("        AffiliateTouchpoint::observe(CommittedShareTouchpointObserver::class);\n","")
    app_provider.write_text(app_text)

    # The owning provider registers its own integration observer once.
    share_provider=ROOT/"app/Features/ShareTracking/FeatureServiceProvider.php"
    share_text=share_provider.read_text()
    share_text=share_text.replace(
        "namespace App\\Features\\ShareTracking;",
        "namespace App\\Features\\ShareTracking;\n\nuse AIArmada\\Affiliates\\Models\\AffiliateTouchpoint;\nuse App\\Features\\ShareTracking\\EntryPoints\\Observers\\CommittedShareTouchpointObserver;",
        1,
    )
    anchor="class FeatureServiceProvider extends FeatureModule\n{"
    if anchor not in share_text: raise RuntimeError("ShareTracking provider declaration missing")
    share_text=share_text.replace(anchor,anchor+"""
    public function boot(): void
    {
        parent::boot();
        AffiliateTouchpoint::observe(CommittedShareTouchpointObserver::class);
    }
""",1)
    share_provider.write_text(share_text)

    # ShareTracking root adapter can only invoke Referrals through the public
    # API; retain after-commit and swallowed-dispatch-failure semantics.
    referrals_api=ROOT/"app/Features/Referrals/Api/ReferralOutcomeWorkflow.php"
    api_text=referrals_api.read_text()
    anchor="    public function qualify(string $businessKey): void;"
    if anchor not in api_text: raise RuntimeError("Qualification API insertion failed")
    api_text=api_text.replace(anchor,anchor+"\n\n    /** Queue qualification after the source transaction commits. */\n    public function scheduleQualification(string $businessKey): void;",1)
    referrals_api.write_text(api_text)

    referrals_service=ROOT/"app/Features/Referrals/Services/ReferralOutcomeService.php"
    service_text=referrals_service.read_text()
    anchor="    public function reverseRewards(string $businessKey, string $reason): void"
    if anchor not in service_text: raise RuntimeError("Referral service insertion failed")
    service_text=service_text.replace(anchor,"""    public function scheduleQualification(string $businessKey): void
    {
        \\App\\Features\\Referrals\\EntryPoints\\Jobs\\QualifyReferralFromShareOutcomeJob::dispatch($businessKey)->afterCommit();
    }

"""+anchor,1)
    referrals_service.write_text(service_text)

    tracking_service=ROOT/"app/Services/ShareTracking/AffiliatesShareTrackingService.php"
    tracking_text=tracking_service.read_text()
    job_import=r"use App\Features\Referrals\EntryPoints\Jobs\QualifyReferralFromShareOutcomeJob;"
    if job_import not in tracking_text: raise RuntimeError("Qualification job import missing")
    tracking_text=tracking_text.replace(job_import,r"use App\Features\Referrals\Api\ReferralOutcomeWorkflow;",1)
    dispatch="QualifyReferralFromShareOutcomeJob::dispatch($businessKey)->afterCommit();"
    if dispatch not in tracking_text: raise RuntimeError("Qualification dispatch missing")
    tracking_text=tracking_text.replace(dispatch,"app(ReferralOutcomeWorkflow::class)->scheduleQualification($businessKey);",1)
    tracking_service.write_text(tracking_text)


    # Update old architecture comments so there is no bogus statement about
    # root job/analytics ownership. Do not change business behavior.
    provider=ROOT/"app/Features/ShareTracking/FeatureServiceProvider.php"
    content=provider.read_text()
    content=content.replace(
        " * \`ProduceCommittedShareSignalJob\`; commands and schedules; account",
        " * commands and schedules; account")
    provider.write_text(content)

    for src in MAPPING:
        if (ROOT/src).exists(): raise RuntimeError("Old class file survived: "+src)
    for path in files():
        content=path.read_text()
        for old in classes:
            if old in content or old.replace("\\","\\\\") in content:
                raise RuntimeError("Stale old class reference "+old+" in "+str(path))
    for src,dst in MAPPING.items():
        text=(ROOT/dst).read_text()
        if "/Features/" in dst and any(x in text for x in ("use App\\Support\\", "use App\\Services\\", "use App\\Jobs\\")):
            raise RuntimeError("New feature still references a conventional root implementation: "+dst)
    print("Relocated "+str(len(MAPPING))+" real classes, without aliases.")
    print("\n".join(src+" => "+dst for src,dst in MAPPING.items()))

if __name__=="__main__":
    main()
