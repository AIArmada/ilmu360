<?php

use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;

it('queues person reindexing when a person address changes', function () {
    Queue::fake();
    config()->set('scout.queue', [
        'connection' => 'sync',
        'queue' => 'scout',
    ]);

    $person = Person::factory()->create([
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($person, [
        'line1' => 'Jalan Baru 1',
    ]);

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (Person $model): bool => $model->is($person)
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
    ]);

    $event = Event::factory()->for($institution)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);

    syncPrimaryAddressForTest($institution, [
        'line1' => 'Jalan Baru 2',
    ]);

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (mixed $model): bool => $model instanceof Institution && $model->is($institution)
    ));

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (mixed $model): bool => $model instanceof Event && $model->is($event)
    ));
});
