<?php

use AIArmada\Contacting\Enums\SocialPlatform;
use App\Enums\EventFormat;
use App\Enums\EventPrayerTime;
use App\Enums\EventType;
use App\Enums\ReferenceType;
use App\Enums\TagType;
use Illuminate\Support\Facades\App;

it('returns translated labels for submit-event enums', function () {
    App::setLocale('en');

    expect(EventFormat::Physical->label())->toBe('Physical')
        ->and(EventPrayerTime::SelepasSubuh->getLabel())->toBe('After Fajr')
        ->and(EventType::KuliahCeramah->getLabel())->toBe('Lecture / Talk')
        ->and(EventType::KuliahCeramah->getGroup())->toBe('Knowledge')
        ->and(EventType::Talim->getLabel())->toBe("Ta'lim")
        ->and(EventType::Talim->getGroup())->toBe('Knowledge')
        ->and(ReferenceType::Book->getLabel())->toBe('Book')
        ->and(SocialPlatform::X->label())->toBe('X / Twitter')
        ->and(SocialPlatform::Telegram->label())->toBe('Telegram')
        ->and(TagType::Discipline->label())->toBe('Discipline');
});

it('contains pakistan and bangladesh keys in all locale files', function () {
    foreach (['en', 'ms', 'ms_MY', 'ar', 'jv', 'ta', 'zh'] as $locale) {
        $translations = json_decode(file_get_contents(base_path("resources/lang/{$locale}.json")), true);

        expect($translations)->toBeArray()
            ->and($translations)->toHaveKey('Pakistan')
            ->and($translations)->toHaveKey('Bangladesh');
    }
});
