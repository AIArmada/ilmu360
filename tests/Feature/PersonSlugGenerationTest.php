<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressCountry;
use App\Actions\Contributions\ApproveContributionRequestAction;
use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventVisibility;
use App\Filament\Resources\Persons\Pages\CreatePerson;
use App\Filament\Resources\Persons\Pages\EditPerson;
use App\Forms\PersonFormSchema;
use App\Jobs\BackfillPersonSlugs;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use App\Services\EventKeyPersonSyncService;
use App\Support\Cache\PublicListingsCache;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('generates country-based slugs for person quick-create flows', function () {
    $country = createPersonSlugCountry();

    $personId = PersonFormSchema::createOptionUsing([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ]);

    $person = Person::query()
        ->with('addresses')
        ->findOrFail($personId);

    expect($person->slug)->toBe('ustaz-ahmad-fauzi-my')
        ->and($person->primaryAddress()?->country_id)->toBe((string) $country->getKey());
});

it('includes displayed person titles in the generated slug', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'honorific' => ['dato'],
        'pre_nominal' => ['ustaz'],
        'post_nominal' => ['PhD'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->formatted_name)->toBe("Dato' Ustaz Ahmad Fauzi, PhD")
        ->and($person->slug)->toBe('dato-ustaz-ahmad-fauzi-phd-my');
});

it('supports dato setia as an honorific in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'honorific' => ['dato_setia'],
        'pre_nominal' => ['dr'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->formatted_name)->toBe("Dato' Setia Dr Ahmad Fauzi")
        ->and($person->slug)->toBe('dato-setia-dr-ahmad-fauzi-my');
});

it('orders full-professor display titles before honorifics and lower prefixes', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Azhar Sulaiman',
        'gender' => 'male',
        'honorific' => ['dato'],
        'pre_nominal' => ['dr', 'prof'],
        'post_nominal' => ['HONS', 'BA', 'PhD'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->formatted_name)->toBe("Prof Dato' Dr Azhar Sulaiman, PhD, BA, HONS")
        ->and($person->slug)->toBe('prof-dato-dr-azhar-sulaiman-phd-ba-hons-my');
});

it('orders associate-professor display titles before honorifics and doctorate prefixes', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Azhar Sulaiman',
        'gender' => 'male',
        'honorific' => ['dato'],
        'pre_nominal' => ['dr', 'prof_madya'],
        'post_nominal' => ['HONS', 'MA'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->formatted_name)->toBe("Prof Madya Dato' Dr Azhar Sulaiman, MA, HONS")
        ->and($person->slug)->toBe('prof-madya-dato-dr-azhar-sulaiman-ma-hons-my');
});

it('keeps religious prefixes ahead of doctorate titles in public display order', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'honorific' => ['dato'],
        'pre_nominal' => ['dr', 'ustaz'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->formatted_name)->toBe("Dato' Ustaz Dr Ahmad Fauzi")
        ->and($person->slug)->toBe('dato-ustaz-dr-ahmad-fauzi-my');
});

it('supports habib as a pre-nominal in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ali Zainal Abidin',
        'gender' => 'male',
        'pre_nominal' => ['dr', 'habib'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->formatted_name)->toBe('Habib Dr Ali Zainal Abidin')
        ->and($person->slug)->toBe('habib-dr-ali-zainal-abidin-my');
});

it('supports maulana as a pre-nominal in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'pre_nominal' => ['dr', 'maulana'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->formatted_name)->toBe('Maulana Dr Ahmad Fauzi')
        ->and($person->slug)->toBe('maulana-dr-ahmad-fauzi-my');
});

it('supports syeikhul maqari ahead of ustaz in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Othman Hamzah',
        'gender' => 'male',
        'pre_nominal' => ['ustaz', 'syeikhul_maqari'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->formatted_name)->toBe('Syeikhul Maqari Ustaz Othman Hamzah')
        ->and($person->slug)->toBe('syeikhul-maqari-ustaz-othman-hamzah-my');
});

it('supports hj as a pre-nominal in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'pre_nominal' => ['hj'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->formatted_name)->toBe('Hj Ahmad Fauzi')
        ->and($person->slug)->toBe('hj-ahmad-fauzi-my');
});

it('supports hjh as a pre-nominal in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Mimi Haryani',
        'gender' => 'female',
        'pre_nominal' => ['hjh'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->formatted_name)->toBe('Hjh Mimi Haryani')
        ->and($person->slug)->toBe('hjh-mimi-haryani-my');
});

it('keeps professional prefixes ahead of doctorate titles in public display order', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Mimi Haryani',
        'gender' => 'female',
        'pre_nominal' => ['dr', 'ir'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->formatted_name)->toBe('Ir Dr Mimi Haryani')
        ->and($person->slug)->toBe('ir-dr-mimi-haryani-my');
});

it('adds duplicate numbering only when the same person name reuses the same country suffix', function () {
    $proposer = User::factory()->create();
    $malaysia = createPersonSlugCountry();
    $singapore = createPersonSlugCountry(
        countryName: 'Singapore',
        countryIso2: 'SG',
        countryIso3: 'SGP',
        countryId: 702,
        phoneCode: '65',
    );

    $first = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $malaysia->getKey(),
    ], $proposer);

    $second = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $malaysia->getKey(),
    ], $proposer);

    $third = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $singapore->getKey(),
    ], $proposer);

    expect($first->slug)->toBe('ustaz-ahmad-fauzi-my')
        ->and($first->primaryAddress()?->country_id)->toBe((string) $malaysia->getKey())
        ->and($second->slug)->toBe('ustaz-ahmad-fauzi-2-my')
        ->and($third->slug)->toBe('ustaz-ahmad-fauzi-sg');
});

it('keeps person slugs unique when a literal numbered name already occupies the expected duplicate slot', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $first = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ustaz Example',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ], $proposer);

    $numberedName = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ustaz Example 2',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ], $proposer);

    $duplicate = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ustaz Example',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ], $proposer);

    expect($first->slug)->toBe('ustaz-example-my')
        ->and($numberedName->slug)->toBe('ustaz-example-2-my')
        ->and($duplicate->slug)->toBe('ustaz-example-3-my');
});

it('uses the generated country slug when admins create persons in filament', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $country = createPersonSlugCountry();

    Livewire::actingAs($administrator)
        ->test(CreatePerson::class)
        ->assertFormFieldDoesNotExist('slug')
        ->fillForm([
            'name' => 'Ustaz Ahmad Fauzi',
            'gender' => 'male',
            'honorific' => [],
            'pre_nominal' => [],
            'post_nominal' => [],
            'qualifications' => [],
            'languages' => [],
            'contactMethods' => [],
            'socialProfiles' => [],
            'status' => 'verified',
            'address' => [
                'country_id' => (string) $country->getKey(),
            ],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $person = Person::query()
        ->where('name', 'Ustaz Ahmad Fauzi')
        ->firstOrFail();

    expect($person->slug)->toBe('ustaz-ahmad-fauzi-my');
});

it('does not expose a writable slug field when admins edit persons in filament', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $person = Person::factory()->create([
        'name' => 'Editable Person',
        'slug' => 'editable-person-my',
    ]);

    Livewire::actingAs($administrator)
        ->test(EditPerson::class, ['record' => $person->getKey()])
        ->assertFormFieldDoesNotExist('slug');
});

it('uses the submitted address country when approving unstaged person create requests', function () {
    $country = createPersonSlugCountry();
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Person,
        'proposer_id' => $proposer->id,
        'status' => ContributionRequestStatus::Pending,
        'proposed_data' => [
            'name' => 'Ustaz Ahmad Approval',
            'gender' => 'male',
            'address' => [
                'country_id' => (string) $country->getKey(),
            ],
        ],
        'original_data' => null,
    ]);

    $approvedRequest = app(ApproveContributionRequestAction::class)->handle($request, $reviewer, 'Approved.');
    $person = Person::query()
        ->with('addresses')
        ->findOrFail($approvedRequest->entity_id);

    expect($person->slug)->toBe('ustaz-ahmad-approval-my')
        ->and($person->primaryAddress()?->country_id)->toBe((string) $country->getKey());
});

it('recomputes person slugs when the person country changes', function () {
    $proposer = User::factory()->create();
    $malaysia = createPersonSlugCountry();
    $singapore = createPersonSlugCountry(
        countryName: 'Singapore',
        countryIso2: 'SG',
        countryIso3: 'SGP',
        countryId: 702,
        phoneCode: '65',
    );

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $malaysia->getKey(),
    ], $proposer);

    $person->primaryAddress()?->update([
        'country_id' => (string) $singapore->getKey(),
    ]);

    expect($person->fresh()?->slug)->toBe('ustaz-ahmad-fauzi-sg');
});

it('recomputes person slugs when displayed name parts change', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($person->slug)->toBe('ahmad-fauzi-my');

    $person->update([
        'honorific' => ['dato'],
        'pre_nominal' => ['dr'],
        'post_nominal' => ['PhD'],
    ]);

    expect($person->fresh()?->formatted_name)->toBe("Dato' Dr Ahmad Fauzi, PhD")
        ->and($person->fresh()?->slug)->toBe('dato-dr-ahmad-fauzi-phd-my');
});

it('normalizes displayed-name ordering when title arrays are updated in arbitrary order', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $person = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Azhar Sulaiman',
        'gender' => 'male',
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    $person->update([
        'honorific' => ['dato'],
        'pre_nominal' => ['dr', 'prof'],
        'post_nominal' => ['BA', 'PhD', 'HONS'],
    ]);

    expect($person->fresh()?->formatted_name)->toBe("Prof Dato' Dr Azhar Sulaiman, PhD, BA, HONS")
        ->and($person->fresh()?->slug)->toBe('prof-dato-dr-azhar-sulaiman-phd-ba-hons-my');
});

it('renumbers remaining person duplicates when a peer is renamed out of the group', function () {
    $proposer = User::factory()->create();
    $country = createPersonSlugCountry();

    $first = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ], $proposer);

    $second = app(ContributionEntityMutationService::class)->createPerson([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ], $proposer);

    expect($second->slug)->toBe('ustaz-ahmad-fauzi-2-my');

    $first->update([
        'name' => 'Ustaz Ahmad Fauzi Perdana',
    ]);

    expect($first->fresh()?->slug)->toBe('ustaz-ahmad-fauzi-perdana-my')
        ->and($second->fresh()?->slug)->toBe('ustaz-ahmad-fauzi-my');
});

it('backfills existing person slugs through the queued job logic', function () {
    $country = createPersonSlugCountry();
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $first = createPersonForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000011',
        name: 'Ustaz Ahmad Fauzi',
        slug: 'legacy-random-1',
        country: $country,
    );

    $second = createPersonForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000012',
        name: 'Ustaz Ahmad Fauzi',
        slug: 'legacy-random-2',
        country: $country,
    );

    Person::withoutTimestamps(function () use ($first, $second): void {
        $first->forceFill(['slug' => 'legacy-random-1'])->saveQuietly();
        $second->forceFill(['slug' => 'legacy-random-2'])->saveQuietly();
    });

    $event = Event::factory()->create([
        'title' => 'Forum Backfill Penceramah',
        'slug' => 'legacy-event-slug',
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_category_ids' => [eventCategoryId('other')],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'delivery_mode' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
    ]);

    app(EventKeyPersonSyncService::class)->sync($event, [$first->id]);

    expect($event->fresh()?->slug)->toBe("forum-backfill-penceramah-legacy-random-1-{$expectedSuffix}");

    app(BackfillPersonSlugs::class)->handle(
        app(GenerateEventSlugAction::class),
        app(GeneratePersonSlugAction::class),
        app(PublicListingsCache::class),
    );

    expect($first->fresh()?->slug)->toBe('ustaz-ahmad-fauzi-my')
        ->and($second->fresh()?->slug)->toBe('ustaz-ahmad-fauzi-2-my')
        ->and($event->fresh()?->slug)->toBe("forum-backfill-penceramah-ustaz-ahmad-fauzi-my-{$expectedSuffix}");
});

it('updates related event slugs when a person address change changes the person slug', function () {
    $malaysia = createPersonSlugCountry();
    $singapore = createPersonSlugCountry(
        countryName: 'Singapore',
        countryIso2: 'SG',
        countryIso3: 'SGP',
        countryId: 702,
        phoneCode: '65',
    );
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $person = createPersonForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000013',
        name: 'Ustaz Ahmad Fauzi',
        slug: 'ustaz-ahmad-fauzi-my',
        country: $malaysia,
    );

    $event = Event::factory()->create([
        'title' => 'Forum Alamat Penceramah',
        'slug' => 'legacy-event-slug-address',
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_category_ids' => [eventCategoryId('other')],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'delivery_mode' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
    ]);

    app(EventKeyPersonSyncService::class)->sync($event, [$person->id]);

    expect($event->fresh()?->slug)->toBe("forum-alamat-penceramah-ustaz-ahmad-fauzi-my-{$expectedSuffix}");

    $person->addresses()->firstOrFail()->update([
        'country_id' => (string) $singapore->getKey(),
    ]);

    expect($person->fresh()?->slug)->toBe('ustaz-ahmad-fauzi-sg')
        ->and($event->fresh()?->slug)->toBe("forum-alamat-penceramah-ustaz-ahmad-fauzi-sg-{$expectedSuffix}");

    $person->addresses()->firstOrFail()->delete();

    expect($person->fresh()?->slug)->toBe('ustaz-ahmad-fauzi')
        ->and($event->fresh()?->slug)->toBe("forum-alamat-penceramah-ustaz-ahmad-fauzi-{$expectedSuffix}");
});

it('queues the person slug backfill command', function () {
    Queue::fake();

    $this->artisan('persons:queue-slug-backfill')
        ->expectsOutput('Queued person slug backfill job.')
        ->assertSuccessful();

    Queue::assertPushed(BackfillPersonSlugs::class);
});

it('uses a country suffix only when person country data is present', function () {
    $country = createPersonSlugCountry();
    $generator = app(GeneratePersonSlugAction::class);

    expect($generator->handle('Ustaz Tanpa Negara'))->toBe('ustaz-tanpa-negara')
        ->and($generator->handle('Ustaz Malaysia', [
            'country_id' => (string) $country->getKey(),
        ]))->toBe('ustaz-malaysia-my')
        ->and($generator->handle('Ustaz Singapura', [
            'country_code' => 'SG',
        ]))->toBe('ustaz-singapura-sg');
});

function createPersonSlugCountry(
    string $countryName = 'Malaysia',
    string $countryIso2 = 'MY',
    string $countryIso3 = 'MYS',
    int $countryId = 132,
    string $phoneCode = '60',
): AddressCountry {
    $country = AddressCountry::query()->where('iso2', $countryIso2)->first();

    if ($country instanceof AddressCountry) {
        return $country;
    }

    return AddressCountry::query()->create([
        'entity_type' => 'country',
        'name' => $countryName,
        'iso2' => $countryIso2,
        'iso3' => $countryIso3,
        'numeric_code' => (string) $countryId,
        'phone_code' => $phoneCode,
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
        'timezones' => $countryIso2 === 'MY' ? ['Asia/Kuala_Lumpur'] : ['Asia/Singapore'],
    ]);
}

function createPersonForSlugBackfill(string $id, string $name, string $slug, AddressCountry $country): Person
{
    $person = Person::unguarded(fn () => Person::query()->create([
        'id' => $id,
        'name' => $name,
        'gender' => 'male',
        'slug' => $slug,
        'status' => 'verified',
    ]));

    $address = Address::query()->create([
        'country_id' => (string) $country->getKey(),
        'country_code' => $country->iso2,
    ]);
    $person->attachAddress($address, type: 'primary', isPrimary: true);

    return $person->fresh(['addresses']) ?? $person;
}
