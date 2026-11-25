<?php

namespace App\Features\Events\States\EventStatus\Transitions;

use App\Features\Events\Models\Event;
use App\Features\Events\Notifications\EventNotificationService;
use App\Features\Events\Notifications\EventSubmittedNotification;
use App\Features\Events\Persistence\EventStatusWriter;
use App\Features\Events\States\EventStatus\Pending;
use App\Models\User;
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
        app(EventStatusWriter::class)->apply($this->event, Pending::class, [
            'last_state_change_at' => now(),
        ]);

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
                $moderators = User::role(['moderator', 'admin', 'super_admin'])->get();
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
