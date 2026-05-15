<?php

use App\Enums\EventKeyPersonRole;
use App\Enums\EventStructure;
use App\Models\District;
use App\Models\Event;
use App\Models\Speaker;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\Venue;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\Draft;
use App\States\EventStatus\Pending;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\Language;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('active scope filters public visible statuses (approved, pending, cancelled)', function () {
    // Active public-visible events
    $approvedEvent = Event::factory()->create([
        'status' => Approved::class,
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $pendingEvent = Event::factory()->create([
        'status' => Pending::class,
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $cancelledEvent = Event::factory()->create([
        'status' => Cancelled::class,
        'visibility' => 'public',
        'is_active' => true,
    ]);

    // Inactive events
    $draftEvent = Event::factory()->create([
        'status' => Draft::class,
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $privateEvent = Event::factory()->create([
        'status' => Approved::class,
        'visibility' => 'private',
        'is_active' => true,
    ]);

    $deactivatedEvent = Event::factory()->create([
        'status' => Approved::class,
        'visibility' => 'public',
        'is_active' => false,
    ]);

    $results = Event::active()->get();

    expect($results)->toHaveCount(3)
        ->and($results->pluck('id')->toArray())->toContain($approvedEvent->id)
        ->and($results->pluck('id')->toArray())->toContain($pendingEvent->id)
        ->and($results->pluck('id')->toArray())->toContain($cancelledEvent->id)
        ->and($results->pluck('id')->toArray())->not->toContain($draftEvent->id)
        ->and($results->pluck('id')->toArray())->not->toContain($privateEvent->id)
        ->and($results->pluck('id')->toArray())->not->toContain($deactivatedEvent->id);
});

it('searchable payload includes is_active and subdistrict_id location fields', function () {
    $state = State::where('country_code', 'MY')->first();

    if (! $state) {
        $countryId = DB::table('countries')->insertGetId([
            'iso2' => 'MY',
            'name' => 'Malaysia',
            'status' => 1,
            'phone_code' => '60',
            'iso3' => 'MYS',
            'region' => 'Asia',
            'subregion' => 'South-Eastern Asia',
        ]);

        $stateId = DB::table('states')->insertGetId([
            'country_id' => $countryId,
            'name' => 'Selangor',
            'country_code' => 'MY',
        ]);

        $state = State::query()->findOrFail($stateId);
    }

    $district = District::query()->create([
        'country_id' => $state->country_id,
        'state_id' => $state->id,
        'country_code' => 'MY',
        'name' => 'Search Payload District '.uniqid(),
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => $state->country_id,
        'state_id' => $state->id,
        'district_id' => $district->id,
        'country_code' => 'MY',
        'name' => 'Search Payload Subdistrict '.uniqid(),
    ]);

    $venue = Venue::factory()->create();
    $venue->address()->update([
        'state_id' => $state->id,
        'district_id' => $district->id,
        'subdistrict_id' => $subdistrict->id,
    ]);

    $event = Event::factory()->for($venue)->create([
        'status' => 'approved',
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $payload = $event->toSearchableArray();

    expect($payload)
        ->toHaveKey('is_active', true)
        ->and($payload)->toHaveKey('state_id', $state->id)
        ->and($payload)->toHaveKey('district_id', $district->id)
        ->and($payload)->toHaveKey('subdistrict_id', $subdistrict->id);
});

it('searchable payload uses canonical language_codes', function () {
    $malay = Language::query()->firstOrCreate(
        ['code' => 'ms'],
        ['name' => 'Malay', 'name_native' => 'Bahasa Melayu', 'dir' => 'ltr'],
    );

    $english = Language::query()->firstOrCreate(
        ['code' => 'en'],
        ['name' => 'English', 'name_native' => 'English', 'dir' => 'ltr'],
    );

    $event = Event::factory()->create([
        'status' => Approved::class,
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $event->languages()->attach([$malay->getKey(), $english->getKey()]);

    $payload = $event->fresh()->toSearchableArray();

    expect($payload['language_codes'])->toBe(['ms', 'en'])
        ->and($payload)->not->toHaveKey('language');
});

it('deduplicates key person roles in the searchable payload', function () {
    $event = Event::factory()->create([
        'status' => Approved::class,
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $moderator = Speaker::factory()->create();
    $imam = Speaker::factory()->create();
    $personInCharge = Speaker::factory()->create([
        'name' => 'Ustaz Searchable PIC',
        'searchable_name' => 'ustaz searchable pic',
    ]);

    $event->keyPeople()->create([
        'speaker_id' => $moderator->getKey(),
        'role' => EventKeyPersonRole::Moderator,
        'name' => $moderator->name,
    ]);

    $event->keyPeople()->create([
        'speaker_id' => $moderator->getKey(),
        'role' => EventKeyPersonRole::Moderator,
        'name' => $moderator->name,
    ]);

    $event->keyPeople()->create([
        'speaker_id' => $imam->getKey(),
        'role' => EventKeyPersonRole::Imam,
        'name' => $imam->name,
    ]);

    $event->keyPeople()->create([
        'speaker_id' => $personInCharge->getKey(),
        'role' => EventKeyPersonRole::PersonInCharge,
        'name' => $personInCharge->name,
    ]);

    $event->keyPeople()->create([
        'role' => EventKeyPersonRole::PersonInCharge,
        'name' => 'Encik Free Text PIC',
    ]);

    $payload = $event->fresh()->toSearchableArray();

    expect($payload['key_person_roles'])->toBe([
        EventKeyPersonRole::Moderator->value,
        EventKeyPersonRole::Imam->value,
        EventKeyPersonRole::PersonInCharge->value,
    ])->and($payload['key_person_speaker_ids'])->toBe([
        (string) $moderator->getKey(),
        (string) $imam->getKey(),
        (string) $personInCharge->getKey(),
    ])->and($payload['person_in_charge_ids'])->toBe([
        (string) $personInCharge->getKey(),
    ])->and($payload['person_in_charge_names'])
        ->toContain('ustaz searchable pic')
        ->toContain('Encik Free Text PIC');
});

it('supports parent program and child event hierarchy helpers', function () {
    $parentEvent = Event::factory()->parentProgram()->create();
    $childEvent = Event::factory()->childEvent($parentEvent)->create();
    $standaloneEvent = Event::factory()->create();

    expect($parentEvent->fresh()->isParentProgram())->toBeTrue()
        ->and($parentEvent->isSchedulable())->toBeFalse()
        ->and($parentEvent->childEvents->pluck('id')->all())->toContain($childEvent->id)
        ->and($childEvent->fresh()->isChildEvent())->toBeTrue()
        ->and($childEvent->isSchedulable())->toBeTrue()
        ->and($childEvent->parentEvent?->is($parentEvent))->toBeTrue()
        ->and($standaloneEvent->fresh()->isStandaloneEvent())->toBeTrue()
        ->and($standaloneEvent->eventStructure())->toBe(EventStructure::Standalone);
});

it('discoverable scope and searchability exclude parent programs', function () {
    $parentEvent = Event::factory()->parentProgram()->create([
        'status' => Approved::class,
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $childEvent = Event::factory()->childEvent($parentEvent)->create([
        'status' => Approved::class,
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $standaloneEvent = Event::factory()->create([
        'status' => Approved::class,
        'visibility' => 'public',
        'is_active' => true,
    ]);

    $discoverableIds = Event::discoverable()->pluck('id')->all();
    $activeIds = Event::active()->pluck('id')->all();

    expect($discoverableIds)->not->toContain($parentEvent->id)
        ->and($discoverableIds)->toContain($childEvent->id)
        ->and($discoverableIds)->toContain($standaloneEvent->id)
        ->and($activeIds)->not->toContain($parentEvent->id)
        ->and($activeIds)->toContain($childEvent->id)
        ->and($activeIds)->toContain($standaloneEvent->id)
        ->and($parentEvent->fresh()->shouldBeSearchable())->toBeFalse()
        ->and($childEvent->fresh()->shouldBeSearchable())->toBeTrue();
});
