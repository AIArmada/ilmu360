<?php

use App\Enums\ContactCategory;
use App\Models\Speaker;
use App\Models\User;
use Database\Seeders\SpeakerSeeder;

test('speaker seeder keeps real speaker contacts idempotent across reruns', function () {
    User::factory()->count(3)->create();

    $this->seed(SpeakerSeeder::class);

    $speaker = Speaker::query()
        ->where('name', 'Ustaz Azhar Idrus')
        ->firstOrFail();

    expect($speaker->contacts()->where('category', ContactCategory::Email->value)->count())->toBe(1)
        ->and($speaker->contacts()->where('category', ContactCategory::Phone->value)->count())->toBe(1);

    $this->seed(SpeakerSeeder::class);

    $speaker->refresh();

    expect($speaker->contacts()->where('category', ContactCategory::Email->value)->count())->toBe(1)
        ->and($speaker->contacts()->where('category', ContactCategory::Phone->value)->count())->toBe(1);
});
