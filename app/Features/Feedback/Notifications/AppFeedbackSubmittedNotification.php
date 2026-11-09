<?php

declare(strict_types=1);

namespace App\Features\Feedback\Notifications;

use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\AppFeedbackResource;
use App\Features\Feedback\Models\AppFeedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppFeedbackSubmittedNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject(__('notifications.app_feedback.submitted.subject', ['title' => $this->feedback->title]))
            ->greeting($this->greeting($notifiable))
            ->line(__('notifications.app_feedback.submitted.intro'))
            ->line(__('notifications.app_feedback.submitted.category', [
                'category' => $this->feedback->category->getLabel(),
            ]))
            ->action(__('notifications.app_feedback.submitted.action'), $this->actionUrl())
            ->line(__('notifications.app_feedback.submitted.footer', ['app' => config('app.name')]));
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
        return AppFeedbackResource::getUrl('view', ['record' => $this->feedback], panel: 'admin');
    }
}
