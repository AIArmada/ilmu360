<?php

namespace App\Features\Events\States\EventStatus\Transitions;

use App\Features\Events\Models\Event;
use App\Features\Events\Notifications\EventNotificationService;
use App\Features\Events\Persistence\EventStatusWriter;
use App\Features\Events\States\EventStatus\Rejected;
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

class RejectEvent extends Transition implements HasColor, HasIcon, HasLabel
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
            throw new LogicException('Moderator and reason code are required to reject an event.');
        }

        return DB::transaction(function () use ($moderator, $reasonCode) {
            $review = app(RecordModerationAction::class)->record([
                'actionable_type' => $this->event->getMorphClass(),
                'actionable_id' => $this->event->id,
                'actioned_by_type' => (new User)->getMorphClass(),
                'actioned_by_id' => $moderator->id,
                'type' => 'reject',
                'reason' => $reasonCode,
                'notes' => $this->note,
            ]);

            // Update status
            app(EventStatusWriter::class)->apply($this->event, Rejected::class, [
                'last_state_change_at' => now(),
            ]);
            $this->event->resolveEscalations();

            // Remove from search
            $this->event->unsearchable();

            // Notify submitter
            app(EventNotificationService::class)->notifySubmissionRejected($this->event, $review->notes);

            Log::info('Event rejected', [
                'event_id' => $this->event->id,
                'moderator_id' => $moderator->id,
                'reason_code' => $reasonCode,
            ]);

            return $this->event;
        });
    }

    protected function assertTransitionContext(): void
    {
        if (! $this->canTransition()) {
            throw new LogicException('Moderator and reason code are required to reject an event.');
        }
    }

    public function getLabel(): string
    {
        return __('Reject Event');
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
