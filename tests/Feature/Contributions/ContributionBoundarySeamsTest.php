<?php

use App\Features\Contributions\Api\ContributionRequestReader;
use App\Features\Contributions\FeatureServiceProvider as ContributionsServiceProvider;
use App\Features\Contributions\Queries\EloquentContributionRequestReader;
use App\Features\Contributions\Support\ContributionModel;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Platform\FeatureCatalog;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Contributions\Enums\ContributionRequestStatus;
use App\Shared\Contributions\Enums\ContributionRequestType;
use App\Shared\Contributions\Enums\ContributionSubjectType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('declares the owned table literally on the root model', function (): void {
    $model = new ContributionRequest;
    $property = new ReflectionProperty(ContributionRequest::class, 'table');
    $defaults = $property->getDeclaringClass()->getDefaultProperties();

    expect($model->getTable())->toBe('contribution_requests')
        ->and($defaults['table'] ?? null)->toBe('contribution_requests');
});

it('keeps morph aliases stable for the request and subject types', function (): void {
    expect((new ContributionRequest)->getMorphClass())->toBe('contribution_request')
        ->and(MorphModelResolver::resolve('contribution_request'))->toBe(ContributionRequest::class)
        ->and(ContributionModel::modelClass())->toBe(ContributionRequest::class);

    $expected = [
        ContributionSubjectType::Event->value => Event::class,
        ContributionSubjectType::Institution->value => Institution::class,
        ContributionSubjectType::Person->value => Person::class,
        ContributionSubjectType::Reference->value => Reference::class,
    ];

    foreach ($expected as $alias => $class) {
        expect(MorphModelResolver::resolve($alias))->toBe($class)
            ->and($class)->toBe(ContributionSubjectType::from($alias)->modelClass());
    }
});

it('preserves stored enum string values', function (): void {
    expect(array_map(static fn (ContributionRequestStatus $status): string => $status->value, ContributionRequestStatus::cases()))
        ->toBe(['pending', 'approved', 'rejected', 'cancelled'])
        ->and(array_map(static fn (ContributionRequestType $type): string => $type->value, ContributionRequestType::cases()))
        ->toBe(['create', 'update'])
        ->and(array_map(static fn (ContributionSubjectType $type): string => $type->value, ContributionSubjectType::cases()))
        ->toBe(['event', 'institution', 'person', 'reference']);
});

it('binds the reader through the feature Api and resolves ownership', function (): void {
    $reader = app(ContributionRequestReader::class);

    expect($reader)->toBeInstanceOf(EloquentContributionRequestReader::class);

    $user = User::factory()->create();
    $other = User::factory()->create();
    $owned = ContributionRequest::factory()->create(['proposer_id' => $user->id]);
    ContributionRequest::factory()->create(['proposer_id' => $other->id]);

    expect($reader->requestIdsForProposer((string) $user->getKey()))->toBe([(string) $owned->id])
        ->and($reader->findRequestIdForProposer((string) $user->getKey(), (string) $owned->id))->toBe((string) $owned->id)
        ->and($reader->findRequestIdForProposer((string) $other->getKey(), (string) $owned->id))->toBeNull()
        ->and($reader->requestIdsForReviewer((string) $user->getKey()))->toBe([]);

    $contractFile = (new ReflectionClass(ContributionRequestReader::class))->getFileName();
    $implementationFile = (new ReflectionClass(EloquentContributionRequestReader::class))->getFileName();

    expect($contractFile)->toEndWith('app/Features/Contributions/Api/ContributionRequestReader.php')
        ->and($implementationFile)->toEndWith('app/Features/Contributions/Queries/EloquentContributionRequestReader.php')
        ->and((string) file_get_contents((string) $implementationFile))->not->toContain('App\\Models\\ContributionRequest');
});

it('discovers the feature manifest without claiming a table', function (): void {
    $catalog = FeatureCatalog::load(base_path());
    $manifest = json_decode((string) file_get_contents(app_path('Features/Contributions/feature.json')), true, 512, JSON_THROW_ON_ERROR);
    $providers = require base_path('bootstrap/providers.php');

    expect($catalog['features'])->toHaveKey('Contributions')
        ->and($catalog['owners'])->not->toHaveKey('table:contribution_requests')
        ->and($manifest)->toMatchArray(['schema' => 2, 'name' => 'Contributions', 'owns' => []])
        ->and(array_keys($providers, ContributionsServiceProvider::class, true))->toHaveCount(1);
});

it('copies staged media from disk into the target collection', function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $institution = Institution::factory()->create(['name' => 'Staged Media Institution']);
    $source = fakeGeneratedImageUpload('staged-logo.png', 800, 800);
    Storage::disk('public')->put('holding/staged-logo.png', (string) $source->get());

    app(MediaCollectionSync::class)->syncFromDisk(
        $institution,
        'holding/staged-logo.png',
        'public',
        'logo',
        customProperties: ['contribution_field' => 'cover', 'is_single' => true],
    );

    $media = $institution->refresh()->getFirstMedia('logo');

    expect($media)->not->toBeNull()
        ->and($media?->file_name)->toBe('staged-logo.png')
        ->and($media?->name)->toStartWith('Logo - ')
        ->and($media?->getCustomProperty('collection'))->toBe('logo')
        ->and($media?->getCustomProperty('original_file_name'))->toBe('staged-logo.png')
        ->and($media?->getCustomProperty('contribution_field'))->toBe('cover')
        ->and($media?->getCustomProperty('is_single'))->toBeTrue()
        ->and(Storage::disk('public')->exists('holding/staged-logo.png'))->toBeTrue();
});
