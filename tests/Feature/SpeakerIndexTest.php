<?php

use App\Enums\ContributionSubjectType;
use App\Livewire\Pages\Contributions\SubmitSpeaker;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\User;
use App\Services\EventKeyPersonSyncService;
use App\Support\Search\SpeakerSearchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\get;

it('can search speakers case-insensitively', function () {
    // Create speakers with different cases
    Speaker::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

    Speaker::factory()->create([
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

it('can search speakers by formatted honorific and prenominal titles', function () {
    Speaker::factory()->create([
        'name' => 'Aisyah Binti Hassan',
        'pre_nominal' => ['syeikhul_maqari'],
        'status' => 'verified',
    ]);

    Speaker::factory()->create([
        'name' => 'Fatimah Binti Omar',
        'status' => 'verified',
    ]);

    get('/penceramah?search='.urlencode('syeikhul maqari'))
        ->assertSuccessful()
        ->assertSee('Aisyah Binti Hassan')
        ->assertDontSee('Fatimah Binti Omar');
});

it('filters by active status on public speaker index', function () {
    Speaker::factory()->create([
        'name' => 'Active Speaker',
        'status' => 'verified',
    ]);

    Speaker::factory()->create([
        'name' => 'Inactive Speaker',
        'status' => 'inactive',
    ]);

    get('/penceramah')
        ->assertSuccessful()
        ->assertSee('Active Speaker')
        ->assertDontSee('Inactive Speaker');
});

it('shows the total speaker count on the speaker index', function () {
    $searchPrefix = 'Jumlah Penceramah Ujian';

    Speaker::factory()->count(2)->create([
        'name' => $searchPrefix,
        'status' => 'verified',
    ]);

    get('/penceramah?search='.urlencode($searchPrefix))
        ->assertSuccessful()
        ->assertSee('Temui penceramah')
        ->assertSee('2 penceramah ditemui');
});

it('uses a stable random speaker order instead of alphabetical sorting', function () {
    $directoryOffset = Speaker::publicDirectoryOrderOffset();
    $speakerId = static function (string $sortCharacter, string $tailCharacter) use ($directoryOffset): string {
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

    $firstAlphabetical = Speaker::factory()->create([
        'id' => $speakerId('f', '1'),
        'name' => 'Adam Penceramah Rawak',
        'status' => 'verified',
    ]);

    $secondAlphabetical = Speaker::factory()->create([
        'id' => $speakerId('0', '2'),
        'name' => 'Zaid Penceramah Rawak',
        'status' => 'verified',
    ]);

    $component = Livewire::test('pages.speakers.index');

    $orderedIds = collect($component->instance()->speakers->items())
        ->pluck('id')
        ->all();

    $expectedOrder = [$secondAlphabetical->id, $firstAlphabetical->id];

    expect(array_values(array_intersect($orderedIds, [$firstAlphabetical->id, $secondAlphabetical->id])))
        ->toBe($expectedOrder)
        ->and($expectedOrder)->not->toBe([$firstAlphabetical->id, $secondAlphabetical->id]);
});

it('renders translated search placeholder on speaker index', function () {
    app()->setLocale('ms');

    get('/penceramah')
        ->assertSuccessful()
        ->assertSee(__('Cari nama penceramah…'));
});

it('renders the search clear control as an icon button instead of text', function () {
    Speaker::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

    get('/penceramah?search=samad')
        ->assertSuccessful()
        ->assertSee('aria-label="'.__('Kosongkan carian').'"', false)
        ->assertDontSee('aria-label="Clear"', false);
});

it('shows add-missing-speaker call to action on speaker index', function () {
    get('/penceramah')
        ->assertSuccessful()
        ->assertSee(__('Kenal penceramah yang belum tersenarai?'))
        ->assertSee(__('Bantu masyarakat menemui lebih banyak guru dan pendakwah. Setiap cadangan akan melalui proses semakan sebelum diterbitkan.'))
        ->assertSee(__('Cadangkan penceramah'));
});

it('supports fuzzy search with minor typos', function () {
    Speaker::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

    Speaker::factory()->create([
        'name' => 'Sulaiman Hasan',
        'status' => 'verified',
    ]);

    get('/penceramah?search=Smad')
        ->assertSuccessful()
        ->assertSee('Samad')
        ->assertDontSee('Sulaiman');
});

it('matches partial speaker names within a larger token', function () {
    Speaker::factory()->create([
        'name' => 'Datuk Ustazah Dr Norhafizah Musa',
        'status' => 'verified',
    ]);

    Speaker::factory()->create([
        'name' => 'Ustaz Hafiz Rahman',
        'status' => 'verified',
    ]);

    get('/penceramah?search=hafizah')
        ->assertSuccessful()
        ->assertSee('Datuk Ustazah Dr Norhafizah Musa');
});

it('shows the empty state when speaker search has no public matches', function () {
    Speaker::factory()->create([
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
    Speaker::factory()->create([
        'name' => 'Samad Al-Bakri',
        'status' => 'verified',
    ]);

    Speaker::factory()->create([
        'name' => 'Ahmad Bin Ali',
        'status' => 'verified',
    ]);

    Livewire::test('pages.speakers.index')
        ->set('search', 'Smad')
        ->assertSee('Samad')
        ->assertDontSee('Ahmad');
});

it('refreshes cached speaker title search results after speaker updates', function () {
    $searchService = app(SpeakerSearchService::class);
    $speaker = Speaker::factory()->create([
        'name' => 'Nurul Akma',
        'pre_nominal' => ['ustazah'],
        'status' => 'verified',
    ]);

    expect($searchService->publicSearchIds('ustazah'))
        ->toContain((string) $speaker->id);

    $speaker->update([
        'pre_nominal' => ['hafizah'],
    ]);

    expect($searchService->publicSearchIds('ustazah'))
        ->not->toContain((string) $speaker->id)
        ->and($searchService->publicSearchIds('hafizah'))
        ->toContain((string) $speaker->id);

    $updatedSearchResults = Livewire::test('pages.speakers.index')
        ->set('search', 'hafizah')
        ->instance()
        ->speakers;

    expect(collect($updatedSearchResults->items())->pluck('id')->all())
        ->toContain((string) $speaker->id);
});

it('allows users to submit a missing speaker from speaker index with pending status', function () {
    $speakerName = 'Cadangan Baru';
    $expectedDisplayName = Speaker::formatDisplayedName(
        $speakerName,
        ['dato'],
        ['ustaz'],
        ['PhD'],
    );

    $user = User::factory()->create();
    $country = ensureTestMalaysiaCountry();

    Livewire::actingAs($user)
        ->test(SubmitSpeaker::class)
        ->set('data.name', $speakerName)
        ->set('data.gender', 'male')
        ->set('data.address.country_id', (string) $country->getKey())
        ->set('data.honorific', ['dato'])
        ->set('data.pre_nominal', ['ustaz'])
        ->set('data.post_nominal', ['PhD'])
        ->call('submit')
        ->assertRedirect(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Speaker->publicRouteSegment()]))
        ->assertHasNoErrors();

    expect(session('contribution_submission_name'))->toBe($expectedDisplayName);

    get(route('contributions.submission-success', ['subjectType' => ContributionSubjectType::Speaker->publicRouteSegment()]))
        ->assertOk()
        ->assertSee($expectedDisplayName);

    $speaker = Speaker::query()
        ->where('name', $speakerName)
        ->with('addresses')
        ->first();

    expect($speaker)->not->toBeNull()
        ->and($speaker?->status)->toBe('pending')
        ->and((string) $speaker?->status)->toBeIn(['verified', 'pending'])
        ->and($speaker?->primaryAddress()?->country_id)->toBe((string) $country->getKey());
});

it('rejects duplicate speaker submissions when name gender and titles all match', function () {
    $user = User::factory()->create();
    $country = ensureTestMalaysiaCountry();

    $speaker = Speaker::factory()->create([
        'name' => 'Ustaz Samad Hassan',
        'gender' => 'male',
        'honorific' => ['dato'],
        'pre_nominal' => ['ustaz'],
        'post_nominal' => ['PhD'],
        'qualifications' => [],
        'status' => 'verified',
    ]);
    syncPrimaryAddressForTest($speaker, [
        'country_id' => (string) $country->getKey(),
    ]);

    Livewire::actingAs($user)
        ->test(SubmitSpeaker::class)
        ->set('data.name', 'Ustaz   Samad   Hassan')
        ->set('data.gender', 'male')
        ->set('data.address.country_id', (string) $country->getKey())
        ->set('data.honorific', ['dato'])
        ->set('data.pre_nominal', ['ustaz'])
        ->set('data.post_nominal', ['PhD'])
        ->call('submit')
        ->assertHasErrors(['data.name']);

    expect(Speaker::query()->where('name', 'Ustaz Samad Hassan')->count())->toBe(1)
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('redirects guests to login when opening add speaker form', function () {
    get(route('contributions.submit-speaker'))
        ->assertRedirect(route('login'));
});

it('counts only upcoming public events on the speaker index cards', function () {
    $speaker = Speaker::factory()->create([
        'name' => 'Speaker Dengan Majlis Akan Datang',
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
        [(string) $speaker->getKey()],
    );
    app(EventKeyPersonSyncService::class)->sync(
        $pastEvent,
        [(string) $speaker->getKey()],
    );

    $component = Livewire::test('pages.speakers.index')
        ->assertSee('Speaker Dengan Majlis Akan Datang');

    $listedSpeaker = collect($component->instance()->speakers->items())
        ->firstWhere('id', $speaker->id);

    expect($listedSpeaker)->not->toBeNull()
        ->and((int) $listedSpeaker?->events_count)->toBe(1);
});

it('renders profile-quality avatar URLs on the speaker index cards', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $speaker = Speaker::factory()->create([
        'name' => 'Kazim Elias',
        'status' => 'verified',
    ]);

    $speaker->addMedia(UploadedFile::fake()->image('kazim.jpg', 1200, 1200))
        ->toMediaCollection('avatar');

    get('/penceramah?search=kazim')
        ->assertSuccessful()
        ->assertSee($speaker->public_avatar_url, false);
});
