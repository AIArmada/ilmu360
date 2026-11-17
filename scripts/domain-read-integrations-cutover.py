#!/usr/bin/env python3
"""Move Membership and Reports read integrations into their capability owners.

These implementations already depend only on declared feature public APIs and
Shared/Platform values. Root composition bindings move to feature providers.
"""
from pathlib import Path
import re

ROOT=Path.cwd()
MOVES={
    "app/Support/Reports/CompositeReportSubjectResolver.php":
        "app/Features/Reports/Services/CompositeReportSubjectResolver.php",
    "app/Support/Membership/EloquentMembershipSubjectCatalog.php":
        "app/Features/Membership/Services/EloquentMembershipSubjectCatalog.php",
}
BINDINGS={
    "Reports":("ReportSubjectResolver","CompositeReportSubjectResolver","Reports"),
    "Membership":("MembershipSubjectCatalog","EloquentMembershipSubjectCatalog","Membership"),
}
FOLDERS=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github","scripts")
SUFFIXES={".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"}

def cls(path):
    return "App\\"+path[4:-4].replace("/","\\")

def files():
    for n in FOLDERS:
        p=ROOT/n
        if p.is_dir():
            for f in p.rglob("*"):
                if f.is_file() and f.suffix in SUFFIXES and not {"vendor","node_modules",".git"}.intersection(f.parts):yield f
    for n in ("composer.json","phpstan.neon","phpunit.xml","README.md","rector.php"):
        p=ROOT/n
        if p.exists():yield p

def change_identifier(text,old,new):
    return re.sub(r"(?<![A-Za-z0-9_])"+re.escape(old)+r"(?![A-Za-z0-9_])",lambda m:new,text)

def main():
    classes={cls(old):cls(new) for old,new in MOVES.items()}
    for old,new in MOVES.items():
        source,target=ROOT/old,ROOT/new
        if not source.is_file() or target.exists():raise RuntimeError("Unexpected source/destination: "+old)
        text=source.read_text()
        if "use App\\Models\\" in text or "use App\\Services\\" in text or "use App\\Support\\" in text:
            raise RuntimeError("Cross-domain model or root adapter dependency in "+old)
    for old,new in MOVES.items():
        source,target=ROOT/old,ROOT/new
        content=source.read_text()
        oldns="namespace "+cls(old).rsplit("\\",1)[0]+";"
        newns="namespace "+cls(new).rsplit("\\",1)[0]+";"
        if oldns not in content:raise RuntimeError("Unexpected namespace "+old)
        target.parent.mkdir(parents=True,exist_ok=True)
        target.write_text(content.replace(oldns,newns,1))
        source.unlink()
    for f in files():
        old_text=f.read_text()
        text=old_text
        for old,new in sorted(classes.items(),key=lambda kv:-len(kv[0])):
            text=change_identifier(text,old.replace("\\","\\\\"),new.replace("\\","\\\\"))
            text=change_identifier(text,old,new)
        for old,new in MOVES.items():text=text.replace(old,new)
        if text!=old_text:f.write_text(text)

    root_provider=ROOT/"app/Providers/AppServiceProvider.php"
    root=root_provider.read_text()
    for owner,(contract,service,sharedgroup) in BINDINGS.items():
        name=cls(next(new for new in MOVES.values() if new.endswith("/"+service+".php")))
        private_import="use "+name+";\n"
        shared_import=f"use App\\Shared\\{sharedgroup}\\{contract};\n"
        bind=f"        $this->app->bind({contract}::class, {service}::class);\n"
        if any(needle not in root for needle in (private_import,shared_import,bind)):
            raise RuntimeError("Root composition binding changed: "+owner)
        root=root.replace(private_import,"",1).replace(shared_import,"",1).replace(bind,"",1)
        provider=ROOT/f"app/Features/{owner}/FeatureServiceProvider.php"
        content=provider.read_text()
        anchor="use App\\Platform\\FeatureModule;"
        if anchor not in content:raise RuntimeError("Owner provider lacks FeatureModule: "+owner)
        content=content.replace(anchor,
            "use "+name+";\n"+f"use App\\Shared\\{sharedgroup}\\{contract};\n"+anchor,1)
        marker="    public function register(): void\n    {"
        if marker not in content:raise RuntimeError("Registration method missing: "+owner)
        content=content.replace(marker,
            marker+"\n"+bind.rstrip(),1)
        provider.write_text(content)
    root_provider.write_text(root)

    for f in files():
        code=f.read_text()
        for old in classes:
            if change_identifier(code,old,"")!=code or change_identifier(code,old.replace("\\","\\\\"),"")!=code:
                raise RuntimeError("Legacy reference remains: "+old+" in "+str(f))
        if f.suffix==".php" and f.as_posix().startswith(str(ROOT/"app")) and "/Features/" not in f.as_posix():
            for new in classes.values():
                if "use "+new+";" in code:raise RuntimeError("Root imports feature private class "+new+" in "+str(f))
    for old,new in MOVES.items():
        if (ROOT/old).exists() or not (ROOT/new).is_file():
            raise RuntimeError("Incomplete direct move: "+old)
    print("Reports and Membership read integrations are feature-owned and bound by their feature providers.")
    for old,new in MOVES.items():print(old+" => "+new)
    print("No class aliases, old bindings or implementation copies survive.")

if __name__=="__main__":
    main()
