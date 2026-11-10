<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Models\Person;
use App\Platform\Cache\PublicDirectoryCacheVersion;
use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

uses(TestCase::class);

it('bumps the directory version for morph aliases and legacy class names', function (string $modelType, string $versionKey): void {
    $version = app(PublicDirectoryCacheVersion::class);
    $before = Cache::get($versionKey);

    $media = new Media;
    $media->model_type = $modelType;

    $version->bumpForMedia($media);

    expect(Cache::get($versionKey))->toBeString()->not->toBe($before);
})->with([
    'institution alias' => ['institution', 'public_directory:institutions:version:v1'],
    'legacy institution class' => [Institution::class, 'public_directory:institutions:version:v1'],
    'person alias' => ['person', 'public_directory:persons:version:v1'],
    'legacy person class' => [Person::class, 'public_directory:persons:version:v1'],
]);

it('does not bump directory versions for unrelated media types', function (): void {
    $version = app(PublicDirectoryCacheVersion::class);

    $media = new Media;
    $media->model_type = 'event';

    $version->bumpForMedia($media);

    expect(Cache::has('public_directory:institutions:version:v1'))->toBeFalse()
        ->and(Cache::has('public_directory:persons:version:v1'))->toBeFalse();
});
