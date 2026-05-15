<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCadence;
use App\Enums\NotificationChannel;
use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Models\PendingNotification;
use App\Models\User;
use App\Notifications\NotificationCenterMessage;
use App\Support\Notifications\NotificationDispatchData;
use App\Support\Notifications\ResolvedNotificationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class NotificationEngine
{
    public function __construct(
        protected NotificationSettingsManager $settingsManager,
    ) {}

    /**
     * @param  iterable<int, User>  $users
     */
    public function dispatch(iterable $users, NotificationDispatchData $data): void
    {
        /** @var Collection<int, User> $collection */
        $collection = collect($users)
            ->unique('id')
            ->values();

        foreach ($collection as $user) {
            $this->dispatchToUser($user, $data);
        }
    }

    public function dispatchToUser(User $user, NotificationDispatchData $data): ?PendingNotification
    {
        $policy = $this->settingsManager->resolvePolicy($user, $data->trigger);

        if (! $policy->enabled) {
            return null;
        }

        $effectiveCadence = $data->forcedCadence ?? $policy->cadence;

        if ($effectiveCadence === NotificationCadence::Off) {
            return null;
        }

        $orderedChannels = $this->orderedChannels($policy);
        $pending = $this->createPendingNotification($user, $policy, $data, $effectiveCadence, $orderedChannels);

        if ($effectiveCadence !== NotificationCadence::Instant || ($data->fingerprint !== null && $data->fingerprint !== '' && ! $pending->wasRecentlyCreated)) {
            return $pending;
        }

        $bypassQuietHours = $data->bypassQuietHours && $policy->urgentOverride;
        $orderedExternalChannels = $this->orderedExternalChannels($orderedChannels);

        if (in_array(NotificationChannel::InApp->value, $orderedChannels, true)) {
            $this->queueChannelNotification(
                user: $user,
                pending: $pending,
                policy: $policy,
                channel: NotificationChannel::InApp,
                channelsAttempted: $orderedChannels,
                fallbackChannels: [],
                bypassQuietHours: $bypassQuietHours,
            );
        }

        $primaryExternalChannel = $this->primaryExternalChannel($orderedExternalChannels);

        if (! $primaryExternalChannel instanceof NotificationChannel) {
            return $pending;
        }

        $fallbackChannels = $this->fallbackChannelsFor($policy, $primaryExternalChannel, $orderedExternalChannels);

        if (! $this->isChannelAvailable($user, $primaryExternalChannel)) {
            $this->queueFallbackByPolicy($user, $pending, $policy, $fallbackChannels, $bypassQuietHours, $orderedChannels);

            return $pending;
        }

        $this->queueChannelNotification(
            user: $user,
            pending: $pending,
            policy: $policy,
            channel: $primaryExternalChannel,
            channelsAttempted: $orderedChannels,
            fallbackChannels: $fallbackChannels,
            bypassQuietHours: $bypassQuietHours,
        );

        return $pending;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function createDigestMessage(
        User $user,
        ResolvedNotificationPolicy $policy,
        string $title,
        string $body,
        string $fingerprint,
        array $meta = [],
    ): PendingNotification {
        return PendingNotification::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'fingerprint' => $fingerprint,
            ],
            [
                'family' => $policy->family->value,
                'trigger' => $policy->trigger->value,
                'title' => $title,
                'body' => $body,
                'priority' => $policy->trigger === NotificationTrigger::EventCancelled
                    ? NotificationPriority::Urgent->value
                    : NotificationPriority::Medium->value,
                'delivery_cadence' => NotificationCadence::Instant->value,
                'occurred_at' => now(),
                'meta' => $meta,
            ]
        );
    }

    public function queueFallbackForNotification(User $user, NotificationCenterMessage $notification): void
    {
        $trigger = $notification->trigger;
        $policy = $this->settingsManager->resolvePolicy($user, $trigger);
        $pending = PendingNotification::query()->find($notification->pendingNotificationId);

        if (! $pending instanceof PendingNotification) {
            return;
        }

        $this->queueFallbackByPolicy(
            user: $user,
            pending: $pending,
            policy: $policy,
            fallbackChannels: $notification->fallbackChannels,
            bypassQuietHours: $notification->bypassQuietHours,
            channelsAttempted: $notification->channelsAttempted,
        );
    }

    /**
     * @param  list<string>  $channelsAttempted
     * @param  list<string>  $fallbackChannels
     */
    protected function queueChannelNotification(
        User $user,
        PendingNotification $pending,
        ResolvedNotificationPolicy $policy,
        NotificationChannel $channel,
        array $channelsAttempted,
        array $fallbackChannels,
        bool $bypassQuietHours,
    ): void {
        $notification = NotificationCenterMessage::fromPending(
            pending: $pending,
            targetChannel: $channel,
            family: $this->pendingFamily($pending),
            trigger: $this->pendingTrigger($pending),
            priority: $this->pendingPriority($pending),
            channelsAttempted: $channelsAttempted,
            fallbackChannels: $fallbackChannels,
            fallbackStrategy: $policy->fallbackStrategy,
            bypassQuietHours: $bypassQuietHours,
        );

        $deliverAfter = $this->deliverAfterFor($policy, $channel, $bypassQuietHours);

        if ($deliverAfter instanceof CarbonImmutable) {
            $notification->delay($deliverAfter);
        }

        Notification::send($user, $notification);
    }

    /**
     * @param  list<string>  $fallbackChannels
     * @param  list<string>  $channelsAttempted
     */
    protected function queueFallbackByPolicy(
        User $user,
        PendingNotification $pending,
        ResolvedNotificationPolicy $policy,
        array $fallbackChannels,
        bool $bypassQuietHours,
        array $channelsAttempted,
    ): void {
        if ($policy->fallbackStrategy === 'skip') {
            return;
        }

        if ($policy->fallbackStrategy === 'in_app_only') {
            if (! in_array(NotificationChannel::InApp->value, $channelsAttempted, true)) {
                $this->queueChannelNotification(
                    user: $user,
                    pending: $pending,
                    policy: $policy,
                    channel: NotificationChannel::InApp,
                    channelsAttempted: array_values(array_unique(array_merge($channelsAttempted, [NotificationChannel::InApp->value]))),
                    fallbackChannels: [],
                    bypassQuietHours: $bypassQuietHours,
                );
            }

            return;
        }

        foreach ($fallbackChannels as $index => $fallbackChannelValue) {
            $fallbackChannel = NotificationChannel::from($fallbackChannelValue);

            if (! $this->isChannelAvailable($user, $fallbackChannel)) {
                continue;
            }

            $remainingFallbacks = collect($fallbackChannels)
                ->slice($index + 1)
                ->values()
                ->all();

            $this->queueChannelNotification(
                user: $user,
                pending: $pending,
                policy: $policy,
                channel: $fallbackChannel,
                channelsAttempted: array_values(array_unique(array_merge($channelsAttempted, [$fallbackChannel->value]))),
                fallbackChannels: $remainingFallbacks,
                bypassQuietHours: $bypassQuietHours,
            );

            return;
        }
    }

    /**
     * @param  list<string>  $orderedChannels
     */
    protected function createPendingNotification(
        User $user,
        ResolvedNotificationPolicy $policy,
        NotificationDispatchData $data,
        NotificationCadence $effectiveCadence,
        array $orderedChannels,
    ): PendingNotification {
        $occurredAt = $data->occurredAt?->toDateTimeString() ?? now()->toDateTimeString();
        $meta = array_merge($data->meta, [
            'inbox_visible' => in_array(NotificationChannel::InApp->value, $orderedChannels, true),
        ]);

        if (is_array($data->render)) {
            $meta['render'] = $data->render;
        }

        if ($data->fingerprint !== null && $data->fingerprint !== '') {
            return PendingNotification::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'fingerprint' => $data->fingerprint,
                ],
                [
                    'family' => $policy->family->value,
                    'trigger' => $policy->trigger->value,
                    'title' => $data->title,
                    'body' => $data->body,
                    'action_url' => $data->actionUrl,
                    'entity_type' => $data->entityType,
                    'entity_id' => $data->entityId,
                    'priority' => $data->priority->value,
                    'delivery_cadence' => $effectiveCadence->value,
                    'occurred_at' => $occurredAt,
                    'channels_attempted' => $orderedChannels,
                    'meta' => $meta,
                ]
            );
        }

        return PendingNotification::query()->create([
            'user_id' => $user->id,
            'family' => $policy->family->value,
            'trigger' => $policy->trigger->value,
            'title' => $data->title,
            'body' => $data->body,
            'action_url' => $data->actionUrl,
            'entity_type' => $data->entityType,
            'entity_id' => $data->entityId,
            'priority' => $data->priority->value,
            'delivery_cadence' => $effectiveCadence->value,
            'occurred_at' => $occurredAt,
            'channels_attempted' => $orderedChannels,
            'meta' => $meta,
        ]);
    }

    protected function isChannelAvailable(User $user, NotificationChannel $channel): bool
    {
        return match ($channel) {
            NotificationChannel::InApp => true,
            NotificationChannel::Email => $this->settingsManager->destinationsFor($user, $channel)->isNotEmpty(),
            NotificationChannel::Push => $this->settingsManager->destinationsFor($user, $channel)->isNotEmpty()
                && filled(config('notification-center.push.project_id'))
                && filled(config('notification-center.push.credentials')),
            NotificationChannel::Whatsapp => $this->settingsManager->destinationsFor($user, $channel)->isNotEmpty()
                && filled(config('notification-center.whatsapp.phone_number_id'))
                && filled(config('notification-center.whatsapp.access_token')),
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    protected function orderedChannels(ResolvedNotificationPolicy $policy): array
    {
        $preferred = collect($policy->preferredChannels)
            ->filter(static fn (string $channel): bool => in_array($channel, $policy->channels, true))
            ->values();

        $remaining = collect($policy->channels)
            ->reject(fn (string $channel): bool => $preferred->contains($channel))
            ->values();

        return $preferred->concat($remaining)->unique()->values()->all();
    }

    /**
     * @param  list<string>  $orderedChannels
     * @return list<string>
     */
    protected function orderedExternalChannels(array $orderedChannels): array
    {
        return collect($orderedChannels)
            ->reject(static fn (string $channel): bool => $channel === NotificationChannel::InApp->value)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $orderedExternalChannels
     */
    protected function primaryExternalChannel(array $orderedExternalChannels): ?NotificationChannel
    {
        $channel = $orderedExternalChannels[0] ?? null;

        if (! is_string($channel) || $channel === '') {
            return null;
        }

        return NotificationChannel::from($channel);
    }

    /**
     * @param  list<string>  $orderedChannels
     * @return list<string>
     */
    protected function fallbackChannelsFor(
        ResolvedNotificationPolicy $policy,
        NotificationChannel $channel,
        array $orderedChannels,
    ): array {
        $configuredFallbacks = collect($policy->fallbackChannels)
            ->map(static fn (string $value): string => $value)
            ->filter(fn (string $fallbackChannel): bool => $fallbackChannel !== $channel->value)
            ->unique()
            ->values();

        if ($configuredFallbacks->isNotEmpty()) {
            return $configuredFallbacks->all();
        }

        $currentIndex = array_search($channel->value, $orderedChannels, true);

        return collect($orderedChannels)
            ->slice($currentIndex === false ? 0 : $currentIndex + 1)
            ->filter(fn (string $fallbackChannel): bool => $fallbackChannel !== $channel->value)
            ->unique()
            ->values()
            ->all();
    }

    protected function pendingFamily(PendingNotification $pending): NotificationFamily
    {
        return $pending->family instanceof NotificationFamily
            ? $pending->family
            : NotificationFamily::from((string) $pending->family);
    }

    protected function pendingTrigger(PendingNotification $pending): NotificationTrigger
    {
        return $pending->trigger instanceof NotificationTrigger
            ? $pending->trigger
            : NotificationTrigger::from((string) $pending->trigger);
    }

    protected function pendingPriority(PendingNotification $pending): NotificationPriority
    {
        return $pending->priority instanceof NotificationPriority
            ? $pending->priority
            : NotificationPriority::from((string) $pending->priority);
    }

    protected function deliverAfterFor(ResolvedNotificationPolicy $policy, NotificationChannel $channel, bool $bypassQuietHours): ?CarbonImmutable
    {
        if ($bypassQuietHours || ! in_array($channel, [NotificationChannel::Push, NotificationChannel::Whatsapp], true)) {
            return null;
        }

        if ($policy->quietHoursStart === null || $policy->quietHoursEnd === null) {
            return null;
        }

        $timezoneNow = CarbonImmutable::now($policy->timezone);
        $start = $timezoneNow->setTimeFromTimeString($policy->quietHoursStart);
        $end = $timezoneNow->setTimeFromTimeString($policy->quietHoursEnd);

        if ($end->lessThanOrEqualTo($start)) {
            $withinQuietHours = $timezoneNow->greaterThanOrEqualTo($start) || $timezoneNow->lessThan($end);

            if (! $withinQuietHours) {
                return null;
            }

            return $timezoneNow->lessThan($end)
                ? $end->utc()
                : $end->addDay()->utc();
        }

        return $timezoneNow->betweenIncluded($start, $end)
            ? $end->utc()
            : null;
    }
}
