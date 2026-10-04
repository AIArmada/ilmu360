<?php

use App\Enums\EventAgeGroup;
use App\Livewire\Pages\Events\Index;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\Language;
use App\Services\EventSearchService;
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

    expect(Cache::get('event_filter_languages_v4'))
        ->toBeArray()
        ->and(Cache::get('event_filter_languages_v4'))
        ->toHaveKey('ms');
});

it('hydrates the submit event safe option caches into the current payload format', function () {
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

    expect(Cache::get('submit_tags_domain_ms_safe_v2'))->toBeArray();
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

    Cache::put(
        'default_events_search_v2',
        [
            'ids' => [(string) $firstEvent->id, (string) $secondEvent->id],
            'total' => 2,
        ],
        now()->addMinute(),
    );

    $paginator = $service->search(null, [], 12, 'time');

    expect($paginator->total())->toBe(2)
        ->and(collect($paginator->items())->map(fn (Event $event): string => (string) $event->id)->all())
        ->toBe([(string) $firstEvent->id, (string) $secondEvent->id])
        ->and(Cache::get('default_events_search_v2'))->toMatchArray([
            'ids' => [(string) $firstEvent->id, (string) $secondEvent->id],
            'total' => 2,
        ]);
});
