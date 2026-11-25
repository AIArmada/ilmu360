<?php

namespace App\Features\Events\States\EventStatus\Transitions;

use App\Features\Events\Models\Event;
use App\Features\Events\Notifications\EventNotificationService;
use App\Features\Events\Persistence\EventStatusWriter;
use App\Features\Events\States\EventStatus\Pending;
use App\Features\Moderation\Api\RecordModerationAction;
use App\Models\User;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\ModelStates\Transition;
use Throwable;

class ReconsiderEvent extends Transition implements HasColor, HasIcon, HasLabel
{
    public function __construct(
        public Event $event,
        public ?User $moderator = null,
        public ?string $note = null
    ) {}

    public function handle(): Event
    {
        return DB::transaction(function () {
            app(RecordModerationAction::class)->record([
                'actionable_type' => $this->event->getMorphClass(),
                'actionable_id' => $this->event->id,
                'actioned_by_type' => (new User)->getMorphClass(),
                'actioned_by_id' => $this->moderator?->id,
                'type' => 'reconsidered',
                'reason' => 'reconsidered',
                'notes' => $this->note ?? 'Event moved back to pending for reconsideration.',
            ]);

            app(EventStatusWriter::class)->apply($this->event, Pending::class, [
                'last_state_change_at' => now(),
            ]);

            $event = $this->event;
            $note = $this->note;
            $moderatorId = $this->moderator?->id;

            // External effects only run once the surrounding transaction commits,
            // so a rolled-back reconsideration never notifies or logs.
            DB::afterCommit(function () use ($event, $note, $moderatorId): void {
                try {
                    app(EventNotificationService::class)->notifySubmissionRemoderated($event, $note);
                } catch (Throwable $exception) {
                    Log::warning('Re-moderation notification failed after reconsideration.', [
                        'event_id' => (string) $event->getKey(),
                        'exception' => $exception,
                    ]);
                }

                Log::info('Rejected event reconsidered', [
                    'event_id' => $event->id,
                    'moderator_id' => $moderatorId,
                ]);
            });

            return $this->event;
        });
    }

    public function getLabel(): string
    {
        return __('Reconsider');
    }

    public function getColor(): string|array
    {
        return Color::Amber;
    }

    public function getIcon(): string
    {
        return 'heroicon-o-arrow-path';
    }
}
