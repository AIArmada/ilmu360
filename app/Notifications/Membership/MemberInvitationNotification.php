<?php

namespace App\Notifications\Membership;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MemberInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $inviterName,
        public readonly string $subjectLabel,
        public readonly string $subjectName,
        public readonly string $roleLabel,
        public readonly string $invitedEmail,
        public readonly string $acceptUrl,
        public readonly ?CarbonInterface $expiresAt = null,
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
        $mail = (new MailMessage)
            ->subject(__('notifications.membership.invitation.subject', [
                'inviter' => $this->inviterName,
                'subject' => $this->subjectName,
            ]))
            ->greeting(__('notifications.mail.greeting', [
                'name' => __('notifications.mail.generic_recipient'),
            ]))
            ->line(__('notifications.membership.invitation.intro', [
                'inviter' => $this->inviterName,
                'subject_label' => strtolower($this->subjectLabel),
                'role' => $this->roleLabel,
            ]))
            ->line(__('notifications.membership.invitation.subject_name', [
                'name' => $this->subjectName,
            ]))
            ->line(__('notifications.membership.invitation.role', [
                'role' => $this->roleLabel,
            ]));

        if ($this->expiresAt instanceof CarbonInterface) {
            $expiresAt = $this->expiresAt
                ->copy()
                ->timezone((string) config('app.default_user_timezone', config('app.timezone')))
                ->locale(app()->getLocale());

            $mail->line(__('notifications.membership.invitation.expires', [
                'datetime' => $expiresAt->translatedFormat('l, j F Y').', '.$expiresAt->format('h:i A'),
            ]));
        }

        return $mail
            ->action(__('notifications.membership.invitation.action'), $this->acceptUrl)
            ->line(__('notifications.membership.invitation.footer', [
                'email' => $this->invitedEmail,
            ]));
    }
}
