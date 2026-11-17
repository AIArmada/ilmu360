#!/usr/bin/env python3
"""Rehome Discovery's search-filter implementations and generic API surface policy.

All call sites are rewritten in place. No forwarding adapters or aliases.
"""
from pathlib import Path
import re

ROOT=Path.cwd()
MOVES={
    "app/Support/Discovery/EventSearchFilterCatalog.php":"app/Features/Discovery/Services/EventSearchFilterCatalog.php",
    "app/Support/Discovery/EventSearchFilterNormalizer.php":"app/Features/Discovery/Services/EventSearchFilterNormalizer.php",
    "app/Support/Discovery/EventSearchFilterRules.php":"app/Features/Discovery/Services/EventSearchFilterRules.php",
    "app/Support/Api/SurfaceSyncPolicy.php":"app/Platform/Api/SurfaceSyncPolicy.php",
}
ROOTS=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github","scripts")
SUFFIXES={".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"}
SERVICE_CLASSES=("EventSearchFilterCatalog","EventSearchFilterNormalizer","EventSearchFilterRules")
INTERFACES=("SearchFilterCatalog","SearchFilterNormalizer","SearchFilterRules")

def fqcn(path):
    return "App\\"+path[4:-4].replace("/","\\")

def corpus():
    for name in ROOTS:
        d=ROOT/name
        if d.is_dir():
            for f in d.rglob("*"):
                if f.is_file() and f.suffix in SUFFIXES and not {"node_modules","vendor",".git"}.intersection(f.parts):
                    yield f
    for name in ("composer.json","phpstan.neon","phpunit.xml","rector.php","README.md"):
        f=ROOT/name
        if f.exists():yield f

def exact(text,old,new):
    return re.sub(r"(?<![A-Za-z0-9_])"+re.escape(old)+r"(?![A-Za-z0-9_])",lambda m:new,text)

def main():
    mapping={fqcn(old):fqcn(new) for old,new in MOVES.items()}
    for old,new in MOVES.items():
        s,d=ROOT/old,ROOT/new
        if not s.is_file() or d.exists():raise RuntimeError("Unexpected path state: "+old+" => "+new)
        source=s.read_text()
        if "use App\\Models\\" in source or "use App\\Features\\" in source:
            raise RuntimeError("Candidate is not a standalone Discovery implementation: "+old)
        if "namespace "+fqcn(old).rsplit("\\",1)[0]+";" not in source:
            raise RuntimeError("Source namespace changed: "+old)
    for old,new in MOVES.items():
        s,d=ROOT/old,ROOT/new
        contents=s.read_text()
        contents=contents.replace(
            "namespace "+fqcn(old).rsplit("\\",1)[0]+";",
            "namespace "+fqcn(new).rsplit("\\",1)[0]+";",1,
        )
        d.parent.mkdir(parents=True,exist_ok=True)
        d.write_text(contents)
        s.unlink()

    for f in corpus():
        original=f.read_text()
        output=original
        for old,new in sorted(mapping.items(),key=lambda pair:-len(pair[0])):
            output=exact(output,old.replace("\\","\\\\"),new.replace("\\","\\\\"))
            output=exact(output,old,new)
        for old,new in sorted(MOVES.items(),key=lambda pair:-len(pair[0])):
            output=output.replace(old,new)
        if original!=output:f.write_text(output)

    # Bind the three established Shared contracts at the owning Feature
    # rather than leaking feature-private classes into root AppServiceProvider.
    root=ROOT/"app/Providers/AppServiceProvider.php"
    content=root.read_text()
    for service,contract in zip(SERVICE_CLASSES,INTERFACES):
        old_use=f"use App\\Features\\Discovery\\Services\\{service};\n"
        old_contract=f"use App\\Shared\\Discovery\\{contract};\n"
        old_binding=f"        $this->app->bind({contract}::class, {service}::class);\n"
        if old_use not in content or old_contract not in content or old_binding not in content:
            raise RuntimeError("Unexpected application binding for "+service)
        content=content.replace(old_use,"",1)
        content=content.replace(old_contract,"",1)
        content=content.replace(old_binding,"",1)
    root.write_text(content)

    feature=ROOT/"app/Features/Discovery/FeatureServiceProvider.php"
    content=feature.read_text()
    anchor="use App\\Platform\\FeatureModule;"
    if anchor not in content:raise RuntimeError("Feature provider imports changed")
    imports="\n".join([f"use App\\Features\\Discovery\\Services\\{service};" for service in SERVICE_CLASSES]+
                      [f"use App\\Shared\\Discovery\\{contract};" for contract in INTERFACES])
    content=content.replace(anchor,imports+"\n"+anchor,1)
    anchor="        $this->app->bind(PublicListingsCacheInvalidator::class, PublicListingsCache::class);"
    if anchor not in content:raise RuntimeError("Discovery cache boundary not found")
    registrations="\n".join(f"        $this->app->bind({contract}::class, {service}::class);" for service,contract in zip(SERVICE_CLASSES,INTERFACES))
    content=content.replace(anchor,anchor+"\n"+registrations,1)
    start=content.find("/**\n * Discovery is")
    end=content.find("*/\nclass FeatureServiceProvider",start)
    if start<0 or end<0:raise RuntimeError("Stale Discovery provider commentary unexpectedly changed")
    content=content[:start]+"""/**
 * Discovery owns the public search-filter normalization, validation and
 * catalog rules, plus cache invalidation orchestration through its public Api.
 * Cross-feature model hydration remains a root integration concern until it
 * can be decoupled behind explicit read-side public boundaries.
 * No database tables or cache stores are newly claimed by this module.
 */
"""+content[end+3:]
    feature.write_text(content)

    for f in corpus():
        text=f.read_text()
        for old in mapping:
            if exact(text,old,"")!=text or exact(text,old.replace("\\","\\\\"),"")!=text:
                raise RuntimeError("Stale class string "+old+" in "+str(f))
        if str(f).startswith(str(ROOT/"app")) and "Features/Discovery/" not in str(f):
            for service in SERVICE_CLASSES:
                if f"use App\\Features\\Discovery\\Services\\{service};" in text:
                    raise RuntimeError("Root client depends on private feature service in "+str(f))
    for old,new in MOVES.items():
        if (ROOT/old).exists() or not (ROOT/new).exists():
            raise RuntimeError("Legacy/absent implementation: "+old)
    print(f"Moved {len(MOVES)} classes and re-registered Discovery search filters at feature provider.")
    for old,new in MOVES.items():print(old+" => "+new)
    print("No root bindings to feature-private services or obsolete classes.")
if __name__=="__main__":
    main()
