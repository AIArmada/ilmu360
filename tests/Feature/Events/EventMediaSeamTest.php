<?php

use App\Features\Directory\Api\DirectoryContributionMedia;
use App\Features\Events\Api\EventMedia;
use App\Features\Events\Services\EventMediaService;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Shared\Contributions\Enums\ContributionRequestStatus;
use App\Shared\Contributions\Enums\ContributionSubjectType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
});

/**
 * Stage a file on the pending request exactly like the contribution transport.
 *
 * @param  array<string, mixed>  $customProperties
 */
function stageEventMedia(ContributionRequest $request, string $fileName, array $customProperties): Media
{
    Storage::disk('public')->put('staging/'.$fileName, (string) fakeGeneratedImageUpload($fileName)->get());

    return $request->addMediaFromDisk('staging/'.$fileName, 'public')
        ->usingFileName($fileName)
        ->withCustomProperties($customProperties)
        ->toMediaCollection('pending_media');
}

it('binds the event media seam to its platform-backed service', function (): void {
    expect(app(EventMedia::class))->toBeInstanceOf(EventMediaService::class);
});

it('applies staged single and gallery media with the exact platform chain', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->for($institution)->create();

    $oldCover = $event->addMedia(fakeGeneratedImageUpload('old-cover.jpg'))
        ->toMediaCollection('cover');
    $existingGallery = $event->addMedia(fakeGeneratedImageUpload('existing-gallery.jpg'))
        ->toMediaCollection('gallery');

    $request = ContributionRequest::factory()->create([
        'status' => ContributionRequestStatus::Pending,
        'subject_type' => ContributionSubjectType::Event,
        'entity_type' => $event->getMorphClass(),
        'entity_id' => $event->getKey(),
        'proposed_data' => [],
    ]);

    $cover = stageEventMedia($request, 'new-cover.jpg', [
        'contribution_field' => 'cover',
        'is_single' => true,
    ]);
    $poster = stageEventMedia($request, 'new-poster.jpg', [
        'contribution_field' => 'poster',
        'is_single' => true,
    ]);
    $galleryOne = stageEventMedia($request, 'gallery-one.jpg', [
        'contribution_field' => 'gallery',
        'is_single' => false,
    ]);
    $galleryTwo = stageEventMedia($request, 'gallery-two.jpg', [
        'contribution_field' => 'gallery',
        'is_single' => false,
    ]);

    app(EventMedia::class)->applyStagedMedia($event, $request->getMedia('pending_media'));

    $event->refresh();
    $appliedCover = $event->getFirstMedia('cover');
    $appliedPoster = $event->getFirstMedia('poster');
    $appliedGallery = $event->getMedia('gallery');

    expect($event->getMedia('cover'))->toHaveCount(1)
        ->and($appliedCover)->not->toBeNull()
        ->and($appliedCover?->file_name)->toBe($cover->file_name)
        ->and($appliedCover?->name)->toBe(pathinfo($cover->file_name, PATHINFO_FILENAME))
        ->and($appliedCover?->getCustomProperty('collection'))->toBeNull()
        ->and($appliedCover?->getCustomProperty('original_file_name'))->toBeNull()
        ->and($appliedCover?->getCustomProperty('contribution_field'))->toBeNull()
        ->and($appliedCover?->getCustomProperty('is_single'))->toBeNull()
        ->and(Media::query()->whereKey($oldCover->getKey())->exists())->toBeFalse()
        ->and($appliedPoster?->file_name)->toBe($poster->file_name)
        ->and($appliedPoster?->getCustomProperty('collection'))->toBeNull()
        ->and($appliedPoster?->getCustomProperty('original_file_name'))->toBeNull()
        ->and($appliedPoster?->getCustomProperty('contribution_field'))->toBeNull()
        ->and($appliedGallery)->toHaveCount(3)
        ->and($appliedGallery->pluck('file_name')->all())->toContain(
            $existingGallery->file_name,
            $galleryOne->file_name,
            $galleryTwo->file_name,
        )
        ->and(Media::query()->whereKey($existingGallery->getKey())->exists())->toBeTrue()
        ->and($request->fresh()->getMedia('pending_media'))->toHaveCount(0);
});

it('delegates staged donation QR media to the directory boundary and discards it', function (): void {
    $institution = Institution::factory()->create(['status' => 'verified']);
    $event = Event::factory()->for($institution)->create();

    $request = ContributionRequest::factory()->create([
        'status' => ContributionRequestStatus::Pending,
        'subject_type' => ContributionSubjectType::Event,
        'entity_type' => $event->getMorphClass(),
        'entity_id' => $event->getKey(),
        'proposed_data' => [],
    ]);

    $fingerprint = 'sha256:'.hash('sha256', 'ewallet|tng|0912345678|');
    $staged = stageEventMedia($request, 'event-qr.png', [
        'contribution_field' => 'donation_qr',
        'donation_fingerprint' => $fingerprint,
        'is_single' => true,
    ]);

    $directoryMedia = app(DirectoryContributionMedia::class);

    $this->mock(DirectoryContributionMedia::class, function ($mock) use ($event, $staged, $directoryMedia): void {
        $mock->shouldReceive('attachStagedDonationQr')
            ->once()
            ->withArgs(fn (HasMedia $entity, Media $media): bool => $entity->is($event) && $media->is($staged))
            ->andReturnUsing(fn (HasMedia $entity, Media $media) => $directoryMedia->attachStagedDonationQr($entity, $media));
    });

    app(EventMedia::class)->applyStagedMedia($event, [$staged]);

    expect($event->refresh()->getMedia('donation_qr'))->toHaveCount(0)
        ->and(Media::query()->whereKey($staged->getKey())->exists())->toBeFalse()
        ->and($request->fresh()->getMedia('pending_media'))->toHaveCount(0);
});
