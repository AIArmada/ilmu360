#!/usr/bin/env python3
"""Rehome cross-domain presentation adapters under Platform/Ui.

The existing <x-event-json-ld> tag and view lookup stay unchanged via an
explicit Blade component registration. No old class, class alias or copied
implementation remains.
"""
from pathlib import Path
import re

ROOT=Path.cwd()
MOVES={
    "app/View/Components/EventJsonLd.php":
        "app/Platform/Ui/Components/EventJsonLd.php",
    "app/Support/Events/EventCategoryPresenter.php":
        "app/Platform/Ui/Events/EventCategoryPresenter.php",
    "app/Support/Events/SubmitterContactPresenter.php":
        "app/Platform/Ui/Events/SubmitterContactPresenter.php",
    "app/Support/Spaces/SpaceLocationPresenter.php":
        "app/Platform/Ui/Places/SpaceLocationPresenter.php",
    "app/Support/Submission/ContributionSubjectPresenter.php":
        "app/Platform/Ui/Contributions/ContributionSubjectPresenter.php",
}
ROOTS=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang")
EXTENSIONS={".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"}

def classname(path):
    return "App\\"+path[4:-4].replace("/","\\")

def files():
    for name in ROOTS:
        root=ROOT/name
        if root.is_dir():
            for f in root.rglob("*"):
                if f.is_file() and f.suffix in EXTENSIONS and not {"vendor","node_modules",".git"}.intersection(f.parts):
                    yield f

for source,target in MOVES.items():
    old,new=ROOT/source,ROOT/target
    if not old.is_file() or new.exists():
        raise RuntimeError("Source missing or target occupied: "+source)
    body=old.read_text()
    namespace="namespace "+classname(source).rsplit("\\",1)[0]+";"
    if namespace not in body:raise RuntimeError("Unexpected namespace: "+source)
    if "use App\\Features\\" in body:raise RuntimeError("Platform presentation adapter imports a feature: "+source)
    if source.endswith("EventJsonLd.php"):
        if "extends Component" not in body or "view('components.event-json-ld')" not in body:
            raise RuntimeError("Blade component contract changed")

for source,target in MOVES.items():
    old,new=ROOT/source,ROOT/target
    data=old.read_text()
    old_namespace="namespace "+classname(source).rsplit("\\",1)[0]+";"
    new_namespace="namespace "+classname(target).rsplit("\\",1)[0]+";"
    new.parent.mkdir(parents=True,exist_ok=True)
    new.write_text(data.replace(old_namespace,new_namespace,1))
    old.unlink()

provider=ROOT/"app/Providers/AppServiceProvider.php"
code=provider.read_text()
namespace="namespace App\\Providers;"
if namespace not in code:raise RuntimeError("App provider namespace changed")
if "use Illuminate\\Support\\Facades\\Blade;" in code or "use App\\Platform\\Ui\\Components\\EventJsonLd;" in code:
    raise RuntimeError("Blade component already imported under a different arrangement")
code=code.replace(namespace,namespace+"""

use App\\Platform\\Ui\\Components\\EventJsonLd;
use Illuminate\\Support\\Facades\\Blade;""",1)
boot="    public function boot(): void\n    {\n"
if code.count(boot)!=1:
    raise RuntimeError("Unexpected AppServiceProvider boot signature")
code=code.replace(boot,boot+"""
        // Preserve the public <x-event-json-ld> tag after removing App/View.
        Blade::component(EventJsonLd::class, 'event-json-ld');

""",1)
provider.write_text(code)

classes={classname(a):classname(b) for a,b in MOVES.items()}
for f in files():
    old=f.read_text()
    new=old
    for source,target in sorted(classes.items(),key=lambda kv:-len(kv[0])):
        for a,b in ((source.replace("\\","\\\\"),target.replace("\\","\\\\")),(source,target)):
            new=re.sub(r"(?<![A-Za-z0-9_])"+re.escape(a)+r"(?![A-Za-z0-9_])",lambda m:b,new)
    for source,target in MOVES.items():
        new=new.replace(source,target)
    if old!=new:f.write_text(new)

# A same-namespace reference to a moved presenter needs an explicit import.
for f in files():
    if f.suffix!=".php":continue
    code=f.read_text()
    updated=code
    for old,new in classes.items():
        parent,short=old.rsplit("\\",1)
        declaration="namespace "+parent+";"
        if declaration not in updated or "use "+new+";" in updated:continue
        if re.search(r"(?<![A-Za-z0-9_\\\\])"+re.escape(short)+r"\b",updated) is None:continue
        updated=updated.replace(declaration,declaration+"\n\nuse "+new+";",1)
    if updated!=code:f.write_text(updated)

for src,dst in MOVES.items():
    if (ROOT/src).exists() or not (ROOT/dst).is_file():
        raise RuntimeError("Old class remained or target absent: "+src)
for f in files():
    content=f.read_text()
    for old in classes:
        for v in (old,old.replace("\\","\\\\")):
            if re.search(r"(?<![A-Za-z0-9_])"+re.escape(v)+r"(?![A-Za-z0-9_])",content):
                raise RuntimeError(f"Stale old class {old} in {f}")
if "Blade::component(EventJsonLd::class, 'event-json-ld');" not in provider.read_text():
    raise RuntimeError("Blade tag registration missing")
print("Five UI presenters relocated into Platform/Ui, root View implementation deleted.")
print("Event JSON-LD Blade alias, view path and public behavior preserved.")
