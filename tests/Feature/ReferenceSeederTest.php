<?php

use App\Enums\ReferenceType;
use App\Models\Event;
use App\Models\Reference;
use Database\Seeders\ReferenceSeeder;

it('seeds references using submit-event compatible fields and links', function () {
    Event::factory()->create([
        'status' => 'approved',
        'title' => 'Kuliah Maghrib: Tafsir Juz Amma',
    ]);

    $this->seed(ReferenceSeeder::class);

    $types = Reference::query()->pluck('type')->unique()->values()->all();
    $statuses = Reference::query()->pluck('status')->unique()->values()->all();

    expect($types)
        ->toContain(ReferenceType::Book->value)
        ->toContain(ReferenceType::Article->value)
        ->toContain(ReferenceType::Video->value)
        ->toContain(ReferenceType::Other->value)
        ->not->toContain('kitab');

    expect($statuses)
        ->toContain('verified')
        ->toContain('pending');

    $reference = Reference::query()->where('title', 'Riyadhus Solihin')->first();

    expect($reference)->not->toBeNull()
        ->and($reference?->is_active)->toBeTrue()
        ->and($reference?->socialMedia()->where('platform', 'website')->exists())->toBeTrue();
});

it('attaches seeded references to approved events via event_reference pivot', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'title' => 'Kuliah Maghrib: Tafsir Juz Amma',
    ]);

    $this->seed(ReferenceSeeder::class);

    $event->refresh()->load('references');

    expect($event->references->isNotEmpty())->toBeTrue()
        ->and($event->references->pluck('pivot.order_column')->filter()->isNotEmpty())->toBeTrue();
});
