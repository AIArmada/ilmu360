<?php

declare(strict_types=1);

use AIArmada\Seating\Models\SeatMap;
use App\Models\Event;
use App\Platform\Seating\SeatMapAssignments;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reassigns selected seat maps and deletes unselected maps', function (): void {
    $event = Event::factory()->create();
    $otherEvent = Event::factory()->create();

    $kept = SeatMap::factory()->create([
        'seatable_type' => $event->getMorphClass(),
        'seatable_id' => $event->getKey(),
    ]);
    $stale = SeatMap::factory()->create([
        'seatable_type' => $event->getMorphClass(),
        'seatable_id' => $event->getKey(),
    ]);
    $adopted = SeatMap::factory()->create([
        'seatable_type' => $otherEvent->getMorphClass(),
        'seatable_id' => $otherEvent->getKey(),
    ]);
    $untouched = SeatMap::factory()->create([
        'seatable_type' => $otherEvent->getMorphClass(),
        'seatable_id' => $otherEvent->getKey(),
    ]);

    app(SeatMapAssignments::class)->sync($event, [$kept->id, $adopted->id]);

    expect($kept->refresh()->seatable_type)->toBe('event')
        ->and($kept->seatable_type)->toBe($event->getMorphClass())
        ->and($kept->seatable_id)->toBe($event->getKey())
        ->and($adopted->refresh()->seatable_type)->toBe($event->getMorphClass())
        ->and($adopted->seatable_id)->toBe($event->getKey())
        ->and(SeatMap::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and($event->seatMaps()->pluck('id')->sort()->values()->all())->toBe(collect([$kept->id, $adopted->id])->sort()->values()->all())
        ->and($untouched->refresh()->seatable_id)->toBe($otherEvent->getKey());
});

it('deletes every seat map when the selection is empty', function (): void {
    $event = Event::factory()->create();

    $first = SeatMap::factory()->create([
        'seatable_type' => $event->getMorphClass(),
        'seatable_id' => $event->getKey(),
    ]);
    $second = SeatMap::factory()->create([
        'seatable_type' => $event->getMorphClass(),
        'seatable_id' => $event->getKey(),
    ]);

    app(SeatMapAssignments::class)->sync($event, []);

    expect(SeatMap::query()->whereKey([$first->id, $second->id])->exists())->toBeFalse()
        ->and($event->seatMaps()->count())->toBe(0);
});
