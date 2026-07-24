<?php

use AIArmada\Contacting\Enums\ContactMethodType;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\PersonSeeder;

test('person seeder keeps real person contacts idempotent across reruns', function () {
    User::factory()->count(3)->create();

    $this->seed(PersonSeeder::class);

    $person = Person::query()
        ->where('name', 'Ustaz Azhar Idrus')
        ->firstOrFail();

    expect($person->contactMethods()->where('type', ContactMethodType::Email->value)->count())->toBe(1)
        ->and($person->contactMethods()->where('type', ContactMethodType::Phone->value)->count())->toBe(1);

    $this->seed(PersonSeeder::class);

    $person->refresh();

    expect($person->contactMethods()->where('type', ContactMethodType::Email->value)->count())->toBe(1)
        ->and($person->contactMethods()->where('type', ContactMethodType::Phone->value)->count())->toBe(1);
});
