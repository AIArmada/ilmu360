<?php

it('has a translation for every public header menu key in each public menu locale', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    preg_match_all("/__\(\s*'([^']+)'\s*\)/", $layout, $matches);
    $keys = array_values(array_unique($matches[1]));

    expect($keys)->not->toBeEmpty();

    $missing = [];

    foreach (config('app.public_menu_locales') as $locale) {
        $translations = json_decode(
            file_get_contents(lang_path("{$locale}.json")),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        foreach ($keys as $key) {
            if (array_key_exists($key, $translations)) {
                continue;
            }

            // English keys that read as English are already correct untranslated.
            if ($locale === 'en' && mb_detect_encoding($key, 'ASCII', true) !== false) {
                continue;
            }

            $missing[] = "{$locale}: {$key}";
        }
    }

    // An unresolved key silently falls back to app.fallback_locale, which is what
    // mixed English/Malay (or English/Javanese) menu labels.
    expect($missing)->toBe([], 'Missing header menu translations: '.implode(', ', $missing));
});
