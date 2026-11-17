#!/usr/bin/env python3
"""One-time Events reminder job migration, preserving reminder schedules and contracts."""
from pathlib import Path

ROOT=Path.cwd()
src=ROOT/"app/Jobs/DispatchEventReminderNotifications.php"
dst=ROOT/"app/Features/Events/EntryPoints/Jobs/DispatchEventReminderNotifications.php"
contract=ROOT/"app/Shared/Events/EventReminderNotificationDispatch.php"
if not src.is_file() or dst.exists() or contract.exists():
    raise RuntimeError("Unexpected ownership state; cutover is not safe")

text=src.read_text()
text=text.replace("namespace App\\Jobs;","namespace App\\Features\\Events\\EntryPoints\\Jobs;",1)
text=text.replace(
    "use App\\Services\\Notifications\\EventNotificationService;",
    "use App\\Shared\\Events\\EventReminderNotificationDispatch;",
    1,
)
text=text.replace(
    "handle(EventNotificationService $notificationService): void",
    "handle(EventReminderNotificationDispatch $notificationService): void",
    1,
)
dst.parent.mkdir(parents=True,exist_ok=True)
dst.write_text(text)
src.unlink()

contract.write_text("""<?php

declare(strict_types=1);

namespace App\\Shared\\Events;

use Carbon\\CarbonImmutable;

/**
 * Integration seam for the Events-owned reminder queue entrypoint.
 * The existing multi-domain notification service implements this contract.
 */
interface EventReminderNotificationDispatch
{
    public function dispatchDueReminderNotifications(?CarbonImmutable $now = null): void;
}
""")

service=ROOT/"app/Services/Notifications/EventNotificationService.php"
content=service.read_text()
needle="namespace App\\Services\\Notifications;"
if needle not in content:raise RuntimeError("Notification service namespace missing")
content=content.replace(needle,needle+"\n\nuse App\\Shared\\Events\\EventReminderNotificationDispatch;",1)
needle="class EventNotificationService\n{"
if needle not in content:raise RuntimeError("Notification class shape changed")
content=content.replace(needle,"class EventNotificationService implements EventReminderNotificationDispatch\n{",1)
if "public function dispatchDueReminderNotifications(?CarbonImmutable $now = null): void" not in content:
    raise RuntimeError("Reminder notification behavior signature changed")
service.write_text(content)

provider=ROOT/"app/Providers/AppServiceProvider.php"
content=provider.read_text()
needle="namespace App\\Providers;"
content=content.replace(needle,needle+"\n\nuse App\\Shared\\Events\\EventReminderNotificationDispatch;\nuse App\\Services\\Notifications\\EventNotificationService;",1)
needle="        $this->app->bind(AccountDirectory::class, EloquentAccountDirectory::class);"
if needle not in content:raise RuntimeError("Root binding insertion point missing")
content=content.replace(needle,needle+"\n        $this->app->bind(EventReminderNotificationDispatch::class, EventNotificationService::class);",1)
provider.write_text(content)

# Relocate job scheduling to owning Events module, preserving exact cadence,
# timezone, event name, and mutex behavior. No double schedule registration.
routes=ROOT/"routes/console.php"
content=routes.read_text()
scheduled="""// Notification reminders (2-hour and check-in windows)
Schedule::job(new DispatchEventReminderNotifications)
    ->everyFifteenMinutes()
    ->timezone('UTC')
    ->name('notification-reminders')
    ->withoutOverlapping();

"""
if scheduled not in content:raise RuntimeError("Root schedule block missing")
content=content.replace(scheduled,"",1)
content=content.replace("use App\\Jobs\\DispatchEventReminderNotifications;\n","",1)
routes.write_text(content)

events=ROOT/"app/Features/Events/FeatureServiceProvider.php"
content=events.read_text()
needle="namespace App\\Features\\Events;"
content=content.replace(needle,needle+"\n\nuse App\\Features\\Events\\EntryPoints\\Jobs\\DispatchEventReminderNotifications;\nuse Illuminate\\Support\\Facades\\Schedule;",1)
needle="    public function boot(): void\n    {\n        parent::boot();"
if needle not in content:raise RuntimeError("Events boot() insertion point changed")
content=content.replace(needle,needle+"""

        if ($this->app->runningInConsole()) {
            Schedule::job(new DispatchEventReminderNotifications)
                ->everyFifteenMinutes()
                ->timezone('UTC')
                ->name('notification-reminders')
                ->withoutOverlapping();
        }
""",1)
events.write_text(content)

# Remove stale class-string references without retaining a delegating job.
old="App\\Jobs\\DispatchEventReminderNotifications"
new="App\\Features\\Events\\EntryPoints\\Jobs\\DispatchEventReminderNotifications"
dirs=("app","bootstrap","config","database","resources","routes","tests","tools","docs","lang",".github")
for dirname in dirs:
    d=ROOT/dirname
    if not d.is_dir():continue
    for path in d.rglob("*"):
        if not path.is_file() or path.suffix not in (".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"):
            continue
        before=path.read_text()
        after=before.replace(old.replace("\\","\\\\"),new.replace("\\","\\\\")).replace(old,new)
        if after!=before:path.write_text(after)
if src.exists():raise RuntimeError("Old reminder job implementation survived")
if "App\\Services\\" in dst.read_text() or "App\\Jobs\\" in dst.read_text():
    raise RuntimeError("New Events job imports old root implementation")
alltext="\n".join(p.read_text() for d in dirs if (ROOT/d).is_dir() for p in (ROOT/d).rglob("*.php"))
if old in alltext:raise RuntimeError("Old job class name still exists")
if "DispatchEventReminderNotifications" in routes.read_text():
    raise RuntimeError("Old schedule or root import still exists")
print("Events reminder job rehomed; single schedule moved to Events; shared dispatch contract is bound. No old job class or schedule left.")

if __name__=="__main__":
    main()
