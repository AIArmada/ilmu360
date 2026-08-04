<?php

use AIArmada\Events\Models\EventOccurrence;
use App\Models\Event;
use App\Services\ModerationService;
use Illuminate\Validation\ValidationException;

function makeOrphanedPublishedEvent(): Event
{
    $event = Event::factory()->create([
        'status' => 'draft',
        'published_at' => null,
        'visibility' => 'public',
    ]);

    $event->occurrences()->delete();
    $event->forceFill([
        'status' => 'approved',
        'published_at' => now(),
    ])->saveQuietly();

    return $event->fresh();
}

it('rejects event approval when the event has no occurrence', function (): void {
    $event = Event::factory()->create([
        'status' => 'draft',
        'published_at' => null,
    ]);
    $event->occurrences()->delete();
    $event->forceFill(['status' => 'pending'])->saveQuietly();

    expect(fn () => app(ModerationService::class)->approve($event))
        ->toThrow(ValidationException::class);

    expect((string) $event->fresh()->status)->toBe('pending')
        ->and($event->fresh()->published_at)->toBeNull();
});

it('excludes occurrence-less published events from public reachability and active discovery', function (): void {
    $event = makeOrphanedPublishedEvent();

    expect($event->isPubliclyReachable())->toBeFalse()
        ->and(Event::query()->active()->whereKey($event)->exists())->toBeFalse()
        ->and(Event::query()->discoverable()->whereKey($event)->exists())->toBeFalse();

    $this->getJson(route('api.events.index'))
        ->assertOk()
        ->assertJsonMissing(['id' => $event->id]);
    $this->get(route('events.show', $event))->assertNotFound();
    $this->getJson(route('api.events.show', $event))->assertNotFound();
    $this->get(route('events.calendar', $event))->assertNotFound();
});

it('does not allow deleting the last occurrence from a published event', function (): void {
    $event = Event::factory()->create([
        'status' => 'approved',
        'published_at' => now(),
    ]);
    $occurrence = $event->occurrences()->firstOrFail();

    expect(fn (): bool => (bool) $occurrence->delete())
        ->toThrow(ValidationException::class);

    expect(EventOccurrence::query()->whereKey($occurrence)->exists())->toBeTrue();
});

it('allows deleting one occurrence while another occurrence remains', function (): void {
    $event = Event::factory()->create([
        'status' => 'approved',
        'published_at' => now(),
    ]);
    $occurrence = $event->occurrences()->firstOrFail();
    $replacement = EventOccurrence::factory()->create(['event_id' => $event->getKey()]);

    $occurrence->delete();

    expect(EventOccurrence::query()->whereKey($occurrence)->exists())->toBeFalse()
        ->and(EventOccurrence::query()->whereKey($replacement)->exists())->toBeTrue();
});
