<?php

use App\Models\Language;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('persists UUID language ids in languageable pivot rows', function (): void {
    $language = Language::query()->where('code', 'ms')->firstOrFail();
    $series = Series::factory()->create();

    $series->languages()->sync([$language->getKey()]);

    $pivot = DB::table('languageables')
        ->where('languageable_type', $series->getMorphClass())
        ->where('languageable_id', $series->getKey())
        ->where('language_id', $language->getKey())
        ->first();

    expect($pivot)->not->toBeNull();

    if ($pivot === null) {
        return;
    }

    expect(Str::isUuid((string) $pivot->language_id))->toBeTrue()
        ->and((string) $pivot->language_id)->toBe((string) $language->getKey());
});
