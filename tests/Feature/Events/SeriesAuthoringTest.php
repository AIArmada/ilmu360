<?php

use App\Features\Events\Api\SeriesAuthoring;
use App\Filament\Resources\Series\Pages\CreateSeries;
use App\Filament\Resources\Series\Pages\EditSeries;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Models\Event;
use App\Models\Language;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');
    Queue::fake();

    config()->set('media-library.disk_name', 'public');

    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('saves and updates series through the authoring boundary', function (): void {
    $malay = seriesAuthoringLanguage('ms', 'Malay');

    $seriesId = app(SeriesAuthoring::class)->save([
        'title' => '  Boundary Series  ',
        'slug' => 'boundary-series',
        'description' => '  Boundary description  ',
        'visibility' => 'public',
        'status' => 'active',
        'languages' => [$malay->getKey()],
        'cover' => fakeGeneratedImageUpload('boundary-cover.png', 1280, 720),
        'gallery' => [
            fakeGeneratedImageUpload('boundary-gallery.png', 1280, 720),
        ],
    ]);

    $series = Series::query()->findOrFail($seriesId);

    expect($series->title)->toBe('Boundary Series')
        ->and($series->slug)->toBe('boundary-series')
        ->and($series->description)->toBe('Boundary description')
        ->and((string) $series->visibility)->toBe('public')
        ->and((string) $series->status)->toBe('active')
        ->and($series->series_type)->toBe('recurring')
        ->and($series->languages()->pluck('languages.id')->all())->toBe([$malay->getKey()])
        ->and($series->getMedia('cover'))->toHaveCount(1)
        ->and($series->getMedia('gallery'))->toHaveCount(1);

    $updatedId = app(SeriesAuthoring::class)->save([
        'title' => 'Updated Boundary Series',
        'slug' => 'boundary-series-updated',
        'description' => '',
        'visibility' => 'private',
        'status' => 'inactive',
        'languages' => [],
        'clear_cover' => true,
        'clear_gallery' => true,
    ], $seriesId);

    $series->refresh();

    expect($updatedId)->toBe($seriesId)
        ->and($series->title)->toBe('Updated Boundary Series')
        ->and($series->slug)->toBe('boundary-series-updated')
        ->and($series->description)->toBeNull()
        ->and((string) $series->visibility)->toBe('private')
        ->and((string) $series->status)->toBe('inactive')
        ->and($series->languages()->count())->toBe(0)
        ->and($series->getMedia('cover'))->toHaveCount(0)
        ->and($series->getMedia('gallery'))->toHaveCount(0);
});

it('keeps admin API series create and update parity with the authoring boundary', function (): void {
    $admin = seriesAuthoringAdmin();
    $malay = seriesAuthoringLanguage('ms', 'Malay');
    $english = seriesAuthoringLanguage('en', 'English');

    Sanctum::actingAs($admin);

    $createPayload = [
        'title' => 'Parity Series',
        'slug' => 'parity-series-api',
        'description' => 'Created through the admin API.',
        'visibility' => 'public',
        'status' => 'active',
        'languages' => [$malay->getKey()],
    ];

    $response = $this->postJson('/api/v1/admin/series', $createPayload)
        ->assertCreated();

    $apiSeries = Series::query()->findOrFail((string) $response->json('data.record.route_key'));

    expect($response->json('data.record.attributes.languages'))->toHaveCount(1)
        ->and($response->json('data.record.attributes.languages.0.id'))->toBe($malay->getKey());

    $boundaryId = app(SeriesAuthoring::class)->save([
        ...$createPayload,
        'slug' => 'parity-series-boundary',
    ]);
    $boundarySeries = Series::query()->findOrFail($boundaryId);

    expect($apiSeries->title)->toBe($boundarySeries->title)
        ->and($apiSeries->description)->toBe($boundarySeries->description)
        ->and((string) $apiSeries->visibility)->toBe((string) $boundarySeries->visibility)
        ->and((string) $apiSeries->status)->toBe((string) $boundarySeries->status)
        ->and($apiSeries->series_type)->toBe($boundarySeries->series_type)
        ->and($apiSeries->languages()->pluck('languages.id')->all())->toBe($boundarySeries->languages()->pluck('languages.id')->all());

    $updatePayload = [
        'title' => 'Updated Parity Series',
        'slug' => 'parity-series-api-updated',
        'description' => 'Updated through the admin API.',
        'visibility' => 'private',
        'status' => 'inactive',
        'languages' => [$english->getKey()],
    ];

    $this->putJson('/api/v1/admin/series/'.$apiSeries->getRouteKey(), $updatePayload)
        ->assertOk();

    app(SeriesAuthoring::class)->save([
        ...$updatePayload,
        'slug' => 'parity-series-boundary-updated',
    ], $boundaryId);

    $apiSeries->refresh();
    $boundarySeries->refresh();

    expect($apiSeries->title)->toBe($boundarySeries->title)
        ->and($apiSeries->description)->toBe($boundarySeries->description)
        ->and((string) $apiSeries->visibility)->toBe((string) $boundarySeries->visibility)
        ->and((string) $apiSeries->status)->toBe((string) $boundarySeries->status)
        ->and($apiSeries->languages()->pluck('languages.id')->all())->toBe($boundarySeries->languages()->pluck('languages.id')->all());
});

it('rejects duplicate series slugs in the authoring boundary', function (): void {
    $existing = Series::factory()->create(['slug' => 'taken-series']);
    $other = Series::factory()->create(['slug' => 'other-series']);

    expect(fn (): string => app(SeriesAuthoring::class)->save([
        'title' => 'Duplicate Series',
        'slug' => 'taken-series',
        'visibility' => 'public',
    ]))->toThrow(ValidationException::class);

    expect(fn (): string => app(SeriesAuthoring::class)->save([
        'title' => (string) $existing->title,
        'slug' => 'other-series',
        'visibility' => 'public',
    ], (string) $existing->getKey()))->toThrow(ValidationException::class);

    $sameId = app(SeriesAuthoring::class)->save([
        'title' => (string) $existing->title,
        'slug' => 'taken-series',
        'visibility' => 'public',
    ], (string) $existing->getKey());

    expect($sameId)->toBe((string) $existing->getKey())
        ->and(Series::query()->where('slug', 'taken-series')->count())->toBe(1)
        ->and(Series::query()->where('slug', 'other-series')->count())->toBe(1)
        ->and($other->fresh())->not->toBeNull();
});

it('deletes one series and its item and rule rows through the authoring boundary', function (): void {
    $selected = Series::factory()->create();
    $survivor = Series::factory()->create();

    seriesAuthoringInsertRows($selected, items: 2, rules: 1);
    seriesAuthoringInsertRows($survivor, items: 1, rules: 1);

    expect(app(SeriesAuthoring::class)->delete((string) $selected->getKey()))->toBeTrue()
        ->and(app(SeriesAuthoring::class)->delete((string) $selected->getKey()))->toBeFalse()
        ->and(app(SeriesAuthoring::class)->delete((string) Str::uuid()))->toBeFalse();

    $this->assertModelMissing($selected);
    $this->assertModelExists($survivor);

    expect(seriesAuthoringChildCount($selected, 'event_series_items'))->toBe(0)
        ->and(seriesAuthoringChildCount($selected, 'event_series_rules'))->toBe(0)
        ->and(seriesAuthoringChildCount($survivor, 'event_series_items'))->toBe(1)
        ->and(seriesAuthoringChildCount($survivor, 'event_series_rules'))->toBe(1);
});

it('deletes many series and their rows through the authoring boundary', function (): void {
    $first = Series::factory()->create();
    $second = Series::factory()->create();
    $survivor = Series::factory()->create();

    seriesAuthoringInsertRows($first, items: 1, rules: 1);
    seriesAuthoringInsertRows($second, items: 1, rules: 2);
    seriesAuthoringInsertRows($survivor, items: 1, rules: 1);

    $deleted = app(SeriesAuthoring::class)->deleteMany([
        (string) $first->getKey(),
        (string) $second->getKey(),
        (string) $first->getKey(),
        (string) Str::uuid(),
    ]);

    expect($deleted)->toBe(2);

    $this->assertModelMissing($first);
    $this->assertModelMissing($second);
    $this->assertModelExists($survivor);

    expect(seriesAuthoringChildCount($first, 'event_series_items'))->toBe(0)
        ->and(seriesAuthoringChildCount($first, 'event_series_rules'))->toBe(0)
        ->and(seriesAuthoringChildCount($second, 'event_series_items'))->toBe(0)
        ->and(seriesAuthoringChildCount($second, 'event_series_rules'))->toBe(0)
        ->and(seriesAuthoringChildCount($survivor, 'event_series_items'))->toBe(1)
        ->and(seriesAuthoringChildCount($survivor, 'event_series_rules'))->toBe(1);
});

it('deletes selected series and their rows from the Filament table bulk action', function (): void {
    $admin = seriesAuthoringAdmin();
    $first = Series::factory()->create();
    $second = Series::factory()->create();
    $survivor = Series::factory()->create();

    seriesAuthoringInsertRows($first);
    seriesAuthoringInsertRows($second);
    seriesAuthoringInsertRows($survivor);

    Livewire::actingAs($admin)
        ->test(ListSeries::class)
        ->callTableBulkAction('delete', [$first, $second])
        ->assertHasNoTableBulkActionErrors();

    $this->assertModelMissing($first);
    $this->assertModelMissing($second);
    $this->assertModelExists($survivor);

    expect(seriesAuthoringChildCount($first, 'event_series_items'))->toBe(0)
        ->and(seriesAuthoringChildCount($first, 'event_series_rules'))->toBe(0)
        ->and(seriesAuthoringChildCount($second, 'event_series_items'))->toBe(0)
        ->and(seriesAuthoringChildCount($second, 'event_series_rules'))->toBe(0)
        ->and(seriesAuthoringChildCount($survivor, 'event_series_items'))->toBe(1)
        ->and(seriesAuthoringChildCount($survivor, 'event_series_rules'))->toBe(1);
});

it('deletes a series and its rows from the Filament edit header action', function (): void {
    $admin = seriesAuthoringAdmin();
    $selected = Series::factory()->create();
    $survivor = Series::factory()->create();

    seriesAuthoringInsertRows($selected);
    seriesAuthoringInsertRows($survivor);

    Livewire::actingAs($admin)
        ->test(EditSeries::class, ['record' => $selected->getKey()])
        ->callAction('delete');

    $this->assertModelMissing($selected);
    $this->assertModelExists($survivor);

    expect(seriesAuthoringChildCount($selected, 'event_series_items'))->toBe(0)
        ->and(seriesAuthoringChildCount($selected, 'event_series_rules'))->toBe(0)
        ->and(seriesAuthoringChildCount($survivor, 'event_series_items'))->toBe(1)
        ->and(seriesAuthoringChildCount($survivor, 'event_series_rules'))->toBe(1);
});

it('creates a series through Filament with scalar fields, languages and media', function (): void {
    $admin = seriesAuthoringAdmin();
    $malay = seriesAuthoringLanguage('ms', 'Malay');

    Livewire::actingAs($admin)
        ->test(CreateSeries::class)
        ->fillForm([
            'title' => '  Filament Created Series  ',
            'slug' => 'filament-created-series',
            'description' => 'Created from the Filament resource.',
            'visibility' => 'unlisted',
            'status' => 'active',
            'languages' => [$malay->getKey()],
        ])
        ->set('data.cover', [TemporaryUploadedFile::fake()->image('filament-series-cover.jpg', 1280, 720)])
        ->set('data.gallery', [TemporaryUploadedFile::fake()->image('filament-series-gallery.jpg', 1280, 720)])
        ->call('create')
        ->assertHasNoFormErrors();

    $series = Series::query()->where('slug', 'filament-created-series')->firstOrFail();

    expect($series->title)->toBe('Filament Created Series')
        ->and($series->description)->toBe('Created from the Filament resource.')
        ->and((string) $series->visibility)->toBe('unlisted')
        ->and((string) $series->status)->toBe('active')
        ->and($series->languages()->pluck('languages.id')->all())->toBe([$malay->getKey()])
        ->and($series->getMedia('cover'))->toHaveCount(1)
        ->and($series->getMedia('gallery'))->toHaveCount(1);
});

it('updates a series through Filament with scalar fields, languages and media', function (): void {
    $admin = seriesAuthoringAdmin();
    $malay = seriesAuthoringLanguage('ms', 'Malay');
    $english = seriesAuthoringLanguage('en', 'English');

    $series = Series::factory()->create([
        'title' => 'Filament Original Series',
        'slug' => 'filament-original-series',
        'description' => 'Original description.',
        'visibility' => 'public',
        'status' => 'active',
    ]);
    $series->languages()->sync([$malay->getKey()]);
    $series->addMedia(UploadedFile::fake()->image('original-series-cover.jpg', 1280, 720))
        ->toMediaCollection('cover');
    $series->addMedia(UploadedFile::fake()->image('original-series-gallery.jpg', 1280, 720))
        ->toMediaCollection('gallery');

    $originalCoverId = $series->getFirstMedia('cover')?->getKey();

    Livewire::actingAs($admin)
        ->test(EditSeries::class, ['record' => $series->getKey()])
        ->fillForm([
            'title' => 'Filament Updated Series',
            'slug' => 'filament-updated-series',
            'description' => '',
            'visibility' => 'private',
            'status' => 'inactive',
            'languages' => [$malay->getKey(), $english->getKey()],
        ])
        ->set('data.cover', [TemporaryUploadedFile::fake()->image('replacement-series-cover.jpg', 1280, 720)])
        ->set('data.gallery', [TemporaryUploadedFile::fake()->image('replacement-series-gallery.jpg', 1280, 720)])
        ->call('save')
        ->assertHasNoFormErrors();

    $series->refresh();

    expect($series->title)->toBe('Filament Updated Series')
        ->and($series->slug)->toBe('filament-updated-series')
        ->and($series->description)->toBeNull()
        ->and((string) $series->visibility)->toBe('private')
        ->and((string) $series->status)->toBe('inactive')
        ->and($series->languages()->pluck('languages.id')->all())->toEqualCanonicalizing([$malay->getKey(), $english->getKey()])
        ->and($series->getMedia('cover'))->toHaveCount(1)
        ->and($series->getFirstMedia('cover')?->getKey())->not->toBe($originalCoverId)
        ->and($series->getMedia('gallery'))->toHaveCount(2);
});

function seriesAuthoringAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    return $admin;
}

function seriesAuthoringLanguage(string $code, string $name): Language
{
    return Language::query()->firstWhere('code', $code) ?? Language::query()->create([
        'code' => $code,
        'name' => $name,
        'native' => $name,
        'dir' => 'ltr',
    ]);
}

function seriesAuthoringInsertRows(Series $series, int $items = 1, int $rules = 1): void
{
    $itemsTable = (string) config('events.database.tables.event_series_items', 'event_series_items');
    $rulesTable = (string) config('events.database.tables.event_series_rules', 'event_series_rules');

    for ($index = 0; $index < $items; $index++) {
        DB::table($itemsTable)->insert([
            'id' => (string) Str::uuid(),
            'event_series_id' => (string) $series->getKey(),
            'seriesable_type' => Event::class,
            'seriesable_id' => (string) Str::uuid(),
            'sort_order' => $index,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    for ($index = 0; $index < $rules; $index++) {
        DB::table($rulesTable)->insert([
            'id' => (string) Str::uuid(),
            'event_series_id' => (string) $series->getKey(),
            'rule_type' => 'recurrence',
            'operator' => 'weekly',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function seriesAuthoringChildCount(Series $series, string $table): int
{
    return DB::table((string) config('events.database.tables.'.$table, $table))
        ->where('event_series_id', (string) $series->getKey())
        ->count();
}
