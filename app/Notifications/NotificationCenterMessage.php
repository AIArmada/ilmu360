<?php

namespace App\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Models\PendingNotification;
use App\Services\Notifications\NotificationMessageRenderer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NotificationCenterMessage extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $channelsAttempted
     * @param  list<string>  $fallbackChannels
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $sourcePendingIds
     */
    public function __construct(
        public readonly string $pendingNotificationId,
        public readonly NotificationChannel $targetChannel,
        public readonly NotificationFamily $family,
        public readonly NotificationTrigger $trigger,
        public readonly NotificationPriority $priority,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionUrl,
        public readonly ?string $entityType,
        public readonly ?string $entityId,
        public readonly ?CarbonInterface $occurredAt,
        public readonly array $channelsAttempted = [],
        public readonly array $fallbackChannels = [],
        public readonly string $fallbackStrategy = 'skip',
        public readonly bool $bypassQuietHours = false,
        public readonly array $meta = [],
        public readonly bool $digest = false,
        public readonly array $sourcePendingIds = [],
    ) {
        $this->afterCommit();
    }

    /**
     * @param  list<string>  $channelsAttempted
     * @param  list<string>  $fallbackChannels
     */
    public static function fromPending(
        PendingNotification $pending,
        NotificationChannel $targetChannel,
        NotificationFamily $family,
        NotificationTrigger $trigger,
        NotificationPriority $priority,
        array $channelsAttempted = [],
        array $fallbackChannels = [],
        string $fallbackStrategy = 'skip',
        bool $bypassQuietHours = false,
    ): self {
        $meta = is_array($pending->meta) ? $pending->meta : [];
        $occurredAt = $pending->occurred_at;

        if (! $occurredAt instanceof CarbonInterface) {
            $occurredAt = null;
        }

        $sourcePendingIds = data_get($meta, 'source_message_ids', []);
        $sourcePendingIds = is_array($sourcePendingIds) ? $sourcePendingIds : [];

        return new self(
            pendingNotificationId: $pending->id,
            targetChannel: $targetChannel,
            family: $family,
            trigger: $trigger,
            priority: $priority,
            title: $pending->title,
            body: $pending->body,
            actionUrl: $pending->action_url,
            entityType: $pending->entity_type,
            entityId: $pending->entity_id,
            occurredAt: $occurredAt,
            channelsAttempted: $channelsAttempted,
            fallbackChannels: $fallbackChannels,
            fallbackStrategy: $fallbackStrategy,
            bypassQuietHours: $bypassQuietHours,
            meta: $meta,
            digest: (bool) data_get($meta, 'digest', false),
            sourcePendingIds: array_values(array_filter(array_map(static fn (mixed $id): string => (string) $id, $sourcePendingIds))),
        );
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [$this->targetChannel->laravelChannel()];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return match ($this->targetChannel) {
            NotificationChannel::Email => ['mail' => 'notifications-mail'],
            NotificationChannel::InApp => ['database' => 'notifications-inbox'],
            NotificationChannel::Push => [NotificationChannel::Push->laravelChannel() => 'notifications-push'],
            NotificationChannel::Whatsapp => [NotificationChannel::Whatsapp->laravelChannel() => 'notifications-whatsapp'],
            default => [],
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->titleFor($notifiable);
        $body = $this->bodyFor($notifiable);

        $mail = (new MailMessage)
            ->subject($title)
            ->greeting($this->mailGreeting($notifiable))
            ->line($body);

        $occurredAt = $this->formattedOccurredAtFor($notifiable);

        if ($occurredAt !== null) {
            $mail->line(__('notifications.mail.occurred_at', [
                'datetime' => $occurredAt,
            ]));
        }

        if ($this->actionUrl !== null && $this->actionUrl !== '') {
            $mail->action(__('notifications.actions.open'), $this->actionUrl);
        }

        return $mail->line(__('notifications.mail.footer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'family' => $this->family->value,
            'trigger' => $this->trigger->value,
            'title' => $this->titleFor($notifiable),
            'body' => $this->bodyFor($notifiable),
            'action_url' => $this->actionUrl,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'priority' => $this->priority->value,
            'occurred_at' => $this->occurredAt?->toIso8601String(),
            'channels_attempted' => $this->channelsAttempted,
            'meta' => $this->meta,
            'inbox_visible' => (bool) ($this->meta['inbox_visible'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->titleFor($notifiable),
            'body' => $this->bodyFor($notifiable),
            'action_url' => $this->actionUrl,
            'family' => $this->family->value,
            'trigger' => $this->trigger->value,
            'notification_id' => $this->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWhatsapp(object $notifiable): array
    {
        return [
            'title' => $this->titleFor($notifiable),
            'body' => $this->bodyFor($notifiable),
            'action_url' => $this->actionUrl,
            'language' => method_exists($notifiable, 'preferredLocale') ? $notifiable->preferredLocale() : app()->getLocale(),
            'template' => 'notification_update',
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->trigger->value;
    }

    protected function mailGreeting(object $notifiable): string
    {
        $name = isset($notifiable->name) && is_string($notifiable->name)
            ? $notifiable->name
            : __('notifications.mail.generic_recipient');

        return __('notifications.mail.greeting', ['name' => $name]);
    }

    public function titleFor(object $notifiable): string
    {
        return $this->renderDefinition('title', $notifiable, $this->title);
    }

    public function bodyFor(object $notifiable): string
    {
        return $this->renderDefinition('body', $notifiable, $this->body);
    }

    protected function formattedOccurredAtFor(object $notifiable): ?string
    {
        if (! $this->occurredAt instanceof CarbonInterface) {
            return null;
        }

        $timezone = method_exists($notifiable, 'preferredTimezone')
            ? (string) $notifiable->preferredTimezone()
            : (is_string(data_get($notifiable, 'timezone')) && data_get($notifiable, 'timezone') !== ''
                ? data_get($notifiable, 'timezone')
                : (string) config('app.timezone', 'UTC'));

        $locale = method_exists($notifiable, 'preferredLocale')
            ? (string) $notifiable->preferredLocale()
            : app()->getLocale();

        return CarbonImmutable::instance($this->occurredAt)
            ->setTimezone($timezone)
            ->locale($locale)
            ->translatedFormat('j M Y, g:i A');
    }

    protected function renderDefinition(string $segment, object $notifiable, string $fallback): string
    {
        $definition = $this->meta['render'][$segment] ?? null;

        return app(NotificationMessageRenderer::class)->renderDefinition(
            is_array($definition) ? $definition : null,
            $notifiable,
            $fallback,
        );
    }
}
