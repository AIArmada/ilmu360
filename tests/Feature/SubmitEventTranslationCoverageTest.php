<?php

use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Events\Models\EventTerm;
use App\Enums\EventFormat;
use App\Enums\EventPrayerTime;
use App\Enums\EventTaxonomyCode;
use App\Enums\ReferenceType;
use Database\Seeders\AIArmada\EventTaxonomySeeder;
use Illuminate\Support\Facades\App;

it('returns translated labels for submit-event enums', function () {
    App::setLocale('en');
    app(EventTaxonomySeeder::class)->run();

    expect(EventFormat::Physical->label())->toBe('Physical')
        ->and(EventPrayerTime::SelepasSubuh->getLabel())->toBe('After Fajr')
        ->and(EventTerm::query()->where('code', 'kuliah_ceramah')->value('name'))->toBe('Kuliah / Ceramah')
        ->and(EventTerm::query()->where('code', 'talim')->value('name'))->toBe("Ta'lim")
        ->and(ReferenceType::Book->getLabel())->toBe('Book')
        ->and(SocialPlatform::X->label())->toBe('X / Twitter')
        ->and(SocialPlatform::Telegram->label())->toBe('Telegram')
        ->and(EventTaxonomyCode::Discipline->label())->toBe('Discipline');
});

it('contains pakistan and bangladesh keys in all locale files', function () {
    foreach (['en', 'ms', 'ms_MY', 'ar', 'jv', 'ta', 'zh'] as $locale) {
        $translations = json_decode(file_get_contents(base_path("resources/lang/{$locale}.json")), true);

        expect($translations)->toBeArray()
            ->and($translations)->toHaveKey('Pakistan')
            ->and($translations)->toHaveKey('Bangladesh');
    }
});
