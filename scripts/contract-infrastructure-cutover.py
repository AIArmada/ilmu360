#!/usr/bin/env python3
"""One-time public contract and leaf infrastructure cleanup; zero legacy aliases."""
from pathlib import Path

ROOT=Path.cwd()
MOVES={
    "app/Contracts/CaptchaVerifier.php":"app/Shared/Captcha/CaptchaVerifier.php",
    "app/Contracts/NullCaptchaVerifier.php":"app/Platform/Captcha/NullCaptchaVerifier.php",
    "app/Contracts/ShareCards/ShareCardError.php":"app/Shared/Media/ShareCards/ShareCardError.php",
    "app/Contracts/ShareCards/ShareCardReadiness.php":"app/Shared/Media/ShareCards/ShareCardReadiness.php",
    "app/Contracts/ShareCards/ShareCardRenderInput.php":"app/Shared/Media/ShareCards/ShareCardRenderInput.php",
    "app/Contracts/ShareCards/ShareCardRenderResult.php":"app/Shared/Media/ShareCards/ShareCardRenderResult.php",
    "app/Contracts/ShareCards/ShareCardRenderer.php":"app/Shared/Media/ShareCards/ShareCardRenderer.php",
    "app/Support/ShareCards/PangoShareCardRenderer.php":"app/Platform/Media/ShareCards/PangoShareCardRenderer.php",
    "app/Support/ShareCards/ShareCardFonts.php":"app/Platform/Media/ShareCards/ShareCardFonts.php",
    "app/Support/ShareCards/ShareCardGlyphCoverage.php":"app/Platform/Media/ShareCards/ShareCardGlyphCoverage.php",
    "app/Support/ShareCards/ShareCardText.php":"app/Platform/Media/ShareCards/ShareCardText.php",
    "app/Support/Persons/PersonBioContent.php":"app/Shared/Persons/PersonBioContent.php",
    "app/Support/Events/PublicScheduleSlug.php":"app/Shared/Events/PublicScheduleSlug.php",
    "app/Support/Events/HeroBackdrop.php":"app/Shared/Events/HeroBackdrop.php",
}
ROOTS=["app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github","scripts"]
EXTS={".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"}

def fqcn(path):
    return "App\\"+path[4:-4].replace("/","\\")

def scan():
    for dirname in ROOTS:
        d=ROOT/dirname
        if not d.is_dir():continue
        for p in d.rglob("*"):
            if p.is_file() and p.suffix in EXTS and not {"vendor","node_modules",".git"}.intersection(p.parts):
                yield p
    for name in ["README.md","composer.json","phpunit.xml","phpstan.neon","rector.php"]:
        p=ROOT/name
        if p.is_file():yield p

def main():
    for old,new in MOVES.items():
        if not (ROOT/old).is_file():raise RuntimeError("Missing source "+old)
        if (ROOT/new).exists():raise RuntimeError("Destination already exists "+new)
    mappings={fqcn(old):fqcn(new) for old,new in MOVES.items()}
    for old,new in MOVES.items():
        src,dst=ROOT/old,ROOT/new
        content=src.read_text()
        oldns=fqcn(old).rsplit("\\",1)[0]
        newns=fqcn(new).rsplit("\\",1)[0]
        needle="namespace "+oldns+";"
        if needle not in content: raise RuntimeError("Unexpected namespace "+old)
        content=content.replace(needle,"namespace "+newns+";",1)
        if old.endswith("/NullCaptchaVerifier.php"):
            needle="namespace "+newns+";"
            content=content.replace(needle,needle+"\n\nuse App\\Shared\\Captcha\\CaptchaVerifier;",1)
        dst.parent.mkdir(parents=True,exist_ok=True)
        dst.write_text(content)
        src.unlink()

    for path in scan():
        before=path.read_text()
        after=before
        for old,new in sorted(mappings.items(),key=lambda kv:-len(kv[0])):
            after=after.replace(old.replace("\\","\\\\"),new.replace("\\","\\\\"))
            after=after.replace(old,new)
        for old,new in sorted(MOVES.items(),key=lambda kv:-len(kv[0])):
            after=after.replace(old,new)
        if before!=after:path.write_text(after)

    for old,new in MOVES.items():
        if (ROOT/old).exists():raise RuntimeError("Old implementation survives "+old)
        if not (ROOT/new).is_file():raise RuntimeError("Missing new implementation "+new)
        if "/Platform/" in new or "/Shared/" in new:
            text=(ROOT/new).read_text()
            if "use App\\Features\\" in text:
                raise RuntimeError("Platform/Shared importing a feature: "+new)

    for path in scan():
        content=path.read_text()
        for old in mappings:
            if old in content or old.replace("\\","\\\\") in content:
                raise RuntimeError(f"Obsolete FQCN {old} in {path}")
    print("Relocated",len(MOVES),"infrastructure/Shared classes; removed all original files and old class references.")
    for old,new in MOVES.items():print(old,"=>",new)

if __name__=="__main__":
    main()
