<?php

use App\Enums\ContributionSubjectType;
use App\Livewire\Pages\Contributions\SubmitPerson;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Services\EventKeyPersonSyncService;
use App\Support\Search\PersonSearchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\get;

it('can search persons case-insensitively', function () {
    // Create persons with different cases
    Person::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

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
    Person::factory()->create([
        'name' => 'Aisyah Binti Hassan',
        'pre_nominal' => ['syeikhul_maqari'],
        'status' => 'verified',
    ]);

    Person::factory()->create([
        'name' => 'Fatimah Binti Omar',
        'status' => 'verified',
    ]);

    get('/penceramah?search='.urlencode('syeikhul maqari'))
        ->assertSuccessful()
        ->assertSee('Aisyah Binti Hassan')
        ->assertDontSee('Fatimah Binti Omar');
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
    $searchPrefix = 'Jumlah Penceramah Ujian';

    Person::factory()->count(2)->create([
        'name' => $searchPrefix,
        'status' => 'verified',
    ]);

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
    Person::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

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
    Person::factory()->create([
        'name' => 'Datuk Ustazah Dr Norhafizah Musa',
        'status' => 'verified',
    ]);

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
        ->assertSee(__('Penceramah tidak ditemui'))
        ->assertSee(__('Tiada profil sepadan dengan “:search”. Cuba ejaan berbeza atau gunakan nama penuh.', ['search' => 'ammar']))
        ->assertDontSee('penceramah ditemui');
});

it('updates search results live when query changes', function () {
    Person::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

    Person::factory()->create([
        'name' => 'Ahmad Bin Ali',
        'status' => 'verified',
    ]);

    Livewire::test('pages.persons.index')
        ->set('search', 'Smad')
        ->assertSee('Samad')
        ->assertDontSee('Ahmad');
});

it('refreshes cached person title search results after person updates', function () {
    $searchService = app(PersonSearchService::class);
    $person = Person::factory()->create([
        'name' => 'Nurul Akma',
        'pre_nominal' => ['ustazah'],
        'status' => 'verified',
    ]);

    expect($searchService->publicSearchIds('ustazah'))
        ->toContain((string) $person->id);

    $person->update([
        'pre_nominal' => ['hafizah'],
    ]);

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

it('allows users to submit a missing person from person index with pending status', function () {
    $personName = 'Cadangan Baru';
    $expectedDisplayName = Person::formatDisplayedName(
        $personName,
        ['dato'],
        ['ustaz'],
        ['PhD'],
    );

    $user = User::factory()->create();
    $country = ensureTestMalaysiaCountry();

    Livewire::actingAs($user)
        ->test(SubmitPerson::class)
        ->set('data.name', $personName)
        ->set('data.gender', 'male')
        ->set('data.address.country_id', (string) $country->getKey())
        ->set('data.honorific', ['dato'])
        ->set('data.pre_nominal', ['ustaz'])
        ->set('data.post_nominal', ['PhD'])
        ->call('submit')
        ->assertRedirect(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Person->publicRouteSegment()]))
        ->assertHasNoErrors();

    expect(session('contribution_submission_name'))->toBe($expectedDisplayName);

    get(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Person->publicRouteSegment()]))
        ->assertOk()
        ->assertSee($expectedDisplayName);

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
        'honorific' => ['dato'],
        'pre_nominal' => ['ustaz'],
        'post_nominal' => ['PhD'],
        'qualifications' => [],
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
        ->set('data.honorific', ['dato'])
        ->set('data.pre_nominal', ['ustaz'])
        ->set('data.post_nominal', ['PhD'])
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

    app(EventKeyPersonSyncService::class)->sync(
        $upcomingEvent,
        [(string) $person->getKey()],
    );
    app(EventKeyPersonSyncService::class)->sync(
        $pastEvent,
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

    $person->addMedia(UploadedFile::fake()->image('kazim.jpg', 1200, 1200))
        ->toMediaCollection('avatar');

    get('/penceramah?search=kazim')
        ->assertSuccessful()
        ->assertSee($person->public_avatar_url, false);
});
