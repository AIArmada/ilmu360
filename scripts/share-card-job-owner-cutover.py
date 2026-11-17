#!/usr/bin/env python3
"""Move the real share-card queue job into Events; expose only true cross-boundaries."""
from pathlib import Path

ROOT=Path.cwd()
old_job=ROOT/"app/Jobs/RenderShareCardJob.php"
new_job=ROOT/"app/Features/Events/EntryPoints/Jobs/RenderShareCardJob.php"
shared=ROOT/"app/Shared/Events/EventShareCardPrewarm.php"
public_api=ROOT/"app/Features/Events/Api/ShareCardWarmScheduler.php"
action=ROOT/"app/Features/Events/Actions/ScheduleShareCardWarmAction.php"
for source in (old_job,):
    if not source.is_file():raise RuntimeError("Missing job source: "+str(source))
for target in (new_job,shared,public_api,action):
    if target.exists():raise RuntimeError("Destination already exists: "+str(target))

job=old_job.read_text()
job=job.replace("namespace App\\Jobs;","namespace App\\Features\\Events\\EntryPoints\\Jobs;",1)
job=job.replace(
    "use App\\Models\\Event;\nuse App\\Services\\ShareCards\\ShareCardService;",
    "use App\\Shared\\Events\\EventShareCardPrewarm;",
    1,
)
old_handle="""    public function handle(ShareCardService $shareCards): void
    {
        try {
            $event = Event::query()->whereKey($this->eventId)->first();

            if (! $event instanceof Event || ! $event->isPubliclyReachable()) {
                return;
            }

            $occurrence = $shareCards->resolveOccurrence($event, null);
            $locale = ShareCardService::DEFAULT_LOCALE;
            $displayTimezone = $shareCards->resolveDisplayTimezone($event);

            $shareCards->renderForRequest($event, $occurrence, $locale, $displayTimezone);
        } catch (Throwable $exception) {
"""
new_handle="""    public function handle(EventShareCardPrewarm $shareCards): void
    {
        try {
            $shareCards->prewarmEvent($this->eventId);
        } catch (Throwable $exception) {
"""
if old_handle not in job:raise RuntimeError("Job render body changed")
job=job.replace(old_handle,new_handle,1)
new_job.parent.mkdir(parents=True,exist_ok=True)
new_job.write_text(job)
old_job.unlink()

shared.write_text("""<?php

declare(strict_types=1);

namespace App\\Shared\\Events;

/** Root renderer integration used by the Events-owned queue adapter. */
interface EventShareCardPrewarm
{
    public function prewarmEvent(string $eventId): void;
}
""")
public_api.write_text("""<?php

declare(strict_types=1);

namespace App\\Features\\Events\\Api;

/** Cross-feature/public transport entrypoint that queues an Event share-card warm. */
interface ShareCardWarmScheduler
{
    public function prewarmForEvent(mixed $eventId): void;
}
""")
action.write_text("""<?php

declare(strict_types=1);

namespace App\\Features\\Events\\Actions;

use App\\Features\\Events\\Api\\ShareCardWarmScheduler;
use App\\Features\\Events\\EntryPoints\\Jobs\\RenderShareCardJob;

final readonly class ScheduleShareCardWarmAction implements ShareCardWarmScheduler
{
    public function prewarmForEvent(mixed $eventId): void
    {
        RenderShareCardJob::prewarmForEvent($eventId);
    }
}
""")

# Exact render behavior goes into the existing root rendering integration.
renderer=ROOT/"app/Services/ShareCards/ShareCardService.php"
render=renderer.read_text()
anchor="namespace App\\Services\\ShareCards;"
render=render.replace(anchor,anchor+"\n\nuse App\\Shared\\Events\\EventShareCardPrewarm;",1)
anchor="class ShareCardService"
if anchor not in render:raise RuntimeError("Render class not found")
# ShareCardService class is non-final with no implements at the start.
if "final readonly class ShareCardService\n{" not in render:raise RuntimeError("Render class declaration changed")
render=render.replace("final readonly class ShareCardService\n{","final readonly class ShareCardService implements EventShareCardPrewarm\n{",1)
needle="final readonly class ShareCardService implements EventShareCardPrewarm\n{"
warm="""
    /**
     * Pre-warm a public event card with the same locale, timezone, and
     * occurrence selection as the former queue job. Rendering errors remain
     * best-effort and are reported by the queue adapter.
     */
    public function prewarmEvent(string $eventId): void
    {
        $event = Event::query()->whereKey($eventId)->first();

        if (! $event instanceof Event || ! $event->isPubliclyReachable()) {
            return;
        }

        $occurrence = $this->resolveOccurrence($event, null);
        $locale = self::DEFAULT_LOCALE;
        $displayTimezone = $this->resolveDisplayTimezone($event);

        $this->renderForRequest($event, $occurrence, $locale, $displayTimezone);
    }

"""
render=render.replace(needle,needle+warm,1)
renderer.write_text(render)

# Bind root renderer as the external integration and new queue scheduler
# as a real feature public operation; never import feature-private job at root.
root_provider=ROOT/"app/Providers/AppServiceProvider.php"
root=root_provider.read_text()
needle="namespace App\\Providers;"
root=root.replace(needle,needle+"\n\nuse App\\Shared\\Events\\EventShareCardPrewarm;\nuse App\\Services\\ShareCards\\ShareCardService;",1)
anchor="        $this->app->bind(AccountDirectory::class, EloquentAccountDirectory::class);"
if anchor not in root:raise RuntimeError("Root provider binding insertion not found")
root=root.replace(anchor,anchor+"\n        $this->app->bind(EventShareCardPrewarm::class, ShareCardService::class);",1)
root_provider.write_text(root)

events_provider=ROOT/"app/Features/Events/FeatureServiceProvider.php"
events=events_provider.read_text()
anchor="namespace App\\Features\\Events;"
events=events.replace(anchor,anchor+"\n\nuse App\\Features\\Events\\Actions\\ScheduleShareCardWarmAction;\nuse App\\Features\\Events\\Api\\ShareCardWarmScheduler;",1)
anchor="    public function register(): void\n    {"
if anchor not in events:raise RuntimeError("Events register not found")
events=events.replace(anchor,anchor+"\n        $this->app->bind(ShareCardWarmScheduler::class, ScheduleShareCardWarmAction::class);",1)
events_provider.write_text(events)

# Every non-feature call through the former static job helper now uses the
# public scheduler; internal/job tests can import the relocated job directly.
old="App\\Jobs\\RenderShareCardJob"
new="App\\Features\\Events\\EntryPoints\\Jobs\\RenderShareCardJob"
roots=("app","resources","tests","bootstrap","config","routes","database","tools","docs","lang",".github")
for dirname in roots:
    directory=ROOT/dirname
    if not directory.is_dir():continue
    for p in directory.rglob("*"):
        if not p.is_file() or p.suffix not in (".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"):
            continue
        before=p.read_text()
        after=before.replace(old.replace("\\","\\\\"),new.replace("\\","\\\\")).replace(old,new)
        if after!=before:p.write_text(after)

# Any root consumer must talk to Events Api rather than import a private job.
for dirname in ("app","bootstrap","config","routes"):
    for p in (ROOT/dirname).rglob("*.php"):
        if p in (new_job,action): continue
        text=p.read_text()
        imported="use App\\Features\\Events\\EntryPoints\\Jobs\\RenderShareCardJob;"
        if imported in text:
            if "RenderShareCardJob::prewarmForEvent(" not in text:
                raise RuntimeError("Unsupported direct external queue call in "+str(p))
            text=text.replace(imported,"use App\\Features\\Events\\Api\\ShareCardWarmScheduler;")
            text=text.replace(
                "RenderShareCardJob::prewarmForEvent(",
                "app(ShareCardWarmScheduler::class)->prewarmForEvent(",
            )
            p.write_text(text)

if old_job.exists():raise RuntimeError("Old job remained")
for dirname in ("app","routes","bootstrap","config","tests"):
    for p in (ROOT/dirname).rglob("*.php"):
        text=p.read_text()
        if old in text or old.replace("\\","\\\\") in text:
            raise RuntimeError("Stale old queue class in "+str(p))
print("Moved real Events share-card job, preserved event rendering behavior, rewired root callers through public API, no alias/legacy job.")

if __name__=="__main__":
    pass
