<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Report;
use App\Models\Speaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Report $report,
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
        $status = $this->report->status === 'resolved' ? 'resolved' : 'dismissed';
        $message = (new MailMessage)
            ->subject(__('notifications.reports.resolved.subject_'.$status))
            ->greeting($this->greeting($notifiable))
            ->line(__('notifications.reports.resolved.intro', [
                'entity' => strtolower($this->entityLabel()),
            ]))
            ->line(__('notifications.reports.resolved.status', [
                'status' => __('notifications.reports.resolved.statuses.'.$status),
            ]));

        if (is_string($this->report->resolution_note) && trim($this->report->resolution_note) !== '') {
            $message->line(__('notifications.reports.resolved.note', [
                'note' => $this->report->resolution_note,
            ]));
        }

        $actionUrl = $this->actionUrl();

        if ($actionUrl !== null) {
            $message->action(__('notifications.reports.resolved.action'), $actionUrl);
        }

        return $message->line(__('notifications.reports.resolved.footer', [
            'app' => config('app.name'),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'report_id' => $this->report->id,
            'entity_type' => $this->report->entity_type,
            'entity_id' => $this->report->entity_id,
            'status' => $this->report->status,
            'category' => $this->report->category,
            'action_url' => $this->actionUrl(),
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
        return 'report_resolved';
    }

    protected function greeting(object $notifiable): string
    {
        $name = isset($notifiable->name) && is_string($notifiable->name) && $notifiable->name !== ''
            ? $notifiable->name
            : __('notifications.mail.generic_recipient');

        return __('notifications.mail.greeting', ['name' => $name]);
    }

    protected function entityLabel(): string
    {
        return match ($this->report->entity_type) {
            'event' => __('Event'),
            'institution' => __('Institution'),
            'speaker' => __('Speaker'),
            'reference' => __('Reference'),
            default => __('Item'),
        };
    }

    protected function actionUrl(): ?string
    {
        $entity = $this->report->entity;

        return match (true) {
            $entity instanceof Event => route('events.show', $entity),
            $entity instanceof Institution => route('institutions.show', $entity),
            $entity instanceof Speaker => route('speakers.show', $entity),
            $entity instanceof Reference => route('references.show', $entity),
            default => null,
        };
    }
}
