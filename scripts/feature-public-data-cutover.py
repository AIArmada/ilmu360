#!/usr/bin/env python3
"""Directly rehome feature-owned public DTOs; leave cross-domain model hydrators alone.

The selected types are scalar value objects or public API response envelopes.
No aliases, classmap fallbacks, duplicate files, or compatibility adapters.
"""
from pathlib import Path

ROOT = Path.cwd()
PREFIXES = {
    "Api/Event/EventMeData.php": ("Events", "EventMeData.php"),
    "Api/EventCheckIn/EventCheckInStateData.php": ("Registration", "EventCheckInStateData.php"),
    "Api/EventGoing/EventGoingResultData.php": ("Engagement", "EventGoingResultData.php"),
    "Api/EventGoing/EventGoingStateData.php": ("Engagement", "EventGoingStateData.php"),
    "Api/EventRegistration/EventRegistrationStatusData.php": ("Registration", "EventRegistrationStatusData.php"),
    "Api/EventSave/EventSaveResultData.php": ("Engagement", "EventSaveResultData.php"),
    "Api/EventSave/EventSaveStateData.php": ("Engagement", "EventSaveStateData.php"),
    "Api/Frontend/Search/CountryData.php": ("Discovery", "CountryData.php"),
    "Api/Frontend/Search/InstitutionDetailMediaData.php": ("Discovery", "InstitutionDetailMediaData.php"),
    "Api/Frontend/Search/PersonGalleryItemData.php": ("Discovery", "PersonGalleryItemData.php"),
    "EventDiscoveryCriteria.php": ("Discovery", "EventDiscoveryCriteria.php"),
    "EventDiscoveryCriteriaFactory.php": ("Discovery", "EventDiscoveryCriteriaFactory.php"),
    "ShareTracking/ShareTrackingAttributionData.php": ("ShareTracking", "ShareTrackingAttributionData.php"),
    "ShareTracking/ShareTrackingLinkData.php": ("ShareTracking", "ShareTrackingLinkData.php"),
    "ShareTracking/ShareTrackingOutcomeData.php": ("ShareTracking", "ShareTrackingOutcomeData.php"),
    "ShareTracking/ShareTrackingVisitData.php": ("ShareTracking", "ShareTrackingVisitData.php"),
}
MOVES = {f"app/Data/{key}":f"app/Features/{feature}/Api/Data/{filename}" for key,(feature,filename) in PREFIXES.items()}
FOLDERS=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github","scripts")
SUFFIXES={".php",".json",".md",".neon",".yml",".yaml",".xml",".js",".ts",".txt",".blade"}

def classname(path):
    return "App\\"+path[4:-4].replace("/","\\")

def files():
    for root in FOLDERS:
        folder=ROOT/root
        if folder.is_dir():
            for file in folder.rglob("*"):
                if file.is_file() and file.suffix in SUFFIXES and not {"node_modules","vendor",".git"}.intersection(file.parts):
                    yield file
    for name in ("composer.json","phpunit.xml","phpstan.neon","rector.php","README.md"):
        f=ROOT/name
        if f.exists():yield f

def main():
    classes={classname(a):classname(b) for a,b in MOVES.items()}
    for src,dst in MOVES.items():
        source,dest=ROOT/src,ROOT/dst
        if not source.is_file() or dest.exists():raise RuntimeError(f"Missing source or occupied destination: {src} -> {dst}")
        code=source.read_text()
        if any(term in code for term in ("use App\\Models\\", "use App\\Services\\", "use App\\Support\\", "use App\\Jobs\\", "use App\\Filament\\")):
            raise RuntimeError(f"Unsafe cross-domain API DTO move: {src}")
        namespace="namespace "+classname(src).rsplit("\\",1)[0]+";"
        if namespace not in code:raise RuntimeError("Namespace mismatch: "+src)
    for src,dst in MOVES.items():
        old,new=ROOT/src,ROOT/dst
        contents=old.read_text()
        contents=contents.replace("namespace "+classname(src).rsplit("\\",1)[0]+";",
                "namespace "+classname(dst).rsplit("\\",1)[0]+";",1)
        new.parent.mkdir(parents=True,exist_ok=True)
        new.write_text(contents)
        old.unlink()
    for file in files():
        original=file.read_text(encoding="utf-8")
        updated=original
        for old,new in sorted(classes.items(),key=lambda pair:-len(pair[0])):
            updated=updated.replace(old.replace("\\","\\\\"),new.replace("\\","\\\\"))
            updated=updated.replace(old,new)
        for old,new in sorted(MOVES.items(),key=lambda pair:-len(pair[0])):
            updated=updated.replace(old,new)
        if updated!=original:file.write_text(updated,encoding="utf-8")
    for src,dst in MOVES.items():
        if (ROOT/src).exists() or not (ROOT/dst).exists():
            raise RuntimeError("Postcondition failed: "+src)
    for file in files():
        text=file.read_text()
        for old in classes:
            if old in text or old.replace("\\","\\\\") in text:
                raise RuntimeError(f"Stale legacy class {old} in {file}")
    print(f"Cut over {len(MOVES)} public value classes to their owning feature Api/Data packages.")
    for src,dst in MOVES.items():print(f"{src} -> {dst}")
    print("No old class implementations, class aliases or stale imports remain.")

if __name__=="__main__":
    main()
