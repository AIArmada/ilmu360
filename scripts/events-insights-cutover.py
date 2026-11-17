#!/usr/bin/env python3
"""One-time Events product signals report cutover, with a real public Api boundary."""
from pathlib import Path

ROOT=Path.cwd()
old="app/Services/Signals/ProductSignalsInsightsService.php"
new="app/Features/Events/Services/ProductSignalsInsightsService.php"
api="app/Features/Events/Api/ProductSignalsInsights.php"
page="app/Filament/Pages/ProductSignals.php"
provider="app/Features/Events/FeatureServiceProvider.php"
source=ROOT/old
dest=ROOT/new
target=ROOT/api
if not source.is_file() or dest.exists() or target.exists():
    raise RuntimeError("Cutover sources/destinations in unexpected state")

text=source.read_text()
start=text.index("/**\n * Read-side reporting")
end=text.index("\nfinal readonly class ProductSignalsInsightsService")
doc=text[start:end].replace(
    "Read-side reporting for the Events dashboard scorecard. This belongs to the\n * Events feature, not Platform\\Telemetry, and deliberately stays here until the\n * Events migration owns the scorecard reads.",
    "Public scalar report response shape for the Events product-signals dashboard."
)
if "@phpstan-type ProductSignalsDashboard" not in doc:
    raise RuntimeError("Missing required dashboard type alias")
interface="<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Features\\Events\\Api;\n\n"+doc+"\ninterface ProductSignalsInsights\n{\n    /** @return ProductSignalsDashboard */\n    public function dashboard(int $days = 30, int $limit = 5000): array;\n}\n"
target.write_text(interface)

text=text.replace(
    "namespace App\\Services\\Signals;",
    "namespace App\\Features\\Events\\Services;\n\nuse App\\Features\\Events\\Api\\ProductSignalsInsights;",
    1,
)
text=text.replace(
    "final readonly class ProductSignalsInsightsService\n{",
    "final readonly class ProductSignalsInsightsService implements ProductSignalsInsights\n{",
    1,
)
if "implements ProductSignalsInsights" not in text:
    raise RuntimeError("Failed to implement new public Api")
dest.parent.mkdir(parents=True,exist_ok=True)
dest.write_text(text)
source.unlink()

# The app's audited subclass inherits its table name from the package;
# declare the literal owned table so the feature's model dependency is
# provably Events-owned, not a broad exception to the boundary checker.
submission_model = ROOT / "app/Models/EventSubmission.php"
model_source = submission_model.read_text()
model_needle = "    use AuditsModelChanges, HasContactMethods;"
if model_needle not in model_source or "protected $table" in model_source:
    raise RuntimeError("Unexpected EventSubmission model ownership declaration")
submission_model.write_text(model_source.replace(
    model_needle,
    model_needle + "\n\n    protected $table = 'event_submissions';",
    1,
))

f=ROOT/page
current=f.read_text()
if "use App\\Services\\Signals\\ProductSignalsInsightsService;" not in current:
    raise RuntimeError("Dashboard caller changed")
current=current.replace(
    "use App\\Services\\Signals\\ProductSignalsInsightsService;",
    "use App\\Features\\Events\\Api\\ProductSignalsInsights;",
)
current=current.replace(
    "ProductSignalsInsightsService $insights",
    "ProductSignalsInsights $insights",
)
current=current.replace(
    "@phpstan-import-type ProductSignalsDashboard from \\App\\Services\\Signals\\ProductSignalsInsightsService",
    "@phpstan-import-type ProductSignalsDashboard from \\App\\Features\\Events\\Api\\ProductSignalsInsights",
)
f.write_text(current)

f=ROOT/provider
current=f.read_text()
needle="namespace App\\Features\\Events;"
if needle not in current: raise RuntimeError("Events provider namespace missing")
current=current.replace(
    needle,
    needle+"\n\nuse App\\Features\\Events\\Api\\ProductSignalsInsights;\nuse App\\Features\\Events\\Services\\ProductSignalsInsightsService;",
    1,
)
needle="public function register(): void\n    {"
if needle not in current: raise RuntimeError("Events provider bind insertion missing")
current=current.replace(
    needle,
    needle+"\n        $this->app->bind(ProductSignalsInsights::class, ProductSignalsInsightsService::class);",
    1,
)
f.write_text(current)

previous="App\\Services\\Signals\\ProductSignalsInsightsService"
for prefix in ("app","tests","bootstrap","config","resources","routes","tools","docs"):
    d=ROOT/prefix
    if not d.is_dir():continue
    for f in d.rglob("*"):
        if not f.is_file() or f.suffix not in (".php",".json",".md",".yml",".yaml",".xml",".neon",".blade"):
            continue
        old_text=f.read_text()
        if previous in old_text or previous.replace("\\","\\\\") in old_text:
            raise RuntimeError(f"Stale old report service namespace: {f}")
if source.exists():
    raise RuntimeError("Legacy report service left behind")
print("Moved Events reporting service behind feature-owned ProductSignalsInsights interface with preserved PHPStan report shape.")
