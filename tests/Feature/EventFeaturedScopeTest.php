<?php

use AIArmada\Events\Models\EventOccurrence;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('filters occurrences through the canonical featured scope', function () {
    $featured = Event::factory()->create();
    $featuredOccurrence = $featured->occurrences()->firstOrFail();
    $featuredOccurrence->update(['is_featured' => true]);

    $notFeatured = Event::factory()->create();
    $notFeaturedOccurrence = $notFeatured->occurrences()->firstOrFail();
    $notFeaturedOccurrence->update(['is_featured' => false]);

    $unset = Event::factory()->create();
    $unsetOccurrence = $unset->occurrences()->firstOrFail();

    $ids = EventOccurrence::query()->featured()->pluck('id')->all();

    expect($ids)
        ->toContain($featuredOccurrence->id)
        ->not()->toContain($notFeaturedOccurrence->id)
        ->not()->toContain($unsetOccurrence->id);
});
