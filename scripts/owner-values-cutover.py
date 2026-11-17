#!/usr/bin/env python3
"""One-shot consolidation of immutable domain enum/value contracts and generic UI/auth primitives.

Preserves exact enum backing values, PHP APIs, and Laravel/Filament runtime behavior.
No backward classes, forwarded subclasses, classmap aliases, or parallel files.
"""
from pathlib import Path
import re

ROOT=Path.cwd()
MOVES={
    "app/Enums/EventChangeSeverity.php":"app/Features/Events/Api/EventChangeSeverity.php",
    "app/Enums/RegistrationScope.php":"app/Features/Events/Api/RegistrationScope.php",
    "app/Enums/ShareSubjectType.php":"app/Shared/ShareTracking/ShareSubjectType.php",
    "app/Enums/SpeakerStatus.php":"app/Features/Persons/Api/SpeakerStatus.php",
    "app/Enums/TaxonomyTerm/DisciplineTermCode.php":"app/Shared/Taxonomy/Terms/DisciplineTermCode.php",
    "app/Enums/TaxonomyTerm/DomainTermCode.php":"app/Shared/Taxonomy/Terms/DomainTermCode.php",
    "app/Enums/TaxonomyTerm/IssueTermCode.php":"app/Shared/Taxonomy/Terms/IssueTermCode.php",
    "app/Enums/TaxonomyTerm/SourceTermCode.php":"app/Shared/Taxonomy/Terms/SourceTermCode.php",
    "app/Support/Submission/SubmissionLockEligibilityResult.php":"app/Shared/Submission/SubmissionLockEligibilityResult.php",
    "app/Support/Submission/PublicSubmissionUiEvents.php":"app/Shared/Submission/PublicSubmissionUiEvents.php",
    "app/Support/Notifications/NotificationDispatchData.php":"app/Features/Communications/Api/NotificationDispatchData.php",
    "app/Concerns/PasswordValidationRules.php":"app/Platform/Auth/PasswordValidationRules.php",
    "app/Forms/Components/Select.php":"app/Platform/Ui/Forms/Select.php",
}
FOLDERS=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github","scripts")
EXTS={".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"}

def cls(path):
    return "App\\"+path[4:-4].replace("/","\\")

def corpus():
    for name in FOLDERS:
        base=ROOT/name
        if base.is_dir():
            for f in base.rglob("*"):
                if f.is_file() and f.suffix in EXTS and not {"vendor","node_modules",".git"}.intersection(f.parts):yield f
    for name in ("composer.json","phpunit.xml","phpstan.neon","rector.php","README.md"):
        f=ROOT/name
        if f.is_file():yield f

def replace_exact(text,old,new):
    return re.sub(r"(?<![A-Za-z0-9_])"+re.escape(old)+r"(?![A-Za-z0-9_])",lambda m:new,text)

def main():
    for source,target in MOVES.items():
        if not (ROOT/source).is_file() or (ROOT/target).exists():
            raise RuntimeError("Unsafe move collision or missing source: "+source+" -> "+target)
    mapping={cls(a):cls(b) for a,b in MOVES.items()}

    for source,target in MOVES.items():
        old,dst=ROOT/source,ROOT/target
        content=old.read_text()
        namespace="namespace "+cls(source).rsplit("\\",1)[0]+";"
        if namespace not in content:raise RuntimeError("Namespace no longer matches: "+source)
        content=content.replace(namespace,"namespace "+cls(target).rsplit("\\",1)[0]+";",1)
        if source.endswith("NotificationDispatchData.php"):
            marker="readonly class NotificationDispatchData"
            if marker not in content:raise RuntimeError("Dispatch data class changed")
            content=content.replace(marker,"final readonly class NotificationDispatchData",1)
        dst.parent.mkdir(parents=True,exist_ok=True)
        dst.write_text(content)
        old.unlink()

    # The root multi-domain submission coordinator previously used its
    # sibling result type without an import; bind its new Shared value explicitly.
    adapter=ROOT/"app/Support/Submission/PublicSubmissionLockService.php"
    content=adapter.read_text()
    namespace="namespace App\\Support\\Submission;"
    if namespace not in content:raise RuntimeError("Submission lock adapter namespace changed")
    adapter.write_text(content.replace(
        namespace,namespace+"\n\nuse App\\Shared\\Submission\\SubmissionLockEligibilityResult;",1
    ))

    for file in corpus():
        old_text=file.read_text()
        new_text=old_text
        for old,new in sorted(mapping.items(),key=lambda kv:-len(kv[0])):
            new_text=replace_exact(new_text,old.replace("\\","\\\\"),new.replace("\\","\\\\"))
            new_text=replace_exact(new_text,old,new)
        for old,new in sorted(MOVES.items(),key=lambda kv:-len(kv[0])):
            new_text=new_text.replace(old,new)
        if old_text!=new_text:file.write_text(new_text)

    for source,target in MOVES.items():
        if (ROOT/source).exists():raise RuntimeError("Old implementation survived: "+source)
        moved=(ROOT/target).read_text()
        if "App\\Shared\\" in target and "use App\\Features\\" in moved:
            raise RuntimeError("Shared class depends on feature: "+target)
        if "App\\Platform\\" in target and "use App\\Features\\" in moved:
            raise RuntimeError("Platform integration depends on feature: "+target)
    for file in corpus():
        s=file.read_text()
        for old in mapping:
            if re.search(r"(?<![A-Za-z0-9_])"+re.escape(old)+r"(?![A-Za-z0-9_])",s):
                raise RuntimeError("Stale class: "+old+" in "+str(file))
            if re.search(r"(?<![A-Za-z0-9_])"+re.escape(old.replace("\\","\\\\"))+r"(?![A-Za-z0-9_])",s):
                raise RuntimeError("Stale escaped class: "+old+" in "+str(file))
    print(f"Moved {len(MOVES)} immutable/shared primitives to owners.")
    for src,dst in MOVES.items():print(f"{src} => {dst}")
    print("All conventional source implementations removed; no fallback class exists.")

if __name__=="__main__":
    main()
