<?php

namespace App\Notifications;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventEscalationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Event $event,
        public string $escalationType,
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
        $message = (new MailMessage)
            ->subject($this->subject())
            ->greeting($this->greeting())
            ->line($this->mainMessage())
            ->line(__('notifications.moderation.fields.event_datetime', ['datetime' => $this->localizedStartsAt($notifiable)]));

        if (filled($this->event->institution?->name)) {
            $message->line(__('notifications.moderation.fields.institution', ['name' => $this->event->institution->name]));
        }

        $message->action(__('notifications.moderation.actions.review_event'), $this->reviewUrl());

        if ($this->escalationType === 'priority') {
            $message->line(__('notifications.moderation.escalation.priority_footer'));
        } elseif ($this->escalationType === 'urgent') {
            $message->line(__('notifications.moderation.escalation.urgent_footer'));
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => $this->event->id,
            'event_title' => $this->event->title,
            'escalation_type' => $this->escalationType,
            'starts_at' => $this->event->starts_at?->toIso8601String(),
            'action_url' => $this->reviewUrl(),
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
        return 'event_escalation';
    }

    protected function subject(): string
    {
        return __('notifications.moderation.escalation.subjects.'.$this->escalationType, [
            'title' => $this->event->title,
        ]);
    }

    protected function greeting(): string
    {
        return __('notifications.moderation.escalation.greetings.'.$this->escalationType);
    }

    protected function mainMessage(): string
    {
        return __('notifications.moderation.escalation.messages.'.$this->escalationType);
    }

    protected function reviewUrl(): string
    {
        return EventResource::getUrl('edit', ['record' => $this->event], panel: 'admin');
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
