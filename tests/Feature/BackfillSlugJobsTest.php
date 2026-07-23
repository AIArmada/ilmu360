<?php

use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\References\GenerateReferenceSlugAction;
use App\Actions\Venues\GenerateVenueSlugAction;
use App\Jobs\BackfillEventSlugs;
use App\Jobs\BackfillReferenceSlugs;
use App\Jobs\BackfillVenueSlugs;
use App\Models\Event;
use App\Models\Reference;
use App\Models\Venue;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('backfills event slugs', function () {
    $event = Event::factory()->create([
        'title' => 'Kuliah Maghrib',
        'slug' => 'legacy-event-slug',
        'status' => 'approved',
        'published_at' => now(),
    ]);

    app(BackfillEventSlugs::class)->handle(
        app(GenerateEventSlugAction::class),
        app(PublicListingsCache::class),
    );

    expect($event->fresh()->slug)->not->toBe('legacy-event-slug');
});

it('updates event slug from a legacy value to the generated format', function () {
    $event = Event::factory()->create([
        'title' => 'Kuliah Maghrib',
        'slug' => 'legacy-event-slug',
        'status' => 'approved',
        'published_at' => now(),
    ]);

    app(BackfillEventSlugs::class)->handle(
        app(GenerateEventSlugAction::class),
        app(PublicListingsCache::class),
    );

    $fresh = $event->fresh();
    expect($fresh->slug)->not->toBe('legacy-event-slug')
        ->and($fresh->slug)->toContain('kuliah-maghrib');
});

it('backfills venue slugs', function () {
    $venue = Venue::factory()->create([
        'name' => 'Masjid Al-Hidayah',
        'slug' => 'legacy-venue-slug',
    ]);

    app(BackfillVenueSlugs::class)->handle(
        app(GenerateVenueSlugAction::class),
    );

    expect($venue->fresh()->slug)->not->toBe('legacy-venue-slug');
});

it('backfills reference slugs', function () {
    $reference = Reference::factory()->create([
        'title' => 'Kitab Al-Hikam',
        'slug' => 'legacy-ref-slug',
    ]);

    app(BackfillReferenceSlugs::class)->handle(
        app(GenerateReferenceSlugAction::class),
        app(PublicListingsCache::class),
    );

    expect($reference->fresh()->slug)->not->toBe('legacy-ref-slug');
});

it('queues the event slug backfill command', function () {
    Queue::fake();

    $this->artisan('events:queue-slug-backfill')
        ->assertSuccessful();

    Queue::assertPushed(BackfillEventSlugs::class);
});

it('queues the venue slug backfill command', function () {
    Bus::fake();

    $venue = Venue::factory()->create();
    $this->artisan('venues:queue-slug-backfill')
        ->assertSuccessful();

    Bus::assertBatched(fn ($batch) => $batch->jobs->contains(
        fn ($job) => $job instanceof BackfillVenueSlugs
    ));
});

it('queues the reference slug backfill command', function () {
    Queue::fake();

    $this->artisan('references:queue-slug-backfill')
        ->assertSuccessful();

    Queue::assertPushed(BackfillReferenceSlugs::class);
});
