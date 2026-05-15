<?php

namespace App\Notifications;

use App\Filament\Pages\ModerationQueue;
use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Event $event
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return [
            'mail' => 'notifications-mail',
            'database' => 'notifications-inbox',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $institution = $this->event->institution;
        $institutionName = $institution?->name ?: __('notifications.moderation.submitted.public_submission');

        return (new MailMessage)
            ->subject(__('notifications.moderation.submitted.subject', ['title' => $this->event->title]))
            ->greeting(__('notifications.moderation.greeting'))
            ->line(__('notifications.moderation.submitted.intro'))
            ->line($this->event->title)
            ->line(__('notifications.moderation.fields.institution', ['name' => $institutionName]))
            ->line(__('notifications.moderation.fields.event_datetime', ['datetime' => $this->localizedStartsAt($notifiable)]))
            ->action(__('notifications.moderation.actions.review_event'), $this->reviewUrl())
            ->line(__('notifications.moderation.submitted.footer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $institution = $this->event->institution;

        return [
            'event_id' => $this->event->id,
            'event_title' => $this->event->title,
            'institution_name' => $institution ? $institution->name : null,
            'action_url' => $this->reviewUrl(),
            'type' => 'event_submitted',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    public function databaseType(object $notifiable): string
    {
        return 'event_submitted';
    }

    protected function reviewUrl(): string
    {
        return ModerationQueue::getUrl(panel: 'admin').'?tab=pending';
    }

    protected function localizedStartsAt(object $notifiable): string
    {
        if (! $this->event->starts_at instanceof CarbonInterface) {
            return __('notifications.moderation.not_scheduled');
        }

        $timezone = isset($notifiable->timezone) && is_string($notifiable->timezone) && $notifiable->timezone !== ''
            ? $notifiable->timezone
            : config('app.timezone');
        $locale = method_exists($notifiable, 'preferredLocale')
            ? (string) $notifiable->preferredLocale()
            : app()->getLocale();
        $startsAt = $this->event->starts_at->copy()->timezone($timezone)->locale($locale);

        return $startsAt->translatedFormat('l, j F Y').', '.$startsAt->format('h:i A');
    }
}
