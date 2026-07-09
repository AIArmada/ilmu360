<?php

namespace App\States\EventStatus\Transitions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Event;
use App\Models\ModerationReview;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\States\EventStatus\Cancelled;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Spatie\ModelStates\Transition;

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
            OwnerContext::withOwner(null, fn () => ModerationReview::create([
                'actionable_type' => Event::class,
                'actionable_id' => $this->event->id,
                'actioned_by_type' => User::class,
                'actioned_by_id' => $moderator->id,
                'type' => 'cancelled',
                'reason' => 'cancelled',
                'notes' => $this->note,
            ]));

            $this->event->status = Cancelled::class;
            $this->event->cancelled_at = now();
            $this->event->last_state_change_at = now();
            $this->event->save();

            // Cancelled events remain searchable so users can still discover status updates.
            $this->event->searchable();

            app(EventNotificationService::class)->notifySubmissionCancelled($this->event, $this->note);
            app(EventNotificationService::class)->notifyTrackedEventCancelled($this->event, $this->note);

            Log::info('Event cancelled', [
                'event_id' => $this->event->id,
                'moderator_id' => $moderator->id,
            ]);

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
