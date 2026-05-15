<?php

namespace App\States\EventStatus\Transitions;

use App\Models\Event;
use App\Models\ModerationReview;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\States\EventStatus\NeedsChanges;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Spatie\ModelStates\Transition;

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
            // Create review record
            $review = ModerationReview::create([
                'event_id' => $this->event->id,
                'moderator_id' => $moderator->id,
                'decision' => 'needs_changes',
                'reason_code' => $reasonCode,
                'note' => $this->note,
            ]);

            // Update status
            $this->event->status = NeedsChanges::class;
            $this->event->save();

            // Notify submitter and institution admins
            app(EventNotificationService::class)->notifySubmissionNeedsChanges($this->event, $review->note);

            Log::info('Event needs changes', [
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
