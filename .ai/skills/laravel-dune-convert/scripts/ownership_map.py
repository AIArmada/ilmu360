#!/usr/bin/env python3
"""Read-only durable resource ownership mapper for Laravel migration planning.

Scans migrations, Eloquent models, and write call sites under app/ to produce a draft
table/store -> writer map showing scattered writes across controllers, jobs, observers, and services.
"""
import argparse
import json
import re
import sys
from collections import defaultdict
from pathlib import Path


SCHEMA_CREATE_RE = re.compile(r"Schema::create\s*\(\s*['\"]([a-zA-Z0-9_]+)['\"]")
MODEL_CLASS_RE = re.compile(r"class\s+([A-Z][A-Za-z0-9_]*)\s+extends\s+(?:[\\A-Za-z0-9_]*Model|Authenticatable|Pivot)\b")
TABLE_PROP_RE = re.compile(r"\$table\s*=\s*['\"]([a-zA-Z0-9_]+)['\"]")
DB_TABLE_WRITE_RE = re.compile(
    r"DB::table\s*\(\s*['\"]([a-zA-Z0-9_]+)['\"]\s*\)[^;]{0,200}?\b(insert|insertGetId|insertOrIgnore|upsert|update|updateOrInsert|delete|truncate|increment|decrement)\s*\(",
    re.S,
)
STORE_WRITE_RE = re.compile(
    r"(?:Storage::disk|Cache::store|Redis::connection)\s*\(\s*['\"]([a-zA-Z0-9_.-]+)['\"]\s*\)[^;]{0,160}?\b(put|putFile|putFileAs|write|delete|forget|forever|set|hset|del)\s*\(",
    re.S,
)
STATIC_MODEL_WRITE_RE = re.compile(
    r"\b([A-Z][A-Za-z0-9_]*)::(?:query\(\)->)?(?:where\([^)]*\)->)?(create|forceCreate|updateOrCreate|firstOrCreate|upsert|insert|destroy|whereKey\([^)]*\)->update)\s*\("
)


def guess_table_name(model_name: str) -> str:
    snake = re.sub(r"(?<!^)(?=[A-Z])", "_", model_name).lower()
    if snake.endswith("y") and not snake.endswith(("ay", "ey", "iy", "oy", "uy")):
        return snake[:-1] + "ies"
    if snake.endswith(("s", "x", "z", "ch", "sh")):
        return snake + "es"
    return snake + "s"


def map_ownership(root: Path) -> dict:
    tables_from_migrations = set()
    mig_dir = root / "database" / "migrations"
    if mig_dir.is_dir():
        for mf in sorted(mig_dir.rglob("*.php")):
            text = mf.read_text(encoding="utf-8", errors="replace")
            tables_from_migrations.update(SCHEMA_CREATE_RE.findall(text))

    models = {}
    table_to_models = defaultdict(list)
    app_dir = root / "app"
    if app_dir.is_dir():
        for pf in sorted(app_dir.rglob("*.php")):
            if pf.is_symlink():
                continue
            rel = pf.relative_to(root).as_posix()
            text = pf.read_text(encoding="utf-8", errors="replace")
            m = MODEL_CLASS_RE.search(text)
            if m:
                cls = m.group(1)
                tm = TABLE_PROP_RE.search(text)
                table = tm.group(1) if tm else guess_table_name(cls)
                models[cls] = {"file": rel, "table": table, "explicit_table": bool(tm)}
                table_to_models[table].append(rel)

    writes_by_resource = defaultdict(set)
    if app_dir.is_dir():
        for pf in sorted(app_dir.rglob("*.php")):
            if pf.is_symlink():
                continue
            rel = pf.relative_to(root).as_posix()
            text = pf.read_text(encoding="utf-8", errors="replace")

            for tbl, _method in DB_TABLE_WRITE_RE.findall(text):
                writes_by_resource[f"table:{tbl}"].add(rel)

            for store, _method in STORE_WRITE_RE.findall(text):
                writes_by_resource[f"store:{store}"].add(rel)

            for cls, _method in STATIC_MODEL_WRITE_RE.findall(text):
                if cls in models:
                    writes_by_resource[f"table:{models[cls]['table']}"].add(rel)

    all_tables = sorted(tables_from_migrations | set(table_to_models.keys()))
    resources = {}
    for tbl in all_tables:
        key = f"table:{tbl}"
        resources[key] = {
            "models": sorted(table_to_models.get(tbl, [])),
            "detected_write_sites": sorted(writes_by_resource.get(key, set())),
            "in_migrations": tbl in tables_from_migrations,
        }

    for key, sites in sorted(writes_by_resource.items()):
        if key not in resources:
            resources[key] = {
                "models": [],
                "detected_write_sites": sorted(sites),
                "in_migrations": False,
            }

    return {
        "schema": 1,
        "summary": {
            "tables_detected": len(all_tables),
            "models_detected": len(models),
            "models_missing_explicit_table": sum(1 for m in models.values() if not m["explicit_table"]),
            "resources_with_multiple_writers": sum(1 for r in resources.values() if len(r["detected_write_sites"]) > 1),
        },
        "resources": resources,
        "models": models,
    }


def render_markdown(data: dict) -> str:
    s = data["summary"]
    lines = [
        "# Draft Durable Ownership Map",
        "",
        f"- Tables detected: **{s['tables_detected']}**",
        f"- Eloquent models detected: **{s['models_detected']}** ({s['models_missing_explicit_table']} missing explicit `$table`)",
        f"- Resources with scattered write sites (>1 file): **{s['resources_with_multiple_writers']}**",
        "",
        "| Resource | Models | Detected Write Sites | Suggested Owner Feature |",
        "| --- | --- | --- | --- |",
    ]
    for res, info in data["resources"].items():
        mods = ", ".join(f"`{m}`" for m in info["models"]) or "—"
        sites = ", ".join(f"`{w}`" for w in info["detected_write_sites"][:5])
        if len(info["detected_write_sites"]) > 5:
            sites += f" (+{len(info['detected_write_sites']) - 5} more)"
        sites = sites or "*(verify via Boost schema / grep)*"
        lines.append(f"| `{res}` | {mods} | {sites} | *TBD* |")
    return "\n".join(lines) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", required=True)
    parser.add_argument("--format", choices=["json", "markdown"], default="json")
    args = parser.parse_args()
    result = map_ownership(Path(args.root).resolve())
    if args.format == "markdown":
        print(render_markdown(result))
    else:
        print(json.dumps(result, indent=2))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(2)
