<?php

use AIArmada\References\Enums\ReferenceContributorRole;
use AIArmada\References\Models\ReferenceContributor;
use AIArmada\Signals\Models\SignalEvent;
use App\Actions\Contributions\ApproveContributionRequestAction;
use App\Actions\Contributions\SubmitContributionUpdateRequestAction;
use App\Actions\References\SaveReferenceAction;
use App\Data\Api\Frontend\Search\ReferenceListData;
use App\Enums\SpeakerStatus;
use App\Filament\Resources\References\Pages\CreateReference;
use App\Filament\Resources\References\Pages\EditReference;
use App\Forms\ReferenceAuthorFormSchema;
use App\Forms\ReferenceFormSchema;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use App\Support\Search\ReferenceSearchService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function referenceAuthorPerson(string $name, string $status = 'verified'): Person
{
    return Person::factory()->create([
        'name' => $name,
        'status' => $status,
    ]);
}

it('stores authors as an unordered set on works through the save boundary', function (): void {
    $first = referenceAuthorPerson('Imam al-Nawawi');
    $second = referenceAuthorPerson('Ibn Hajar al-Asqalani');

    $reference = app(SaveReferenceAction::class)->handle([
        'title' => 'Authored Work',
        'type' => 'book',
        'record_kind' => 'work',
        'author_ids' => [(string) $second->getKey(), (string) $first->getKey(), (string) $second->getKey(), ''],
        'status' => 'verified',
    ]);

    $expectedIds = [(string) $first->getKey(), (string) $second->getKey()];

    expect($reference->authorIdsValue())->toEqualCanonicalizing($expectedIds)
        ->and(array_column($reference->effectiveAuthorsStructured(), 'id'))->toEqualCanonicalizing($expectedIds)
        ->and($reference->effectiveAuthorNamesList())->toEqualCanonicalizing(['Imam al-Nawawi', 'Ibn Hajar al-Asqalani']);
});

it('supports unknown authors and clears authors with explicit null', function (): void {
    $author = referenceAuthorPerson('Cleared Author');

    $reference = app(SaveReferenceAction::class)->handle([
        'title' => 'Unknown Authors Work',
        'type' => 'article',
        'record_kind' => 'work',
        'status' => 'verified',
    ]);

    expect($reference->effectiveAuthorsStructured())->toBe([])
        ->and($reference->effectiveAuthorNames())->toBe('');

    app(SaveReferenceAction::class)->handle([
        'title' => 'Unknown Authors Work',
        'author_ids' => [(string) $author->getKey()],
        'status' => 'verified',
    ], $reference);

    expect($reference->fresh()->authorIdsValue())->toBe([(string) $author->getKey()]);

    app(SaveReferenceAction::class)->handle([
        'title' => 'Unknown Authors Work',
        'author_ids' => null,
        'status' => 'verified',
    ], $reference);

    expect($reference->fresh()->authorIdsValue())->toBe([])
        ->and(ReferenceContributor::query()->count())->toBe(0);
});

it('rejects unknown and non-visible author ids', function (): void {
    $rejected = referenceAuthorPerson('Rejected Author', 'rejected');

    expect(fn (): Reference => app(SaveReferenceAction::class)->handle([
        'title' => 'Bad Author Work',
        'author_ids' => [(string) $rejected->getKey()],
        'status' => 'verified',
    ]))->toThrow(ValidationException::class);

    expect(fn (): Reference => app(SaveReferenceAction::class)->handle([
        'title' => 'Bad Author Work',
        'author_ids' => ['00000000-0000-0000-0000-000000000000'],
        'status' => 'verified',
    ]))->toThrow(ValidationException::class);
});

it('inherits effective authors from the root work without storing child links', function (): void {
    $author = referenceAuthorPerson('Root Work Author');

    $work = app(SaveReferenceAction::class)->handle([
        'title' => 'Inheritance Work',
        'type' => 'book',
        'record_kind' => 'work',
        'author_ids' => [(string) $author->getKey()],
        'status' => 'verified',
    ]);

    $edition = app(SaveReferenceAction::class)->handle([
        'title' => 'Inheritance Work',
        'type' => 'book',
        'record_kind' => 'edition',
        'parent_id' => (string) $work->getKey(),
        'edition_number' => 2,
        'author_ids' => [(string) $author->getKey()],
        'status' => 'verified',
    ]);

    $part = app(SaveReferenceAction::class)->handle([
        'title' => 'Inheritance Work',
        'type' => 'book',
        'record_kind' => 'part',
        'parent_id' => (string) $edition->getKey(),
        'part_type' => 'jilid',
        'part_number' => '1',
        'status' => 'verified',
    ]);

    expect($edition->authorIdsValue())->toBe([])
        ->and($part->authorIdsValue())->toBe([])
        ->and($edition->fresh()->effectiveAuthorNames())->toBe('Root Work Author')
        ->and($part->fresh()->effectiveAuthorNames())->toBe('Root Work Author')
        ->and(ReferenceContributor::query()->count())->toBe(1)
        ->and($work->fresh()->authorIdsValue())->toBe([(string) $author->getKey()]);
});

it('drops stored author links when a work becomes a child record', function (): void {
    $author = referenceAuthorPerson('Converted Author');
    $parent = Reference::factory()->create(['title' => 'Conversion Parent', 'type' => 'book']);

    $reference = app(SaveReferenceAction::class)->handle([
        'title' => 'Conversion Candidate',
        'type' => 'book',
        'record_kind' => 'work',
        'author_ids' => [(string) $author->getKey()],
        'status' => 'verified',
    ]);

    expect($reference->authorIdsValue())->toBe([(string) $author->getKey()]);

    $reference->update([
        'record_kind' => 'part',
        'parent_id' => $parent->getKey(),
        'part_type' => 'jilid',
        'part_number' => '1',
    ]);

    expect($reference->fresh()->authorIdsValue())->toBe([])
        ->and(ReferenceContributor::query()->count())->toBe(0);
});

it('drops stored author links when a work becomes a child with a simultaneous title change', function (): void {
    $ownAuthor = referenceAuthorPerson('Simultaneous Own Author');
    $parentAuthor = referenceAuthorPerson('Simultaneous Parent Author');

    $parent = Reference::factory()->withAuthors([(string) $parentAuthor->getKey()])->create([
        'title' => 'Simultaneous Parent',
        'type' => 'book',
    ]);

    $reference = app(SaveReferenceAction::class)->handle([
        'title' => 'Simultaneous Candidate',
        'type' => 'book',
        'record_kind' => 'work',
        'author_ids' => [(string) $ownAuthor->getKey()],
        'status' => 'verified',
    ]);

    $parentAuthorsBefore = $parent->fresh()->authorIdsValue();
    $parentTitleBefore = (string) $parent->fresh()->title;

    $reference->update([
        'title' => 'Simultaneous Candidate Renamed',
        'record_kind' => 'part',
        'parent_id' => $parent->getKey(),
        'part_type' => 'jilid',
        'part_number' => '1',
    ]);

    $fresh = $reference->fresh();

    expect($fresh->authorIdsValue())->toBe([])
        ->and(ReferenceContributor::query()->where('reference_id', $reference->getKey())->count())->toBe(0)
        ->and($fresh->effectiveAuthorNames())->toBe('Simultaneous Parent Author')
        ->and($parent->fresh()->authorIdsValue())->toBe($parentAuthorsBefore)
        ->and((string) $parent->fresh()->title)->toBe($parentTitleBefore)
        ->and(ReferenceContributor::query()->count())->toBe(1);
});

it('validates reference language codes against the languages catalog', function (): void {
    $this->seed(LanguageSeeder::class);

    $reference = app(SaveReferenceAction::class)->handle([
        'title' => 'Catalog Language Work',
        'language' => 'ar',
        'status' => 'verified',
    ]);

    expect($reference->language)->toBe('ar');

    $cleared = app(SaveReferenceAction::class)->handle([
        'title' => 'Catalog Language Work',
        'language' => '',
        'status' => 'verified',
    ], $reference);

    expect($cleared->language)->toBeNull();

    expect(fn (): Reference => app(SaveReferenceAction::class)->handle([
        'title' => 'Bad Language Work',
        'language' => 'xx',
        'status' => 'verified',
    ]))->toThrow(ValidationException::class);
});

it('validates language codes on the direct model path without language aliases', function (): void {
    $this->seed(LanguageSeeder::class);

    $reference = Reference::factory()->create([
        'title' => 'Direct Model Language Work',
        'language' => 'ar',
    ]);

    expect($reference->language)->toBe('ar');

    expect(fn (): mixed => $reference->update(['language' => 'xx']))->toThrow(ValidationException::class);
    expect(fn (): mixed => Reference::factory()->create([
        'title' => 'Direct Model Bad Language',
        'language' => 'xx',
    ]))->toThrow(ValidationException::class);

    $reference->update(['language' => '   ']);

    expect($reference->fresh()->language)->toBeNull();
});

it('serializes structured authors and friendly language labels in reference payloads', function (): void {
    $this->seed(LanguageSeeder::class);

    $author = referenceAuthorPerson('Payload Author');

    $reference = Reference::factory()->withAuthors([(string) $author->getKey()])->create([
        'title' => 'Payload Work',
        'language' => 'ar',
        'status' => 'verified',
    ]);

    $item = ReferenceListData::fromModel($reference->fresh(), null)->toArray();

    expect($item['authors'])->toBe([
        ['id' => (string) $author->getKey(), 'name' => 'Payload Author', 'slug' => (string) $author->slug],
    ])
        ->and($item['author_ids'])->toBe([(string) $author->getKey()])
        ->and($item['language'])->toBe('ar')
        ->and($item['language_label'])->toBe('Arabic (العربية)');
});

it('searches references by author name including inherited descendants', function (): void {
    $matchAuthor = referenceAuthorPerson('Qudamah Searchable');
    $otherAuthor = referenceAuthorPerson('Unrelated Writer');

    $matchWork = Reference::factory()->withAuthors([(string) $matchAuthor->getKey()])->create([
        'title' => 'Searchable Author Work',
        'type' => 'book',
        'status' => 'verified',
    ]);

    $part = Reference::factory()->part()->create([
        'title' => 'Searchable Author Work',
        'parent_id' => $matchWork->getKey(),
        'status' => 'verified',
    ]);

    Reference::factory()->withAuthors([(string) $otherAuthor->getKey()])->create([
        'title' => 'Other Authored Work',
        'status' => 'verified',
    ]);

    $service = app(ReferenceSearchService::class);

    expect($service->publicSearchIds('Qudamah'))->toContain((string) $matchWork->getKey(), (string) $part->getKey())
        ->and($service->publicFuzzySearchIds('Qudamha'))->toContain((string) $matchWork->getKey());
});

it('matches differently cased author names in the picker and reference search', function (): void {
    $author = referenceAuthorPerson('Imam al-Nawawi');
    $middleAuthor = Person::factory()->create([
        'name' => 'Ahmad',
        'middle_name' => 'Qudamah',
        'status' => 'verified',
    ]);
    $familyAuthor = Person::factory()->create([
        'name' => 'Yahya',
        'family_name' => 'Sharafuddin',
        'status' => 'verified',
    ]);

    $work = Reference::factory()->withAuthors([(string) $author->getKey()])->create([
        'title' => 'Case Insensitive Author Work',
        'type' => 'book',
        'status' => 'verified',
    ]);

    $part = Reference::factory()->part()->create([
        'title' => 'Case Insensitive Author Work',
        'parent_id' => $work->getKey(),
        'status' => 'verified',
    ]);

    expect(ReferenceAuthorFormSchema::searchOptions('nawawi'))->toHaveKey((string) $author->getKey())
        ->and(ReferenceAuthorFormSchema::searchOptions('qudamah'))->toHaveKey((string) $middleAuthor->getKey())
        ->and(ReferenceAuthorFormSchema::searchOptions('sharafuddin'))->toHaveKey((string) $familyAuthor->getKey())
        ->and(app(ReferenceSearchService::class)->publicSearchIds('nawawi'))->toContain((string) $work->getKey(), (string) $part->getKey());
});

it('reindexes authored references when a person name changes', function (): void {
    $author = referenceAuthorPerson('Before Rename');

    $reference = Reference::factory()->withAuthors([(string) $author->getKey()])->create([
        'title' => 'Rename Reindex Work',
        'status' => 'verified',
    ]);

    $service = app(ReferenceSearchService::class);

    expect($service->publicSearchIds('Before Rename'))->toContain((string) $reference->getKey());

    $author->update(['name' => 'After Rename']);

    expect($service->publicSearchIds('After Rename'))->toContain((string) $reference->getKey())
        ->and($reference->fresh()->effectiveAuthorNames())->toBe('After Rename');
});

it('blocks deleting a person linked as a reference contributor', function (): void {
    $author = referenceAuthorPerson('Guarded Author');

    $reference = Reference::factory()->withAuthors([(string) $author->getKey()])->create([
        'title' => 'Guarded Work',
        'status' => 'verified',
    ]);

    expect(fn (): ?bool => $author->delete())->toThrow(ValidationException::class);

    expect(Person::query()->whereKey($author->getKey())->exists())->toBeTrue();

    $reference->delete();

    $author->refresh()->delete();

    expect(Person::query()->whereKey($author->getKey())->exists())->toBeFalse();
});

it('removes contributor links when a reference subtree is deleted', function (): void {
    $author = referenceAuthorPerson('Cascade Author');

    $work = Reference::factory()->withAuthors([(string) $author->getKey()])->create([
        'title' => 'Cascade Work',
        'type' => 'book',
    ]);

    Reference::factory()->part()->create([
        'title' => 'Cascade Work',
        'parent_id' => $work->getKey(),
    ]);

    expect(ReferenceContributor::query()->count())->toBe(1);

    $work->delete();

    expect(ReferenceContributor::query()->count())->toBe(0);
});

it('quick creates pending authors without privileges and records a curated signal', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $authorId = ReferenceAuthorFormSchema::quickCreateUsing([
        'name' => 'Quick Author',
        'gender' => 'male',
    ]);

    $author = Person::query()->whereKey($authorId)->firstOrFail();

    expect($author->status)->toBe('pending')
        ->and($author->speaker_status)->toBe(SpeakerStatus::Inactive)
        ->and($author->allow_public_event_submission)->toBeFalse()
        ->and($author->members()->count())->toBe(0)
        ->and($author->shouldBeSearchable())->toBeFalse();

    $signal = SignalEvent::query()->where('event_name', 'author.quick_created')->sole();

    expect($signal->event_category)->toBe('contribution')
        ->and(data_get($signal->properties, 'person_id'))->toBe($authorId)
        ->and(data_get($signal->properties, 'source'))->toBe('reference_author_picker');

    $duplicateId = ReferenceAuthorFormSchema::quickCreateUsing([
        'name' => 'Quick Author',
        'gender' => 'male',
    ]);

    expect($duplicateId)->not->toBe($authorId)
        ->and(Person::query()->where('name', 'Quick Author')->count())->toBe(2);
});

it('validates quick-created author identity without inventing name or gender', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(fn (): string => ReferenceAuthorFormSchema::quickCreateUsing([
        'name' => '   ',
        'gender' => 'male',
    ]))->toThrow(ValidationException::class);

    expect(fn (): string => ReferenceAuthorFormSchema::quickCreateUsing([
        'name' => str_repeat('a', 256),
    ]))->toThrow(ValidationException::class);

    expect(fn (): string => ReferenceAuthorFormSchema::quickCreateUsing([
        'name' => 'Invalid Gender Author',
        'gender' => 'unknown',
    ]))->toThrow(ValidationException::class);

    $authorId = ReferenceAuthorFormSchema::quickCreateUsing([
        'name' => '  Trimmed Author  ',
    ]);

    $author = Person::query()->whereKey($authorId)->firstOrFail();

    expect($author->name)->toBe('Trimmed Author')
        ->and($author->gender)->toBeNull()
        ->and($author->status)->toBe('pending')
        ->and($author->speaker_status)->toBe(SpeakerStatus::Inactive)
        ->and($author->members()->count())->toBe(0);

    expect(Person::query()->where('name', 'Unknown Author')->exists())->toBeFalse();
});

it('scopes author selection to visible persons and disambiguates duplicate names', function (): void {
    $verified = referenceAuthorPerson('Samad Penulis', 'verified');
    $pending = referenceAuthorPerson('Samad Penulis', 'pending');
    referenceAuthorPerson('Samad Penulis', 'rejected');

    $options = ReferenceAuthorFormSchema::searchOptions('Samad Penulis');

    expect($options)->toHaveCount(2)
        ->and($options)->toHaveKeys([(string) $verified->getKey(), (string) $pending->getKey()]);

    $labels = ReferenceAuthorFormSchema::selectedLabels([
        (string) $verified->getKey(),
        (string) $pending->getKey(),
    ]);

    expect($labels[(string) $verified->getKey()])->toContain((string) $verified->slug)
        ->and($labels[(string) $pending->getKey()])->toContain((string) $pending->slug)
        ->and($labels[(string) $verified->getKey()])->not->toBe($labels[(string) $pending->getKey()]);
});

it('exposes selectable authors through the reference authors catalog', function (): void {
    referenceAuthorPerson('Catalog Author', 'verified');
    referenceAuthorPerson('Hidden Author', 'rejected');

    $this->getJson(route('api.client.catalogs.reference-authors', ['q' => 'Catalog']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'Catalog Author');
});

it('applies contribution author updates through approval', function (): void {
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();
    $author = referenceAuthorPerson('Contribution Author');

    $reference = Reference::factory()->create([
        'title' => 'Contribution Author Work',
        'status' => 'verified',
    ]);

    $request = app(SubmitContributionUpdateRequestAction::class)->handle(
        $reference,
        $proposer,
        [
            'title' => 'Contribution Author Work',
            'author_ids' => [(string) $author->getKey()],
        ],
        'Linking the author.',
    );

    app(ApproveContributionRequestAction::class)->handle($request, $reviewer, 'Approved.');

    expect($reference->fresh()->authorIdsValue())->toBe([(string) $author->getKey()]);
});

it('syncs authors through the direct admin forms and validates codes server-side', function (): void {
    $this->seed(LanguageSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $author = referenceAuthorPerson('Admin Form Author');
    $other = referenceAuthorPerson('Admin Form Other');

    Livewire::actingAs($administrator)
        ->test(CreateReference::class)
        ->fillForm([
            'title' => 'Admin Form Work',
            'type' => 'book',
            'author_ids' => [(string) $author->getKey()],
            'language' => 'ms',
            'status' => 'verified',
            'socialProfiles' => [],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $reference = Reference::query()->where('title', 'Admin Form Work')->firstOrFail();

    expect($reference->authorIdsValue())->toBe([(string) $author->getKey()])
        ->and($reference->language)->toBe('ms');

    Livewire::actingAs($administrator)
        ->test(EditReference::class, ['record' => $reference->getRouteKey()])
        ->fillForm([
            'title' => 'Admin Form Work',
            'type' => 'book',
            'author_ids' => [(string) $other->getKey()],
            'language' => 'ar',
            'status' => 'verified',
            'socialProfiles' => [],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($reference->fresh()->authorIdsValue())->toBe([(string) $other->getKey()])
        ->and($reference->fresh()->language)->toBe('ar');

    Livewire::actingAs($administrator)
        ->test(EditReference::class, ['record' => $reference->getRouteKey()])
        ->fillForm([
            'title' => 'Admin Form Work',
            'type' => 'book',
            'language' => 'xx',
            'status' => 'verified',
            'socialProfiles' => [],
        ])
        ->call('save')
        ->assertHasErrors(['data.language']);

    expect($reference->fresh()->language)->toBe('ar');
});

it('clears authors with explicit null or empty array through the direct admin edit form', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $author = referenceAuthorPerson('Admin Clear Author');

    $reference = Reference::factory()->withAuthors([(string) $author->getKey()])->create([
        'title' => 'Admin Clear Work',
        'type' => 'book',
        'status' => 'verified',
    ]);

    expect($reference->authorIdsValue())->toBe([(string) $author->getKey()]);

    Livewire::actingAs($administrator)
        ->test(EditReference::class, ['record' => $reference->getRouteKey()])
        ->fillForm([
            'title' => 'Admin Clear Work',
            'type' => 'book',
            'author_ids' => null,
            'status' => 'verified',
            'socialProfiles' => [],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($reference->fresh()->authorIdsValue())->toBe([])
        ->and(ReferenceContributor::query()->where('reference_id', $reference->getKey())->count())->toBe(0);

    $reference->syncContributors(ReferenceContributorRole::Author, (new Person)->getMorphClass(), [(string) $author->getKey()]);

    expect($reference->fresh()->authorIdsValue())->toBe([(string) $author->getKey()]);

    Livewire::actingAs($administrator)
        ->test(EditReference::class, ['record' => $reference->getRouteKey()])
        ->fillForm([
            'title' => 'Admin Clear Work',
            'type' => 'book',
            'author_ids' => [],
            'status' => 'verified',
            'socialProfiles' => [],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($reference->fresh()->authorIdsValue())->toBe([]);
});

it('preserves authors when the admin payload omits them', function (): void {
    $author = referenceAuthorPerson('Omitted Preserve Author');

    $reference = Reference::factory()->withAuthors([(string) $author->getKey()])->create([
        'title' => 'Omitted Preserve Work',
        'type' => 'book',
        'status' => 'verified',
    ]);

    app(ContributionEntityMutationService::class)->syncReferenceRelations($reference, []);

    expect($reference->fresh()->authorIdsValue())->toBe([(string) $author->getKey()]);

    app(ContributionEntityMutationService::class)->syncReferenceRelations($reference, ['author_ids' => null]);

    expect($reference->fresh()->authorIdsValue())->toBe([]);
});

it('shows inherited authors on child quick creates without storing duplicates', function (): void {
    $author = referenceAuthorPerson('Picker Author');

    $work = Reference::factory()->create([
        'title' => 'Picker Work',
        'type' => 'book',
        'status' => 'verified',
    ]);
    $work->syncContributors(ReferenceContributorRole::Author, (new Person)->getMorphClass(), [(string) $author->getKey()]);

    $id = ReferenceFormSchema::createPending([
        'title' => 'Picker Work',
        'type' => 'book',
        'record_kind' => 'part',
        'parent_id' => (string) $work->getKey(),
        'part_type' => 'jilid',
        'part_number' => '1',
        'author_ids' => [(string) $author->getKey()],
    ]);

    $part = Reference::query()->findOrFail($id);

    expect($part->authorIdsValue())->toBe([])
        ->and($part->effectiveAuthorNames())->toBe('Picker Author')
        ->and($work->fresh()->authorIdsValue())->toBe([(string) $author->getKey()])
        ->and(ReferenceContributor::query()->count())->toBe(1);
});

it('rejects non-string author names in quick create', function (array $payload): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $count = Person::query()->count();

    try {
        ReferenceAuthorFormSchema::quickCreateUsing($payload);

        $this->fail('Expected a ValidationException for a non-string author name.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('name');
    }

    expect(Person::query()->count())->toBe($count)
        ->and(Person::query()->where('name', 'Array')->exists())->toBeFalse();
})->with([
    'missing' => [[]],
    'null' => [['name' => null]],
    'array' => [['name' => ['Quick Author']]],
    'integer' => [['name' => 123]],
    'float' => [['name' => 12.5]],
    'boolean' => [['name' => true]],
]);

it('covers new author strings in the en, ms, ms_MY, and id catalogs', function (): void {
    $keys = [
        'Select gender',
        'Enter the author name.',
        'The author name must be at most 255 characters.',
        'Select a valid gender or leave it blank.',
        'Optional short background that helps tell same-named authors apart.',
        'Authors',
        'Authors (from parent work)',
        'Optional. Leave empty when the authors are unknown.',
        'Select a parent work or edition to see its authors.',
        'Unknown authors',
        'This person is linked as a reference contributor and cannot be deleted.',
        'Select a valid language.',
    ];

    foreach (['en', 'ms', 'ms_MY', 'id'] as $locale) {
        $translations = json_decode((string) file_get_contents(base_path("resources/lang/{$locale}.json")), true);

        expect($translations)->toBeArray();

        foreach ($keys as $key) {
            expect($translations)->toHaveKey($key)
                ->and(trim((string) $translations[$key]))->not->toBe('');
        }
    }
});
