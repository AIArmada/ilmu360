<?php

use AIArmada\Addressing\Features\AddressLifecycle\Models\Address;
use AIArmada\Addressing\Features\Geography\Models\AddressCountry;
use AIArmada\Addressing\Features\Geography\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventGenderRestriction;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\Enums\TimingMode;
use App\Features\Events\EntryPoints\Livewire\Components\EventFilters;
use App\Features\Events\EntryPoints\Livewire\Pages\Index;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Api\SpeakerStatus;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * @return array{
 *     user: User,
 *     institutions: Illuminate\Support\Collection<int, Institution>,
 *     persons: Illuminate\Support\Collection<int, Person>,
 *     references: Illuminate\Support\Collection<int, Reference>,
 *     events: Illuminate\Support\Collection<int, Event>
 * }
 */
function seedPublicDirectoryPerformanceData(): array
{
    return OwnerContext::withOwner(null, function (): array {
        $country = AddressCountry::query()->firstOrCreate(
            ['iso2' => 'MY'],
            ['name' => 'Malaysia', 'iso3' => 'MYS', 'region' => 'Asia', 'subregion' => 'South-Eastern Asia', 'phone_code' => '60'],
        );

        $states = collect(['Selangor', 'Kuala Lumpur', 'Johor'])
            ->map(static fn (string $name): State => State::query()->create([
                'country_id' => (string) $country->getKey(),
                'name' => $name,
            ]));

        $institutions = collect(range(0, 14))->map(function (int $index) use ($country, $states): Institution {
            $institution = Institution::factory()->create(['status' => 'verified']);
            $state = $states[$index % $states->count()];

            $institution->attachAddress(
                Address::query()->create([
                    'country_id' => (string) $country->getKey(),
                    'country_code' => 'MY',
                    'state_id' => (string) $state->getKey(),
                    'state' => $state->name,
                ]),
                'primary',
                true,
            );

            return $institution->refresh();
        });

        $title = Title::query()->firstOrFail();

        $persons = collect(range(0, 14))->map(function (int $index) use ($title): Person {
            $person = Person::factory()->create([
                'status' => 'verified',
                'speaker_status' => SpeakerStatus::Active,
                'is_featured' => $index < 5,
            ]);

            TitleAssignment::query()->create([
                'titleable_type' => $person->getMorphClass(),
                'titleable_id' => $person->getKey(),
                'title_id' => $title->getKey(),
                'status' => AssignmentStatus::Active,
            ]);

            return $person->refresh();
        });

        $references = collect(range(0, 14))->map(function (int $index) use ($persons): Reference {
            $reference = Reference::factory()->create([
                'type' => 'book',
                'status' => 'verified',
                'published_at' => now()->subDay(),
            ]);

            $reference->authors()->attach($persons[$index % $persons->count()]->getKey(), [
                'id' => (string) Str::uuid(),
                'role' => 'author',
            ]);

            $edition = Reference::factory()->create([
                'type' => 'book',
                'record_kind' => 'edition',
                'parent_id' => $reference->getKey(),
            ]);

            Reference::factory()->create([
                'type' => 'book',
                'record_kind' => 'part',
                'part_type' => 'jilid',
                'part_number' => 1,
                'parent_id' => $edition->getKey(),
            ]);

            return $reference->refresh();
        });

        $events = collect(range(0, 19))->map(function (int $index) use ($institutions, $persons, $references): Event {
            $startsAt = now()->addDays(($index % 20) + 1)->setTime(20, 0);

            $event = Event::factory()->create([
                'status' => 'approved',
                'visibility' => EventVisibility::Public->value,
                'delivery_mode' => EventFormat::Physical->value,
                'timing_mode' => TimingMode::Absolute->value,
                'institution_id' => $institutions[$index % $institutions->count()]->getKey(),
                'default_venue_id' => null,
                'published_at' => now()->subDays(10),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHours(2),
            ]);

            $event->persons()->attach($persons[$index % $persons->count()]->getKey(), ['sort_order' => 0]);
            $event->references()->attach($references[$index % $references->count()]->getKey());

            foreach (range(1, 2) as $offset) {
                $event->occurrences()->create([
                    'title' => 'Future occurrence '.$offset,
                    'starts_at' => $startsAt->copy()->addDays($offset),
                    'ends_at' => $startsAt->copy()->addDays($offset)->addHours(2),
                    'status' => 'scheduled',
                    'visibility' => 'public',
                ]);
            }

            $event->occurrences()->create([
                'title' => 'Past occurrence',
                'starts_at' => now()->subDays(($index % 5) + 1),
                'ends_at' => now()->subDays(($index % 5) + 1)->addHours(2),
                'status' => 'scheduled',
                'visibility' => 'public',
            ]);

            if ($index < 6) {
                $event->occurrences()->update(['is_featured' => true]);
            }

            return $event->refresh();
        });

        $user = User::factory()->create();

        $user->follow($persons[0]);
        $user->follow($persons[1]);
        $user->follow($institutions[0]);
        $user->follow($institutions[1]);
        $user->follow($references[0]);
        $user->follow($references[1]);

        app(EngagementManager::class)->bookmark($user, $events[0]);
        app(EngagementManager::class)->bookmark($user, $events[1]);

        foreach ([$events[2], $events[3]] as $goingEvent) {
            $user->responses()->create([
                'respondable_type' => $goingEvent->getMorphClass(),
                'respondable_id' => $goingEvent->getKey(),
                'response_type' => 'going',
                'status' => 'active',
                'visibility' => 'public',
                'responded_at' => now(),
            ]);
        }

        return ['user' => $user, 'institutions' => $institutions, 'persons' => $persons, 'references' => $references, 'events' => $events];
    });
}

it('serves public directory pages without lazy-loading violations and within bounded warm-cache query counts', function (): void {
    $data = seedPublicDirectoryPerformanceData();
    $user = $data['user'];

    $urls = ['/', '/penceramah', '/majlis', '/institusi', '/rujukan'];

    $this->get('/')->assertOk();
    $this->get('/majlis')->assertOk();

    $violations = [];

    Model::preventLazyLoading(true);
    Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use (&$violations): void {
        $violations[] = $model->getMorphClass().'::'.$relation;
    });

    $measure = function (string $url): array {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get($url)->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        return ['count' => $queries->count(), 'queries' => $queries];
    };

    try {
        $guest = [];
        foreach ($urls as $url) {
            $guest[$url] = $measure($url);
        }

        $this->actingAs($user);

        $auth = [];
        foreach ($urls as $url) {
            $auth[$url] = $measure($url);
        }
    } finally {
        Model::preventLazyLoading(false);
        Model::handleLazyLoadingViolationUsing(null);
    }

    expect($guest['/']['count'])->toBeLessThanOrEqual(38)
        ->and($auth['/']['count'])->toBeLessThanOrEqual(48)
        ->and($guest['/penceramah']['count'])->toBeLessThanOrEqual(15)
        ->and($auth['/penceramah']['count'])->toBeLessThanOrEqual(20)
        ->and($guest['/majlis']['count'])->toBeLessThanOrEqual(34)
        ->and($auth['/majlis']['count'])->toBeLessThanOrEqual(40)
        ->and($guest['/institusi']['count'])->toBeLessThanOrEqual(15)
        ->and($auth['/institusi']['count'])->toBeLessThanOrEqual(20)
        ->and($guest['/rujukan']['count'])->toBeLessThanOrEqual(16)
        ->and($auth['/rujukan']['count'])->toBeLessThanOrEqual(22)
        ->and($violations)->toBeEmpty();

    $countAggregate = static fn (string $query, string $table): bool => str_contains($query, 'from "'.$table.'"')
        && str_contains($query, 'count(*) as "aggregate"');

    expect($guest['/penceramah']['queries']->filter(static fn (string $query): bool => $countAggregate($query, 'persons')))->toBeEmpty()
        ->and($guest['/institusi']['queries']->filter(static fn (string $query): bool => $countAggregate($query, 'institutions')))->toBeEmpty()
        ->and($guest['/rujukan']['queries']->filter(static fn (string $query): bool => $countAggregate($query, 'references')))->toBeEmpty()
        ->and($guest['/majlis']['queries']->filter(static fn (string $query): bool => str_contains($query, '0 = 1')))->toBeEmpty();
});

it('keeps the event filter form unmounted while the panel is closed and mounts it on demand', function (): void {
    Livewire::test(Index::class)
        ->assertSet('filtersPanelOpen', false)
        ->assertSet('filterFormMounted', false)
        ->assertDontSee('Cari atau pilih penceramah...');

    Livewire::test(EventFilters::class)
        ->assertSet('filterFormMounted', false)
        ->assertDontSee('Cari atau pilih penceramah...');

    $this->get('/')->assertOk()->assertSee('data-testid="near-me-button"', false);

    Livewire::test(Index::class)
        ->call('mountFilterForm')
        ->assertSet('filterFormMounted', true)
        ->assertSee('Cari atau pilih penceramah...');

    Livewire::test(Index::class)
        ->call('openFiltersPanel')
        ->assertSet('filtersPanelOpen', true)
        ->assertSet('filterFormMounted', true)
        ->assertSee('Cari atau pilih penceramah...');

    Livewire::test(Index::class, ['filters_panel' => 'open'])
        ->assertSet('filterFormMounted', true);

    Livewire::withQueryParams(['gender' => EventGenderRestriction::MenOnly->value])
        ->test(Index::class)
        ->assertSet('filterFormMounted', true);
});

it('pushes majlis occurrence pagination into SQL with windowed counts and page-scoped hydration', function (): void {
    seedPublicDirectoryPerformanceData();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get('/majlis')->assertOk();

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $windowed = $queries->filter(
        static fn (string $query): bool => str_contains($query, 'from "event_occurrences"')
            && str_contains($query, 'count(*) over ()')
            && str_contains($query, 'limit'),
    );

    expect($windowed)->toHaveCount(1);

    $hydration = $queries->filter(
        static fn (string $query): bool => str_starts_with($query, 'select * from "event_occurrences"')
            && str_contains($query, '"id" in (')
            && ! str_contains($query, 'count(*) over ()'),
    );

    expect($hydration)->toHaveCount(1);
});

it('serves webp assets with picture fallbacks, hero prioritization, and no duplicate filament css', function (): void {
    $webpAssets = [
        'images/footer-courtyard-v14-desktop',
        'images/footer-courtyard-v14-tablet',
        'images/footer-courtyard-v14-mobile',
        'images/home/speaker-feature-v1',
        'images/logo-ilmu360',
        'images/logo-ilmu360-footer',
    ];

    foreach ($webpAssets as $relativePath) {
        $webp = public_path($relativePath.'.webp');
        $png = public_path($relativePath.'.png');

        expect($webp)->toBeFile()
            ->and($png)->toBeFile()
            ->and(filesize($webp))->toBeLessThan(filesize($png));
    }

    $this->get('/')
        ->assertOk()
        ->assertSee('type="image/webp"', false)
        ->assertSee('images/home/speaker-feature-v1.webp', false)
        ->assertSee('images/logo-ilmu360-footer.webp', false)
        ->assertSee('images/footer-courtyard-v14-desktop.webp', false)
        ->assertSee('images/footer-courtyard-v14-tablet.webp', false)
        ->assertSee('images/footer-courtyard-v14-mobile.webp', false)
        ->assertDontSee('/css/filament/filament/app.css', false);

    $this->get('/majlis')
        ->assertOk()
        ->assertSee('images/logo-ilmu360.webp', false);

    foreach (['/', '/penceramah', '/majlis', '/institusi', '/rujukan'] as $url) {
        $this->get($url)->assertOk()->assertSee('fetchpriority="high"', false);
    }
});
