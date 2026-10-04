<?php

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Contributions\ApproveContributionRequestAction;
use App\Actions\Events\SaveAdminEventAction;
use App\Actions\Events\SyncEventClassificationsAction;
use App\Data\EventDiscoveryCriteriaFactory;
use App\Data\Prayer\PrayerTimesDTO;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionSubjectType;
use App\Enums\EventFormat;
use App\Enums\EventPrayerTime;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Livewire\Pages\Contributions\SuggestUpdate;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Services\PostgresEventDiscovery;
use App\Services\Prayer\PrayerTimesCache;
use App\Services\PrayerTimeExpressionResolver;
use App\Services\PublicScheduleDiscoveryService;
use Carbon\CarbonImmutable;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(EventRoleSeeder::class);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    fakePrayerTimesApi();
    Http::preventStrayRequests();
    Cache::flush();
    Bus::fake();
    config(['prayer.enabled' => true]);
});

function contributionPrayerOrganizer(): Institution
{
    return Institution::factory()->create(['status' => 'verified']);
}

function contributionOvernightIsyaDto(): PrayerTimesDTO
{
    // Prayer day 2026-02-20 with isha 23:58 local, so Selepas Isyak (+5)
    // starts 2026-02-21 00:03 local — the start's local day differs from
    // the prayer day.
    $base = prayerCacheDto('2026-02-20');
    $times = $base->timesUtc;
    $times['isha'] = CarbonImmutable::parse('2026-02-20 15:58:00', 'UTC');

    return new PrayerTimesDTO(
        timesUtc: $times,
        source: $base->source,
        fetchedAt: $base->fetchedAt,
        timezoneUsed: $base->timezoneUsed,
        date: $base->date,
        zoneOrCell: $base->zoneOrCell,
    );
}

function contributionProviderEvent(User $actor, Institution $institution, string $prayerTime, string $eventDate, ?PrayerTimesDTO $feb20Dto = null): Event
{
    // Seed the category taxonomy before anything resolves catalog options:
    // the taxonomy must be hierarchical for term validation, and options
    // cache on first read.
    eventCategoryId('komuniti_kebajikan');
    EventTaxonomy::query()->where('code', 'event_category')->update([
        'is_active' => true,
        'is_hierarchical' => true,
    ]);
    Cache::flush();

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-19' => prayerCacheDto('2026-02-19'),
        '2026-02-20' => $feb20Dto ?? prayerCacheDto('2026-02-20'),
    ], 'MY');

    $person = Person::factory()->create(['status' => 'verified']);

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Provider Prayer Event',
        'primary_organizer_id' => $institution->getKey(),
        'institution_id' => $institution->getKey(),
        'persons' => [$person->getKey()],
        'event_date' => $eventDate,
        'prayer_time' => $prayerTime,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    $event->forceFill([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
    ])->save();

    app(SyncEventClassificationsAction::class)->handle($event, [
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
        'domain_tags' => [],
        'discipline_tags' => [],
        'source_tags' => [],
        'issue_tags' => [],
    ]);

    return $event->fresh();
}

function contributionOnlineMyEvent(User $actor): Event
{
    eventCategoryId('komuniti_kebajikan');
    EventTaxonomy::query()->where('code', 'event_category')->update([
        'is_active' => true,
        'is_hierarchical' => true,
    ]);
    Cache::flush();

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-02', [
        '2026-02-19' => prayerCacheDto('2026-02-19'),
        '2026-02-20' => prayerCacheDto('2026-02-20'),
    ], 'MY');

    $person = Person::factory()->create(['status' => 'verified']);

    $event = app(SaveAdminEventAction::class)->handle([
        'title' => 'Online Prayer Event',
        'primary_organizer_id' => $person->getKey(),
        'persons' => [$person->getKey()],
        'event_format' => EventFormat::Online->value,
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasIsyak->value,
        'timezone' => 'Asia/Kuala_Lumpur',
    ], $actor);

    $event->forceFill([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
    ])->save();

    app(SyncEventClassificationsAction::class)->handle($event, [
        'event_category_ids' => [eventCategoryId('komuniti_kebajikan')],
        'domain_tags' => [],
        'discipline_tags' => [],
        'source_tags' => [],
        'issue_tags' => [],
    ]);

    return $event->fresh();
}

function contributionAddressedInstitution(string $iso2, string $name): Institution
{
    $country = $iso2 === 'MY'
        ? ensureTestMalaysiaCountry()
        : ensureTestAddressCountry(iso2: $iso2, name: $name, iso3: $iso2.'X', timezones: ['Asia/Jakarta'], phoneCode: '62');

    $institution = Institution::factory()->create(['status' => 'verified']);
    syncPrimaryAddressForTest($institution, ['country_id' => (string) $country->getKey()]);

    return $institution->fresh();
}

function contributionPrayerTimeOptions(mixed $component): array
{
    $select = collect($component->instance()->getForm('form')->getFlatFields())
        ->first(fn (mixed $field): bool => method_exists($field, 'getName') && $field->getName() === 'prayer_time');

    return is_object($select) && method_exists($select, 'getOptions') ? (array) $select->getOptions() : [];
}

function contributionPrayerExpression(Event $event): ?EventTimeExpression
{
    return EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->whereNull('event_occurrence_id')
        ->whereNull('event_session_id')
        ->first();
}

function contributionSuggestParams(Event $event): array
{
    return [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subjectId' => $event->slug,
    ];
}

function contributionSuggestUrl(Event $event): string
{
    return route('api.client.contributions.suggest.store', [
        'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
        'subject' => $event->slug,
    ]);
}

function contributionPostgresTitles(string $prayerTime): array
{
    $criteria = app(EventDiscoveryCriteriaFactory::class)
        ->fromSearch(null, ['prayer_time' => $prayerTime], 50, 'time');

    return collect(app(PostgresEventDiscovery::class)->search($criteria)->items())
        ->pluck('title')
        ->all();
}

function contributionScheduleTitles(string $prayerTime): array
{
    return collect(app(PublicScheduleDiscoveryService::class)->search(null, ['prayer_time' => $prayerTime])->items())
        ->map(fn ($leaf): string => $leaf->event->title)
        ->unique()
        ->values()
        ->all();
}

it('preserves the provider instant and provenance on a UI direct title-only edit', function () {
    $owner = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasMaghrib->value, '2026-02-20');

    $expectedStart = CarbonImmutable::parse('2026-02-20 19:07:00', 'Asia/Kuala_Lumpur')->utc()->toIso8601String();
    $beforeMeta = contributionPrayerExpression($event)?->metadata['prayer'] ?? null;

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, contributionSuggestParams($event))
        ->set('data.title', 'Provider Prayer Event Renamed')
        ->call('submit')
        ->assertHasNoFormErrors();

    $event->refresh();
    $expression = contributionPrayerExpression($event);

    expect($event->title)->toBe('Provider Prayer Event Renamed')
        ->and($event->starts_at->toIso8601String())->toBe($expectedStart)
        ->and($expression?->anchor_code)->toBe('maghrib')
        ->and($expression?->metadata['prayer'] ?? null)->toBe($beforeMeta)
        ->and($expression?->metadata['prayer']['zone'] ?? null)->toBe('WLY01')
        ->and($expression?->metadata['prayer']['country'] ?? null)->toBe('MY')
        ->and($expression?->metadata['prayer']['prayer_date'] ?? null)->toBe('2026-02-20');
});

it('preserves the provider instant and provenance on an API direct title-only edit', function () {
    $owner = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasMaghrib->value, '2026-02-20');

    $expectedStart = CarbonImmutable::parse('2026-02-20 19:07:00', 'Asia/Kuala_Lumpur')->utc()->toIso8601String();
    $beforeMeta = contributionPrayerExpression($event)?->metadata['prayer'] ?? null;

    Sanctum::actingAs($owner);

    $this->postJson(contributionSuggestUrl($event), [
        'title' => 'Provider Prayer Event Renamed',
    ])->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    $event->refresh();
    $expression = contributionPrayerExpression($event);

    expect($event->title)->toBe('Provider Prayer Event Renamed')
        ->and($event->starts_at->toIso8601String())->toBe($expectedStart)
        ->and($expression?->anchor_code)->toBe('maghrib')
        ->and($expression?->metadata['prayer'] ?? null)->toBe($beforeMeta);
});

it('preserves the provider instant and provenance when a title-only proposal is approved', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasMaghrib->value, '2026-02-20');

    $expectedStart = CarbonImmutable::parse('2026-02-20 19:07:00', 'Asia/Kuala_Lumpur')->utc()->toIso8601String();
    $beforeMeta = contributionPrayerExpression($event)?->metadata['prayer'] ?? null;

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, contributionSuggestParams($event))
        ->set('data.title', 'Provider Prayer Event Renamed')
        ->set('data.proposer_note', 'Better title')
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $event->getKey())->latest()->firstOrFail();

    expect($request->status)->toBe(ContributionRequestStatus::Pending)
        ->and($event->fresh()->title)->toBe('Provider Prayer Event');

    app(ApproveContributionRequestAction::class)->handle($request->fresh(), $reviewer);

    $event->refresh();
    $expression = contributionPrayerExpression($event);

    expect($event->title)->toBe('Provider Prayer Event Renamed')
        ->and($event->starts_at->toIso8601String())->toBe($expectedStart)
        ->and($expression?->anchor_code)->toBe('maghrib')
        ->and($expression?->metadata['prayer'] ?? null)->toBe($beforeMeta);
});

it('re-resolves a changed anchor through cached provider data after a title-only edit', function () {
    $owner = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasMaghrib->value, '2026-02-20');

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, contributionSuggestParams($event))
        ->set('data.title', 'Provider Prayer Event Renamed')
        ->call('submit')
        ->assertHasNoFormErrors();

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, contributionSuggestParams($event->fresh()))
        ->set('data.prayer_time', EventPrayerTime::SelepasIsyak->value)
        ->call('submit')
        ->assertHasNoFormErrors();

    // Isha 20:11 + Immediately (+5) => 20:16, resolved from cache.
    $expectedStart = CarbonImmutable::parse('2026-02-20 20:16:00', 'Asia/Kuala_Lumpur')->utc()->toIso8601String();

    $event->refresh();
    $expression = contributionPrayerExpression($event);

    expect($event->starts_at->toIso8601String())->toBe($expectedStart)
        ->and($expression?->anchor_code)->toBe('isha')
        ->and($expression?->metadata['prayer']['zone'] ?? null)->toBe('WLY01')
        ->and($expression?->metadata['prayer']['country'] ?? null)->toBe('MY')
        ->and($expression?->metadata['prayer']['prayer_date'] ?? null)->toBe('2026-02-20');
});

it('rejects invalid prayer calendar combinations on the contribution API without writes', function (string $eventDate, string $prayerTime) {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasMaghrib->value, '2026-02-20');

    $beforeStart = $event->starts_at->toIso8601String();

    Sanctum::actingAs($owner);

    $this->postJson(contributionSuggestUrl($event), [
        'event_date' => $eventDate,
        'prayer_time' => $prayerTime,
    ])->assertUnprocessable();

    Sanctum::actingAs($visitor);

    $this->postJson(contributionSuggestUrl($event), [
        'event_date' => $eventDate,
        'prayer_time' => $prayerTime,
        'proposer_note' => 'Invalid combo',
    ])->assertUnprocessable();

    expect(ContributionRequest::query()->where('entity_id', $event->getKey())->count())->toBe(0)
        ->and($event->fresh()->starts_at->toIso8601String())->toBe($beforeStart);
})->with([
    'friday zuhur' => ['2026-02-20', EventPrayerTime::SelepasZuhur->value],
    'thursday jumaat' => ['2026-02-19', EventPrayerTime::SelepasJumaat->value],
    'out-of-ramadan tarawih' => ['2026-06-10', EventPrayerTime::SelepasTarawih->value],
]);

it('accepts valid prayer calendar counterparts on the contribution API', function (string $eventDate, string $prayerTime) {
    $owner = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasMaghrib->value, '2026-02-20');

    Sanctum::actingAs($owner);

    $this->postJson(contributionSuggestUrl($event), [
        'event_date' => $eventDate,
        'prayer_time' => $prayerTime,
    ])->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');
})->with([
    'thursday zuhur' => ['2026-02-19', EventPrayerTime::SelepasZuhur->value],
    'friday jumaat' => ['2026-02-20', EventPrayerTime::SelepasJumaat->value],
    'in-ramadan tarawih' => ['2026-02-20', EventPrayerTime::SelepasTarawih->value],
]);

it('nulls the anchor on a direct isyak to tarawih contribution update', function () {
    // Discovery only surfaces upcoming events: travel into Ramadan 1447
    // so the February event reads as future.
    $this->travelTo(CarbonImmutable::parse('2026-02-15 12:00:00', 'Asia/Kuala_Lumpur'));

    $owner = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasIsyak->value, '2026-02-20');

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, contributionSuggestParams($event))
        ->set('data.prayer_time', EventPrayerTime::SelepasTarawih->value)
        ->call('submit')
        ->assertHasNoFormErrors();

    $event->refresh();
    $expression = contributionPrayerExpression($event);

    expect($expression?->anchor_code)->toBeNull()
        ->and($expression?->display_label)->toBe('Selepas Tarawih')
        ->and($event->prayer_reference)->toBeNull();

    $form = app(SaveAdminEventAction::class)->formStateForRecord($event);

    expect($form['prayer_time'])->toBe(EventPrayerTime::SelepasTarawih->value)
        ->and(contributionPostgresTitles(EventPrayerTime::SelepasTarawih->value))->toContain($event->title)
        ->and(contributionPostgresTitles(EventPrayerTime::SelepasIsyak->value))->not->toContain($event->title)
        ->and(contributionScheduleTitles(EventPrayerTime::SelepasTarawih->value))->toContain($event->title)
        ->and(contributionScheduleTitles(EventPrayerTime::SelepasIsyak->value))->not->toContain($event->title);
});

it('nulls the anchor when a reviewed isyak to tarawih proposal is approved', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasIsyak->value, '2026-02-20');

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, contributionSuggestParams($event))
        ->set('data.prayer_time', EventPrayerTime::SelepasTarawih->value)
        ->set('data.proposer_note', 'Tarawih session')
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $event->getKey())->latest()->firstOrFail();

    app(ApproveContributionRequestAction::class)->handle($request->fresh(), $reviewer);

    $event->refresh();
    $expression = contributionPrayerExpression($event);

    expect($expression?->anchor_code)->toBeNull()
        ->and($expression?->display_label)->toBe('Selepas Tarawih')
        ->and($event->prayer_reference)->toBeNull();

    $form = app(SaveAdminEventAction::class)->formStateForRecord($event);

    expect($form['prayer_time'])->toBe(EventPrayerTime::SelepasTarawih->value);
});

it('preserves the overnight instant on a UI direct title-only edit', function () {
    $owner = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasIsyak->value, '2026-02-20', contributionOvernightIsyaDto());

    // Isha 23:58 + Immediately (+5) => next day 00:03.
    $expectedStart = CarbonImmutable::parse('2026-02-21 00:03:00', 'Asia/Kuala_Lumpur')->utc()->toIso8601String();
    $beforeMeta = contributionPrayerExpression($event)?->metadata['prayer'] ?? null;

    expect($event->starts_at->toIso8601String())->toBe($expectedStart);

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, contributionSuggestParams($event))
        ->set('data.title', 'Provider Prayer Event Renamed')
        ->call('submit')
        ->assertHasNoFormErrors();

    $event->refresh();
    $expression = contributionPrayerExpression($event);

    expect($event->title)->toBe('Provider Prayer Event Renamed')
        ->and($event->starts_at->toIso8601String())->toBe($expectedStart)
        ->and($expression?->anchor_code)->toBe('isha')
        ->and($expression?->metadata['prayer'] ?? null)->toBe($beforeMeta)
        ->and($expression?->metadata['prayer']['prayer_date'] ?? null)->toBe('2026-02-20');
});

it('preserves the overnight instant on an API direct title-only edit', function () {
    $owner = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasIsyak->value, '2026-02-20', contributionOvernightIsyaDto());

    $expectedStart = CarbonImmutable::parse('2026-02-21 00:03:00', 'Asia/Kuala_Lumpur')->utc()->toIso8601String();
    $beforeMeta = contributionPrayerExpression($event)?->metadata['prayer'] ?? null;

    Sanctum::actingAs($owner);

    $this->postJson(contributionSuggestUrl($event), [
        'title' => 'Provider Prayer Event Renamed',
    ])->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    $event->refresh();
    $expression = contributionPrayerExpression($event);

    expect($event->title)->toBe('Provider Prayer Event Renamed')
        ->and($event->starts_at->toIso8601String())->toBe($expectedStart)
        ->and($expression?->anchor_code)->toBe('isha')
        ->and($expression?->metadata['prayer'] ?? null)->toBe($beforeMeta);
});

it('preserves the overnight instant when a title-only proposal is approved', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasIsyak->value, '2026-02-20', contributionOvernightIsyaDto());

    $expectedStart = CarbonImmutable::parse('2026-02-21 00:03:00', 'Asia/Kuala_Lumpur')->utc()->toIso8601String();
    $beforeMeta = contributionPrayerExpression($event)?->metadata['prayer'] ?? null;

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, contributionSuggestParams($event))
        ->set('data.title', 'Provider Prayer Event Renamed')
        ->set('data.proposer_note', 'Better title')
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $event->getKey())->latest()->firstOrFail();

    app(ApproveContributionRequestAction::class)->handle($request->fresh(), $reviewer);

    $event->refresh();
    $expression = contributionPrayerExpression($event);

    expect($event->title)->toBe('Provider Prayer Event Renamed')
        ->and($event->starts_at->toIso8601String())->toBe($expectedStart)
        ->and($expression?->anchor_code)->toBe('isha')
        ->and($expression?->metadata['prayer'] ?? null)->toBe($beforeMeta);
});

it('resolves the intended prayer day on an intentional overnight date change', function () {
    $owner = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasIsyak->value, '2026-02-20', contributionOvernightIsyaDto());

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, contributionSuggestParams($event))
        ->set('data.event_date', '2026-02-19')
        // Changing the date clears the prayer-time select by design.
        ->set('data.prayer_time', EventPrayerTime::SelepasIsyak->value)
        ->call('submit')
        ->assertHasNoFormErrors();

    // Standard 19th row: isha 20:11 + Immediately (+5) => 20:16.
    $expectedStart = CarbonImmutable::parse('2026-02-19 20:16:00', 'Asia/Kuala_Lumpur')->utc()->toIso8601String();

    $event->refresh();
    $expression = contributionPrayerExpression($event);

    expect($event->starts_at->toIso8601String())->toBe($expectedStart)
        ->and($expression?->anchor_code)->toBe('isha')
        ->and($expression?->metadata['prayer']['prayer_date'] ?? null)->toBe('2026-02-19');
});

it('shows exact-MY tarawih options for an address-less online event', function () {
    $owner = User::factory()->create();
    $event = contributionOnlineMyEvent($owner);

    expect(contributionPrayerExpression($event)?->metadata['prayer']['country'] ?? null)->toBe('MY');

    // Exact MY rejects 2026-02-17; the tabular estimate would admit it.
    $component = Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, contributionSuggestParams($event))
        ->set('data.event_date', '2026-02-17');

    expect(contributionPrayerTimeOptions($component))->not->toHaveKey(EventPrayerTime::SelepasTarawih->value);

    $component->set('data.event_date', '2026-02-20');

    expect(contributionPrayerTimeOptions($component))->toHaveKey(EventPrayerTime::SelepasTarawih->value);
});

it('updates tarawih options when the contribution location changes country', function () {
    $owner = User::factory()->create();
    $malaysia = contributionAddressedInstitution('MY', 'Malaysia');
    $indonesia = contributionAddressedInstitution('ID', 'Indonesia');
    addTestMember($malaysia, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $malaysia, EventPrayerTime::SelepasIsyak->value, '2026-02-20');

    $component = Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, contributionSuggestParams($event))
        ->set('data.event_date', '2026-02-17');

    // Persisted MY location: the exact window rejects the 17th.
    expect(contributionPrayerTimeOptions($component))->not->toHaveKey(EventPrayerTime::SelepasTarawih->value);

    $component
        ->set('data.location_same_as_institution', false)
        ->set('data.location_type', 'institution')
        ->set('data.location_institution_id', (string) $indonesia->getKey());

    // ID has no announced window: the tabular estimate admits the 17th.
    expect(contributionPrayerTimeOptions($component))->toHaveKey(EventPrayerTime::SelepasTarawih->value);
});

it('accepts and rejects tarawih consistently on the contribution API for an address-less event', function () {
    $owner = User::factory()->create();
    $owner->assignRole('super_admin');
    $event = contributionOnlineMyEvent($owner);

    Sanctum::actingAs($owner);

    $this->postJson(contributionSuggestUrl($event), [
        'event_date' => '2026-02-17',
        'prayer_time' => EventPrayerTime::SelepasTarawih->value,
    ])->assertUnprocessable();

    $this->postJson(contributionSuggestUrl($event), [
        'event_date' => '2026-02-20',
        'prayer_time' => EventPrayerTime::SelepasTarawih->value,
    ])->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');
});

it('preserves non-default prayer offsets on UI direct title-only edits', function (PrayerOffset $offset) {
    $owner = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasMaghrib->value, '2026-02-20');

    // Maghrib 19:02 plus the dataset offset.
    $expectedStart = CarbonImmutable::parse('2026-02-20 19:02:00', 'Asia/Kuala_Lumpur')->addMinutes($offset->minutes());

    rewritePrayerExpressionOffset($event, $offset, $expectedStart);

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, contributionSuggestParams($event->fresh()))
        ->set('data.title', 'Provider Prayer Event Renamed')
        ->call('submit')
        ->assertHasNoFormErrors();

    $event = $event->fresh();
    $expression = contributionPrayerExpression($event);

    expect($expression?->anchor_code)->toBe('maghrib')
        ->and($expression?->relation)->toBe($offset->minutes() < 0 ? 'before' : 'after')
        ->and($expression?->offset_minutes)->toBe(abs($offset->minutes()))
        ->and($expression?->display_label)->toBe($offset->displayText(PrayerReference::Maghrib))
        ->and($event->starts_at->toIso8601String())->toBe($expectedStart->utc()->toIso8601String());

    // Warm-cache re-resolution honors the preserved offset.
    expect(app(PrayerTimeExpressionResolver::class)->resolve($expression->fresh())?->toIso8601String())
        ->toBe($expectedStart->utc()->toIso8601String());
})->with([
    'before 30' => [PrayerOffset::Before30],
    'after 30' => [PrayerOffset::After30],
    'after 60' => [PrayerOffset::After60],
    'before 15' => [PrayerOffset::Before15],
]);

it('preserves non-default prayer offsets on API direct title-only edits', function (PrayerOffset $offset) {
    $owner = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasMaghrib->value, '2026-02-20');

    $expectedStart = CarbonImmutable::parse('2026-02-20 19:02:00', 'Asia/Kuala_Lumpur')->addMinutes($offset->minutes());

    rewritePrayerExpressionOffset($event, $offset, $expectedStart);

    Sanctum::actingAs($owner);

    $this->postJson(contributionSuggestUrl($event->fresh()), [
        'title' => 'Provider Prayer Event Renamed',
    ])->assertOk()
        ->assertJsonPath('data.mode', 'direct_edit');

    $event = $event->fresh();
    $expression = contributionPrayerExpression($event);

    expect($expression?->anchor_code)->toBe('maghrib')
        ->and($expression?->relation)->toBe($offset->minutes() < 0 ? 'before' : 'after')
        ->and($expression?->offset_minutes)->toBe(abs($offset->minutes()))
        ->and($expression?->display_label)->toBe($offset->displayText(PrayerReference::Maghrib))
        ->and($event->starts_at->toIso8601String())->toBe($expectedStart->utc()->toIso8601String());

    expect(app(PrayerTimeExpressionResolver::class)->resolve($expression->fresh())?->toIso8601String())
        ->toBe($expectedStart->utc()->toIso8601String());
})->with([
    'before 30' => [PrayerOffset::Before30],
    'after 30' => [PrayerOffset::After30],
    'after 60' => [PrayerOffset::After60],
    'before 15' => [PrayerOffset::Before15],
]);

it('preserves non-default prayer offsets when a title-only proposal is approved', function (PrayerOffset $offset) {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = contributionPrayerOrganizer();
    addTestMember($institution, $owner, MemberRole::Owner);
    $event = contributionProviderEvent($owner, $institution, EventPrayerTime::SelepasMaghrib->value, '2026-02-20');

    $expectedStart = CarbonImmutable::parse('2026-02-20 19:02:00', 'Asia/Kuala_Lumpur')->addMinutes($offset->minutes());

    rewritePrayerExpressionOffset($event, $offset, $expectedStart);

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, contributionSuggestParams($event->fresh()))
        ->set('data.title', 'Provider Prayer Event Renamed')
        ->set('data.proposer_note', 'Better title')
        ->call('submit')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()->where('entity_id', $event->getKey())->latest()->firstOrFail();

    app(ApproveContributionRequestAction::class)->handle($request->fresh(), $reviewer);

    $event = $event->fresh();
    $expression = contributionPrayerExpression($event);

    expect($expression?->anchor_code)->toBe('maghrib')
        ->and($expression?->relation)->toBe($offset->minutes() < 0 ? 'before' : 'after')
        ->and($expression?->offset_minutes)->toBe(abs($offset->minutes()))
        ->and($expression?->display_label)->toBe($offset->displayText(PrayerReference::Maghrib))
        ->and($event->starts_at->toIso8601String())->toBe($expectedStart->utc()->toIso8601String());

    expect(app(PrayerTimeExpressionResolver::class)->resolve($expression->fresh())?->toIso8601String())
        ->toBe($expectedStart->utc()->toIso8601String());
})->with([
    'before 30' => [PrayerOffset::Before30],
    'after 30' => [PrayerOffset::After30],
    'after 60' => [PrayerOffset::After60],
    'before 15' => [PrayerOffset::Before15],
]);
