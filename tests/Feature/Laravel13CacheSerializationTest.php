<?php

use App\Features\Discovery\Services\EventSearchService;
use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\EntryPoints\Livewire\Pages\Index;
use App\Features\Events\EntryPoints\Livewire\Pages\SubmitEvent\Create;
use App\Features\Events\Models\Event;
use App\Platform\Language\MalaysiaLanguageCatalog;
use App\Platform\Language\Models\Language;
use App\Shared\Cache\DiscoveryCache;
use App\Shared\Cache\SelectionCatalog;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

it('hydrates the events index language cache into the current safe payload format', function () {
    config()->set('cache.default', 'database');
    app('cache')->setDefaultDriver('database');
    Cache::flush();

    Language::firstOrCreate(
        ['code' => 'ms'],
        ['name' => 'Malay', 'native' => 'Bahasa Melayu', 'dir' => 'ltr'],
    );

    Livewire::test(Index::class)
        ->assertSee('Temui majlis ilmu yang');

    $options = app(SelectionCatalog::class)->languageOptions(
        'code',
        MalaysiaLanguageCatalog::labels(),
    );

    expect($options)->toBeArray()->toHaveKey('ms');
});

it('hydrates the submit event safe option caches into the current versioned payload format', function () {
    config()->set('cache.default', 'database');
    app('cache')->setDefaultDriver('database');
    Cache::flush();
    app()->setLocale('ms');

    Livewire::test(Create::class)
        ->set('data.age_group', [EventAgeGroup::Children->value])
        ->assertSet('data.age_group', [EventAgeGroup::Children->value])
        ->assertFormFieldExists('languages', function (Select $field): bool {
            expect($field->getOptions())->not->toBeEmpty();

            return true;
        });

    $version = app(DiscoveryCache::class)->version((string) config('discovery.cache.versions.submission_events'));

    expect(Cache::get("submit_tags_domain:ms:{$version}"))->toBeArray();
});

it('rehydrates the current default events search cache safely from the database cache store', function () {
    config()->set('cache.default', 'database');
    app('cache')->setDefaultDriver('database');
    Cache::flush();
    config()->set('scout.driver', 'database');

    $firstEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDay(),
    ]);
    $secondEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(2),
    ]);

    $service = app(EventSearchService::class);
    $version = app(DiscoveryCache::class)->version((string) config('discovery.cache.versions.events_schedule'));

    Cache::put(
        "default_events_search:country:global:per:12:{$version}",
        [
            'ids' => [(string) $firstEvent->id, (string) $secondEvent->id],
            'total' => 2,
        ],
        now()->addMinute(),
    );

    $paginator = $service->search(null, [], 12, 'time');

    expect($paginator->total())->toBe(2)
        ->and(collect($paginator->items())->map(fn ($event): string => (string) $event->id)->all())
        ->toBe([(string) $firstEvent->id, (string) $secondEvent->id])
        ->and(Cache::get("default_events_search:country:global:per:12:{$version}"))->toMatchArray([
            'ids' => [(string) $firstEvent->id, (string) $secondEvent->id],
            'total' => 2,
        ]);
});
