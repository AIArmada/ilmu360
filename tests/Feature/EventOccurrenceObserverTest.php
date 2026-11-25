<?php

use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Catalog\Models\EventTimeExpression;
use App\Features\Events\Models\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;

function searchableEvent(): Event
{
    return Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);
}

function nonSearchableEvent(): Event
{
    return Event::factory()->create([
        'status' => 'draft',
        'visibility' => 'public',
        'published_at' => null,
    ]);
}

it('queues event reindex when an occurrence is created for a searchable event', function () {
    $event = searchableEvent();
    Queue::fake();
    config()->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);

    EventOccurrence::factory()->create(['event_id' => $event->id]);

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (mixed $model): bool => $model instanceof Event && $model->is($event)
    ));
});

it('does not queue event reindex when an occurrence is created for a non-searchable event', function () {
    $event = nonSearchableEvent();
    Queue::fake();
    config()->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);

    EventOccurrence::factory()->create(['event_id' => $event->id]);

    Queue::assertNotPushed(MakeSearchable::class);
});

it('queues event reindex when a time expression is created for a searchable event', function () {
    $event = searchableEvent();
    Queue::fake();
    config()->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);

    EventTimeExpression::factory()->create(['event_id' => $event->id]);

    Queue::assertPushed(MakeSearchable::class, fn (MakeSearchable $job): bool => $job->models->contains(
        fn (mixed $model): bool => $model instanceof Event && $model->is($event)
    ));
});

it('does not queue event reindex when a time expression is created for a non-searchable event', function () {
    $event = nonSearchableEvent();
    Queue::fake();
    config()->set('scout.queue', ['connection' => 'sync', 'queue' => 'scout']);

    EventTimeExpression::factory()->create(['event_id' => $event->id]);

    Queue::assertNotPushed(MakeSearchable::class);
});
