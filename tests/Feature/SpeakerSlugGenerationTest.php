<?php

use App\Actions\Contributions\ApproveContributionRequestAction;
use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Speakers\GenerateSpeakerSlugAction;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Filament\Resources\Speakers\Pages\CreateSpeaker;
use App\Filament\Resources\Speakers\Pages\EditSpeaker;
use App\Forms\SpeakerFormSchema;
use App\Jobs\BackfillSpeakerSlugs;
use App\Models\ContributionRequest;
use App\Models\Country;
use App\Models\Event;
use App\Models\Speaker;
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

it('generates country-based slugs for speaker quick-create flows', function () {
    $country = createSpeakerSlugCountry();

    $speakerId = SpeakerFormSchema::createOptionUsing([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ]);

    $speaker = Speaker::query()
        ->with('address')
        ->findOrFail($speakerId);

    expect($speaker->slug)->toBe('ustaz-ahmad-fauzi-my')
        ->and($speaker->addressModel?->country_id)->toBe((int) $country->getKey());
});

it('includes displayed speaker titles in the generated slug', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'honorific' => ['dato'],
        'pre_nominal' => ['ustaz'],
        'post_nominal' => ['PhD'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->formatted_name)->toBe("Dato' Ustaz Ahmad Fauzi, PhD")
        ->and($speaker->slug)->toBe('dato-ustaz-ahmad-fauzi-phd-my');
});

it('supports dato setia as an honorific in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'honorific' => ['dato_setia'],
        'pre_nominal' => ['dr'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->formatted_name)->toBe("Dato' Setia Dr Ahmad Fauzi")
        ->and($speaker->slug)->toBe('dato-setia-dr-ahmad-fauzi-my');
});

it('orders full-professor display titles before honorifics and lower prefixes', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Azhar Sulaiman',
        'gender' => 'male',
        'honorific' => ['dato'],
        'pre_nominal' => ['dr', 'prof'],
        'post_nominal' => ['HONS', 'BA', 'PhD'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->formatted_name)->toBe("Prof Dato' Dr Azhar Sulaiman, PhD, BA, HONS")
        ->and($speaker->slug)->toBe('prof-dato-dr-azhar-sulaiman-phd-ba-hons-my');
});

it('orders associate-professor display titles before honorifics and doctorate prefixes', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Azhar Sulaiman',
        'gender' => 'male',
        'honorific' => ['dato'],
        'pre_nominal' => ['dr', 'prof_madya'],
        'post_nominal' => ['HONS', 'MA'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->formatted_name)->toBe("Prof Madya Dato' Dr Azhar Sulaiman, MA, HONS")
        ->and($speaker->slug)->toBe('prof-madya-dato-dr-azhar-sulaiman-ma-hons-my');
});

it('keeps religious prefixes ahead of doctorate titles in public display order', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'honorific' => ['dato'],
        'pre_nominal' => ['dr', 'ustaz'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->formatted_name)->toBe("Dato' Ustaz Dr Ahmad Fauzi")
        ->and($speaker->slug)->toBe('dato-ustaz-dr-ahmad-fauzi-my');
});

it('supports habib as a pre-nominal in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ali Zainal Abidin',
        'gender' => 'male',
        'pre_nominal' => ['dr', 'habib'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->formatted_name)->toBe('Habib Dr Ali Zainal Abidin')
        ->and($speaker->slug)->toBe('habib-dr-ali-zainal-abidin-my');
});

it('supports maulana as a pre-nominal in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'pre_nominal' => ['dr', 'maulana'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->formatted_name)->toBe('Maulana Dr Ahmad Fauzi')
        ->and($speaker->slug)->toBe('maulana-dr-ahmad-fauzi-my');
});

it('supports syeikhul maqari ahead of ustaz in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Othman Hamzah',
        'gender' => 'male',
        'pre_nominal' => ['ustaz', 'syeikhul_maqari'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->formatted_name)->toBe('Syeikhul Maqari Ustaz Othman Hamzah')
        ->and($speaker->slug)->toBe('syeikhul-maqari-ustaz-othman-hamzah-my');
});

it('supports hj as a pre-nominal in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'pre_nominal' => ['hj'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->formatted_name)->toBe('Hj Ahmad Fauzi')
        ->and($speaker->slug)->toBe('hj-ahmad-fauzi-my');
});

it('supports hjh as a pre-nominal in formatted names and slugs', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Mimi Haryani',
        'gender' => 'female',
        'pre_nominal' => ['hjh'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->formatted_name)->toBe('Hjh Mimi Haryani')
        ->and($speaker->slug)->toBe('hjh-mimi-haryani-my');
});

it('keeps professional prefixes ahead of doctorate titles in public display order', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Mimi Haryani',
        'gender' => 'female',
        'pre_nominal' => ['dr', 'ir'],
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->formatted_name)->toBe('Ir Dr Mimi Haryani')
        ->and($speaker->slug)->toBe('ir-dr-mimi-haryani-my');
});

it('adds duplicate numbering only when the same speaker name reuses the same country suffix', function () {
    $proposer = User::factory()->create();
    $malaysia = createSpeakerSlugCountry();
    $singapore = createSpeakerSlugCountry(
        countryName: 'Singapore',
        countryIso2: 'SG',
        countryIso3: 'SGP',
        countryId: 702,
        phoneCode: '65',
    );

    $first = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $malaysia->getKey(),
    ], $proposer);

    $second = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $malaysia->getKey(),
    ], $proposer);

    $third = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $singapore->getKey(),
    ], $proposer);

    expect($first->slug)->toBe('ustaz-ahmad-fauzi-my')
        ->and($first->addressModel?->country_id)->toBe((int) $malaysia->getKey())
        ->and($second->slug)->toBe('ustaz-ahmad-fauzi-2-my')
        ->and($third->slug)->toBe('ustaz-ahmad-fauzi-sg');
});

it('keeps speaker slugs unique when a literal numbered name already occupies the expected duplicate slot', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $first = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ustaz Example',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ], $proposer);

    $numberedName = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ustaz Example 2',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ], $proposer);

    $duplicate = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ustaz Example',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ], $proposer);

    expect($first->slug)->toBe('ustaz-example-my')
        ->and($numberedName->slug)->toBe('ustaz-example-2-my')
        ->and($duplicate->slug)->toBe('ustaz-example-3-my');
});

it('uses the generated country slug when admins create speakers in filament', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $country = createSpeakerSlugCountry();

    Livewire::actingAs($administrator)
        ->test(CreateSpeaker::class)
        ->assertFormFieldDoesNotExist('slug')
        ->fillForm([
            'name' => 'Ustaz Ahmad Fauzi',
            'gender' => 'male',
            'honorific' => [],
            'pre_nominal' => [],
            'post_nominal' => [],
            'qualifications' => [],
            'languages' => [],
            'contacts' => [],
            'socialMedia' => [],
            'status' => 'verified',
            'is_active' => true,
            'address' => [
                'country_id' => (string) $country->getKey(),
            ],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $speaker = Speaker::query()
        ->where('name', 'Ustaz Ahmad Fauzi')
        ->firstOrFail();

    expect($speaker->slug)->toBe('ustaz-ahmad-fauzi-my');
});

it('does not expose a writable slug field when admins edit speakers in filament', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $speaker = Speaker::factory()->create([
        'name' => 'Editable Speaker',
        'slug' => 'editable-speaker-my',
    ]);

    Livewire::actingAs($administrator)
        ->test(EditSpeaker::class, ['record' => $speaker->getKey()])
        ->assertFormFieldDoesNotExist('slug');
});

it('uses the submitted address country when approving unstaged speaker create requests', function () {
    $country = createSpeakerSlugCountry();
    $proposer = User::factory()->create();
    $reviewer = User::factory()->create();

    $request = ContributionRequest::factory()->create([
        'type' => ContributionRequestType::Create,
        'subject_type' => ContributionSubjectType::Speaker,
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
    $speaker = Speaker::query()
        ->with('address')
        ->findOrFail($approvedRequest->entity_id);

    expect($speaker->slug)->toBe('ustaz-ahmad-approval-my')
        ->and($speaker->addressModel?->country_id)->toBe((int) $country->getKey());
});

it('recomputes speaker slugs when the speaker country changes', function () {
    $proposer = User::factory()->create();
    $malaysia = createSpeakerSlugCountry();
    $singapore = createSpeakerSlugCountry(
        countryName: 'Singapore',
        countryIso2: 'SG',
        countryIso3: 'SGP',
        countryId: 702,
        phoneCode: '65',
    );

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $malaysia->getKey(),
    ], $proposer);

    $speaker->addressModel?->update([
        'country_id' => (int) $singapore->getKey(),
    ]);

    expect($speaker->fresh()?->slug)->toBe('ustaz-ahmad-fauzi-sg');
});

it('recomputes speaker slugs when displayed name parts change', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ahmad Fauzi',
        'gender' => 'male',
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    expect($speaker->slug)->toBe('ahmad-fauzi-my');

    $speaker->update([
        'honorific' => ['dato'],
        'pre_nominal' => ['dr'],
        'post_nominal' => ['PhD'],
    ]);

    expect($speaker->fresh()?->formatted_name)->toBe("Dato' Dr Ahmad Fauzi, PhD")
        ->and($speaker->fresh()?->slug)->toBe('dato-dr-ahmad-fauzi-phd-my');
});

it('normalizes displayed-name ordering when title arrays are updated in arbitrary order', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $speaker = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Azhar Sulaiman',
        'gender' => 'male',
        'address' => [
            'country_id' => (string) $country->getKey(),
        ],
    ], $proposer);

    $speaker->update([
        'honorific' => ['dato'],
        'pre_nominal' => ['dr', 'prof'],
        'post_nominal' => ['BA', 'PhD', 'HONS'],
    ]);

    expect($speaker->fresh()?->formatted_name)->toBe("Prof Dato' Dr Azhar Sulaiman, PhD, BA, HONS")
        ->and($speaker->fresh()?->slug)->toBe('prof-dato-dr-azhar-sulaiman-phd-ba-hons-my');
});

it('renumbers remaining speaker duplicates when a peer is renamed out of the group', function () {
    $proposer = User::factory()->create();
    $country = createSpeakerSlugCountry();

    $first = app(ContributionEntityMutationService::class)->createSpeaker([
        'name' => 'Ustaz Ahmad Fauzi',
        'gender' => 'male',
        'country_id' => (string) $country->getKey(),
    ], $proposer);

    $second = app(ContributionEntityMutationService::class)->createSpeaker([
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

it('backfills existing speaker slugs through the queued job logic', function () {
    $country = createSpeakerSlugCountry();
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $first = createSpeakerForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000011',
        name: 'Ustaz Ahmad Fauzi',
        slug: 'legacy-random-1',
        country: $country,
    );

    $second = createSpeakerForSlugBackfill(
        id: '00000000-0000-0000-0000-000000000012',
        name: 'Ustaz Ahmad Fauzi',
        slug: 'legacy-random-2',
        country: $country,
    );

    Speaker::withoutTimestamps(function () use ($first, $second): void {
        $first->forceFill(['slug' => 'legacy-random-1'])->saveQuietly();
        $second->forceFill(['slug' => 'legacy-random-2'])->saveQuietly();
    });

    $event = Event::factory()->create([
        'title' => 'Forum Backfill Penceramah',
        'slug' => 'legacy-event-slug',
        'starts_at' => $startsAt,
        'timezone' => 'Asia/Kuala_Lumpur',
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    app(EventKeyPersonSyncService::class)->sync($event, [$first->id]);

    expect($event->fresh()?->slug)->toBe("forum-backfill-penceramah-legacy-random-1-{$expectedSuffix}");

    app(BackfillSpeakerSlugs::class)->handle(
        app(GenerateEventSlugAction::class),
        app(GenerateSpeakerSlugAction::class),
        app(PublicListingsCache::class),
    );

    expect($first->fresh()?->slug)->toBe('ustaz-ahmad-fauzi-my')
        ->and($second->fresh()?->slug)->toBe('ustaz-ahmad-fauzi-2-my')
        ->and($event->fresh()?->slug)->toBe("forum-backfill-penceramah-ustaz-ahmad-fauzi-my-{$expectedSuffix}");
});

it('updates related event slugs when a speaker address change changes the speaker slug', function () {
    $malaysia = createSpeakerSlugCountry();
    $singapore = createSpeakerSlugCountry(
        countryName: 'Singapore',
        countryIso2: 'SG',
        countryIso3: 'SGP',
        countryId: 702,
        phoneCode: '65',
    );
    $startsAt = Carbon::parse('2026-04-12 20:00:00', 'Asia/Kuala_Lumpur')->utc();
    $expectedSuffix = Carbon::parse('2026-04-12', 'Asia/Kuala_Lumpur')->format('j-n-y');

    $speaker = createSpeakerForSlugBackfill(
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
        'event_type' => [EventType::Other->value],
        'gender' => EventGenderRestriction::All->value,
        'age_group' => [EventAgeGroup::AllAges->value],
        'children_allowed' => true,
        'event_format' => EventFormat::Physical->value,
        'visibility' => EventVisibility::Public->value,
        'status' => 'approved',
        'is_active' => true,
    ]);

    app(EventKeyPersonSyncService::class)->sync($event, [$speaker->id]);

    expect($event->fresh()?->slug)->toBe("forum-alamat-penceramah-ustaz-ahmad-fauzi-my-{$expectedSuffix}");

    $speaker->address()->firstOrFail()->update([
        'country_id' => (int) $singapore->getKey(),
    ]);

    expect($speaker->fresh()?->slug)->toBe('ustaz-ahmad-fauzi-sg')
        ->and($event->fresh()?->slug)->toBe("forum-alamat-penceramah-ustaz-ahmad-fauzi-sg-{$expectedSuffix}");

    $speaker->address()->firstOrFail()->delete();

    expect($speaker->fresh()?->slug)->toBe('ustaz-ahmad-fauzi-my')
        ->and($event->fresh()?->slug)->toBe("forum-alamat-penceramah-ustaz-ahmad-fauzi-my-{$expectedSuffix}");
});

it('queues the speaker slug backfill command', function () {
    Queue::fake();

    $this->artisan('speakers:queue-slug-backfill')
        ->expectsOutput('Queued speaker slug backfill job.')
        ->assertSuccessful();

    Queue::assertPushed(BackfillSpeakerSlugs::class);
});

it('falls back to the preferred country suffix when speaker country data is missing', function () {
    $country = createSpeakerSlugCountry();
    $generator = app(GenerateSpeakerSlugAction::class);

    expect($generator->handle('Ustaz Tanpa Negara'))->toBe('ustaz-tanpa-negara-my')
        ->and($generator->handle('Ustaz Malaysia', [
            'country_id' => (string) $country->getKey(),
        ]))->toBe('ustaz-malaysia-my')
        ->and($generator->handle('Ustaz Singapura', [
            'country_code' => 'SG',
        ]))->toBe('ustaz-singapura-sg');
});

function createSpeakerSlugCountry(
    string $countryName = 'Malaysia',
    string $countryIso2 = 'MY',
    string $countryIso3 = 'MYS',
    int $countryId = 132,
    string $phoneCode = '60',
): Country {
    $country = Country::query()->find($countryId);

    if ($country instanceof Country) {
        return $country;
    }

    $country = new Country;
    $country->forceFill([
        'id' => $countryId,
        'name' => $countryName,
        'iso2' => $countryIso2,
        'iso3' => $countryIso3,
        'phone_code' => $phoneCode,
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
        'status' => 1,
    ]);
    $country->save();

    return $country;
}

function createSpeakerForSlugBackfill(string $id, string $name, string $slug, Country $country): Speaker
{
    $speaker = Speaker::unguarded(fn () => Speaker::query()->create([
        'id' => $id,
        'name' => $name,
        'gender' => 'male',
        'slug' => $slug,
        'status' => 'verified',
        'is_active' => true,
    ]));

    $speaker->address()->create([
        'type' => 'main',
        'country_id' => (int) $country->getKey(),
    ]);

    return $speaker->fresh(['address']) ?? $speaker;
}
