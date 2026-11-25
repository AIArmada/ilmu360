<?php

namespace App\Features\Events\States\EventStatus\Transitions;

use App\Features\Events\Models\Event;
use App\Features\Events\Notifications\EventNotificationService;
use App\Features\Events\Persistence\EventStatusWriter;
use App\Features\Events\States\EventStatus\Cancelled;
use App\Features\Moderation\Api\RecordModerationAction;
use App\Features\ShareTracking\Api\ParticipationOutcomeReversals;
use App\Models\User;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Spatie\ModelStates\Transition;
use Throwable;

class CancelEvent extends Transition implements HasColor, HasIcon, HasLabel
{
    public function __construct(
        public Event $event,
        public ?User $moderator = null,
        public ?string $note = null
    ) {}

    #[\Override]
    public function canTransition(): bool
    {
        return $this->moderator instanceof User;
    }

    public function handle(): Event
    {
        $this->assertTransitionContext();

        /** @var User $moderator */
        $moderator = $this->moderator;

        return DB::transaction(function () use ($moderator): Event {
            app(RecordModerationAction::class)->record([
                'actionable_type' => $this->event->getMorphClass(),
                'actionable_id' => $this->event->id,
                'actioned_by_type' => (new User)->getMorphClass(),
                'actioned_by_id' => $moderator->id,
                'type' => 'cancelled',
                'reason' => 'cancelled',
                'notes' => $this->note,
            ]);

            app(EventStatusWriter::class)->apply($this->event, Cancelled::class, [
                'cancelled_at' => now(),
                'last_state_change_at' => now(),
            ]);
            $this->event->resolveEscalations();
            app(ParticipationOutcomeReversals::class)->reverse('event', (string) $this->event->getKey(), (string) $moderator->getKey(), 'event_cancelled');

            $event = $this->event;
            $note = $this->note;
            $moderatorId = $moderator->id;

            // External effects only run once the surrounding transaction commits,
            // so a rolled-back cancellation never indexes, notifies, or logs. Each
            // effect is contained individually so one failure cannot mask others.
            DB::afterCommit(function () use ($event, $note, $moderatorId): void {
                try {
                    // Cancelled events remain searchable so users can still discover status updates.
                    $event->searchable();
                } catch (Throwable $exception) {
                    Log::warning('Search index update failed after cancellation.', [
                        'event_id' => (string) $event->getKey(),
                        'exception' => $exception,
                    ]);
                }

                try {
                    app(EventNotificationService::class)->notifySubmissionCancelled($event, $note);
                } catch (Throwable $exception) {
                    Log::warning('Submission-cancelled notification failed after cancellation.', [
                        'event_id' => (string) $event->getKey(),
                        'exception' => $exception,
                    ]);
                }

                try {
                    app(EventNotificationService::class)->notifyTrackedEventCancelled($event, $note);
                } catch (Throwable $exception) {
                    Log::warning('Tracked-event cancellation notification failed.', [
                        'event_id' => (string) $event->getKey(),
                        'exception' => $exception,
                    ]);
                }

                Log::info('Event cancelled', [
                    'event_id' => $event->id,
                    'moderator_id' => $moderatorId,
                ]);
            });

            return $this->event;
        });
    }

    protected function assertTransitionContext(): void
    {
        if (! $this->canTransition()) {
            throw new LogicException('Moderator is required to cancel an event.');
        }
    }

    public function getLabel(): string
    {
        return __('Cancel Event');
    }

    public function getColor(): string|array
    {
        return Color::Red;
    }

    public function getIcon(): string
    {
        return 'heroicon-o-x-circle';
    }
}
