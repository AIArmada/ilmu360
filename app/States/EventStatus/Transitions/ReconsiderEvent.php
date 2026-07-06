<?php

namespace App\States\EventStatus\Transitions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Event;
use App\Models\ModerationReview;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\States\EventStatus\Pending;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\ModelStates\Transition;

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
            OwnerContext::withOwner(null, fn () => ModerationReview::create([
                'actionable_type' => Event::class,
                'actionable_id' => $this->event->id,
                'actioned_by_type' => User::class,
                'actioned_by_id' => $this->moderator?->id,
                'type' => 'reconsidered',
                'notes' => $this->note ?? 'Event moved back to pending for reconsideration.',
            ]));

            $this->event->status = Pending::class;
            $this->event->save();

            app(EventNotificationService::class)->notifySubmissionRemoderated($this->event, $this->note);

            Log::info('Rejected event reconsidered', [
                'event_id' => $this->event->id,
                'moderator_id' => $this->moderator?->id,
            ]);

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
