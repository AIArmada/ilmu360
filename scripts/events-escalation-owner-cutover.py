#!/usr/bin/env python3
"""One-time Events escalation cutover with true action/storage ownership.

Moves the queued entrypoint and the exact duplicate-safe mutation into Events,
leaving only a real Laravel identity/mail transport in Platform.
"""
from pathlib import Path
import json

ROOT=Path.cwd()
src=ROOT/"app/Jobs/EscalatePendingEvents.php"
dst=ROOT/"app/Features/Events/EntryPoints/Jobs/EscalatePendingEvents.php"
action=ROOT/"app/Features/Events/Actions/Escalations/RecordPendingEventEscalationAction.php"
contract=ROOT/"app/Shared/Events/EventEscalationTransport.php"
transport=ROOT/"app/Platform/Notifications/EloquentEventEscalationTransport.php"
for p in (src,):
    if not p.is_file():raise RuntimeError("Missing escalation source")
for p in (dst,action,contract,transport):
    if p.exists():raise RuntimeError("Escalation destination occupied: "+str(p))
old=src.read_text()
for guard in ("User::role('moderator')->get()", "EventEscalation::query()->insertOrIgnore(", "EventEscalationNotification", "->withoutOverlapping()"):
    if guard=="->withoutOverlapping()":continue
    if guard not in old:raise RuntimeError("Original business behavior has changed: "+guard)

for p in (dst,action,contract,transport):p.parent.mkdir(parents=True,exist_ok=True)

contract.write_text("""<?php

declare(strict_types=1);

namespace App\\Shared\\Events;

use Illuminate\\Contracts\\Auth\\Authenticatable;
use Illuminate\\Support\\Collection;

/**
 * Integration across the Events escalation workflow and Laravel's
 * globally-scoped role directory and queued mail-notifiable accounts.
 */
interface EventEscalationTransport
{
    /** @return Collection<int, Authenticatable> */
    public function forRole(string $role): Collection;

    /** @param Collection<int, Authenticatable> $recipients */
    public function notify(string $eventId, string $notificationType, Collection $recipients): void;
}
""")
transport.write_text("""<?php

declare(strict_types=1);

namespace App\\Platform\\Notifications;

use App\\Models\\Event;
use App\\Models\\User;
use App\\Notifications\\EventEscalationNotification;
use App\\Shared\\Events\\EventEscalationTransport;
use Illuminate\\Contracts\\Auth\\Authenticatable;
use Illuminate\\Support\\Collection;

final readonly class EloquentEventEscalationTransport implements EventEscalationTransport
{
    /** @return Collection<int, \\Illuminate\\Contracts\\Auth\\Authenticatable> */
    public function forRole(string $role): Collection
    {
        /** @var Collection<int, Authenticatable> $recipients */
        $recipients = User::role($role)->get();

        return $recipients;
    }

    public function notify(string $eventId, string $notificationType, Collection $recipients): void
    {
        /** @var Event $event */
        $event = Event::query()->findOrFail($eventId);

        foreach ($recipients as $recipient) {
            if ($recipient instanceof User) {
                $recipient->notify(new EventEscalationNotification($event, $notificationType));
            }
        }
    }
}
""")
action.write_text("""<?php

declare(strict_types=1);

namespace App\\Features\\Events\\Actions\\Escalations;

use AIArmada\\Events\\Enums\\EventEscalationType;
use AIArmada\\Events\\Models\\EventEscalation;
use App\\Models\\Event;
use Carbon\\CarbonImmutable;
use Illuminate\\Support\\Str;

/** The named, idempotent Events writer for pending-moderation escalation. */
final readonly class RecordPendingEventEscalationAction
{
    public function record(Event $event, EventEscalationType $type, string $reason, CarbonImmutable $now): ?EventEscalation
    {
        $decisionKey = $event->id.':'.$type->value;

        $created = EventEscalation::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'type' => $type->value,
            'decision_key' => $decisionKey,
            'reason' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($created === 0) {
            return null;
        }

        return EventEscalation::query()->where('decision_key', $decisionKey)->firstOrFail();
    }

    public function markDispatched(EventEscalation $escalation, CarbonImmutable $now): void
    {
        $escalation->update(['dispatched_at' => $now]);
    }
}
""")

dst.write_text("""<?php

declare(strict_types=1);

namespace App\\Features\\Events\\EntryPoints\\Jobs;

use AIArmada\\Events\\Enums\\EventEscalationType;
use App\\Features\\Events\\Actions\\Escalations\\RecordPendingEventEscalationAction;
use App\\Models\\Event;
use App\\Shared\\Events\\EventEscalationTransport;
use Carbon\\CarbonImmutable;
use Illuminate\\Contracts\\Auth\\Authenticatable;
use Illuminate\\Contracts\\Queue\\ShouldQueue;
use Illuminate\\Database\\Eloquent\\Builder;
use Illuminate\\Foundation\\Queue\\Queueable;
use Illuminate\\Support\\Collection;
use Illuminate\\Support\\Facades\\Log;

class EscalatePendingEvents implements ShouldQueue
{
    use Queueable;

    public function handle(EventEscalationTransport $transport, RecordPendingEventEscalationAction $records): void
    {
        $now = now()->toImmutable();
        $moderators = null;
        $superAdmins = null;

        Event::query()
            ->where('status', 'pending')
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '>', $now);
            })
            ->chunkById(100, function (Collection $events) use ($now, &$moderators, &$superAdmins, $transport, $records): void {
                foreach ($events as $event) {
                    $this->processEvent($event, $now, $moderators, $superAdmins, $transport, $records);
                }
            });
    }

    /**
     * @param Collection<int, Authenticatable>|null $moderators
     * @param Collection<int, Authenticatable>|null $superAdmins
     */
    private function processEvent(
        Event $event,
        CarbonImmutable $now,
        ?Collection &$moderators,
        ?Collection &$superAdmins,
        EventEscalationTransport $transport,
        RecordPendingEventEscalationAction $records,
    ): void {
        if ($event->created_at?->lte($now->subHours(48))) {
            $moderators ??= $transport->forRole('moderator');
            $this->escalate($event, EventEscalationType::ModeratorSla, 'Pending moderation reached the 48-hour SLA.', '48_hours', $moderators, $now, $transport, $records);
        }

        if (
            $event->created_at?->lte($now->subHours(72))
            && $this->moderatorEscalationReached($event, $now)
        ) {
            $superAdmins ??= $transport->forRole('super_admin');
            $this->escalate($event, EventEscalationType::SuperAdminSla, 'Pending moderation reached the 72-hour SLA.', '72_hours', $superAdmins, $now, $transport, $records);
        }

        if ($event->starts_at?->gt($now) && $event->starts_at->lte($now->addHours(24)) && $event->starts_at->gt($now->addHours(6))) {
            $moderators ??= $transport->forRole('moderator');
            $this->escalate($event, EventEscalationType::Imminent, 'Pending event starts within 24 hours.', 'urgent', $moderators, $now, $transport, $records);
        }

        if ($event->starts_at?->gt($now) && $event->starts_at->lte($now->addHours(6))) {
            $moderators ??= $transport->forRole('moderator');
            $superAdmins ??= $transport->forRole('super_admin');
            $this->escalate($event, EventEscalationType::Priority, 'Pending event starts within 6 hours.', 'priority', $moderators->merge($superAdmins), $now, $transport, $records);
        }
    }

    private function moderatorEscalationReached(Event $event, CarbonImmutable $now): bool
    {
        return $event->escalations()
            ->where('type', EventEscalationType::ModeratorSla->value)
            ->whereNull('resolved_at')
            ->where('created_at', '<=', $now->subHours(24))
            ->exists();
    }

    /** @param Collection<int, Authenticatable> $recipients */
    private function escalate(
        Event $event,
        EventEscalationType $type,
        string $reason,
        string $notificationType,
        Collection $recipients,
        CarbonImmutable $now,
        EventEscalationTransport $transport,
        RecordPendingEventEscalationAction $records,
    ): void {
        $escalation = $records->record($event, $type, $reason, $now);

        if ($escalation === null) {
            return;
        }

        Log::info('Event escalation recorded.', [
            'event_id' => $event->id,
            'type' => $type->value,
        ]);

        $transport->notify((string) $event->getKey(), $notificationType, $recipients);

        if ($recipients->isNotEmpty()) {
            $records->markDispatched($escalation, $now);
        }
    }
}
""")
src.unlink()

# Claim exactly the escalation resource for this named writer. Preserve all
# other resource ownership entries and original model/schema behavior.
manifest=ROOT/"app/Features/Events/feature.json"
obj=json.loads(manifest.read_text())
owner="App\\Features\\Events\\Actions\\Escalations\\RecordPendingEventEscalationAction"
key="table:event_escalations"
if key in obj["owns"]:raise RuntimeError("Escalations already claimed")
obj["owns"][key]=[owner]
manifest.write_text(json.dumps(obj,ensure_ascii=False,indent=2)+"\n")

root_provider=ROOT/"app/Providers/AppServiceProvider.php"
content=root_provider.read_text()
anchor="namespace App\\Providers;"
content=content.replace(anchor,anchor+"\n\nuse App\\Platform\\Notifications\\EloquentEventEscalationTransport;\nuse App\\Shared\\Events\\EventEscalationTransport;",1)
anchor="        $this->app->bind(AccountDirectory::class, EloquentAccountDirectory::class);"
if anchor not in content:raise RuntimeError("Root provider insertion missing")
content=content.replace(anchor,anchor+"\n        $this->app->bind(EventEscalationTransport::class, EloquentEventEscalationTransport::class);",1)
root_provider.write_text(content)

routes=ROOT/"routes/console.php"
content=routes.read_text()
schedule="""// SLA Escalation (per documentation B4a and B6b)
// Runs hourly to check for pending events needing escalation
Schedule::job(new EscalatePendingEvents)
    ->hourly()
    ->timezone('Asia/Kuala_Lumpur')
    ->name('escalate-pending-events')
    ->withoutOverlapping();

"""
if schedule not in content:raise RuntimeError("Root escalation schedule changed")
content=content.replace(schedule,"",1)
content=content.replace("use App\\Jobs\\EscalatePendingEvents;\n","",1)
routes.write_text(content)

feature_provider=ROOT/"app/Features/Events/FeatureServiceProvider.php"
content=feature_provider.read_text()
anchor="namespace App\\Features\\Events;"
content=content.replace(anchor,anchor+"\n\nuse App\\Features\\Events\\EntryPoints\\Jobs\\EscalatePendingEvents;",1)
anchor="    public function boot(): void\n    {\n        parent::boot();"
if anchor not in content:raise RuntimeError("Feature provider boot missing")
content=content.replace(anchor,anchor+"""

        if ($this->app->runningInConsole()) {
            Schedule::job(new EscalatePendingEvents)
                ->hourly()
                ->timezone('Asia/Kuala_Lumpur')
                ->name('escalate-pending-events')
                ->withoutOverlapping();
        }
""",1)
feature_provider.write_text(content)

oldcls="App\\Jobs\\EscalatePendingEvents"
newcls="App\\Features\\Events\\EntryPoints\\Jobs\\EscalatePendingEvents"
for dirname in ("app","resources","tests","bootstrap","config","routes","database","tools","docs","lang",".github"):
    d=ROOT/dirname
    if not d.is_dir():continue
    for p in d.rglob("*"):
        if not p.is_file() or p.suffix not in (".php",".json",".md",".yml",".yaml",".xml",".neon",".js",".ts",".txt"):continue
        before=p.read_text()
        after=before.replace(oldcls.replace("\\","\\\\"),newcls.replace("\\","\\\\")).replace(oldcls,newcls)
        if before!=after:p.write_text(after)

# Feature-private queue class must never be imported by a root component.
for dirname in ("app","bootstrap","config","routes"):
    for p in (ROOT/dirname).rglob("*.php"):
        if p==dst:continue
        text=p.read_text()
        if "App\\Features\\Events\\EntryPoints\\Jobs\\EscalatePendingEvents" in text and p!=feature_provider:
            raise RuntimeError("Private escalation job leaked into "+str(p))
        if oldcls in text:raise RuntimeError("Legacy EscalatePendingEvents FQCN survives "+str(p))

if src.exists() or "EscalatePendingEvents" in routes.read_text():
    raise RuntimeError("Root escalation job or duplicate schedule survives")
print("Moved the final root job, extracted the named escalation writer, preserved hourly Asia/Kuala_Lumpur schedule and queued mail semantics.")
