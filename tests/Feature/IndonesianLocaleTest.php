<?php

use Illuminate\Support\Facades\App;

it('registers Indonesian as a supported locale', function () {
    expect(config('app.supported_locales'))->toHaveKey('id', 'Bahasa Indonesia');
});

it('translates key strings to Indonesian', function () {
    App::setLocale('id');

    expect(__('Surau'))->toBe('Musala')
        ->and(__('Hantar Majlis'))->toBe('Kirim Majelis')
        ->and(__('Majlis & Topik'))->toBe('Majelis & Topik');
});

it('keeps Indonesian keys in parity with Malay', function () {
    $msKeys = array_keys(json_decode(file_get_contents(lang_path('ms.json')), true));
    $idKeys = array_keys(json_decode(file_get_contents(lang_path('id.json')), true));

    expect($idKeys)->toBe($msKeys);
});
