<?php

use App\Models\Institution;
use App\Platform\Media\MediaCollectionSync;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
});

it('clears one collection without touching other collections', function (): void {
    $institution = Institution::factory()->create(['name' => 'Clear Media Institution']);
    $institution->addMedia(fakeGeneratedImageUpload('logo.png', 800, 800))->toMediaCollection('logo');
    $institution->addMedia(fakeGeneratedImageUpload('gallery.png', 1200, 800))->toMediaCollection('gallery');

    app(MediaCollectionSync::class)->clearCollection($institution, 'gallery');

    expect($institution->refresh()->getMedia('gallery'))->toHaveCount(0)
        ->and($institution->getMedia('logo'))->toHaveCount(1);
});

it('keeps existing media when a single sync receives no upload', function (): void {
    $institution = Institution::factory()->create(['name' => 'No Upload Institution']);
    $institution->addMedia(fakeGeneratedImageUpload('logo.png', 800, 800))->toMediaCollection('logo');

    app(MediaCollectionSync::class)->syncSingle($institution, null, 'logo');

    expect($institution->refresh()->getMedia('logo'))->toHaveCount(1);
});

it('replaces a single collection file with slug naming and custom properties', function (): void {
    $institution = Institution::factory()->create(['name' => 'Single Media Institution']);
    $institution->addMedia(fakeGeneratedImageUpload('old-logo.png', 800, 800))->toMediaCollection('logo');

    app(MediaCollectionSync::class)->syncSingle(
        $institution,
        fakeGeneratedImageUpload('new-logo.png', 900, 900),
        'logo',
    );

    $media = $institution->refresh()->getFirstMedia('logo');

    expect($institution->getMedia('logo'))->toHaveCount(1)
        ->and($media)->not->toBeNull()
        ->and($media?->file_name)->toStartWith($institution->slug.'-')
        ->and($media?->file_name)->toMatch('/-[a-z0-9]{8}\.png$/')
        ->and($media?->name)->toStartWith('Logo - ')
        ->and($media?->getCustomProperty('collection'))->toBe('logo')
        ->and($media?->getCustomProperty('original_file_name'))->toBe('new-logo.png');
});

it('appends multiple files by default', function (): void {
    $institution = Institution::factory()->create(['name' => 'Append Media Institution']);
    $institution->addMedia(fakeGeneratedImageUpload('one.png', 1200, 800))->toMediaCollection('gallery');

    app(MediaCollectionSync::class)->syncMultiple($institution, [
        fakeGeneratedImageUpload('two.png', 1200, 800),
        fakeGeneratedImageUpload('three.png', 1200, 800),
    ], 'gallery');

    expect($institution->refresh()->getMedia('gallery'))->toHaveCount(3);
});

it('replaces multiple files when requested', function (): void {
    $institution = Institution::factory()->create(['name' => 'Replace Media Institution']);
    $institution->addMedia(fakeGeneratedImageUpload('one.png', 1200, 800))->toMediaCollection('gallery');
    $institution->addMedia(fakeGeneratedImageUpload('two.png', 1200, 800))->toMediaCollection('gallery');

    app(MediaCollectionSync::class)->syncMultiple($institution, [
        fakeGeneratedImageUpload('three.png', 1200, 800),
    ], 'gallery', replace: true);

    expect($institution->refresh()->getMedia('gallery'))->toHaveCount(1);
});

it('clears the existing single file before a rejected replacement fails', function (): void {
    $institution = Institution::factory()->create(['name' => 'Failed Single Institution']);
    $institution->addMedia(fakeGeneratedImageUpload('logo.png', 800, 800))->toMediaCollection('logo');

    expect(fn () => app(MediaCollectionSync::class)->syncSingle(
        $institution,
        UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
        'logo',
    ))->toThrow(FileUnacceptableForCollection::class);

    expect($institution->refresh()->getMedia('logo'))->toHaveCount(0);
});

it('keeps files added before a rejected replacement fails', function (): void {
    $institution = Institution::factory()->create(['name' => 'Partial Multiple Institution']);
    $institution->addMedia(fakeGeneratedImageUpload('existing.png', 1200, 800))->toMediaCollection('gallery');

    expect(fn () => app(MediaCollectionSync::class)->syncMultiple($institution, [
        fakeGeneratedImageUpload('accepted.png', 1200, 800),
        UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
    ], 'gallery', replace: true))->toThrow(FileUnacceptableForCollection::class);

    expect($institution->refresh()->getMedia('gallery'))->toHaveCount(1);
});
