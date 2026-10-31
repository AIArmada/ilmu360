<?php

use Illuminate\Support\Facades\File;

it('renders the login and registration pages in Malay by default', function () {
    config()->set('app.locale', 'ms');
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.google.redirect', 'https://ilmu360.test/oauth/google/callback');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Selamat Kembali')
        ->assertSee('Log masuk dengan Google')
        ->assertSee('Hak cipta terpelihara.')
        ->assertDontSee('Welcome Back')
        ->assertDontSee('Sign in with Google');

    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Cipta akaun')
        ->assertSee('Daftar dengan Google')
        ->assertSee('Hak cipta terpelihara.')
        ->assertDontSee('Create an account')
        ->assertDontSee('Sign up with Google');
});

it('has Malay translations for every auth view string', function () {
    $paths = [
        resource_path('views/livewire/auth'),
        resource_path('views/auth'),
        resource_path('views/layouts/auth.blade.php'),
        resource_path('views/components/layouts/auth'),
    ];

    $keys = collect($paths)
        ->flatMap(function (string $path): array {
            if (is_dir($path)) {
                return collect(File::allFiles($path))
                    ->map(fn ($file): string => $file->getContents())
                    ->all();
            }

            return [File::get($path)];
        })
        ->flatMap(function (string $contents): array {
            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/", $contents, $matches);

            return array_map(
                static fn (string $key): string => str_replace("\\'", "'", $key),
                $matches[1],
            );
        })
        ->unique()
        ->values();

    $translations = json_decode(File::get(lang_path('ms.json')), true, 512, JSON_THROW_ON_ERROR);

    $missing = $keys
        ->reject(fn (string $key): bool => array_key_exists($key, $translations))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});
