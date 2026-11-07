<?php

declare(strict_types=1);

use App\Actions\SavedSearches\DeleteOwnerSavedSearchesAction;
use App\Actions\SavedSearches\RestoreSavedSearchesAction;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;

it('deletes only searches matching both owner type and owner identity', function (): void {
    $owner = User::factory()->create();
    $owned = SavedSearch::factory()->create(['user_id' => $owner->getKey()]);
    $otherOwner = SavedSearch::factory()->create();
    $otherType = SavedSearch::factory()->create([
        'user_type' => 'another-owner-type',
        'user_id' => $owner->getKey(),
    ]);

    DeleteOwnerSavedSearchesAction::run($owner->getMorphClass(), (string) $owner->getKey());

    $this->assertModelMissing($owned);
    $this->assertModelExists($otherOwner);
    $this->assertModelExists($otherType);
});

it('restores the original attributes and timestamps without replaying creation events', function (): void {
    $savedSearch = SavedSearch::factory()->create([
        'name' => 'Restored search',
        'query' => 'kuliah',
        'created_at' => '2025-01-02 03:00:00',
        'updated_at' => '2025-02-03 04:00:00',
    ])->refresh();
    $attributes = $savedSearch->getRawOriginal();
    $snapshot = $savedSearch->attributesToArray();
    $savedSearch->delete();
    $eventName = 'eloquent.creating: '.SavedSearch::class;
    Event::fake([$eventName]);

    RestoreSavedSearchesAction::run([$snapshot]);

    $restored = SavedSearch::query()->findOrFail($savedSearch->getKey());
    expect(Arr::except($restored->getRawOriginal(), ['created_at', 'updated_at']))
        ->toBe(Arr::except($attributes, ['created_at', 'updated_at']))
        ->and($restored->created_at?->toISOString())->toBe('2025-01-02T03:00:00.000000Z')
        ->and($restored->updated_at?->toISOString())->toBe('2025-02-03T04:00:00.000000Z');
    Event::assertNotDispatched($eventName);
});
