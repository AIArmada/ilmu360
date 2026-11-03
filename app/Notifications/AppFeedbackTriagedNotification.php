<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AppFeedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppFeedbackTriagedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AppFeedback $feedback,
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
        return [
            'mail' => 'notifications-mail',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('notifications.app_feedback.triaged.subject', ['title' => $this->feedback->title]))
            ->greeting($this->greeting($notifiable))
            ->line(__('notifications.app_feedback.triaged.intro'))
            ->line(__('notifications.app_feedback.triaged.status', [
                'status' => $this->feedback->status->getLabel(),
            ]));

        return $message
            ->action(__('notifications.app_feedback.triaged.action'), $this->actionUrl())
            ->line(__('notifications.app_feedback.triaged.footer', ['app' => config('app.name')]));
    }

    protected function greeting(object $notifiable): string
    {
        $name = isset($notifiable->name) && is_string($notifiable->name) && $notifiable->name !== ''
            ? $notifiable->name
            : __('notifications.mail.generic_recipient');

        return __('notifications.mail.greeting', ['name' => $name]);
    }

    protected function actionUrl(): string
    {
        return route('app-feedback.index', ['lihat' => $this->feedback->getKey()]).'#maklum-balas-'.$this->feedback->getKey();
    }
}
