<?php

use App\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Venue;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\Draft;
use App\States\EventStatus\Pending;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nnjeim\World\Models\Language;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('active scope filters public visible statuses (approved, pending, cancelled)', function () {
    withGlobalOwnerContext(function (): void {
        $approvedEvent = Event::factory()->create([
            'status' => Approved::class,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $pendingEvent = Event::factory()->create([
            'status' => Pending::class,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $cancelledEvent = Event::factory()->create([
            'status' => Cancelled::class,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $draftEvent = Event::factory()->create([
            'status' => Draft::class,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $privateEvent = Event::factory()->create([
            'status' => Approved::class,
            'visibility' => 'private',
            'published_at' => now(),
        ]);

        $deactivatedEvent = Event::factory()->create([
            'status' => Approved::class,
            'visibility' => 'public',
            'published_at' => null,
        ]);

        Event::query()->whereKey($deactivatedEvent->id)->update(['published_at' => null]);

        $results = Event::active()->get();

        expect($results->pluck('id')->toArray())->toContain($approvedEvent->id)
            ->and($results->pluck('id')->toArray())->toContain($pendingEvent->id)
            ->and($results->pluck('id')->toArray())->toContain($cancelledEvent->id)
            ->and($results->pluck('id')->toArray())->not->toContain($draftEvent->id)
            ->and($results->pluck('id')->toArray())->not->toContain($privateEvent->id)
            ->and($results->pluck('id')->toArray())->not->toContain($deactivatedEvent->id);
    });
});

it('searchable payload includes status and product-native address geography fields', function () {
    withGlobalOwnerContext(function (): void {
        $geo = createTestPackageGeography('Selangor', 'Petaling', 'Shah Alam');

        $venue = Venue::factory()->create();
        syncPrimaryAddressForTest($venue, [
            ...$geo['address'],
            'country_code' => 'MY',
            'state' => 'Selangor',
            'city' => 'Shah Alam',
        ]);

        $event = Event::factory()->for($venue)->create([
            'status' => Approved::class,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $payload = $event->fresh()->toSearchableArray();

        expect($payload)
            ->toHaveKey('status')
            ->and($payload)->toHaveKey('country_code', 'MY')
            ->and($payload)->toHaveKey('state', 'Selangor')
            ->and($payload)->toHaveKey('city', 'Shah Alam')
            ->and($payload['state_id'])->toBe((string) $geo['state']->getKey())
            ->and($payload['admin_area_1_id'])->toBe((string) $geo['district']->getKey())
            ->and($payload['admin_area_2_id'])->toBe((string) $geo['subdistrict']->getKey())
            ->and($payload)->not->toHaveKey('admin_area_3_id');
    });
});

it('searchable payload includes the institution location ID for filtering', function () {
    withGlobalOwnerContext(function (): void {
        $institution = Institution::factory()->create();
        $event = Event::factory()->create(['institution_id' => $institution->getKey()]);

        expect($event->fresh()->toSearchableArray())
            ->toHaveKey('institution_id', (string) $institution->getKey());
    });
});

it('typesense facets the institution location ID', function () {
    $fields = config('scout.typesense.model-settings.'.Event::class.'.collection-schema.fields');

    expect(collect($fields)->firstWhere('name', 'institution_id'))
        ->toBe([
            'name' => 'institution_id',
            'type' => 'string',
            'optional' => true,
            'facet' => true,
        ]);
});

it('does not expose removed Event attribute aliases', function () {
    $event = new Event;

    expect($event->isFillable('event_format'))->toBeFalse()
        ->and($event->isFillable('venue_id'))->toBeFalse()
        ->and($event->isFillable('type'))->toBeFalse();
});

it('keeps the Typesense event schema aligned with searchable filters', function () {
    $fields = collect(config('scout.typesense.model-settings.'.Event::class.'.collection-schema.fields'))
        ->keyBy('name');

    expect($fields->get('event_format'))->toMatchArray([
        'name' => 'event_format',
        'type' => 'string',
        'facet' => true,
    ])
        ->and($fields->get('gender'))->toMatchArray([
            'name' => 'gender',
            'type' => 'string',
            'facet' => true,
        ])
        ->and($fields->get('children_allowed'))->toMatchArray([
            'name' => 'children_allowed',
            'type' => 'bool',
            'facet' => true,
        ])
        ->and($fields->get('venue_id'))->toMatchArray([
            'name' => 'venue_id',
            'type' => 'string',
            'facet' => true,
        ]);
});

it('searchable payload uses canonical language_codes', function () {
    withGlobalOwnerContext(function (): void {
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
            'published_at' => now(),
        ]);

        $event->syncLanguages([(int) $malay->getKey(), (int) $english->getKey()]);

        $payload = $event->fresh()->toSearchableArray();

        expect($payload['language_codes'])->toBe(['ms', 'en'])
            ->and($payload)->not->toHaveKey('language');
    });
});

it('deduplicates key person roles in the searchable payload', function () {
    withGlobalOwnerContext(function (): void {
        $event = Event::factory()->create([
            'status' => Approved::class,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $moderator = Person::factory()->create();
        $imam = Person::factory()->create();
        $personInCharge = Person::factory()->create([
            'name' => 'Ustaz Searchable PIC',
            'searchable_name' => 'ustaz searchable pic',
        ]);

        $event->keyPeople()->create([
            'involveable_type' => 'speaker',
            'involveable_id' => $moderator->getKey(),
            'role_code' => EventKeyPersonRole::Moderator->value,
            'display_name' => $moderator->name,
        ]);

        $event->keyPeople()->create([
            'involveable_type' => 'speaker',
            'involveable_id' => $moderator->getKey(),
            'role_code' => EventKeyPersonRole::Moderator->value,
            'display_name' => $moderator->name,
        ]);

        $event->keyPeople()->create([
            'involveable_type' => 'speaker',
            'involveable_id' => $imam->getKey(),
            'role_code' => EventKeyPersonRole::Imam->value,
            'display_name' => $imam->name,
        ]);

        $event->keyPeople()->create([
            'involveable_type' => 'speaker',
            'involveable_id' => $personInCharge->getKey(),
            'role_code' => EventKeyPersonRole::PersonInCharge->value,
            'display_name' => $personInCharge->name,
        ]);

        $event->keyPeople()->create([
            'role_code' => EventKeyPersonRole::PersonInCharge->value,
            'display_name' => 'Encik Free Text PIC',
        ]);

        $payload = $event->fresh()->toSearchableArray();

        expect($payload['key_person_roles'])->toBe([
            EventKeyPersonRole::Moderator->value,
            EventKeyPersonRole::Imam->value,
            EventKeyPersonRole::PersonInCharge->value,
        ])->and($payload['key_person_person_ids'])->toBe([
            (string) $moderator->getKey(),
            (string) $imam->getKey(),
            (string) $personInCharge->getKey(),
        ])->and($payload['person_in_charge_ids'])->toBe([
            (string) $personInCharge->getKey(),
        ])->and($payload['person_in_charge_names'])
            ->toContain('ustaz searchable pic')
            ->toContain('Encik Free Text PIC');
    });
});

it('keeps every public event container discoverable and searchable', function () {
    withGlobalOwnerContext(function (): void {
        $event = Event::factory()->create([
            'status' => Approved::class,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $discoverableIds = Event::discoverable()->pluck('id')->all();
        $activeIds = Event::active()->pluck('id')->all();

        expect($discoverableIds)->toContain($event->id)
            ->and($activeIds)->toContain($event->id)
            ->and($event->fresh()->shouldBeSearchable())->toBeTrue();
    });
});
