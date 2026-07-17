<?php

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('filters events through the canonical featured scope', function () {
    $featured = Event::factory()->create(['is_featured' => true]);
    $notFeatured = Event::factory()->create(['is_featured' => false]);
    $unset = Event::factory()->create();

    $ids = Event::query()->featured()->pluck('id')->all();

    expect($ids)
        ->toContain($featured->id)
        ->not()->toContain($notFeatured->id)
        ->not()->toContain($unset->id);
});
