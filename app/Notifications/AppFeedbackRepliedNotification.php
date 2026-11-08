<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AppFeedback;
use App\Models\AppFeedbackMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppFeedbackRepliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AppFeedback $feedback,
        public AppFeedbackMessage $message,
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
        return (new MailMessage)
            ->subject(__('notifications.app_feedback.replied.subject', ['title' => $this->feedback->title]))
            ->greeting($this->greeting($notifiable))
            ->line(__('notifications.app_feedback.replied.intro'))
            ->line(__('notifications.app_feedback.replied.reply_label'))
            ->line($this->message->body)
            ->line(__('notifications.app_feedback.replied.status', [
                'status' => $this->feedback->status->getLabel(),
            ]))
            ->action(__('notifications.app_feedback.replied.action'), $this->actionUrl())
            ->line(__('notifications.app_feedback.replied.footer', ['app' => config('app.name')]));
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
