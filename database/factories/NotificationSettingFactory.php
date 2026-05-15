<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationSetting>
 */
class NotificationSettingFactory extends Factory
{
    protected $model = NotificationSetting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'locale' => 'ms',
            'timezone' => 'Asia/Kuala_Lumpur',
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '07:00:00',
            'digest_delivery_time' => '08:00:00',
            'digest_weekly_day' => 1,
            'preferred_channels' => ['in_app', 'email', 'push'],
            'fallback_channels' => ['email', 'push'],
            'fallback_strategy' => 'next_available',
            'urgent_override' => true,
            'meta' => [],
        ];
    }
}
