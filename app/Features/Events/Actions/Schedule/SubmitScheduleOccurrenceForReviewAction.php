<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Schedule;

use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventOccurrence;
use App\Features\Events\Models\Event;
use App\Features\Events\Support\EventModel;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class SubmitScheduleOccurrenceForReviewAction
{
    public function __construct(private SaveOccurrenceAction $saveOccurrence) {}

    /**
     * @param  array{title: string, starts_at: string, ends_at: string|null, timezone: string, capacity: int|null}  $attributes
     */
    public function handle(string $eventId, User $actor, array $attributes, ?string $note): EventOccurrence
    {
        return DB::transaction(function () use ($eventId, $actor, $attributes, $note): EventOccurrence {
            $event = Event::query()->lockForUpdate()->findOrFail($eventId);

            Gate::forUser($actor)->authorize('update', $event);
            abort_unless((string) $event->status === 'approved' && $actor->canSubmitDirectoryFeedback(), 403);

            $visibility = $event->visibility instanceof BackedEnum ? $event->visibility->value : $event->visibility;
            $occurrence = $this->saveOccurrence->handle($event, [
                ...$attributes,
                'slug' => Str::limit(Str::slug($attributes['title']), 220, '').'-'.Str::lower((string) Str::ulid()),
                'status' => EventOccurrence::DRAFT,
                'visibility' => PackageEvent::PRIVATE,
                'delivery_mode' => (string) ($event->delivery_mode ?: PackageEvent::DELIVERY_PHYSICAL),
                'metadata' => ['public_submission' => ['requested_visibility' => $visibility]],
            ]);

            $this->record($event, $occurrence, $actor, $note);

            return $occurrence;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function proposeChange(
        string $eventId,
        User $actor,
        string $occurrenceId,
        bool $isSession,
        ?string $sessionId,
        array $attributes,
        ?string $note,
    ): void {
        DB::transaction(function () use ($eventId, $actor, $occurrenceId, $isSession, $sessionId, $attributes, $note): void {
            $event = Event::query()->lockForUpdate()->findOrFail($eventId);

            Gate::forUser($actor)->authorize('update', $event);
            abort_unless((string) $event->status === 'approved' && $actor->canSubmitDirectoryFeedback(), 403);

            $occurrence = $event->occurrences()->where('status', EventOccurrence::SCHEDULED)
                ->whereKey($occurrenceId)->lockForUpdate()->firstOrFail();
            $session = $sessionId === null ? null : $occurrence->sessions()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $keys = ['title', 'starts_at', 'ends_at', 'timezone', 'capacity'];

            if ($isSession) {
                $keys = [...$keys, 'summary', 'description'];
            }

            $target = $isSession ? $session : $occurrence;

            $this->record($event, $occurrence, $actor, $note, [
                'kind' => $isSession ? 'session' : 'occurrence',
                'session_id' => $sessionId,
                'values' => Arr::only($attributes, $keys),
                'original' => $target === null ? null : Arr::only($target->getRawOriginal(), $keys),
            ]);
        });
    }

    /** @param array<string, mixed>|null $change */
    private function record(Event $event, EventOccurrence $occurrence, User $actor, ?string $note, ?array $change = null): void
    {
        $data = [
            'submitter_name' => $actor->name,
            'occurrence_title' => $occurrence->title,
            'notes' => $note,
        ];

        if ($change !== null) {
            $data['schedule_change'] = $change;
        }

        EventModel::query('event_submission')->create([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => $occurrence->getKey(),
            'status' => 'pending',
            'submitted_at' => now(),
            'submission_data' => $data,
            'submitter_type' => $actor->getMorphClass(),
            'submitter_id' => $actor->getKey(),
        ]);
    }
}
