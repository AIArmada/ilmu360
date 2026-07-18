<?php

use App\Data\Api\Event\EventPayloadData;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps the stable event payload fields serialized at the API boundary', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'is_featured' => true,
    ]);

    $payload = EventPayloadData::fromModel($event)->toArray();

    expect($payload)
        ->toHaveKey('id', $event->id)
        ->toHaveKey('status', 'approved')
        ->toHaveKey('is_featured', true)
        ->toHaveKey('event_categories')
        ->toHaveKey('change_announcements')
        ->toHaveKey('replacement_event');
});

it('serializes only package-native event fields and omits removed aliases', function () {
    $event = Event::factory()->create([
        'delivery_mode' => 'online',
        'default_venue_id' => null,
    ]);

    $payload = EventPayloadData::fromModel($event)->toArray();

    expect($payload)
        ->toHaveKey('delivery_mode', 'online')
        ->toHaveKey('default_venue_id')
        ->not->toHaveKey('type')
        ->not->toHaveKey('event_format')
        ->not->toHaveKey('venue_id');
});
