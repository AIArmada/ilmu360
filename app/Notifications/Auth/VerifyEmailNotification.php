<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends BaseVerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
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

    #[\Override]
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.auth.verification.subject'))
            ->greeting($this->greeting())
            ->line(__('notifications.auth.verification.intro'))
            ->action(__('notifications.auth.actions.verify_email'), $url)
            ->line(__('notifications.auth.verification.outro'));
    }

    protected function greeting(): string
    {
        return __('notifications.mail.greeting', [
            'name' => __('notifications.mail.generic_recipient'),
        ]);
    }
}
