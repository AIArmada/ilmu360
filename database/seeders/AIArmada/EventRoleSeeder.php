<?php

declare(strict_types=1);

namespace Database\Seeders\AIArmada;

use AIArmada\Events\Models\EventRole;
use App\Enums\EventKeyPersonRole;
use Illuminate\Database\Seeder;

class EventRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'organizer' => ['name' => 'Organizer', 'description' => 'Event organizer (institution/speaker hosting the event)'],
        ];

        foreach (EventKeyPersonRole::cases() as $case) {
            $roles[$case->value] = ['name' => $case->getLabel(), 'description' => null];
        }

        $sortOrder = 0;

        foreach ($roles as $code => $data) {
            EventRole::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                ],
            );

            $sortOrder++;
        }
    }
}
