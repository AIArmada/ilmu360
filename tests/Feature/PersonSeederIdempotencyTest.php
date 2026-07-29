<?php

use AIArmada\Contacting\Enums\ContactMethodType;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\PersonSeeder;

test('person seeder keeps real person contacts idempotent across reruns', function () {
    User::factory()->count(3)->create();

    $this->seed(PersonSeeder::class);

    $person = Person::query()
        ->where('name', 'Fawwaz Mat Jan')
        ->firstOrFail();

    expect($person->contactMethods()->where('type', ContactMethodType::Email->value)->count())->toBe(1)
        ->and($person->contactMethods()->where('type', ContactMethodType::Phone->value)->count())->toBe(1)
        ->and($person->name)->toBe('Fawwaz Mat Jan')
        ->and($person->formatted_name)->toBe('Ustaz Fawwaz Mat Jan')
        ->and($person->titleAssignments)->toHaveCount(1)
        ->and($person->primaryAddress()?->country_code)->toBe('MY');

    $this->seed(PersonSeeder::class);

    $person->refresh();

    expect($person->contactMethods()->where('type', ContactMethodType::Email->value)->count())->toBe(1)
        ->and($person->contactMethods()->where('type', ContactMethodType::Phone->value)->count())->toBe(1)
        ->and($person->titleAssignments)->toHaveCount(1);
});

test('person seeder assigns multiple titles through the persons package', function () {
    User::factory()->create();

    $this->seed(PersonSeeder::class);

    $person = Person::query()
        ->where('name', 'Muhaya Mohamad')
        ->firstOrFail();

    expect($person->formatted_name)->toBe('Prof Dr. Muhaya Mohamad')
        ->and($person->titleAssignments)->toHaveCount(2)
        ->and($person->primaryAddress()?->country_code)->toBe('MY');
});
