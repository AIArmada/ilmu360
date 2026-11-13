<?php

declare(strict_types=1);

namespace App\Support\Communications;

use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Communications\Api\NotificationTrigger;
use App\Models\User;
use App\Shared\Communications\DigestPolicy;
use App\Shared\Communications\DigestPolicySnapshot;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Root digest-policy adapter over the Communications preference API.
 *
 * Maps notification cadence/trigger enums and the global digest delivery
 * configuration into the scalar Shared snapshot; no enum or root user model
 * crosses into feature code.
 */
final readonly class NotificationCenterDigestPolicy implements DigestPolicy
{
    public function __construct(
        private AccountNotificationPreferences $settings,
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

        $policy = $this->settings->resolvePolicy((string) $recipient->getKey(), $notificationTrigger);

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
