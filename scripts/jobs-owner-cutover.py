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
