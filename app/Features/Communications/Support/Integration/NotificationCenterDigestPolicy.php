<?php

declare(strict_types=1);

namespace App\Features\Communications\Support\Integration;

use App\Features\Communications\Api\AccountNotificationPreferences;
use App\Features\Communications\Api\NotificationTrigger;
use App\Shared\Auth\UserMorphType;
use App\Shared\Communications\DigestPolicy;
use App\Shared\Communications\DigestPolicySnapshot;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
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
        private UserMorphType $userMorphType,
    ) {}

    public function resolve(Authenticatable $recipient, string $trigger): DigestPolicySnapshot
    {
        if (! $recipient instanceof Model || $recipient->getMorphClass() !== $this->userMorphType->morphClass()) {
            throw new InvalidArgumentException('Unknown digest recipient.');
        }

        $notificationTrigger = NotificationTrigger::tryFrom($trigger);

        if (! $notificationTrigger instanceof NotificationTrigger) {
            throw new InvalidArgumentException("Unknown digest trigger [{$trigger}].");
        }

        $policy = $this->settings->resolvePolicy((string) $recipient->getAuthIdentifier(), $notificationTrigger);

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
