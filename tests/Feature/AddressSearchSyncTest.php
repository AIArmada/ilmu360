<?php

use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;

uses(RefreshDatabase::class);

it('queues speaker reindexing when a speaker address changes', function () {
    Queue::fake();
    config()->set('scout.queue', [
        'connection' => 'sync',
        'queue' => 'scout',
    ]);

    $speaker = Speaker::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    $speaker->address()->update([
        'line1' => 'Jalan Baru 1',
    ]);

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (Speaker $model): bool => $model->is($speaker)
    ));
});

it('queues institution and related event reindexing when an institution address changes', function () {
    Queue::fake();
    config()->set('scout.queue', [
        'connection' => 'sync',
        'queue' => 'scout',
    ]);

    $institution = Institution::factory()->create([
        'status' => 'verified',
        'is_active' => true,
    ]);

    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $institution->address()->update([
        'line1' => 'Jalan Baru 2',
    ]);

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (mixed $model): bool => $model instanceof Institution && $model->is($institution)
    ));

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (mixed $model): bool => $model instanceof Event && $model->is($event)
    ));
});
