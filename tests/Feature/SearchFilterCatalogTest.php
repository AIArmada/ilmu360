<?php

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Shared\Discovery\SearchFilterCatalog;
use Illuminate\Support\Str;

it('exposes canonical discovery filter values', function (string $key, array $expected): void {
    expect(app(SearchFilterCatalog::class)->allowedValues($key))->toBe($expected);
})->with([
    'format' => ['event_format', ['physical', 'online', 'hybrid']],
    'gender' => ['gender', ['all', 'men_only', 'women_only']],
    'age' => ['age_group', ['all_ages', 'adults', 'youth', 'children', 'warga_emas']],
    'roles' => ['key_person_roles', ['moderator', 'person_in_charge', 'imam', 'khatib', 'bilal']],
    'timing' => ['timing_mode', ['absolute', 'prayer_relative']],
    'time scope' => ['time_scope', ['upcoming', 'past', 'all']],
    'prayer' => ['prayer_time', [
        'sebelum_subuh', 'selepas_subuh', 'selepas_zuhur', 'sebelum_jumaat', 'selepas_jumaat',
        'selepas_asar', 'sebelum_maghrib', 'selepas_maghrib', 'selepas_isyak', 'selepas_tarawih', 'lain_waktu',
    ]],
    'unknown' => ['unknown_filter', []],
]);

it('labels historical speaker roles without permitting them as key person filters', function (): void {
    app()->setLocale('en');
    $catalog = app(SearchFilterCatalog::class);

    expect($catalog->label('key_person_roles', 'speaker'))->toBe('Speaker')
        ->and($catalog->allowedValues('key_person_roles'))->not->toContain('speaker');
});

it('resolves labels using the current locale on each call', function (): void {
    $catalog = app(SearchFilterCatalog::class);
    app()->setLocale('en');

    expect($catalog->label('event_format', 'online'))->toBe('Online')
        ->and($catalog->label('time_scope', 'all'))->toBe('All Time')
        ->and($catalog->label('timing_mode', 'absolute'))->toBe('Exact Time');

    app()->setLocale('ms');

    expect($catalog->label('event_format', 'online'))->toBe('Dalam talian')
        ->and($catalog->label('time_scope', 'all'))->toBe('Semua Masa')
        ->and($catalog->label('timing_mode', 'absolute'))->toBe('Masa Tepat');
});

it('returns no label for unknown filter choices', function (string $key): void {
    expect(app(SearchFilterCatalog::class)->label($key, 'unknown-value'))->toBeNull();
})->with(['event_format', 'gender', 'age_group', 'key_person_roles', 'prayer_time', 'timing_mode', 'time_scope', 'unknown_filter']);

it('exposes category labels and valid ids without returning mutable terms', function (): void {
    app()->setLocale('ms');
    $categoryId = eventCategoryId('kuliah_ceramah');
    $domain = EventTaxonomy::query()->firstOrCreate(['code' => 'domain'], ['name' => 'Domain']);
    $otherTerm = EventTerm::factory()->create(['event_taxonomy_id' => $domain->id]);
    $catalog = app(SearchFilterCatalog::class);

    expect($catalog->label('event_category_ids', $categoryId))->toBe('Kuliah / Ceramah')
        ->and($catalog->label('event_category_ids', (string) Str::uuid()))->toBeNull()
        ->and($catalog->validCategoryIds([$categoryId, $otherTerm->id, (string) Str::uuid()]))->toBe([$categoryId]);
});
