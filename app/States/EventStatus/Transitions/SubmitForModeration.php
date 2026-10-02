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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\ModelStates\Transition;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Throwable;

class SubmitForModeration extends Transition implements HasColor, HasIcon, HasLabel
{
    public function __construct(
        public Event $event
    ) {}

    public function handle(): Event
    {
        $this->event->status = Pending::class;
        $this->event->last_state_change_at = now();
        $this->event->save();

        $event = $this->event;

        // External effects only run once the surrounding transaction commits,
        // so a rolled-back submission never notifies or logs. Each effect is
        // contained individually so the committed Pending state is returned
        // accurately even when a notification channel fails.
        DB::afterCommit(function () use ($event): void {
            try {
                app(EventNotificationService::class)->notifySubmissionReceived($event);
            } catch (Throwable $exception) {
                Log::warning('Submission-received notification failed after submit.', [
                    'event_id' => (string) $event->getKey(),
                    'exception' => $exception,
                ]);
            }

            // Notify moderators
            try {
                $moderators = User::role(['moderator', 'super_admin'])->get();
                if ($moderators->isNotEmpty()) {
                    Notification::send($moderators, new EventSubmittedNotification($event));
                }
            } catch (RoleDoesNotExist $exception) {
                Log::warning('Could not notify moderators: roles not found', [
                    'event_id' => $event->id,
                    'exception' => $exception,
                ]);
            } catch (Throwable $exception) {
                Log::warning('Moderator submission notification failed after submit.', [
                    'event_id' => (string) $event->getKey(),
                    'exception' => $exception,
                ]);
            }

            Log::info('Event submitted for moderation', ['event_id' => $event->id]);
        });

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
