<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Enums\NotificationTrigger;
use App\Models\User;
use App\Services\Notifications\NotificationSettingsManager;
use App\Shared\Communications\DigestPolicy;
use App\Shared\Communications\DigestPolicySnapshot;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Root digest-policy adapter over the conventional notification settings.
 *
 * Maps notification cadence/trigger enums and the global digest delivery
 * configuration into the scalar Shared snapshot; no enum or root user model
 * crosses into feature code.
 */
final readonly class NotificationCenterDigestPolicy implements DigestPolicy
{
    public function __construct(
        private NotificationSettingsManager $settings,
    ) {}

    public function resolve(Authenticatable $recipient, string $trigger): DigestPolicySnapshot
    {
        if (! $recipient instanceof User) {
            throw new InvalidArgumentException('Unknown digest recipient.');
        }

        $notificationTrigger = NotificationTrigger::tryFrom($trigger);

        if (! $notificationTrigger instanceof NotificationTrigger) {
            throw new InvalidArgumentException("Unknown digest trigger [{$trigger}].");
        }

        $policy = $this->settings->resolvePolicy($recipient, $notificationTrigger);

        return new DigestPolicySnapshot(
            enabled: $policy->enabled,
            cadence: $policy->cadence->value,
            trigger: $policy->trigger->value,
            timezone: $policy->timezone,
            deliveryTime: $policy->digestDeliveryTime,
            weeklyDay: $policy->digestWeeklyDay,
            quietHoursStart: $policy->quietHoursStart,
            quietHoursEnd: $policy->quietHoursEnd,
        );
    }
}
