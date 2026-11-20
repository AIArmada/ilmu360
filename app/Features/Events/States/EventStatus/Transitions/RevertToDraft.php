<?php

namespace App\Features\Events\States\EventStatus\Transitions;

use App\Features\Events\Models\Event;
use App\Features\Events\Persistence\EventStatusWriter;
use App\Features\Events\States\EventStatus\Draft;
use App\Features\Moderation\Api\RecordModerationAction;
use App\Models\User;
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
            app(RecordModerationAction::class)->record([
                'actionable_type' => $this->event->getMorphClass(),
                'actionable_id' => $this->event->id,
                'actioned_by_type' => (new User)->getMorphClass(),
                'actioned_by_id' => $this->moderator?->id,
                'type' => 'reverted_to_draft',
                'reason' => 'reverted_to_draft',
                'notes' => $this->note ?? 'Event reverted to draft.',
            ]);

            app(EventStatusWriter::class)->apply($this->event, Draft::class, [
                'published_at' => null,
                'last_state_change_at' => now(),
            ]);

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
