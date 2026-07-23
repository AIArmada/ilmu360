<?php

use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config()->set('scout.queue', [
        'connection' => 'sync',
        'queue' => 'scout',
    ]);
});

it('queues institution reindex when an addressable is created for an institution', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);

    syncPrimaryAddressForTest($institution, [
        'line1' => 'Jalan Institution',
        'country' => 'Malaysia',
        'country_code' => 'MY',
    ]);

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (mixed $model): bool => $model instanceof Institution && $model->is($institution)
    ));
});

it('queues institution and related event reindex when an addressable is created', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);

    syncPrimaryAddressForTest($institution, [
        'line1' => 'Jalan Event',
        'country' => 'Malaysia',
        'country_code' => 'MY',
    ]);

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (mixed $model): bool => $model instanceof Institution && $model->is($institution)
    ));
});

it('queues speaker reindex when an addressable is created for a speaker', function () {
    $speaker = Speaker::factory()->create(['status' => 'verified']);

    syncPrimaryAddressForTest($speaker, [
        'line1' => 'Jalan Speaker',
        'country' => 'Malaysia',
        'country_code' => 'MY',
    ]);

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (mixed $model): bool => $model instanceof Speaker && $model->is($speaker)
    ));
});

it('queues event reindex when an addressable is created for a venue with events', function () {
    $venue = Venue::factory()->create(['status' => 'verified']);
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'default_venue_id' => $venue->id,
    ]);

    syncPrimaryAddressForTest($venue, [
        'line1' => 'Jalan Venue',
        'country' => 'Malaysia',
        'country_code' => 'MY',
    ]);

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (mixed $model): bool => $model instanceof Event && $model->is($event)
    ));
});
