<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('audit.console', true);
    config()->set('media-library.disk_name', 'public');
    Storage::fake('public');
});

it('records audit when media is created on an event', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $event = Event::factory()->create();

    $media = $event->addMedia(UploadedFile::fake()->image('poster.jpg', 800, 1000))
        ->toMediaCollection('poster');

    $audit = $event->audits()
        ->where('event', 'media_created')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->user_id)->toBe((string) $user->getKey())
        ->and($audit?->event)->toBe('media_created')
        ->and($audit?->old_values['poster_media'] ?? null)->toBe([])
        ->and($audit?->new_values['poster_media'][0]['id'] ?? null)->toBe((string) $media->getKey())
        ->and($audit?->new_values['poster_media'][0]['file_name'] ?? null)->toBe($media->file_name);
});

it('records audit when media is deleted from an event', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $event = Event::factory()->create();

    $media = $event->addMedia(UploadedFile::fake()->image('poster.jpg', 800, 1000))
        ->toMediaCollection('poster');

    $mediaId = $media->id;
    $media->delete();

    $audit = $event->audits()
        ->where('event', 'media_deleted')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit?->event)->toBe('media_deleted')
        ->and($audit?->old_values['poster_media'][0]['id'] ?? null)->toBe((string) $mediaId)
        ->and($audit?->new_values['poster_media'] ?? null)->toBe([]);
});

it('records audit when media collection is changed on an event', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $event = Event::factory()->create();

    $media = $event->addMedia(UploadedFile::fake()->image('poster.jpg', 800, 1000))
        ->toMediaCollection('poster');

    $media->collection_name = 'gallery';
    $media->save();

    $audits = $event->audits()
        ->where('event', 'media_updated')
        ->latest('created_at')
        ->get();

    expect($audits)->not->toBeEmpty();
    expect($audits->first()?->event)->toBe('media_updated');
});
