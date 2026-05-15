<?php

namespace App\States\EventStatus\Transitions;

use App\Models\Event;
use App\Models\ModerationReview;
use App\Models\User;
use App\Notifications\EventSubmittedNotification;
use App\Services\Notifications\EventNotificationService;
use App\States\EventStatus\Pending;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\ModelStates\Transition;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class RemoderateEvent extends Transition implements HasColor, HasIcon, HasLabel
{
    public function __construct(
        public Event $event,
        public ?User $moderator = null,
        public ?string $note = null,
        public ?string $reasonCode = null,
    ) {}

    public function handle(): Event
    {
        return DB::transaction(function () {
            ModerationReview::create([
                'event_id' => $this->event->id,
                'moderator_id' => $this->moderator?->id,
                'decision' => 'remoderated',
                'reason_code' => $this->reasonCode,
                'note' => $this->note ?? 'Approved event sent back for re-moderation.',
            ]);

            $this->event->status = Pending::class;
            $this->event->save();

            app(EventNotificationService::class)->notifySubmissionRemoderated($this->event, $this->note);

            // Remove from search temporarily
            $this->event->unsearchable();

            // Notify moderators
            try {
                $moderators = User::role(['moderator', 'super_admin'])->get();
                if ($moderators->isNotEmpty()) {
                    Notification::send($moderators, new EventSubmittedNotification($this->event));
                }
            } catch (RoleDoesNotExist) {
                Log::warning('Could not notify moderators: roles not found', ['event_id' => $this->event->id]);
            }

            Log::info('Approved event sent for re-moderation', [
                'event_id' => $this->event->id,
                'moderator_id' => $this->moderator?->id,
            ]);

            return $this->event;
        });
    }

    public function getLabel(): string
    {
        return __('Send for Re-moderation');
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
