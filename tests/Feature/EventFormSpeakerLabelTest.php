<?php

use App\Filament\Resources\Events\Schemas\EventForm;
use App\Models\Speaker;

it('uses formatted speaker names for organizer options in event form', function () {
    $speaker = Speaker::factory()->create([
        'name' => 'Aisyah binti Noor',
        'status' => 'verified',
        'honorific' => ['toh_puan'],
        'pre_nominal' => ['dr'],
    ]);

    $method = new ReflectionMethod(EventForm::class, 'getOrganizerOptions');

    /** @var array<string, string> $options */
    $options = $method->invoke(null, Speaker::class);

    expect($options)->toHaveKey((string) $speaker->id)
        ->and($options[(string) $speaker->id])->toBe($speaker->formatted_name)
        ->and($options[(string) $speaker->id])->not->toBe($speaker->name);
});
