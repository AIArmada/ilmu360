<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationCadence;
use App\Enums\NotificationRuleScope;
use App\Models\NotificationRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationRule>
 */
class NotificationRuleFactory extends Factory
{
    protected $model = NotificationRule::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'scope_type' => NotificationRuleScope::Family->value,
            'scope_key' => 'event_updates',
            'enabled' => true,
            'cadence' => NotificationCadence::Instant->value,
            'channels' => ['in_app', 'email'],
            'fallback_channels' => ['email'],
            'urgent_override' => null,
            'meta' => [],
        ];
    }
}
