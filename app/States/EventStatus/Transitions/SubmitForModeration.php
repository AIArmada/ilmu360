<?php

namespace App\States\EventStatus\Transitions;

use App\Models\Event;
use App\Models\User;
use App\Notifications\EventSubmittedNotification;
use App\Services\Notifications\EventNotificationService;
use App\States\EventStatus\Pending;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\ModelStates\Transition;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class SubmitForModeration extends Transition implements HasColor, HasIcon, HasLabel
{
    public function __construct(
        public Event $event
    ) {}

    public function handle(): Event
    {
        $this->event->status = Pending::class;
        $this->event->save();

        app(EventNotificationService::class)->notifySubmissionReceived($this->event);

        // Notify moderators
        try {
            $moderators = User::role(['moderator', 'super_admin'])->get();
            if ($moderators->isNotEmpty()) {
                Notification::send($moderators, new EventSubmittedNotification($this->event));
            }
        } catch (RoleDoesNotExist) {
            Log::warning('Could not notify moderators: roles not found', ['event_id' => $this->event->id]);
        }

        Log::info('Event submitted for moderation', ['event_id' => $this->event->id]);

        return $this->event;
    }

    public function getLabel(): string
    {
        return __('Submit for Review');
    }

    public function getColor(): string|array
    {
        return Color::Amber;
    }

    public function getIcon(): string
    {
        return 'heroicon-o-paper-airplane';
    }
}
