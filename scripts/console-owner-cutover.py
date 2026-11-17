#!/usr/bin/env python3
"""One-time feature/platform console ownership cutover.

Rehome existing commands without aliasing the old FQCNs or changing CLI
signatures. Only Laravel's command registration moves.
"""
from pathlib import Path
import re

ROOT = Path.cwd()
PERSON_SOURCE = ROOT / "app/Console/Commands/ReindexPersonSearch.php"
PERSON_TARGET = ROOT / "app/Features/Persons/EntryPoints/Console/Commands/ReindexPersonSearch.php"
LOCK_SOURCE = ROOT / "app/Console/Commands/SyncPublicSubmissionLocks.php"
LOCK_TARGET = ROOT / "app/Platform/Console/Commands/SyncPublicSubmissionLocks.php"
LOCK_CONTRACT = ROOT / "app/Shared/Submission/PublicSubmissionLockSweeper.php"
LOCK_SERVICE = ROOT / "app/Support/Submission/PublicSubmissionLockService.php"
PERSON_PROVIDER = ROOT / "app/Features/Persons/FeatureServiceProvider.php"
ROOT_PROVIDER = ROOT / "app/Providers/AppServiceProvider.php"
BOOTSTRAP = ROOT / "bootstrap/app.php"

for source in (PERSON_SOURCE, LOCK_SOURCE, LOCK_SERVICE, PERSON_PROVIDER, ROOT_PROVIDER, BOOTSTRAP):
    if not source.is_file():
        raise RuntimeError(f"Missing source: {source}")
for dest in (PERSON_TARGET, LOCK_TARGET, LOCK_CONTRACT):
    if dest.exists():
        raise RuntimeError(f"Destination already exists: {dest}")

person = PERSON_SOURCE.read_text()
lock = LOCK_SOURCE.read_text()
assert "namespace App\\Console\\Commands;" in person
assert "namespace App\\Console\\Commands;" in lock
assert "#[Signature('persons:reindex-search" in person
assert "#[Signature('app:sync-public-submission-locks')" in lock
assert "handle(PublicSubmissionLockService $lockService)" in lock

PERSON_TARGET.parent.mkdir(parents=True, exist_ok=True)
PERSON_TARGET.write_text(person.replace(
    "namespace App\\Console\\Commands;",
    "namespace App\\Features\\Persons\\EntryPoints\\Console\\Commands;",
    1
))
PERSON_SOURCE.unlink()

LOCK_TARGET.parent.mkdir(parents=True, exist_ok=True)
LOCK_TARGET.write_text(lock.replace(
    "namespace App\\Console\\Commands;",
    "namespace App\\Platform\\Console\\Commands;",
    1
).replace(
    "use App\\Support\\Submission\\PublicSubmissionLockService;",
    "use App\\Shared\\Submission\\PublicSubmissionLockSweeper;",
    1
).replace(
    "handle(PublicSubmissionLockService $lockService)",
    "handle(PublicSubmissionLockSweeper $lockService)",
    1
))
LOCK_SOURCE.unlink()

LOCK_CONTRACT.write_text("""<?php

declare(strict_types=1);

namespace App\\Shared\\Submission;

/** Cross-domain submission lock maintenance boundary for console transport. */
interface PublicSubmissionLockSweeper
{
    /** @return array{institutions_reopened: int, persons_reopened: int} */
    public function sweepLockedEntities(): array;
}
""")

service = LOCK_SERVICE.read_text()
ns = "namespace App\\Support\\Submission;"
class_decl = "final readonly class PublicSubmissionLockService"
if ns not in service or class_decl not in service:
    raise RuntimeError("Submission lock service declaration changed")
service = service.replace(ns, ns + "\n\nuse App\\Shared\\Submission\\PublicSubmissionLockSweeper;", 1)
service = service.replace(class_decl, class_decl + " implements PublicSubmissionLockSweeper", 1)
LOCK_SERVICE.write_text(service)

persons = PERSON_PROVIDER.read_text()
anchor = """        $this->commands([
            EntryPoints\\Console\\Commands\\IndexPersonsToTypesense::class,
        ]);"""
replacement = """        $this->commands([
            EntryPoints\\Console\\Commands\\IndexPersonsToTypesense::class,
            EntryPoints\\Console\\Commands\\ReindexPersonSearch::class,
        ]);"""
if anchor not in persons:
    raise RuntimeError("Persons command registration changed")
PERSON_PROVIDER.write_text(persons.replace(anchor, replacement, 1))

bootstrap = BOOTSTRAP.read_text()
anchor = "        App\\Platform\\Mcp\\Commands\\IssueMcpToken::class,"
if anchor not in bootstrap:
    raise RuntimeError("Bootstrap command registration changed")
BOOTSTRAP.write_text(bootstrap.replace(
    anchor,
    anchor + "\n        App\\Platform\\Console\\Commands\\SyncPublicSubmissionLocks::class,",
    1,
))

root = ROOT_PROVIDER.read_text()
imports = "namespace App\\Providers;"
if imports not in root:
    raise RuntimeError("App provider namespace changed")
root = root.replace(
    imports,
    imports + "\n\nuse App\\Shared\\Submission\\PublicSubmissionLockSweeper;\nuse App\\Support\\Submission\\PublicSubmissionLockService;",
    1
)
registration = "        $this->app->bind(AccountDirectory::class, EloquentAccountDirectory::class);"
if registration not in root:
    raise RuntimeError("App provider registration insertion point missing")
root = root.replace(
    registration,
    registration + "\n        $this->app->bind(PublicSubmissionLockSweeper::class, PublicSubmissionLockService::class);",
    1
)
ROOT_PROVIDER.write_text(root)

old_classes = {
    "App\\Console\\Commands\\ReindexPersonSearch":
        "App\\Features\\Persons\\EntryPoints\\Console\\Commands\\ReindexPersonSearch",
    "App\\Console\\Commands\\SyncPublicSubmissionLocks":
        "App\\Platform\\Console\\Commands\\SyncPublicSubmissionLocks",
}
# Keep same command names and options. Rewrite complete FQCNs only.
scan_roots = ("app", "bootstrap", "config", "database", "resources", "routes", "tests", "tools", "docs", "lang", ".github")
for directory in scan_roots:
    base = ROOT / directory
    if not base.is_dir():
        continue
    for file in base.rglob("*"):
        if not file.is_file() or file.suffix not in (".php", ".json", ".md", ".yml", ".yaml", ".xml", ".neon", ".js", ".ts", ".txt"):
            continue
        before = file.read_text()
        after = before
        for old, new in old_classes.items():
            for source, dest in ((old.replace("\\", "\\\\"), new.replace("\\", "\\\\")), (old, new)):
                after = re.sub(
                    r"(?<![A-Za-z0-9_])" + re.escape(source) + r"(?![A-Za-z0-9_])",
                    lambda m: dest, after
                )
        if after != before:
            file.write_text(after)

for old, new in old_classes.items():
    for directory in scan_roots:
        base = ROOT / directory
        if not base.is_dir():
            continue
        for file in base.rglob("*.php"):
            data = file.read_text()
            if any(re.search(r"(?<![A-Za-z0-9_])" + re.escape(value) + r"(?![A-Za-z0-9_])", data)
                   for value in (old, old.replace("\\", "\\\\"))):
                raise RuntimeError(f"Legacy namespace {old} remains in {file}")
assert not PERSON_SOURCE.exists() and not LOCK_SOURCE.exists()
assert PERSON_TARGET.is_file() and LOCK_TARGET.is_file()
assert "PublicSubmissionLockSweeper" in LOCK_TARGET.read_text()
print("Person search maintenance now belongs to Persons, and cross-domain submission lock CLI is a Platform adapter.")
print("Old command classes deleted, exact CLI signatures retained, registration explicit and unique.")
