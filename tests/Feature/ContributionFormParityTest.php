<?php

use AIArmada\Membership\Enums\MemberRole;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitInstitution;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitPerson;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SubmitReference;
use App\Features\Contributions\EntryPoints\Livewire\Pages\SuggestUpdate;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * @return array<string, Field>
 */
function contributionParityFields($component): array
{
    return $component->instance()->getForm('form')->getFlatFields(withHidden: true);
}

/**
 * @param  array<string, Field>  $fields
 * @return array<string, bool>
 */
function contributionParityRequiredRules(array $fields): array
{
    return array_map(static fn (Field $field): bool => $field->isRequired(), $fields);
}

/**
 * @return list<string>
 */
function contributionParitySectionHeadings($component): array
{
    return collect($component->instance()->getForm('form')->getFlatComponents(withActions: false, withHidden: true))
        ->filter(static fn (mixed $component): bool => $component instanceof Section)
        ->map(static fn (Section $section): string => (string) $section->getHeading())
        ->values()
        ->all();
}

/**
 * @param  list<string>  $headings
 * @return list<string>
 */
function contributionParityLocationHeadings(array $headings): array
{
    return array_values(array_filter(
        $headings,
        static fn (string $heading): bool => ! in_array($heading, [
            __('Find the institution on Google Maps'),
            __('Update the location on Google Maps'),
        ], true),
    ));
}

it('keeps institution fields, order, and required rules aligned between create and update', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    $create = Livewire::actingAs($user)->test(SubmitInstitution::class);
    $update = Livewire::actingAs($user)->test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ])->set('data.contactMethods', []);

    $createFields = contributionParityFields($create);
    $updateFields = contributionParityFields($update);
    $createHeadings = contributionParitySectionHeadings($create);
    $updateHeadings = contributionParitySectionHeadings($update);

    expect($createFields)->not->toHaveKey('proposer_note')
        ->and($updateFields)->toHaveKey('proposer_note')
        ->and($createHeadings)->toContain(__('Find the institution on Google Maps'))
        ->and($updateHeadings)->not->toContain(__('Find the institution on Google Maps'));

    unset($updateFields['proposer_note']);

    expect(array_keys($updateFields))->toBe(array_keys($createFields))
        ->and(contributionParityRequiredRules($updateFields))->toBe(contributionParityRequiredRules($createFields))
        ->and(contributionParityLocationHeadings($updateHeadings))
        ->toBe(contributionParityLocationHeadings($createHeadings));
});

it('keeps person fields, order, and required rules aligned between create and update', function (): void {
    $user = User::factory()->create();
    $person = Person::factory()->create(['status' => 'verified']);

    $create = Livewire::actingAs($user)->test(SubmitPerson::class);
    $update = Livewire::actingAs($user)->test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ])->set('data.names', [])
        ->set('data.contactMethods', []);

    $createFields = contributionParityFields($create);
    $updateFields = contributionParityFields($update);

    expect($createFields)->not->toHaveKey('proposer_note')
        ->and($updateFields)->toHaveKey('proposer_note');

    unset($updateFields['proposer_note']);

    expect(array_keys($updateFields))->toBe(array_keys($createFields))
        ->and(contributionParityRequiredRules($updateFields))->toBe(contributionParityRequiredRules($createFields))
        ->and(contributionParitySectionHeadings($update))->toBe(contributionParitySectionHeadings($create))
        ->and($createFields['address.country_id']->isRequired())->toBeTrue()
        ->and($updateFields['address.country_id']->isRequired())->toBeTrue();
});

it('keeps reference fields, order, and required rules aligned across dynamic record kinds', function (): void {
    $user = User::factory()->create();
    $reference = Reference::factory()->create([
        'type' => 'book',
        'record_kind' => 'work',
        'status' => 'verified',
    ]);

    $create = Livewire::actingAs($user)->test(SubmitReference::class);
    $update = Livewire::actingAs($user)->test(SuggestUpdate::class, [
        'subjectType' => ContributionSubjectType::Reference->publicRouteSegment(),
        'subjectId' => $reference->slug,
    ]);

    $createFields = contributionParityFields($create);
    $updateFields = contributionParityFields($update);

    expect($createFields)->not->toHaveKey('proposer_note')
        ->and($updateFields)->toHaveKey('proposer_note');

    unset($updateFields['proposer_note']);

    expect(array_keys($updateFields))->toBe(array_keys($createFields))
        ->and(contributionParityRequiredRules($updateFields))->toBe(contributionParityRequiredRules($createFields))
        ->and(contributionParitySectionHeadings($update))->toBe(contributionParitySectionHeadings($create));

    foreach ([$create, $update] as $form) {
        $form->set('data.record_kind', 'edition')
            ->set('data.publisher', null)
            ->set('data.publication_year', null)
            ->set('data.isbn', null)
            ->set('data.edition_number', null);
    }

    $createEditionFields = contributionParityFields($create);
    $updateEditionFields = contributionParityFields($update);

    unset($updateEditionFields['proposer_note']);

    expect(contributionParityRequiredRules($updateEditionFields))->toBe(contributionParityRequiredRules($createEditionFields))
        ->and($createEditionFields['parent_id']->isRequired())->toBeTrue()
        ->and($updateEditionFields['parent_id']->isRequired())->toBeTrue();
});

it('keeps single media deletable for maintainers and locked for visitors on every public update form', function (ContributionSubjectType $subjectType, string $field): void {
    $owner = User::factory()->create();
    $owner->assignRole('admin');
    $visitor = User::factory()->create();

    $entity = match ($subjectType) {
        ContributionSubjectType::Institution => Institution::factory()->create(['status' => 'verified']),
        ContributionSubjectType::Person => Person::factory()->create(['status' => 'verified']),
        ContributionSubjectType::Reference => Reference::factory()->create([
            'type' => 'book',
            'record_kind' => 'work',
            'status' => 'verified',
        ]),
    };

    withGlobalOwnerContext(fn (): null => addTestMember($entity, $owner, MemberRole::Owner));

    $parameters = [
        'subjectType' => $subjectType->publicRouteSegment(),
        'subjectId' => $entity->slug,
    ];

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, $parameters)
        ->assertFormFieldExists($field, function (SpatieMediaLibraryFileUpload $upload): bool {
            expect($upload->isDeletable())->toBeTrue();

            return true;
        });

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, $parameters)
        ->assertFormFieldExists($field, function (SpatieMediaLibraryFileUpload $upload): bool {
            expect($upload->isDeletable())->toBeFalse();

            return true;
        });
})->with([
    'institution logo' => [ContributionSubjectType::Institution, 'logo'],
    'institution cover' => [ContributionSubjectType::Institution, 'cover'],
    'person avatar' => [ContributionSubjectType::Person, 'avatar'],
    'person square photo' => [ContributionSubjectType::Person, 'square'],
    'reference front cover' => [ContributionSubjectType::Reference, 'front_cover'],
    'reference back cover' => [ContributionSubjectType::Reference, 'back_cover'],
]);

it('removes institution logo and cover when an authorized maintainer clears them', function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $owner = User::factory()->create();
    $owner->assignRole('admin');

    $institution = Institution::factory()->create(['status' => 'verified']);
    $institution->primaryAddress()?->forceFill([
        'google_place_id' => 'place_parity_media',
        'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query_place_id=place_parity_media',
    ])->save();
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    $logo = $institution->addMedia(fakeGeneratedImageUpload('parity-logo.png'))
        ->toMediaCollection('logo');
    $cover = $institution->addMedia(fakeGeneratedImageUpload('parity-cover.jpg', 1600, 900))
        ->toMediaCollection('cover');

    $logoId = $logo->getKey();
    $coverId = $cover->getKey();

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.logo', [])
        ->set('data.cover', [])
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->getMedia('logo'))->toBeEmpty()
        ->and($institution->fresh()->getMedia('cover'))->toBeEmpty()
        ->and(Media::query()->whereKey([$logoId, $coverId])->exists())->toBeFalse();
});
