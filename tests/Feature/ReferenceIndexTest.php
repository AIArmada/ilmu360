<?php

use App\Features\Directory\Api\ReferenceWorkflow;
use App\Models\Event;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Platform\Time\UserDateTimeFormatter;
use App\Support\Search\ReferenceSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\get;

beforeEach(function (): void {
    config()->set('scout.driver', 'null');
});

it('renders the public reference index hero and search copy', function () {
    app()->setLocale('ms');

    get('/rujukan')
        ->assertSuccessful()
        ->assertSee(__('Discover sources of'))
        ->assertSee(__('knowledge you can trust'))
        ->assertSee(__('Search references...'));
});

it('searches published verified and pending references by title', function () {
    Reference::factory()->create([
        'title' => 'Riyadhus Solihin Terjemahan',
        'status' => 'verified',
    ]);

    Reference::factory()->create([
        'title' => 'Bulughul Maram',
        'status' => 'verified',
    ]);

    get('/rujukan?search='.urlencode('riyadhus'))
        ->assertSuccessful()
        ->assertSee('Riyadhus Solihin Terjemahan')
        ->assertDontSee('Bulughul Maram')
        ->assertSee('data-search-query="riyadhus"', false)
        ->assertSee('wire:loading.delay.short', false)
        ->assertSee('wire:target="search"', false)
        ->assertSee(__('Searching…'));
});

it('filters the reference directory to followed references', function () {
    $user = User::factory()->create();
    $followedReference = Reference::factory()->create([
        'title' => 'Rujukan Diikuti Filter',
        'status' => 'verified',
    ]);
    $otherReference = Reference::factory()->create([
        'title' => 'Rujukan Lain Filter',
        'status' => 'verified',
    ]);

    $user->follow($followedReference);

    $this->actingAs($user);

    get('/rujukan?followed_only=1')
        ->assertSuccessful()
        ->assertSee('Rujukan Diikuti Filter')
        ->assertDontSee('Rujukan Lain Filter')
        ->assertSee('data-followed-filter', false);
});

it('keeps the followed reference filter active across search', function () {
    $user = User::factory()->create();
    $followedReference = Reference::factory()->create([
        'title' => 'Rujukan Diikuti Carian',
        'status' => 'verified',
    ]);
    $otherReference = Reference::factory()->create([
        'title' => 'Rujukan Lain Carian',
        'status' => 'verified',
    ]);

    $user->follow($followedReference);

    Livewire::actingAs($user)
        ->test('pages.references.index')
        ->set('followed_only', true)
        ->set('search', 'Carian')
        ->assertSee('Rujukan Diikuti Carian')
        ->assertDontSee('Rujukan Lain Carian');
});

it('only lists published verified and pending references on the public index', function () {
    Reference::factory()->create([
        'title' => 'Rujukan Sah Paparan',
        'status' => 'verified',
    ]);

    Reference::factory()->create([
        'title' => 'Rujukan Menunggu Semakan',
        'status' => 'pending',
    ]);

    Reference::factory()->create([
        'title' => 'Rujukan Tidak Aktif',
        'status' => 'inactive',
    ]);

    Reference::factory()->pending()->unpublished()->create([
        'title' => 'Rujukan Belum Diterbitkan',
    ]);

    get('/rujukan')
        ->assertSuccessful()
        ->assertSee('Rujukan Sah Paparan')
        ->assertSee('Rujukan Menunggu Semakan')
        ->assertDontSee('Rujukan Tidak Aktif')
        ->assertDontSee('Rujukan Belum Diterbitkan');
});

it('lists pending references in the directory and includes them in search', function () {
    Reference::factory()->create([
        'title' => 'Rujukan Belum Disahkan',
        'status' => 'pending',
    ]);

    Reference::factory()->pending()->unpublished()->create([
        'title' => 'Rujukan Pending Belum Terbit',
    ]);

    get('/rujukan')
        ->assertSuccessful()
        ->assertSee('Rujukan Belum Disahkan');

    Livewire::test('pages.references.index', ['search' => 'Rujukan Belum Disahkan'])
        ->assertSee('Rujukan Belum Disahkan');

    Livewire::test('pages.references.index', ['search' => 'Rujukan Pending Belum Terbit'])
        ->assertSee('data-search-total="0"', false)
        ->assertSee(__('No references found')); // unpublished pending: echoed in the header only, never as a result card
});

it('shows the reference empty state and clear icon button', function () {
    get('/rujukan?search=zzzzzzzzz')
        ->assertSuccessful()
        ->assertSee(__('No references found'))
        ->assertSee(__('We couldn\'t find any references matching your search.'))
        ->assertSee('aria-label="Clear search"', false);
});

it('renders every matching reference in the index', function () {
    $searchPrefix = 'Jumlah Rujukan Ujian';

    Reference::factory()->count(2)->create([
        'title' => $searchPrefix,
        'status' => 'verified',
    ]);

    $response = get('/rujukan?search='.urlencode($searchPrefix))
        ->assertSuccessful()
        ->assertSee('Direktori Rujukan');

    expect(substr_count($response->getContent(), 'wire:key="reference-'))->toBe(2);
});

it('skips the search query for short reference queries', function () {
    Reference::factory()->create([
        'title' => 'Riyadhus Solihin Terjemahan',
        'status' => 'verified',
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->toRawSql();
    });

    get('/rujukan?search='.urlencode('ri'))
        ->assertSuccessful()
        ->assertSee(__('Continue typing to search'))
        ->assertDontSee('Riyadhus Solihin Terjemahan');

    $likeQueries = collect($queries)
        ->filter(static fn (string $query): bool => str_contains(strtolower($query), '"title" like'));

    expect($likeQueries)->toBeEmpty();
});

it('scopes reference index media loads to cover collections', function () {
    Reference::factory()->create([
        'title' => 'Rujukan Sampul Depan',
        'status' => 'verified',
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->toRawSql();
    });

    get('/rujukan')->assertSuccessful();

    $mediaQueries = collect($queries)
        ->filter(static fn (string $query): bool => str_contains($query, '"media"'));

    expect($mediaQueries)->not->toBeEmpty()
        ->and($mediaQueries->every(
            static fn (string $query): bool => str_contains($query, '"collection_name"')
                && str_contains($query, 'front_cover')
                && str_contains($query, 'back_cover'),
        ))->toBeTrue();
});

it('shows the reference card placeholder when no cover is available', function () {
    Reference::factory()->create([
        'title' => 'Rujukan Tanpa Sampul',
        'status' => 'verified',
    ]);

    get('/rujukan')
        ->assertSuccessful()
        ->assertSee(asset('images/placeholders/reference-portrait-v1.webp'), false);
});

it('renders multiple reference authors on separate rows', function () {
    $firstAuthor = Person::factory()->create(['name' => 'Imam al-Nawawi']);
    $secondAuthor = Person::factory()->create(['name' => 'Ibn Hajar al-Asqalani']);

    app(ReferenceWorkflow::class)->saveFromAdmin([
        'title' => 'Rujukan Dua Penulis',
        'type' => 'book',
        'record_kind' => 'work',
        'author_ids' => [(string) $firstAuthor->getKey(), (string) $secondAuthor->getKey()],
        'status' => 'verified',
    ]);

    get('/rujukan')
        ->assertSuccessful()
        ->assertSee('<p>Imam al-Nawawi</p>', false)
        ->assertSee('<p>Ibn Hajar al-Asqalani</p>', false);
});

it('refreshes cached reference search results when a reference becomes verified', function () {
    $reference = Reference::factory()->create([
        'title' => 'Rujukan Menunggu Pengesahan',
        'status' => 'rejected',
    ]);
    $searchService = app(ReferenceSearchService::class);

    expect($searchService->publicSearchIds('menunggu pengesahan'))
        ->not->toContain((string) $reference->id);

    $reference->update(['status' => 'verified']);

    expect($searchService->publicSearchIds('menunggu pengesahan'))
        ->toContain((string) $reference->id);
});

it('shows the nearest upcoming public majlis on reference cards', function () {
    $reference = Reference::factory()->create([
        'title' => 'Rujukan Majlis Terdekat',
    ]);

    $laterEvent = Event::factory()->create([
        'title' => 'Majlis Rujukan Lebih Lewat',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(7),
    ]);
    $laterEvent->references()->attach($reference->id);

    $nearestEvent = Event::factory()->create([
        'title' => 'Majlis Rujukan Terdekat',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
        'starts_at' => now()->addDays(2),
    ]);
    $nearestEvent->references()->attach($reference->id);

    $pastEvent = Event::factory()->create([
        'title' => 'Majlis Rujukan Lalu',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDays(8),
        'starts_at' => now()->subDays(7),
    ]);
    $pastEvent->references()->attach($reference->id);

    $component = Livewire::test('pages.references.index');

    $listedReference = collect($component->instance()->references->items())
        ->firstWhere('id', $reference->id);
    $listedReferenceDate = CarbonImmutable::parse(
        (string) data_get($listedReference, 'next_event_starts_at'),
        'UTC',
    );

    $component
        ->assertSee('data-next-event', false)
        ->assertSee('href="'.route('events.show', $nearestEvent).'"', false)
        ->assertSee(__('Next event'))
        ->assertSee(UserDateTimeFormatter::translatedFormat($listedReferenceDate, 'j M'))
        ->assertDontSee(UserDateTimeFormatter::translatedFormat($listedReferenceDate, 'j M Y'))
        ->assertSee('Majlis Rujukan Terdekat')
        ->assertDontSee('Majlis Rujukan Lebih Lewat')
        ->assertDontSee('Majlis Rujukan Lalu')
        ->assertSee('2 '.__('Upcoming Events'));

    expect($listedReference)->not->toBeNull()
        ->and($listedReference?->next_event_slug)->toBe($nearestEvent->slug)
        ->and($listedReference?->next_event_title)->toBe('Majlis Rujukan Terdekat')
        ->and($listedReference?->next_event_starts_at)->not->toBeNull();
});

it('allows authenticated users to follow and unfollow a reference from the directory card', function () {
    $user = User::factory()->create();
    $reference = Reference::factory()->create([
        'title' => 'Rujukan Ikutan Direktori',
        'status' => 'verified',
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages.references.index')
        ->assertSee('<article', false)
        ->assertSee('data-follow-state="not-following"', false)
        ->assertSee('aria-label="Ikuti"', false)
        ->assertSee('wire:click.stop.prevent="toggleFollow(\''.$reference->id.'\')"', false)
        ->call('toggleFollow', (string) $reference->id)
        ->assertSet('followingReferenceIds', [(string) $reference->id])
        ->assertSee('data-follow-state="following"', false)
        ->assertSee('aria-pressed="true"', false);

    expect($user->isFollowing($reference))->toBeTrue();

    $component
        ->call('toggleFollow', (string) $reference->id)
        ->assertSet('followingReferenceIds', [])
        ->assertSee('data-follow-state="not-following"', false)
        ->assertSee('aria-pressed="false"', false);

    expect($user->isFollowing($reference))->toBeFalse();
});

it('hydrates an existing reference follow in the directory card', function () {
    $user = User::factory()->create();
    $reference = Reference::factory()->create([
        'title' => 'Rujukan Telah Diikuti',
        'status' => 'verified',
    ]);

    $user->follow($reference);

    Livewire::actingAs($user)
        ->test('pages.references.index')
        ->assertSet('followingReferenceIds', [(string) $reference->id])
        ->assertSee('data-follow-state="following"', false)
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('fill="currentColor"', false);
});

it('shows a trust badge only on verified reference cards', function () {
    Reference::factory()->create(['title' => 'Rujukan Sah', 'status' => 'verified']);
    Reference::factory()->create(['title' => 'Rujukan Semakan', 'status' => 'pending']);

    $response = get('/rujukan')->assertSuccessful()->assertSee('Rujukan Sah')->assertSee('Rujukan Semakan');

    expect(substr_count($response->getContent(), 'data-testid="reference-verified-badge"'))->toBe(1);
});

it('scopes the reference search and results to the reference-results island', function () {
    get('/rujukan')
        ->assertSuccessful()
        ->assertSee('aria-controls="reference-results"', false)
        ->assertSee('id="reference-results"', false)
        ->assertSee('name=reference-results', false);
});

it('scrolls reference pagination to the results region', function () {
    foreach (range(1, 13) as $index) {
        Reference::factory()->create([
            'title' => sprintf('Rujukan Skrol %02d', $index),
            'status' => 'verified',
        ]);
    }

    get('/rujukan')
        ->assertSuccessful()
        ->assertSee('const targetSelector = &#039;#reference-results&#039;;', false);
});
