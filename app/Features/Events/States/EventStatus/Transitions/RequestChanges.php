<?php

namespace App\Features\Events\States\EventStatus\Transitions;

use App\Features\Events\Models\Event;
use App\Features\Events\Notifications\EventNotificationService;
use App\Features\Events\Persistence\EventStatusWriter;
use App\Features\Events\States\EventStatus\NeedsChanges;
use App\Features\Moderation\Api\RecordModerationAction;
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

class RequestChanges extends Transition implements HasColor, HasIcon, HasLabel
{
    public function __construct(
        public Event $event,
        public ?User $moderator = null,
        public ?string $reasonCode = null,
        public ?string $note = null
    ) {}

    #[\Override]
    public function canTransition(): bool
    {
        return $this->moderator instanceof User && filled($this->reasonCode);
    }

    public function handle(): Event
    {
        $this->assertTransitionContext();
        $moderator = $this->moderator;
        $reasonCode = $this->reasonCode;
        if (! $moderator instanceof User || ! is_string($reasonCode)) {
            throw new LogicException('Moderator and reason code are required to request event changes.');
        }

        return DB::transaction(function () use ($moderator, $reasonCode) {
            $review = app(RecordModerationAction::class)->record([
                'actionable_type' => $this->event->getMorphClass(),
                'actionable_id' => $this->event->id,
                'actioned_by_type' => (new User)->getMorphClass(),
                'actioned_by_id' => $moderator->id,
                'type' => 'changes_requested',
                'reason' => $reasonCode,
                'notes' => $this->note,
            ]);

            // Update status
            app(EventStatusWriter::class)->apply($this->event, NeedsChanges::class, [
                'last_state_change_at' => now(),
            ]);
            $this->event->resolveEscalations();

            $event = $this->event;
            $reviewNotes = $review->notes;
            $moderatorId = $moderator->id;

            // External effects only run once the surrounding transaction commits,
            // so a rolled-back request never notifies or logs. Each effect is
            // contained individually so one failure cannot mask others.
            DB::afterCommit(function () use ($event, $reviewNotes, $moderatorId, $reasonCode): void {
                try {
                    app(EventNotificationService::class)->notifySubmissionNeedsChanges($event, $reviewNotes);
                } catch (Throwable $exception) {
                    Log::warning('Submission-needs-changes notification failed after request.', [
                        'event_id' => (string) $event->getKey(),
                        'exception' => $exception,
                    ]);
                }

                Log::info('Event needs changes', [
                    'event_id' => $event->id,
                    'moderator_id' => $moderatorId,
                    'reason_code' => $reasonCode,
                ]);
            });

            return $this->event;
        });
    }

    protected function assertTransitionContext(): void
    {
        if (! $this->canTransition()) {
            throw new LogicException('Moderator and reason code are required to request event changes.');
        }
    }

    public function getLabel(): string
    {
        return __('Request Changes');
    }

    public function getColor(): string|array
    {
        return Color::Orange;
    }

    public function getIcon(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }
}
