<?php

declare(strict_types=1);

use App\Features\Events\Api\Data\EventScheduleCard;
use App\Features\Events\Api\EventPageSchedule;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\Series;
use App\Features\Places\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

it('provides immutable schedule cards rather than foreign event models', function (): void {
    $venue = Venue::factory()->create(['status' => 'verified']);
    $series = Series::factory()->create(['status' => 'active', 'visibility' => 'public']);
    $event = Event::factory()->create([
        'title' => 'Jadual Berkontrak',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'default_venue_id' => $venue->getKey(),
    ]);
    $series->events()->attach($event->getKey(), ['id' => (string) Str::uuid(), 'sort_order' => 1]);

    $reader = app(EventPageSchedule::class);
    $venuePage = $reader->forVenue((string) $venue->getKey(), 8, 8);
    $seriesPage = $reader->forSeries((string) $series->getKey(), 8, 8);

    expect($venuePage['upcoming_total'])->toBe(1)
        ->and($venuePage['upcoming'])->toHaveCount(1)
        ->and($venuePage['upcoming'][0])->toBeInstanceOf(EventScheduleCard::class)
        ->not->toBeInstanceOf(Model::class)
        ->and($venuePage['upcoming'][0]->title)->toBe('Jadual Berkontrak')
        ->and($seriesPage['upcoming_total'])->toBe(1)
        ->and($seriesPage['upcoming'][0])->toBeInstanceOf(EventScheduleCard::class)
        ->and($seriesPage['upcoming'][0]->url)->toBe(route('events.show', $event));

    $this->get(route('venues.show', $venue))->assertSuccessful()->assertSee('Jadual Berkontrak');
    $this->get(route('series.show', $series))->assertSuccessful()->assertSee('Jadual Berkontrak');
});
