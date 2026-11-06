<?php

declare(strict_types=1);

use App\Support\Filament\ContentVersionedJs;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Storage;

it('changes a published script URL when its contents change without a package upgrade', function (): void {
    Storage::fake('local');
    $disk = Storage::disk('local');
    $disk->put('script.js', 'window.assetVersion = 1;');
    $script = Js::make('script', $disk->path('script.js'))->package('app');
    $firstUrl = $script->getSrc();
    expect($script->getSrc())->toBe($firstUrl);

    $disk->put('script.js', 'window.assetVersion = 2;');

    expect($script->getSrc())->not->toBe($firstUrl);
});

it('uses content versions for the registered Filament notification script', function (): void {
    $script = collect(FilamentAsset::getScripts(['filament/notifications']))
        ->first(fn (Js $script): bool => $script->getId() === 'notifications');

    expect($script)->toBeInstanceOf(ContentVersionedJs::class)
        ->and($script->getSrc())->toMatch('/notifications\.js\?v=.+-[a-f0-9]{32}$/');
});

it('preserves remote script URLs', function (): void {
    $url = 'https://cdn.example.com/script.js?v=1';

    expect(Js::make('remote', $url)->getSrc())->toBe($url);
});
