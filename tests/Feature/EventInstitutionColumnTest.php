<?php

use App\Models\Event;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists the event location institution as a native column', function () {
    $institution = Institution::factory()->create();
    $event = Event::factory()->create(['institution_id' => $institution->getKey()]);

    $row = $event->newQuery()->findOrFail($event->getKey());

    expect($row->institution_id)
        ->toBe((string) $institution->getKey())
        ->and(data_get($row->metadata, 'institution_id'))->toBeNull();
});
