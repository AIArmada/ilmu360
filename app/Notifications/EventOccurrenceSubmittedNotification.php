<?php

declare(strict_types=1);

namespace App\Notifications;

use AIArmada\Events\Models\EventOccurrence;
use App\Filament\Pages\ModerationQueue;
use App\Models\Event;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventOccurrenceSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Event $event,
        public EventOccurrence $occurrence,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'notifications-mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $institutionName = $this->event->institution?->name
            ?: __('notifications.moderation.submitted.public_submission');

        return (new MailMessage)
            ->subject(__('notifications.moderation.submitted.subject', ['title' => $this->occurrence->title]))
            ->greeting(__('notifications.moderation.greeting'))
            ->line(__('notifications.moderation.submitted.intro'))
            ->line($this->occurrence->title)
            ->line(__('notifications.moderation.fields.institution', ['name' => $institutionName]))
            ->line(__('notifications.moderation.fields.event_datetime', ['datetime' => $this->localizedStartsAt($notifiable)]))
            ->action(__('notifications.moderation.actions.review_event'), $this->reviewUrl())
            ->line($this->event->title);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => $this->event->getKey(),
            'event_occurrence_id' => $this->occurrence->getKey(),
            'event_title' => $this->event->title,
            'occurrence_title' => $this->occurrence->title,
            'action_url' => $this->reviewUrl(),
            'type' => 'event_occurrence_submitted',
        ];
    }

    private function reviewUrl(): string
    {
        return ModerationQueue::getUrl(panel: 'admin').'?tab=pending_occurrences';
    }

    private function localizedStartsAt(object $notifiable): string
    {
        if (! $this->occurrence->starts_at instanceof CarbonInterface) {
            return __('notifications.moderation.not_scheduled');
        }

        $timezone = isset($notifiable->timezone) && is_string($notifiable->timezone) && $notifiable->timezone !== ''
            ? $notifiable->timezone
            : config('app.timezone');

        return UserDateTimeFormatter::translatedFormat(
            $this->occurrence->starts_at,
            'l, j F Y',
            timezone: $timezone,
        ).', '.UserDateTimeFormatter::format(
            $this->occurrence->starts_at,
            'h:i A',
            timezone: $timezone,
        );
    }
}
