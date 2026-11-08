<?php

declare(strict_types=1);

use App\Enums\NotificationCadence;
use App\Enums\NotificationTrigger;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Services\Notifications\EventNotificationService;
use App\Support\Notifications\NotificationDispatchData;
use Illuminate\Support\Str;

class SavedSearchNotificationRecorder extends EventNotificationService
{
    /** @var list<array{recipientId: string, data: NotificationDispatchData}> */
    public array $recordedNotifications = [];

    protected function dispatchForUsers(iterable $users, callable $builder): void
    {
        foreach ($users as $user) {
            $this->recordedNotifications[] = [
                'recipientId' => (string) $user->getKey(),
                'data' => $builder($user),
            ];
        }
    }
}

it('groups matching search snapshots into one owner notification with the fastest cadence', function (): void {
    config(['scout.driver' => 'database']);
    $this->travelTo('2026-04-01 00:00:00');
    $owner = User::factory()->create();
    $event = Event::factory()->for(Institution::factory())->create([
        'title' => 'Kuliah Tafsir',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(2),
    ]);
    $searches = SavedSearch::factory()->count(4)->sequence(
        ['name' => 'Weekly search', 'notify' => 'weekly'],
        ['name' => 'Daily search', 'notify' => 'daily'],
        ['name' => 'Instant search', 'notify' => 'instant'],
        ['name' => 'Fourth search', 'notify' => 'weekly'],
    )->create([
        'user_id' => $owner->id,
        'query' => 'kuliah',
        'filters' => [],
        'lat' => null, 'lng' => null, 'radius_km' => null,
    ]);
    SavedSearch::factory()->create([
        'user_id' => $owner->id, 'query' => 'absentwordxyz', 'filters' => [], 'notify' => 'instant',
        'lat' => null, 'lng' => null, 'radius_km' => null,
    ]);
    SavedSearch::factory()->create(['notify' => 'off']);
    SavedSearch::factory()->create([
        'user_type' => (new Institution)->getMorphClass(), 'user_id' => $owner->id, 'notify' => 'instant',
    ]);
    SavedSearch::factory()->create([
        'user_id' => (string) Str::uuid(), 'notify' => 'instant',
    ]);
    $service = app(SavedSearchNotificationRecorder::class);

    $service->notifyPublication($event);

    expect($service->recordedNotifications)->toHaveCount(1)
        ->and($service->recordedNotifications[0]['recipientId'])->toBe($owner->id);
    $notification = $service->recordedNotifications[0]['data'];
    expect($notification->trigger)->toBe(NotificationTrigger::SavedSearchMatch)
        ->and($notification->forcedCadence)->toBe(NotificationCadence::Instant)
        ->and($notification->fingerprint)->toBe('saved-search-match:'.$event->id.':'.$owner->id)
        ->and($notification->meta)->toBe([
            'saved_search_ids' => $searches->modelKeys(),
            'saved_search_names' => ['Weekly search', 'Daily search', 'Instant search', 'Fourth search'],
        ])
        ->and(data_get($notification->render, 'body.params.searches'))->toBe('Weekly search, Daily search, Instant search');
});

it('uses daily cadence for unknown subscriptions and ignores structured text query values', function (): void {
    config(['scout.driver' => 'database']);
    $this->travelTo('2026-04-01 00:00:00');
    $event = Event::factory()->for(Institution::factory())->create([
        'status' => 'approved', 'visibility' => 'public',
        'published_at' => now(), 'starts_at' => now()->addDays(2),
    ]);
    SavedSearch::factory()->create([
        'query' => ['search' => 'absentwordxyz'], 'filters' => [], 'notify' => 'unrecognized',
        'lat' => null, 'lng' => null, 'radius_km' => null,
    ]);
    $service = app(SavedSearchNotificationRecorder::class);

    $service->notifyPublication($event);

    expect($service->recordedNotifications)->toHaveCount(1)
        ->and($service->recordedNotifications[0]['data']->forcedCadence)->toBe(NotificationCadence::Daily);
});

it('matches complete geographic searches nearby and falls back to text for incomplete coordinates', function (): void {
    config(['scout.driver' => 'database']);
    $this->travelTo('2026-04-01 00:00:00');
    $institution = Institution::factory()->create();
    syncPrimaryAddressForTest($institution, ['lat' => 3.1390, 'lng' => 101.6869]);
    $event = Event::factory()->for($institution)->create([
        'default_venue_id' => null,
        'status' => 'approved', 'visibility' => 'public',
        'published_at' => now(), 'starts_at' => now()->addDays(2),
    ]);
    $near = SavedSearch::factory()->create([
        'query' => 'absentwordxyz', 'filters' => [], 'notify' => 'weekly',
        'lat' => 3.1390, 'lng' => 101.6869, 'radius_km' => 12,
    ]);
    SavedSearch::factory()->create([
        'query' => null, 'filters' => [], 'notify' => 'weekly',
        'lat' => 5.4, 'lng' => 100.3, 'radius_km' => 12,
    ]);
    SavedSearch::factory()->create([
        'query' => 'absentwordxyz', 'filters' => [], 'notify' => 'weekly',
        'lat' => 3.1390, 'lng' => null, 'radius_km' => 12,
    ]);
    $service = app(SavedSearchNotificationRecorder::class);

    $service->notifyPublication($event);

    expect($service->recordedNotifications)->toHaveCount(1)
        ->and($service->recordedNotifications[0]['recipientId'])->toBe($near->user_id)
        ->and($service->recordedNotifications[0]['data']->forcedCadence)->toBe(NotificationCadence::Weekly);
});
