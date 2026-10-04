<?php

use AIArmada\Addressing\Actions\SyncAddressAreaAssignmentsAction;
use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaRelationship;
use AIArmada\Addressing\Models\AddressAreaStateLink;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Models\Timezone;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Signals\Models\TrackedProperty;
use App\Actions\Events\SyncEventScheduleAction;
use App\Data\Prayer\PrayerTimesDTO;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Enums\PrayerOffset;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Services\Prayer\PrayerProviderRegistry;
use App\Support\Auth\OAuthTransactionStore;
use App\Support\Cache\PublicListingsCache;
use Carbon\CarbonImmutable;
use Database\Seeders\AIArmada\EventTaxonomySeeder;
use Database\Seeders\TitleCategorySeeder;
use Database\Seeders\TitleSeeder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

pest()->tia()->locally();

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        static $livewireOwnerContextListenersRegistered = false;

        PreventRequestForgery::except('*');

        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        OwnerContext::setForRequest(null);

        if (! $livewireOwnerContextListenersRegistered) {
            Livewire::listen('pre-mount', static function (mixed ...$_): void {
                ensureTestLivewireOwnerContext();
            });
            Livewire::listen('mount', static function (mixed ...$_): void {
                ensureTestLivewireOwnerContext();
            });
            Livewire::listen('hydrate', static function (mixed ...$_): void {
                ensureTestLivewireOwnerContext();
            });

            $livewireOwnerContextListenersRegistered = true;
        }

        $compiledViewPath = storage_path('framework/views/testing_'.ParallelTesting::token());
        $mediaTemporaryPath = storage_path('media-library/temp/testing_'.ParallelTesting::token());

        File::ensureDirectoryExists($compiledViewPath);
        File::ensureDirectoryExists($mediaTemporaryPath);
        config()->set('view.compiled', $compiledViewPath);
        config()->set('media-library.temporary_directory_path', $mediaTemporaryPath);
        // Optimizer binaries add per-conversion process overhead in tests and
        // no test asserts optimizer output.
        config()->set('media-library.image_optimizers', []);

        if (app()->resolved('blade.compiler')) {
            $compiler = app('blade.compiler');

            (function () use ($compiledViewPath): void {
                $this->cachePath = $compiledViewPath;
            })->bindTo($compiler, $compiler)();
        }

        if (Schema::hasTable(config('signals.database.tables.tracked_properties', 'signal_tracked_properties'))) {
            try {
                TrackedProperty::query()->firstOrCreate(
                    ['slug' => 'ilmu360'],
                    [
                        'name' => config('app.name').' Website',
                        'write_key' => Str::random(40),
                        'domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
                        'type' => (string) config('signals.defaults.property_type', 'website'),
                        'timezone' => (string) config('signals.defaults.timezone', config('app.timezone', 'UTC')),
                        'currency' => (string) config('signals.defaults.currency', 'MYR'),
                        'is_active' => true,
                    ],
                );
            } catch (Throwable) {
                // parallel process already seeded
            }
        }

        config()->set('services.turnstile.enabled', false);
        config()->set('services.turnstile.site_key');
        config()->set('services.turnstile.secret_key');

        // Clear tag option caches to prevent stale data in tests
        foreach (['domain', 'discipline', 'source', 'issue'] as $type) {
            Cache::forget("submit_tags_{$type}_ms_safe_v2");
            Cache::forget("submit_tags_{$type}_en_safe_v2");
        }

        foreach (['discipline', 'issue'] as $type) {
            Cache::forget("submit_tags_{$type}_verified_ms_safe_v2");
            Cache::forget("submit_tags_{$type}_verified_en_safe_v2");
        }

        Cache::forget('submit_languages_safe_v2');
        Cache::forget('submit_venues_safe_v2');

        app(PublicListingsCache::class)->bustMajlisListing();

        // Seed title categories and titles for tests
        try {
            ensureTestMalaysiaCountry();
            app(TitleCategorySeeder::class)->run();
            app(TitleSeeder::class)->run();
        } catch (Throwable) {
            // idempotent
        }

        // Seed common languages for tests that use the submit event form
        $testLanguageData = [
            ['code' => 'ar', 'name' => 'Arabic', 'native' => 'العربية', 'dir' => 'rtl'],
            ['code' => 'zh', 'name' => 'Chinese', 'native' => '中文', 'dir' => 'ltr'],
            ['code' => 'en', 'name' => 'English', 'native' => 'English', 'dir' => 'ltr'],
            ['code' => 'id', 'name' => 'Indonesian', 'native' => 'Bahasa Indonesia', 'dir' => 'ltr'],
            ['code' => 'jv', 'name' => 'Javanese', 'native' => 'ꦧꦱꦗꦮ', 'dir' => 'ltr'],
            ['code' => 'ms', 'name' => 'Malay', 'native' => 'bahasa Melayu', 'dir' => 'ltr'],
            ['code' => 'ta', 'name' => 'Tamil', 'native' => 'தமிழ்', 'dir' => 'ltr'],
        ];

        $now = now()->toIso8601ZuluString();

        try {
            foreach ($testLanguageData as $row) {
                DB::table('languages')->insertOrIgnore([
                    'id' => (string) str()->uuid(),
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'native' => $row['native'],
                    'dir' => $row['dir'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (Throwable) {
            // parallel process already seeded
        }
    })
    ->in('Feature', 'Browser');

/**
 * Get a language UUID by its ISO 639-1 code for use in tests.
 */
function languageId(string $code): ?string
{
    return DB::table('languages')->where('code', $code)->value('id');
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function addTestMember(Model $subject, Model $user, MemberRole|string $role = MemberRole::Viewer): void
{
    withGlobalOwnerContext(function () use ($role, $subject, $user): void {
        app(AddMemberAction::class)->handle(
            $subject,
            $user,
            is_string($role) ? MemberRole::from($role) : $role,
        );
    });
}

/**
 * @template TReturn
 *
 * @param  callable(): TReturn  $callback
 * @return TReturn
 */
function withGlobalOwnerContext(callable $callback): mixed
{
    return OwnerContext::withOwner(null, $callback);
}

function ensureTestLivewireOwnerContext(): void
{
    if (OwnerContext::hasOverride() || OwnerContext::resolve() !== null) {
        return;
    }

    OwnerContext::setForRequest(null);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function syncPrimaryAddressForTest(mixed $model, array $attributes): Address
{
    if (! method_exists($model, 'primaryAddress') || ! method_exists($model, 'attachAddress')) {
        throw new InvalidArgumentException('Model does not support primary-address syncing in tests.');
    }

    $normalizedAttributes = normalizeTestAddressAttributes($attributes);
    $assignments = $normalizedAttributes['area_assignments'] ?? [];
    unset($normalizedAttributes['area_assignments']);

    /** @var Address|null $existingAddress */
    $existingAddress = $model->primaryAddress();

    if ($existingAddress instanceof Address) {
        $existingAddress->fill($normalizedAttributes)->save();
        app(SyncAddressAreaAssignmentsAction::class)->execute($existingAddress, $assignments, $existingAddress->state_id);
        $model->refresh();

        return $existingAddress->fresh() ?? $existingAddress;
    }

    $address = Address::query()->create($normalizedAttributes);

    $model->attachAddress($address, type: 'primary', isPrimary: true);
    app(SyncAddressAreaAssignmentsAction::class)->execute($address, $assignments, $address->state_id);
    $model->refresh();

    return $address;
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function normalizeTestAddressAttributes(array $attributes): array
{
    $attributes['area_assignments'] ??= [];

    if (array_key_exists('administrative_district_id', $attributes)) {
        if ($attributes['administrative_district_id'] !== null && $attributes['administrative_district_id'] !== '') {
            $attributes['area_assignments']['administrative_district'] = $attributes['administrative_district_id'];
        }
        unset($attributes['administrative_district_id']);
    }

    if (array_key_exists('administrative_subdivision_id', $attributes)) {
        if ($attributes['administrative_subdivision_id'] !== null && $attributes['administrative_subdivision_id'] !== '') {
            $attributes['area_assignments']['administrative_subdivision'] = $attributes['administrative_subdivision_id'];
        }
        unset($attributes['administrative_subdivision_id']);
    }

    if (! array_key_exists('country_code', $attributes) || $attributes['country_code'] === null) {
        $countryId = $attributes['country_id'] ?? null;

        if (is_string($countryId) && $countryId !== '') {
            $country = AddressCountry::query()->find($countryId);

            if ($country instanceof AddressCountry) {
                $attributes['country_code'] = $country->iso2;
            }
        }
    }

    if (array_key_exists('lat', $attributes) && ! array_key_exists('latitude', $attributes)) {
        $attributes['latitude'] = $attributes['lat'];
    }
    unset($attributes['lat']);

    if (array_key_exists('lng', $attributes) && ! array_key_exists('longitude', $attributes)) {
        $attributes['longitude'] = $attributes['lng'];
    }
    unset($attributes['lng']);

    return $attributes;
}

/**
 * Create records for package models that guard attributes from mass assignment.
 *
 * @param  class-string<Model>  $modelClass
 * @param  array<string, mixed>  $attributes
 */
function createTestRecord(string $modelClass, array $attributes): Model
{
    return OwnerContext::withOwner(null, function () use ($modelClass, $attributes): Model {
        $record = new $modelClass;
        $record->forceFill($attributes);
        $record->save();

        return $record;
    });
}

function fakeGeneratedImageUpload(string $name = 'image.png', int $width = 1200, int $height = 800): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('Unable to create image fixture.');
    }

    $background = imagecolorallocate($image, 24, 90, 160);
    $overlay = imagecolorallocate($image, 245, 248, 250);

    imagefill($image, 0, 0, $background);
    imagefilledrectangle(
        $image,
        (int) ($width * 0.12),
        (int) ($height * 0.12),
        (int) ($width * 0.88),
        (int) ($height * 0.88),
        $overlay,
    );

    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'png';
    $temporaryPath = tempnam(sys_get_temp_dir(), 'ilmu360-test-image-');

    if ($temporaryPath === false) {
        imagedestroy($image);

        throw new RuntimeException('Unable to allocate temporary image fixture path.');
    }

    $imagePath = $temporaryPath.'.'.$extension;

    if (! @rename($temporaryPath, $imagePath)) {
        @unlink($temporaryPath);
        imagedestroy($image);

        throw new RuntimeException('Unable to prepare temporary image fixture path.');
    }

    $encoded = match ($extension) {
        'jpg', 'jpeg' => imagejpeg($image, $imagePath, 90),
        'gif' => imagegif($image, $imagePath),
        'webp' => function_exists('imagewebp') && imagewebp($image, $imagePath, 90),
        default => imagepng($image, $imagePath),
    };

    imagedestroy($image);

    if (! $encoded || ! is_file($imagePath) || filesize($imagePath) === 0) {
        @unlink($imagePath);

        throw new RuntimeException('Unable to encode image fixture.');
    }

    $mimeType = match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'image/png',
    };

    return new UploadedFile($imagePath, $name, $mimeType, null, true);
}

function fakePrayerTimesApi(): void
{
    // Submit-event feature tests do not exercise real notification delivery.
    Notification::fake();

    Http::fake([
        'api.aladhan.com/*' => Http::response([
            'code' => 200,
            'status' => 'OK',
            'data' => [
                'timings' => [
                    'Fajr' => '05:50',
                    'Dhuhr' => '13:15',
                    'Asr' => '16:40',
                    'Maghrib' => '19:25',
                    'Isha' => '20:35',
                ],
            ],
        ], 200),
    ]);
}

function prayerCacheDto(string $date = '2026-10-15', string $source = 'jakim:v2/WLY01'): PrayerTimesDTO
{
    $day = CarbonImmutable::parse($date.' 00:00:00', 'UTC');

    return new PrayerTimesDTO(
        timesUtc: [
            // Pre-dawn local clocks live on the previous UTC day, exactly
            // as real +08 provider rows serialize them.
            'fajr' => $day->subDay()->setTime(21, 50),
            'sunrise' => $day->subDay()->setTime(22, 57),
            'dhuhr' => $day->setTime(5, 2),
            'asr' => $day->setTime(8, 18),
            'maghrib' => $day->setTime(11, 2),
            'isha' => $day->setTime(12, 11),
        ],
        source: $source,
        fetchedAt: CarbonImmutable::now('UTC'),
        timezoneUsed: 'Asia/Kuala_Lumpur',
        date: $date,
        zoneOrCell: 'WLY01',
    );
}

/**
 * Stamps a hand-built monthly DTO with the owning provider's current
 * canonical fingerprint so the row survives calc pruning exactly like a
 * provider-warmed row. Unknown sources and authoritative rows pass
 * through unstamped.
 */
function prayerWithCalcFingerprint(PrayerTimesDTO $dto, string $zone = 'WLY01', string $country = 'MY'): PrayerTimesDTO
{
    $registry = app(PrayerProviderRegistry::class);

    foreach ($registry->allProviders() as $provider) {
        if (! str_starts_with($dto->source, $provider->key().':')) {
            continue;
        }

        $fingerprint = $provider->calcFingerprint($registry->canonicalZoneQuery($country, $zone, $dto->date));

        if ($fingerprint === null) {
            return $dto;
        }

        return new PrayerTimesDTO(
            timesUtc: $dto->timesUtc,
            source: $dto->source,
            fetchedAt: $dto->fetchedAt,
            timezoneUsed: $dto->timezoneUsed,
            date: $dto->date,
            zoneOrCell: $dto->zoneOrCell,
            calcFingerprint: $fingerprint,
        );
    }

    return $dto;
}

/**
 * Rewrites an event's prayer expression to the given offset through the
 * real schedule writer, keeping provenance intact. Returns the new
 * persisted start instant.
 */
function rewritePrayerExpressionOffset(Event $event, PrayerOffset $offset, CarbonImmutable $starts): CarbonImmutable
{
    $expression = EventTimeExpression::query()
        ->where('event_id', $event->getKey())
        ->where('anchor_type', 'prayer')
        ->whereNull('event_occurrence_id')
        ->whereNull('event_session_id')
        ->firstOrFail();

    $meta = $expression->metadata['prayer'] ?? [];
    $meta = is_array($meta) ? $meta : [];

    $scheduleKind = $event->schedule_kind;
    $scheduleKind = $scheduleKind instanceof ScheduleKind
        ? $scheduleKind
        : (ScheduleKind::tryFrom((string) $scheduleKind) ?? ScheduleKind::Single);

    app(SyncEventScheduleAction::class)->execute(
        event: $event->fresh() ?? $event,
        scheduleKind: $scheduleKind,
        startsAt: $starts,
        endsAt: $starts->addHours(2),
        timezone: is_string($event->timezone) && $event->timezone !== '' ? $event->timezone : 'Asia/Kuala_Lumpur',
        timingMode: TimingMode::PrayerRelative,
        prayerReference: $expression->anchor_code,
        prayerOffset: $offset->minutes(),
        prayerDisplayText: $offset->displayText(PrayerReference::from((string) $expression->anchor_code)),
        prayerSource: $meta['source'] ?? null,
        prayerFetchedAt: $meta['fetched_at'] ?? null,
        prayerZone: $meta['zone'] ?? null,
        prayerDate: $meta['prayer_date'] ?? null,
        prayerLat: isset($meta['lat']) && is_numeric($meta['lat']) ? (float) $meta['lat'] : null,
        prayerLng: isset($meta['lng']) && is_numeric($meta['lng']) ? (float) $meta['lng'] : null,
        prayerVenueId: $meta['venue_id'] ?? null,
        prayerInstitutionId: $meta['institution_id'] ?? null,
        prayerCountry: $meta['country'] ?? null,
    );

    return $starts;
}

/**
 * @return array<string, PrayerTimesDTO>
 */
function prayerCompleteMonth(string $yearMonth, string $source = 'jakim:v2/WLY01'): array
{
    $start = CarbonImmutable::parse($yearMonth.'-01', 'UTC');
    $days = [];

    for ($day = 1; $day <= $start->daysInMonth; $day++) {
        $date = $start->setDay($day)->format('Y-m-d');
        $days[$date] = prayerCacheDto($date, $source);
    }

    return $days;
}

function refreshUmmahMonthPayload(): array
{
    $day = function (string $date): array {
        return [
            'date' => $date,
            'prayer_times' => [
                'imsak' => '05:30', 'fajr' => '05:40', 'sunrise' => '06:58', 'dhuhr' => '12:59',
                'asr' => '16:15', 'maghrib' => '19:01', 'isha' => '20:10',
            ],
            'prayer_datetimes' => [
                'imsak' => "{$date}T05:30:00+08:00", 'fajr' => "{$date}T05:40:00+08:00",
                'sunrise' => "{$date}T06:58:00+08:00", 'dhuhr' => "{$date}T12:59:00+08:00",
                'asr' => "{$date}T16:15:00+08:00", 'maghrib' => "{$date}T19:01:00+08:00",
                'isha' => "{$date}T20:10:00+08:00",
            ],
        ];
    };

    return [
        'success' => true,
        'service' => 'prayer-times-month',
        'data' => [
            'timezone' => 'Asia/Kuala_Lumpur',
            'month' => 10,
            'year' => 2026,
            'calculation_method' => 'JAKIM',
            'madhab' => 'Shafi',
            'days' => [$day('2026-10-14'), $day('2026-10-15')],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function refreshV2Payload(): array
{
    return [
        'zone' => 'WLY01',
        'year' => 2026,
        'month' => 'OCT',
        'month_number' => 10,
        'last_updated' => null,
        'prayers' => [
            ['day' => 15, 'hijri' => '1448-05-04', 'imsak' => 1792014000, 'fajr' => 1792014600, 'syuruk' => 1792018620, 'dhuha' => 1792020120, 'dhuhr' => 1792040520, 'asr' => 1792052280, 'maghrib' => 1792062120, 'isha' => 1792066260],
        ],
    ];
}

class FailingPrayerCacheStore extends ArrayStore
{
    public function get($key, $default = null): mixed
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function put($key, $value, $seconds = null): bool
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function many(array $keys): array
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function putMany(array $values, $seconds = null): bool
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function increment($key, $value = 1): int|float
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function decrement($key, $value = 1): int|float
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function forget($key): bool
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function flush(): bool
    {
        throw new RuntimeException('Prayer cache store is down.');
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        throw new RuntimeException('Prayer cache store is down.');
    }
}

function useFailingPrayerCacheStore(): void
{
    Cache::extend('failing-prayer-store', fn (): Repository => new Repository(new FailingPrayerCacheStore));

    // The store entry must exist: without it CacheManager throws at
    // resolution instead of exercising the failing store itself.
    config([
        'cache.stores.failing-prayer-store' => ['driver' => 'failing-prayer-store'],
        'prayer.cache.store' => 'failing-prayer-store',
    ]);
}

function submitEventPrayerFormData(array $overrides = []): array
{
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $person = Person::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);

    return array_merge([
        'title' => 'Prayer Submit Event',
        'description' => 'Prayer submit test event.',
        'event_date' => now()->addDays(5)->format('Y-m-d'),
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'event_category_ids' => [eventCategoryId('kuliah_ceramah')],
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'languages' => [languageId('ms')],
        'domain_tags' => [submitEventTerm('domain')->id],
        'discipline_tags' => [submitEventTerm('discipline')->id],
        'primary_organizer_id' => $institution->id,
        'persons' => [$person->id],
        'submitter_name' => 'Guest Submitter',
        'submitter_email' => 'guest@example.com',
        'submission_country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $state
 */
function setSubmitEventFormState(mixed $component, array $state): mixed
{
    $nonUploadState = [];

    foreach ($state as $field => $value) {
        if (
            $value instanceof UploadedFile ||
            (is_array($value) && isset($value[0]) && $value[0] instanceof UploadedFile)
        ) {
            $component->set("data.{$field}", $value);

            continue;
        }

        $nonUploadState[$field] = $value;
    }

    if ($nonUploadState !== []) {
        if (method_exists($component, 'fillForm')) {
            $component->fillForm($nonUploadState);
        } else {
            foreach ($nonUploadState as $field => $value) {
                $component->set("data.{$field}", $value);
            }
        }
    }

    return $component;
}

function submitEventTerm(string $taxonomyCode): EventTerm
{
    $taxonomy = EventTaxonomy::query()->firstOrCreate(
        ['code' => $taxonomyCode],
        ['name' => ucfirst($taxonomyCode), 'is_active' => true],
    );

    return EventTerm::query()->create([
        'event_taxonomy_id' => $taxonomy->id,
        'code' => "test-{$taxonomyCode}-".Str::lower(Str::random(8)),
        'name' => ucfirst($taxonomyCode),
        'sort_order' => 0,
        'is_active' => true,
    ]);
}

function eventCategoryId(string $code): string
{
    $taxonomy = EventTaxonomy::query()->firstOrCreate(
        ['code' => 'event_category'],
        ['name' => 'Event Category', 'is_hierarchical' => true, 'is_active' => true],
    );

    $term = EventTerm::query()
        ->where('event_taxonomy_id', $taxonomy->getKey())
        ->where('code', $code)
        ->first();

    if (! $term instanceof EventTerm) {
        app(EventTaxonomySeeder::class)->run();
        $term = EventTerm::query()
            ->where('event_taxonomy_id', $taxonomy->getKey())
            ->where('code', $code)
            ->firstOrFail();
    }

    return (string) $term->getKey();
}

function ensureTestAddressCountry(
    string $iso2,
    string $name,
    ?string $iso3 = null,
    array $timezones = ['UTC'],
    ?string $phoneCode = null,
): AddressCountry {
    $iso2 = strtoupper($iso2);

    /** @var AddressCountry|null $country */
    $country = AddressCountry::query()->where('iso2', $iso2)->first();

    if (! $country instanceof AddressCountry) {
        $country = AddressCountry::query()->create([
            'name' => $name,
            'iso2' => $iso2,
            'iso3' => $iso3,
            'phone_code' => $phoneCode,
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);
    }

    foreach ($timezones as $timezoneName) {
        $timezone = Timezone::query()->firstOrCreate(['name' => $timezoneName]);

        DB::table(config('addressing.tables.country_timezone_links', 'country_timezone_links'))->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'country_id' => $country->getKey(),
            'timezone_id' => $timezone->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $country;
}

function ensureTestMalaysiaCountry(): AddressCountry
{
    return ensureTestAddressCountry(
        iso2: 'MY',
        name: 'Malaysia',
        iso3: 'MYS',
        timezones: ['Asia/Kuala_Lumpur'],
        phoneCode: '60',
    );
}

function testMalaysiaCountryId(): string
{
    return (string) ensureTestMalaysiaCountry()->getKey();
}

function createTestAddressArea(
    string $name,
    int $level,
    ?AddressArea $parent = null,
    ?AddressCountry $country = null,
    ?string $type = null,
): AddressArea {
    $country ??= ensureTestMalaysiaCountry();
    $type ??= match ($level) {
        1 => 'state',
        2 => 'district',
        3 => 'subdistrict',
        4 => 'locality',
        default => 'area',
    };

    $area = AddressArea::query()->firstOrCreate(
        [
            'country_id' => (string) $country->getKey(),
            'parent_id' => $parent?->getKey(),
            'type' => $type,
            'level' => $level,
            'name' => $name,
        ],
        [
            'country_code' => $country->iso2,
            'slug' => Str::slug($name.'-'.$type.'-'.Str::lower(Str::random(6))),
            'source' => 'tests',
            'source_id' => (string) Str::ulid(),
            'parent_source_id' => $parent?->source_id,
        ],
    );

    if ($parent instanceof AddressArea) {
        AddressAreaRelationship::query()->firstOrCreate(
            [
                'parent_address_area_id' => $parent->getKey(),
                'child_address_area_id' => $area->getKey(),
            ],
            [
                'relationship_type' => 'contains',
                'hierarchy_type' => 'administrative',
                'source' => 'tests',
            ],
        );
    }

    return $area;
}

/**
 * Canonical MY product geography fixture:
 * - package State/City tables for state_id/city_id
 * - AddressArea tree for administrative district + subdivision assignments
 * - area_tree_root is AddressArea level-1 used only as parent for district nodes (never address FK)
 *
 * @return array{
 *     country: AddressCountry,
 *     state: State,
 *     city: City|null,
 *     area_tree_root: AddressArea,
 *     district: AddressArea,
 *     subdistrict: AddressArea,
 *     address: array<string, mixed>
 * }
 */
function createTestPackageGeography(
    string $stateName = 'Selangor',
    string $districtName = 'Petaling',
    ?string $subdistrictName = null,
    ?string $cityName = null,
    ?AddressCountry $country = null,
): array {
    $country ??= ensureTestMalaysiaCountry();

    $packageState = State::query()->firstOrCreate(
        [
            'country_id' => (string) $country->getKey(),
            'name' => $stateName,
        ],
        [
            'code' => null,
        ],
    );

    $city = null;
    if ($cityName !== null && $cityName !== '') {
        $city = City::query()->firstOrCreate(
            [
                'state_id' => (string) $packageState->getKey(),
                'name' => $cityName,
            ],
            [
                'country_id' => (string) $country->getKey(),
            ],
        );
    }

    $areaTreeRoot = createTestAddressArea($stateName, 1, country: $country, type: 'state');
    $district = createTestAddressArea($districtName, 2, parent: $areaTreeRoot, country: $country, type: 'district');
    $subdistrict = $subdistrictName !== null
        ? createTestAddressArea($subdistrictName, 3, parent: $district, country: $country, type: 'subdistrict')
        : null;

    $areaStateLink = AddressAreaStateLink::query()->firstOrCreate(
        [
            'address_area_id' => $areaTreeRoot->getKey(),
            'state_id' => $packageState->getKey(),
        ],
        [
            'hierarchy_type' => 'administrative',
        ],
    );

    return [
        'country' => $country,
        'state' => $packageState,
        'city' => $city,
        'area_state_link' => $areaStateLink,
        'area_tree_root' => $areaTreeRoot,
        'district' => $district,
        'subdistrict' => $subdistrict,
        'address' => [
            'country_id' => (string) $country->getKey(),
            'state_id' => (string) $packageState->getKey(),
            'city_id' => $city instanceof City ? (string) $city->getKey() : null,
            'area_assignments' => array_filter([
                'administrative_district' => (string) $district->getKey(),
                'administrative_subdivision' => $subdistrict instanceof AddressArea ? (string) $subdistrict->getKey() : null,
            ]),
            'state' => $stateName,
            'city' => $cityName ?? $subdistrictName,
        ],
    ];
}

/**
 * Start a Google OAuth flow through the redirect endpoint and return the
 * opaque state issued for the provider callback.
 *
 * Must run before Socialite::fake() so the real driver builds the auth URL.
 *
 * @param  array<string, mixed>  $parameters
 */
function googleOAuthState(array $parameters = []): string
{
    $response = test()->get(route('socialite.redirect', ['provider' => 'google', ...$parameters]));
    $response->assertRedirect();

    // The test client does not persist cookies across requests the way a
    // browser does, so carry the encrypted verifier cookie forward
    // explicitly (resent verbatim, exactly as a browser would).
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === OAuthTransactionStore::VERIFIER_COOKIE) {
            test()->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue());
        }
    }

    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    $state = $query['state'] ?? null;

    if (! is_string($state) || $state === '') {
        test()->fail('Google OAuth redirect did not issue a state parameter.');
    }

    return $state;
}

/**
 * Run a cross-domain Google OAuth callback and return the handoff token.
 *
 * Requires bridge-mode configuration (services.google.redirect on a different
 * host than app.url). Asserts the callback redirects to the canonical
 * complete endpoint instead of logging in on the callback host.
 *
 * @param  array<string, mixed>  $socialUserAttributes
 * @param  array<string, mixed>  $redirectParameters
 */
function googleOAuthHandoffToken(array $socialUserAttributes = [], array $redirectParameters = []): string
{
    $state = googleOAuthState($redirectParameters);

    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-bridge-123',
        'name' => 'Bridge User',
        'email' => 'bridge@example.com',
        'avatar' => 'https://example.com/bridge.jpg',
        'email_verified' => true,
        ...$socialUserAttributes,
    ]));

    $response = test()->get(route('socialite.callback', ['provider' => 'google', 'state' => $state]));
    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    $expected = rtrim((string) config('app.url'), '/').'/oauth/google/complete?token=';

    test()->assertStringStartsWith($expected, $location);

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $token = $query['token'] ?? null;

    if (! is_string($token) || $token === '') {
        test()->fail('Cross-domain callback did not issue a handoff token.');
    }

    return $token;
}

/**
 * Canonical MY geography for the masjid-feed fixtures:
 * Selangor (code 10, Petaling/Damansara, Shah Alam city) plus
 * Wilayah Persekutuan Kuala Lumpur (code 14, Lembah Pantai district) and a
 * postal 'Subang Jaya' locality under the Selangor root.
 *
 * @return array{selangor: array<string, mixed>, kl: array<string, mixed>, locality: AddressArea}
 */
function seedCanonicalMasjidFeedGeography(): array
{
    $country = ensureTestMalaysiaCountry();

    $selangor = createTestPackageGeography(
        stateName: 'Selangor',
        districtName: 'Petaling',
        subdistrictName: 'Damansara',
        cityName: 'Shah Alam',
        country: $country,
    );
    $selangor['state']->forceFill(['code' => '10'])->save();

    $kl = createTestPackageGeography(
        stateName: 'Wilayah Persekutuan Kuala Lumpur',
        districtName: 'Lembah Pantai',
        country: $country,
    );
    $kl['state']->forceFill(['code' => '14'])->save();

    AddressAreaStateLink::query()->create([
        'address_area_id' => $selangor['area_tree_root']->getKey(),
        'state_id' => $selangor['state']->getKey(),
        'hierarchy_type' => 'postal',
    ]);

    $locality = AddressArea::query()->create([
        'country_id' => $country->getKey(),
        'country_code' => 'MY',
        'parent_id' => $selangor['area_tree_root']->getKey(),
        'type' => 'locality',
        'level' => 2,
        'name' => 'Subang Jaya',
        'slug' => 'subang-jaya-'.Str::lower(Str::random(6)),
        'source' => 'tests',
        'source_id' => (string) Str::ulid(),
        'parent_source_id' => $selangor['area_tree_root']->source_id,
    ]);

    AddressAreaRelationship::query()->create([
        'parent_address_area_id' => $selangor['area_tree_root']->getKey(),
        'child_address_area_id' => $locality->getKey(),
        'relationship_type' => 'contains',
        'hierarchy_type' => 'postal',
        'source' => 'tests',
    ]);

    return ['selangor' => $selangor, 'kl' => $kl, 'locality' => $locality];
}
