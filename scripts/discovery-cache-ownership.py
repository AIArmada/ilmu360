#!/usr/bin/env python3
"""Cut public listing cache orchestration over to Discovery's public contract.

Only Discovery coordinates the five cache APIs; external observers use the
public invalidation boundary, never a feature-private implementation.
"""
from pathlib import Path
import re

ROOT=Path.cwd()
SOURCE=ROOT/"app/Services/PublicListingsCache.php"
TARGET=ROOT/"app/Features/Discovery/Services/PublicListingsCache.php"
API=ROOT/"app/Features/Discovery/Api/PublicListingsCacheInvalidator.php"
PROVIDER=ROOT/"app/Features/Discovery/FeatureServiceProvider.php"

if not SOURCE.is_file() or TARGET.exists() or API.exists() or not PROVIDER.is_file():
    raise RuntimeError("Invalid repository state for Discovery public listings ownership")

oldclass="App\\Services\\PublicListingsCache"
interface="App\\Features\\Discovery\\Api\\PublicListingsCacheInvalidator"
roots=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github","scripts")

def files():
    for name in roots:
        base=ROOT/name
        if not base.is_dir():continue
        for f in base.rglob("*"):
            if f.is_file() and f.suffix in (".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"):
                yield f

# Existing injection sites can depend on a public interface; do not
# silently convert manually constructed services to unresolved interfaces.
for f in files():
    if re.search(r"\bnew\s+(?:\\?App\\Services\\)?PublicListingsCache\s*\(",f.read_text()):
        raise RuntimeError("Manual cache coordinator construction requires explicit migration in "+str(f))

code=SOURCE.read_text()
if "final readonly class PublicListingsCache" not in code:
    raise RuntimeError("Unexpected cache service declaration")
if "use App\\Models\\" in code or "use App\\Services\\" in code:
    raise RuntimeError("Cache coordinator unexpectedly depends on root mutable internals")
code=code.replace(
    "namespace App\\Services;",
    "namespace App\\Features\\Discovery\\Services;\n\nuse App\\Features\\Discovery\\Api\\PublicListingsCacheInvalidator;",
    1,
)
code=code.replace(
    "final readonly class PublicListingsCache",
    "final readonly class PublicListingsCache implements PublicListingsCacheInvalidator",
    1,
)
TARGET.parent.mkdir(parents=True,exist_ok=True)
TARGET.write_text(code)
SOURCE.unlink()

API.write_text("""<?php

declare(strict_types=1);

namespace App\\Features\\Discovery\\Api;

/** Cross-feature invalidation of public discovery projections. */
interface PublicListingsCacheInvalidator
{
    public function bustHomepageStats(): void;

    public function bustMajlisListing(): void;
}
""")

provider=PROVIDER.read_text()
if "class FeatureServiceProvider extends FeatureModule {}" not in provider:
    raise RuntimeError("Discovery feature provider shape changed")
provider=provider.replace(
    "use App\\Platform\\FeatureModule;",
    "use App\\Features\\Discovery\\Api\\PublicListingsCacheInvalidator;\nuse App\\Features\\Discovery\\Services\\PublicListingsCache;\nuse App\\Platform\\FeatureModule;",
    1,
)
provider=provider.replace(
    "class FeatureServiceProvider extends FeatureModule {}",
    """class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(PublicListingsCacheInvalidator::class, PublicListingsCache::class);
    }
}""",
    1,
)
provider=provider.replace(
    "Discovery intentionally ships no Api contract and no\n * bindings; \`owns: {}\` is the accepted boundary.",
    "Discovery owns cache invalidation orchestration behind its public Api;\n * read adapters can remain root until their domain models are decoupled.\n * No database tables or cache keys are newly claimed.",
)
PROVIDER.write_text(provider)

# Replace only the exact old FQCN, never a class-name prefix.
for f in files():
    original=f.read_text()
    modified=re.sub(
        r"(?<![A-Za-z0-9_])"+re.escape(oldclass.replace("\\","\\\\"))+r"(?![A-Za-z0-9_])",
        lambda m:interface.replace("\\","\\\\"),original,
    )
    modified=re.sub(
        r"(?<![A-Za-z0-9_])"+re.escape(oldclass)+r"(?![A-Za-z0-9_])",
        lambda m:interface,modified,
    )
    modified=modified.replace("app/Services/PublicListingsCache.php","app/Features/Discovery/Services/PublicListingsCache.php")
    # Import replacement alone is insufficient: the observer/adapter short
    # type hints must be updated to the actual public interface identifier.
    if f != PROVIDER and f != TARGET and "use App\\Features\\Discovery\\Api\\PublicListingsCacheInvalidator;" in modified:
        modified=re.sub(r"(?<![A-Za-z0-9_\\\\])PublicListingsCache(?![A-Za-z0-9_])",
                        "PublicListingsCacheInvalidator",modified)
    if modified!=original:f.write_text(modified)

for f in files():
    text=f.read_text()
    for pattern in (oldclass,oldclass.replace("\\","\\\\")):
        if re.search(r"(?<![A-Za-z0-9_])"+re.escape(pattern)+r"(?![A-Za-z0-9_])",text):
            raise RuntimeError("Stale old cache service in "+str(f))
if SOURCE.exists() or "PublicListingsCacheInvalidator::class, PublicListingsCache::class" not in PROVIDER.read_text():
    raise RuntimeError("Direct cutover postcondition failed")
print("Discovery now owns public listings cache coordination, with interface-only callers and no root service alias.")
