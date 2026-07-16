<?php

namespace App\States\EventStatus\Transitions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Event;
use App\Models\ModerationReview;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\States\EventStatus\Rejected;
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
            $review = OwnerContext::withOwner(null, fn () => ModerationReview::create([
                'actionable_type' => Event::class,
                'actionable_id' => $this->event->id,
                'actioned_by_type' => User::class,
                'actioned_by_id' => $moderator->id,
                'type' => 'reject',
                'reason' => $reasonCode,
                'notes' => $this->note,
            ]));

            // Update status
            $this->event->status = Rejected::class;
            $this->event->last_state_change_at = now();
            $this->event->save();
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
