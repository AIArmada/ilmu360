<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Contributions\Actions\ApplyDirectContributionUpdateAction;
use App\Features\Contributions\Actions\ApproveContributionRequestAction;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\Pages\EditInstitution;
use App\Features\Institutions\Models\Institution;
use App\Models\User;
use App\Platform\Media\MediaCollectionSync;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('redirects moderators to the public update form instead of direct institution editing', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');
    $institution = Institution::factory()->create(['status' => 'verified']);

    OwnerContext::withOwner(null, fn () => Livewire::actingAs($moderator)
        ->test(EditInstitution::class, ['record' => $institution->getKey()])
        ->assertRedirect(route('contributions.suggest-update', [
            'subjectType' => 'institusi',
            'subjectId' => $institution->slug,
        ])));
});

it('rejects direct contribution updates from maintainers who are not global administrators', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'name' => 'Masjid Asal',
        'status' => 'verified',
    ]);

    withGlobalOwnerContext(fn () => addTestMember($institution, $owner, MemberRole::Owner));

    try {
        app(ApplyDirectContributionUpdateAction::class)->apply(
            (string) $owner->getKey(),
            $institution->getMorphClass(),
            (string) $institution->getKey(),
            ['name' => 'Masjid Diubah'],
        );

        $this->fail('Expected the direct update to be rejected.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }

    expect($institution->fresh()->name)->toBe('Masjid Asal');
});

it('restores a direct contribution when a later media write fails', function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $institution = Institution::factory()->create(['name' => 'Original Name', 'status' => 'verified']);
    $original = $institution->addMedia(fakeGeneratedImageUpload('original.jpg', 160, 90))
        ->toMediaCollection('cover');
    $replacementPath = null;

    expect(function () use ($admin, $institution, &$replacementPath): void {
        app(ApplyDirectContributionUpdateAction::class)->apply(
            (string) $admin->getKey(),
            $institution->getMorphClass(),
            (string) $institution->getKey(),
            ['name' => 'Replacement Name'],
            function () use ($institution, &$replacementPath): void {
                $replacement = app(MediaCollectionSync::class)->storeSingle(
                    $institution,
                    fakeGeneratedImageUpload('replacement.jpg', 160, 90),
                    'cover',
                );
                $replacementPath = $replacement->getPathRelativeToRoot();

                throw new RuntimeException('Could not write a second image.');
            },
        );
    })->toThrow(RuntimeException::class, 'Could not write a second image.');

    expect($institution->fresh()->name)->toBe('Original Name')
        ->and($institution->fresh()->getFirstMedia('cover')?->getKey())->toBe($original->getKey())
        ->and($replacementPath)->toBeString();

    Storage::disk('public')->assertExists($original->getPathRelativeToRoot());
    Storage::disk('public')->assertMissing($replacementPath);
});

it('restores the original media when a later write fails after the replacement is stored', function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $institution = Institution::factory()->create(['name' => 'Original Name', 'status' => 'verified']);
    $original = $institution->addMedia(fakeGeneratedImageUpload('original.jpg', 160, 90))
        ->toMediaCollection('cover');
    $originalPath = $original->getPathRelativeToRoot();

    expect(function () use ($admin, $institution): void {
        app(ApplyDirectContributionUpdateAction::class)->apply(
            (string) $admin->getKey(),
            $institution->getMorphClass(),
            (string) $institution->getKey(),
            ['name' => 'Replacement Name'],
            function () use ($institution): void {
                app(MediaCollectionSync::class)->syncSingle(
                    $institution,
                    fakeGeneratedImageUpload('replacement.jpg', 160, 90),
                    'cover',
                );

                throw new RuntimeException('Could not finish the update.');
            },
        );
    })->toThrow(RuntimeException::class, 'Could not finish the update.');

    expect($institution->fresh()->name)->toBe('Original Name')
        ->and($institution->fresh()->getFirstMedia('cover')?->getKey())->toBe($original->getKey())
        ->and(Storage::disk('public')->exists($originalPath))->toBeTrue();
});

it('reserves direct donation channel changes for global administrators', function () {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $channel = DonationChannel::factory()->verified()->create();

    expect($moderator->can('view', $channel))->toBeTrue()
        ->and($moderator->can('create', DonationChannel::class))->toBeFalse()
        ->and($moderator->can('update', $channel))->toBeFalse()
        ->and($admin->can('create', DonationChannel::class))->toBeTrue()
        ->and($admin->can('update', $channel))->toBeTrue();
});

it('keeps a staged institution update pending if its gallery would exceed capacity', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create(['status' => 'verified']);
    $reviewer = User::factory()->create();
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'status' => ContributionRequestStatus::Pending,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->getKey(),
        'proposed_data' => ['name' => 'Proposed name'],
    ]);
    $upload = fakeGeneratedImageUpload('gallery.jpg', 64, 64);

    foreach (range(1, 10) as $index) {
        $institution->addMedia($upload)->preservingOriginal()
            ->usingFileName("gallery-{$index}.jpg")
            ->toMediaCollection('gallery');
    }

    $request->addMedia($upload)
        ->withCustomProperties(['contribution_field' => 'gallery', 'is_single' => false])
        ->toMediaCollection('pending_media');

    expect(fn () => app(ApproveContributionRequestAction::class)->handle($request, $reviewer))
        ->toThrow(ValidationException::class);

    expect($institution->fresh()->getMedia('gallery'))->toHaveCount(10)
        ->and($institution->fresh()->name)->toBe($institution->name)
        ->and($request->fresh()->status)->toBe(ContributionRequestStatus::Pending)
        ->and($request->fresh()->getMedia('pending_media'))->toHaveCount(1);
});

it('preserves the published cover and staged replacements when approval cannot copy an image', function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create(['name' => 'Original institution', 'status' => 'verified']);
    $original = $institution->addMedia(fakeGeneratedImageUpload('old-cover.jpg', 160, 90))
        ->toMediaCollection('cover');
    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Update,
        'status' => ContributionRequestStatus::Pending,
        'subject_type' => ContributionSubjectType::Institution,
        'entity_type' => $institution->getMorphClass(),
        'entity_id' => $institution->getKey(),
        'proposed_data' => ['name' => 'Proposed institution'],
    ]);
    $cover = $request->addMedia(fakeGeneratedImageUpload('new-cover.jpg', 160, 90))
        ->withCustomProperties(['contribution_field' => 'cover', 'is_single' => true])
        ->toMediaCollection('pending_media');
    $gallery = $request->addMedia(fakeGeneratedImageUpload('gallery.jpg', 64, 64))
        ->withCustomProperties(['contribution_field' => 'gallery', 'is_single' => false])
        ->toMediaCollection('pending_media');
    Storage::disk('public')->delete($gallery->getPathRelativeToRoot());
    $reviewer = User::factory()->create();

    expect(fn () => app(ApproveContributionRequestAction::class)->handle($request, $reviewer))
        ->toThrow(Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist::class);

    expect($institution->fresh()->name)->toBe('Original institution')
        ->and($institution->fresh()->getFirstMedia('cover')?->getKey())->toBe($original->getKey())
        ->and($request->fresh()->status)->toBe(ContributionRequestStatus::Pending)
        ->and($request->fresh()->getMedia('pending_media'))->toHaveCount(2);

    Storage::disk('public')->assertExists($original->getPathRelativeToRoot());
    Storage::disk('public')->assertExists($cover->getPathRelativeToRoot());
});
