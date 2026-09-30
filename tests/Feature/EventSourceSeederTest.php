<?php

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Enums\EventTaxonomyCode;
use Database\Seeders\AIArmada\EventSourceSeeder;

it('seeds the primary reference sources with the canonical labels', function () {
    $this->seed(EventSourceSeeder::class);

    $taxonomy = EventTaxonomy::query()->where('code', EventTaxonomyCode::Source->value)->firstOrFail();

    $names = EventTerm::query()
        ->where('event_taxonomy_id', $taxonomy->getKey())
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->pluck('name')
        ->all();

    expect($names)->toBe([
        'Al-Quran',
        'Al-Sunnah (Hadith)',
        "Ijma' Ulama",
        'Fatwa',
        'Qias',
        'Kajian',
    ]);
});

it('keeps stable codes when source labels change', function () {
    $this->seed(EventSourceSeeder::class);

    $taxonomy = EventTaxonomy::query()->where('code', EventTaxonomyCode::Source->value)->firstOrFail();

    $codes = EventTerm::query()
        ->where('event_taxonomy_id', $taxonomy->getKey())
        ->orderBy('sort_order')
        ->pluck('code')
        ->all();

    expect($codes)->toBe(['al-quran', 'hadith', 'ulama', 'fatwa', 'qias', 'kajian']);
});
