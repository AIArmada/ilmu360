<?php

namespace App\States\EventStatus\Transitions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Event;
use App\Models\ModerationReview;
use App\Models\User;
use App\States\EventStatus\Draft;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\ModelStates\Transition;

class RevertToDraft extends Transition implements HasColor, HasIcon, HasLabel
{
    public function __construct(
        public Event $event,
        public ?User $moderator = null,
        public ?string $note = null
    ) {}

    public function handle(): Event
    {
        return DB::transaction(function () {
            OwnerContext::withOwner(null, fn () => ModerationReview::create([
                'actionable_type' => Event::class,
                'actionable_id' => $this->event->id,
                'actioned_by_type' => User::class,
                'actioned_by_id' => $this->moderator?->id,
                'type' => 'reverted_to_draft',
                'notes' => $this->note ?? 'Event reverted to draft.',
            ]));

            $this->event->status = Draft::class;
            $this->event->published_at = null;
            $this->event->save();

            // Remove from search
            $this->event->unsearchable();

            Log::info('Event reverted to draft', [
                'event_id' => $this->event->id,
                'moderator_id' => $this->moderator?->id,
            ]);

            return $this->event;
        });
    }

    public function getLabel(): string
    {
        return __('Revert to Draft');
    }

    public function getColor(): string|array
    {
        return Color::Gray;
    }

    public function getIcon(): string
    {
        return 'heroicon-o-arrow-uturn-left';
    }
}
