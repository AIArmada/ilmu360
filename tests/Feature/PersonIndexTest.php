<?php

use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\PersonNameType;
use AIArmada\Persons\Models\PersonName;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use App\Enums\ContributionSubjectType;
use App\Livewire\Pages\Contributions\SubmitPerson;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Language;
use App\Models\Person;
use App\Models\User;
use App\Services\EventKeyPersonSyncService;
use App\Support\Search\PersonSearchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\get;

it('can search persons case-insensitively', function () {
    // Create persons with different cases
    $searchService = app(PersonSearchService::class);

    $person = Person::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

    $searchService->syncPersonRecord($person);

    Person::factory()->create([
        'name' => 'Ahmad Bin Ali',
        'status' => 'verified',
    ]);

    // Test with exact name
    get('/penceramah?search=Samad')
        ->assertSuccessful()
        ->assertSee('Samad')
        ->assertDontSee('Ahmad');

    // Test with lowercase name (should find Samad because of ILIKE fix)
    get('/penceramah?search=samad')
        ->assertSuccessful()
        ->assertSee('Samad')
        ->assertDontSee('Ahmad');
});

it('can search persons by formatted honorific and prenominal titles', function () {
    $person = Person::factory()->create([
        'name' => 'Aisyah Binti Hassan',
        'status' => 'verified',
    ]);

    $syeikhulMaqari = Title::query()->where('short_form', 'Syeikhul Maqari')->firstOrFail();

    TitleAssignment::query()->create([
        'titleable_type' => $person->getMorphClass(),
        'titleable_id' => $person->getKey(),
        'title_id' => $syeikhulMaqari->getKey(),
        'status' => AssignmentStatus::Active,
    ]);

    app(PersonSearchService::class)->syncPersonRecord($person->fresh());

    Person::factory()->create([
        'name' => 'Fatimah Binti Omar',
        'status' => 'verified',
    ]);

    get('/penceramah?search='.urlencode('syeikhul maqari'))
        ->assertSuccessful()
        ->assertSee('Aisyah Binti Hassan')
        ->assertDontSee('Fatimah Binti Omar');
});

it('can search persons by alternate names and refreshes when they change', function () {
    $person = Person::factory()->create([
        'name' => 'Muhammad Zaid Hassan',
        'status' => 'verified',
    ]);

    $alternateName = PersonName::query()->create([
        'person_id' => $person->getKey(),
        'name_type' => PersonNameType::Display,
        'full_name' => 'Ustaz Lama',
        'language_code' => 'ms',
        'is_primary' => false,
    ]);

    get('/penceramah?search='.urlencode('Lama'))
        ->assertSuccessful()
        ->assertSee('Muhammad Zaid Hassan');

    $alternateName->update(['full_name' => 'Ustaz Baharu']);

    get('/penceramah?search='.urlencode('Baharu'))
        ->assertSuccessful()
        ->assertSee('Muhammad Zaid Hassan');

    get('/penceramah?search='.urlencode('Lama'))
        ->assertSuccessful()
        ->assertDontSee('Muhammad Zaid Hassan');
});

it('filters by active status on public person index', function () {
    Person::factory()->create([
        'name' => 'Active Person',
        'status' => 'verified',
    ]);

    Person::factory()->create([
        'name' => 'Inactive Person',
        'status' => 'inactive',
    ]);

    get('/penceramah')
        ->assertSuccessful()
        ->assertSee('Active Person')
        ->assertDontSee('Inactive Person');
});

it('shows the total person count on the person index', function () {
    $searchService = app(PersonSearchService::class);
    $searchPrefix = 'Jumlah Penceramah Ujian';

    Person::factory()->count(2)->create([
        'name' => $searchPrefix,
        'status' => 'verified',
    ])->each(fn ($p) => $searchService->syncPersonRecord($p));

    get('/penceramah?search='.urlencode($searchPrefix))
        ->assertSuccessful()
        ->assertSee('Temui penceramah')
        ->assertSee('2 penceramah ditemui');
});

it('uses a stable random person order instead of alphabetical sorting', function () {
    $directoryOffset = Person::publicDirectoryOrderOffset();
    $personId = static function (string $sortCharacter, string $tailCharacter) use ($directoryOffset): string {
        $characters = array_fill(0, 32, '0');
        $characters[$directoryOffset - 1] = $sortCharacter;
        $characters[31] = $tailCharacter;
        $normalized = implode('', $characters);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($normalized, 0, 8),
            substr($normalized, 8, 4),
            substr($normalized, 12, 4),
            substr($normalized, 16, 4),
            substr($normalized, 20, 12),
        );
    };

    $firstAlphabetical = Person::factory()->create([
        'id' => $personId('f', '1'),
        'name' => 'Adam Penceramah Rawak',
        'status' => 'verified',
    ]);

    $secondAlphabetical = Person::factory()->create([
        'id' => $personId('0', '2'),
        'name' => 'Zaid Penceramah Rawak',
        'status' => 'verified',
    ]);

    $component = Livewire::test('pages.persons.index');

    $orderedIds = collect($component->instance()->persons->items())
        ->pluck('id')
        ->all();

    $expectedOrder = [$secondAlphabetical->id, $firstAlphabetical->id];

    expect(array_values(array_intersect($orderedIds, [$firstAlphabetical->id, $secondAlphabetical->id])))
        ->toBe($expectedOrder)
        ->and($expectedOrder)->not->toBe([$firstAlphabetical->id, $secondAlphabetical->id]);
});

it('sorts the directory alphabetically by given name when sort=name', function () {
    $alpha = Person::factory()->create(['name' => 'Aminah Binti Yusof', 'status' => 'verified']);
    $mid = Person::factory()->create(['name' => 'Mohd Faiz', 'status' => 'verified']);
    $zed = Person::factory()->create(['name' => 'Zarith Sofea', 'status' => 'verified']);

    $component = Livewire::test('pages.persons.index')->set('sort', 'name');

    $ordered = collect($component->instance()->persons->items())
        ->pluck('name')
        ->filter(static fn (string $name): bool => in_array($name, [$alpha->name, $mid->name, $zed->name], true))
        ->values()
        ->all();

    expect($ordered)->toBe([$alpha->name, $mid->name, $zed->name]);
});

it('filters the public directory by active speaker title', function () {
    $title = Title::query()->where('short_form', 'Ustazah')->firstOrFail();
    $matchingPerson = Person::factory()->create([
        'name' => 'Title Filter Match',
        'status' => 'verified',
    ]);
    $otherPerson = Person::factory()->create([
        'name' => 'Title Filter Other',
        'status' => 'verified',
    ]);

    $matchingPerson->titleAssignments()->delete();
    $otherPerson->titleAssignments()->delete();

    TitleAssignment::query()->create([
        'titleable_type' => $matchingPerson->getMorphClass(),
        'titleable_id' => $matchingPerson->getKey(),
        'title_id' => $title->getKey(),
        'status' => AssignmentStatus::Active,
    ]);

    Livewire::test('pages.persons.index')
        ->set('title_id', (string) $title->getKey())
        ->assertSee('Title Filter Match')
        ->assertDontSee('Title Filter Other');
});

it('filters the public directory by speaker language', function () {
    $language = Language::query()->firstOrFail();
    $matchingPerson = Person::factory()->create([
        'name' => 'Language Filter Match',
        'status' => 'verified',
    ]);
    $otherPerson = Person::factory()->create([
        'name' => 'Language Filter Other',
        'status' => 'verified',
    ]);

    $matchingPerson->languages()->sync([$language->getKey()]);
    $otherPerson->languages()->sync([]);

    Livewire::test('pages.persons.index')
        ->set('language_id', (string) $language->getKey())
        ->assertSee('Language Filter Match')
        ->assertDontSee('Language Filter Other');
});

it('filters the public directory by Malaysian state', function () {
    $selangor = createTestPackageGeography('Directory Selangor');
    $johor = createTestPackageGeography('Directory Johor');
    $matchingPerson = Person::factory()->create([
        'name' => 'State Filter Match',
        'status' => 'verified',
    ]);
    $otherPerson = Person::factory()->create([
        'name' => 'State Filter Other',
        'status' => 'verified',
    ]);

    syncPrimaryAddressForTest($matchingPerson, $selangor['address']);
    syncPrimaryAddressForTest($otherPerson, $johor['address']);

    Livewire::test('pages.persons.index')
        ->set('state_id', (string) $selangor['state']->getKey())
        ->assertSee('State Filter Match')
        ->assertDontSee('State Filter Other');
});

it('resets pagination when filters change and preserves filter query strings', function () {
    $title = Title::query()->where('short_form', 'Ustazah')->firstOrFail();

    foreach (range(1, 13) as $index) {
        $person = Person::factory()->create([
            'name' => "Pagination Filter Match {$index}",
            'status' => 'verified',
        ]);

        $person->titleAssignments()->delete();

        TitleAssignment::query()->create([
            'titleable_type' => $person->getMorphClass(),
            'titleable_id' => $person->getKey(),
            'title_id' => $title->getKey(),
            'status' => AssignmentStatus::Active,
        ]);
    }

    $titleId = (string) $title->getKey();

    $component = Livewire::test('pages.persons.index')
        ->call('setPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('title_id', $titleId)
        ->assertSet('paginators.page', 1)
        ->assertSet('title_id', $titleId);

    expect($component->instance()->persons->url(2))
        ->toContain('title_id='.$titleId);

    get('/penceramah?title_id='.urlencode($titleId))
        ->assertSuccessful()
        ->assertSee(trans_choice(':count active filter|:count active filters', 1, ['count' => 1]));
});

it('preserves direct search service relevance order', function () {
    $firstPerson = Person::factory()->create([
        'name' => 'Ranked Search First',
        'status' => 'verified',
    ]);
    $secondPerson = Person::factory()->create([
        'name' => 'Ranked Search Second',
        'status' => 'verified',
    ]);

    $searchService = Mockery::mock(PersonSearchService::class);
    $searchService->shouldReceive('publicSearchIds')
        ->once()
        ->with('ranked')
        ->andReturn([(string) $secondPerson->getKey(), (string) $firstPerson->getKey()]);
    app()->instance(PersonSearchService::class, $searchService);

    $component = Livewire::test('pages.persons.index')
        ->set('search', 'ranked');

    expect(collect($component->instance()->persons->items())
        ->pluck('id')
        ->map(static fn (mixed $id): string => (string) $id)
        ->all())->toBe([
            (string) $secondPerson->getKey(),
            (string) $firstPerson->getKey(),
        ]);
});

it('preserves fuzzy search service relevance order', function () {
    $firstPerson = Person::factory()->create([
        'name' => 'Fuzzy Search First',
        'status' => 'verified',
    ]);
    $secondPerson = Person::factory()->create([
        'name' => 'Fuzzy Search Second',
        'status' => 'verified',
    ]);

    $searchService = Mockery::mock(PersonSearchService::class);
    $searchService->shouldReceive('publicSearchIds')
        ->once()
        ->with('fuzzy')
        ->andReturn([]);
    $searchService->shouldReceive('publicFuzzySearchIds')
        ->once()
        ->with('fuzzy')
        ->andReturn([(string) $secondPerson->getKey(), (string) $firstPerson->getKey()]);
    app()->instance(PersonSearchService::class, $searchService);

    $component = Livewire::test('pages.persons.index')
        ->set('search', 'fuzzy');

    expect(collect($component->instance()->persons->items())
        ->pluck('id')
        ->map(static fn (mixed $id): string => (string) $id)
        ->all())->toBe([
            (string) $secondPerson->getKey(),
            (string) $firstPerson->getKey(),
        ]);
});

it('exposes directory status semantics and aligned loading skeleton markup', function () {
    Person::factory()->create([
        'name' => 'Accessible Directory Speaker',
        'status' => 'verified',
    ]);

    get('/penceramah')
        ->assertSuccessful()
        ->assertSee('data-art-direction="living-majlis"', false)
        ->assertSee('data-testid="person-directory-folio"', false)
        ->assertSee('data-material="translucent-control"', false)
        ->assertSee('data-material="opaque-folio"', false)
        ->assertSee('data-material="opaque-card"', false)
        ->assertSee('living-majlis-field', false)
        ->assertSee('living-majlis-folio', false)
        ->assertSee('id="person-results"', false)
        ->assertSee('aria-live="polite"', false)
        ->assertSee('aria-busy', false)
        ->assertSee('aria-pressed="true"', false)
        ->assertSee(__('Verified'))
        ->assertSee(trans_choice('upcoming majlis|upcoming majlis', 0), false)
        ->assertSee('motion-safe:animate-pulse', false)
        ->assertSee('sm:aspect-[4/4.6]', false);
});

it('translates the speaker directory heading for supported locales', function (string $locale, string $expectedHeading) {
    app()->setLocale($locale);

    get('/penceramah')
        ->assertSuccessful()
        ->assertSee($expectedHeading);
})->with([
    ['ms', 'Direktori Penceramah Islam'],
    ['en', 'Islamic speaker directory'],
    ['jv', 'Direktori penceramah Islam'],
]);

it('renders translated search placeholder on person index', function () {
    app()->setLocale('ms');

    get('/penceramah')
        ->assertSuccessful()
        ->assertSee(__('Cari nama penceramah…'));
});

it('renders the search clear control as an icon button instead of text', function () {
    Person::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

    get('/penceramah?search=samad')
        ->assertSuccessful()
        ->assertSee('aria-label="'.__('Kosongkan carian').'"', false)
        ->assertDontSee('aria-label="Clear"', false);
});

it('shows add-missing-person call to action on person index', function () {
    get('/penceramah')
        ->assertSuccessful()
        ->assertSee(__('Kenal penceramah yang belum tersenarai?'))
        ->assertSee(__('Bantu masyarakat menemui lebih banyak guru dan pendakwah. Setiap cadangan akan melalui proses semakan sebelum diterbitkan.'))
        ->assertSee(__('Cadangkan penceramah'));
});

it('supports fuzzy search with minor typos', function () {
    $searchService = app(PersonSearchService::class);

    $p1 = Person::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

    $searchService->syncPersonRecord($p1);

    Person::factory()->create([
        'name' => 'Sulaiman Hasan',
        'status' => 'verified',
    ]);

    get('/penceramah?search=Smad')
        ->assertSuccessful()
        ->assertSee('Samad')
        ->assertDontSee('Sulaiman');
});

it('matches partial person names within a larger token', function () {
    $searchService = app(PersonSearchService::class);

    $p1 = Person::factory()->create([
        'name' => 'Datuk Ustazah Dr Norhafizah Musa',
        'status' => 'verified',
    ]);

    $searchService->syncPersonRecord($p1);

    Person::factory()->create([
        'name' => 'Ustaz Hafiz Rahman',
        'status' => 'verified',
    ]);

    get('/penceramah?search=hafizah')
        ->assertSuccessful()
        ->assertSee('Datuk Ustazah Dr Norhafizah Musa');
});

it('shows the empty state when person search has no public matches', function () {
    Person::factory()->create([
        'name' => 'Ammar',
        'status' => 'pending',
    ]);

    get('/penceramah?search=ammar')
        ->assertSuccessful()
        ->assertSee(__('No speakers found'))
        ->assertSee(__('No profile matches “:search”. Try a different spelling or the full name.', ['search' => 'ammar']))
        ->assertDontSee('0 penceramah ditemui');
});

it('updates search results live when query changes', function () {
    $searchService = app(PersonSearchService::class);

    $p1 = Person::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

    $searchService->syncPersonRecord($p1);

    Person::factory()->create([
        'name' => 'Ahmad Bin Ali',
        'status' => 'verified',
    ]);

    Livewire::test('pages.persons.index')
        ->set('search', 'Smad')
        ->assertSee('Samad')
        ->assertDontSee('Ahmad');
});

it('does not repeat the public verified status predicate for person search', function () {
    $person = Person::factory()->create([
        'name' => 'Public Search Predicate Person',
        'status' => 'verified',
    ]);

    $searchService = app(PersonSearchService::class);
    $searchService->syncPersonRecord($person);
    $searchService->bustPublicSearchCache();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->toRawSql();
    });

    get('/penceramah?search='.urlencode('Public Search Predicate Person'))
        ->assertSuccessful()
        ->assertSee('Public Search Predicate Person');

    $personQueries = collect($queries)
        ->filter(static fn (string $query): bool => str_contains($query, 'from "persons"'));

    expect($personQueries)->not->toBeEmpty()
        ->and($personQueries->every(
            static fn (string $query): bool => ! str_contains($query, 'status" in'),
        ))->toBeTrue();
});

it('refreshes cached person title search results after person updates', function () {
    $searchService = app(PersonSearchService::class);
    $person = Person::factory()->create([
        'name' => 'Nurul Akma',
        'status' => 'verified',
    ]);

    $ustazah = Title::query()->where('short_form', 'Ustazah')->firstOrFail();
    $hafizah = Title::query()->where('short_form', 'Hafizah')->firstOrFail();

    $assignment = TitleAssignment::query()->create([
        'titleable_type' => $person->getMorphClass(),
        'titleable_id' => $person->getKey(),
        'title_id' => $ustazah->getKey(),
        'status' => AssignmentStatus::Active,
    ]);

    $searchService->syncPersonRecord($person->fresh());

    expect($searchService->publicSearchIds('ustazah'))
        ->toContain((string) $person->id);

    $assignment->delete();

    TitleAssignment::query()->create([
        'titleable_type' => $person->getMorphClass(),
        'titleable_id' => $person->getKey(),
        'title_id' => $hafizah->getKey(),
        'status' => AssignmentStatus::Active,
    ]);

    $searchService->syncPersonRecord($person->fresh());

    expect($searchService->publicSearchIds('ustazah'))
        ->not->toContain((string) $person->id)
        ->and($searchService->publicSearchIds('hafizah'))
        ->toContain((string) $person->id);

    $updatedSearchResults = Livewire::test('pages.persons.index')
        ->set('search', 'hafizah')
        ->instance()
        ->persons;

    expect(collect($updatedSearchResults->items())->pluck('id')->all())
        ->toContain((string) $person->id);
});

it('reindexes person search when a title assignment changes', function () {
    $person = Person::factory()->create([
        'name' => 'Aisyah Binti Hassan',
        'status' => 'verified',
    ]);

    $title = Title::query()->where('short_form', 'Syeikhul Maqari')->firstOrFail();

    expect(app(PersonSearchService::class)->publicSearchIds('syeikhul maqari'))
        ->not->toContain((string) $person->id);

    TitleAssignment::query()->create([
        'titleable_type' => $person->getMorphClass(),
        'titleable_id' => $person->getKey(),
        'title_id' => $title->getKey(),
        'status' => AssignmentStatus::Active,
    ]);

    expect(app(PersonSearchService::class)->publicSearchIds('syeikhul maqari'))
        ->toContain((string) $person->id);
});

it('refreshes cached person search results when a person becomes verified', function () {
    $person = Person::factory()->create([
        'name' => 'Person Menunggu Pengesahan',
        'status' => 'pending',
    ]);
    $searchService = app(PersonSearchService::class);

    expect($searchService->publicSearchIds('menunggu pengesahan'))
        ->not->toContain((string) $person->id);

    $person->update(['status' => 'verified']);

    expect($searchService->publicSearchIds('menunggu pengesahan'))
        ->toContain((string) $person->id);
});

it('allows users to submit a missing person from person index with pending status', function () {
    $personName = 'Cadangan Baru';

    $user = User::factory()->create();
    $country = ensureTestMalaysiaCountry();

    Livewire::actingAs($user)
        ->test(SubmitPerson::class)
        ->set('data.name', $personName)
        ->set('data.gender', 'male')
        ->set('data.address.country_id', (string) $country->getKey())
        ->call('submit')
        ->assertRedirect(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Person->publicRouteSegment()]))
        ->assertHasNoErrors();

    expect(session('contribution_submission_name'))->toBe($personName);

    get(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Person->publicRouteSegment()]))
        ->assertOk()
        ->assertSee($personName);

    $person = Person::query()
        ->where('name', $personName)
        ->with('addresses')
        ->first();

    expect($person)->not->toBeNull()
        ->and($person?->status)->toBe('pending')
        ->and((string) $person?->status)->toBeIn(['verified', 'pending'])
        ->and($person?->primaryAddress()?->country_id)->toBe((string) $country->getKey());
});

it('rejects duplicate person submissions when name gender and titles all match', function () {
    $user = User::factory()->create();
    $country = ensureTestMalaysiaCountry();

    $person = Person::factory()->create([
        'name' => 'Ustaz Samad Hassan',
        'gender' => 'male',
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($person, [
        'country_id' => (string) $country->getKey(),
    ]);

    Livewire::actingAs($user)
        ->test(SubmitPerson::class)
        ->set('data.name', 'Ustaz   Samad   Hassan')
        ->set('data.gender', 'male')
        ->set('data.address.country_id', (string) $country->getKey())
        ->call('submit')
        ->assertHasErrors(['data.name']);

    expect(Person::query()->where('name', 'Ustaz Samad Hassan')->count())->toBe(1)
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('redirects guests to login when opening add person form', function () {
    get(route('contributions.submit-person'))
        ->assertRedirect(route('login'));
});

it('counts only upcoming public events on the person index cards', function () {
    $person = Person::factory()->create([
        'name' => 'Person Dengan Majlis Akan Datang',
        'status' => 'verified',
    ]);

    $upcomingEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subHour(),
        'starts_at' => now()->addDays(3),
    ]);

    $pastEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subHour(),
        'starts_at' => now()->subDays(3),
    ]);

    $pastAndUpcomingEvent = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subHour(),
        'starts_at' => now()->subDays(2),
    ]);

    $pastAndUpcomingEvent->occurrences()->create([
        'starts_at' => now()->addDays(4),
        'ends_at' => now()->addDays(4)->addHours(2),
        'status' => 'scheduled',
        'visibility' => 'public',
    ]);

    app(EventKeyPersonSyncService::class)->sync(
        $upcomingEvent,
        [(string) $person->getKey()],
    );
    app(EventKeyPersonSyncService::class)->sync(
        $pastEvent,
        [(string) $person->getKey()],
    );
    app(EventKeyPersonSyncService::class)->sync(
        $pastAndUpcomingEvent,
        [(string) $person->getKey()],
    );

    $component = Livewire::test('pages.persons.index')
        ->assertSee('Person Dengan Majlis Akan Datang');

    $listedPerson = collect($component->instance()->persons->items())
        ->firstWhere('id', $person->id);

    expect($listedPerson)->not->toBeNull()
        ->and((int) $listedPerson?->events_count)->toBe(1);
});

it('renders profile-quality avatar URLs on the person index cards', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $person = Person::factory()->create([
        'name' => 'Kazim Elias',
        'status' => 'verified',
    ]);

    app(PersonSearchService::class)->syncPersonRecord($person);

    $person->addMedia(UploadedFile::fake()->image('kazim.jpg', 1200, 1200))
        ->toMediaCollection('profile');

    get('/penceramah?search=kazim')
        ->assertSuccessful()
        ->assertSee($person->public_main_url, false);
});
