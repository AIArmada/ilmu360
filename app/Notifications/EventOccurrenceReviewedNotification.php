<?php

declare(strict_types=1);

namespace App\Notifications;

use AIArmada\Events\Models\EventOccurrence;
use App\Models\Event;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventOccurrenceReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Event $event,
        public EventOccurrence $occurrence,
        public bool $approved,
        public ?string $reason,
        public ?string $note,
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
        $message = (new MailMessage)
            ->subject(__('Your event date review is complete'))
            ->greeting(__('Hello'))
            ->line($this->reviewMessage())
            ->line($this->occurrence->title)
            ->line($this->localizedStartsAt($notifiable));

        if ($this->reason !== null) {
            $message->line(__('Reason: :reason', ['reason' => $this->reason]));
        }

        if (filled($this->note)) {
            $message->line(__('Moderator note: :note', ['note' => $this->note]));
        }

        return $message->action(__('View event'), $this->actionUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => (string) $this->event->getKey(),
            'event_occurrence_id' => (string) $this->occurrence->getKey(),
            'occurrence_title' => $this->occurrence->title,
            'approved' => $this->approved,
            'reason' => $this->reason,
            'note' => $this->note,
            'action_url' => $this->actionUrl(),
            'type' => 'event_occurrence_reviewed',
        ];
    }

    private function reviewMessage(): string
    {
        return $this->approved
            ? __('Your submitted date for :title has been approved.', ['title' => $this->event->title])
            : __('Your submitted date for :title was not approved.', ['title' => $this->event->title]);
    }

    private function actionUrl(): string
    {
        if ($this->approved && filled($this->occurrence->slug)) {
            return route('events.occurrence', [$this->event, $this->occurrence->slug]);
        }

        return route('events.show', $this->event);
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
