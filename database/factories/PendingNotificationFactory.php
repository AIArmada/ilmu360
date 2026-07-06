<?php

namespace Database\Factories;

use App\Models\PendingNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PendingNotificationFactory extends Factory
{
    protected $model = PendingNotification::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'family' => 'event_updates',
            'trigger' => 'event_schedule_changed',
            'priority' => 'medium',
            'title' => 'Legacy notification',
            'body' => 'Legacy notification body',
        ];
    }
}
