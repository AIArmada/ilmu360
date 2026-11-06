<?php

use App\Data\Api\Event\EventPayloadData;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps the stable event payload fields serialized at the API boundary', function () {
    $event = Event::factory()->featured()->create([
        'status' => 'approved',
    ]);

    $payload = EventPayloadData::fromModel($event)->toArray();

    expect($payload)
        ->toHaveKey('id', $event->id)
        ->toHaveKey('status', 'approved')
        ->not->toHaveKey('is_featured')
        ->toHaveKey('event_categories')
        ->toHaveKey('change_announcements')
        ->toHaveKey('replacement_event')
        ->toHaveKey('display_occurrence');

    expect($payload['display_occurrence'])
        ->toHaveKey('is_featured', true);
});

it('serializes package-native event fields', function () {
    $event = Event::factory()->create([
        'delivery_mode' => 'online',
        'default_venue_id' => null,
    ]);

    $payload = EventPayloadData::fromModel($event)->toArray();

    expect($payload)
        ->toHaveKey('delivery_mode', 'online')
        ->toHaveKey('default_venue_id');
});
