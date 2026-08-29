<?php

use App\Enums\InstitutionNameType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds the person searchable payload with title text and geography facets', function () {
    withGlobalOwnerContext(function (): void {
        $country = ensureTestMalaysiaCountry();

        $person = Person::factory()->create([
            'name' => 'Samad Hassan',
            'gender' => 'male',
            'status' => 'pending',
        ]);

        syncPrimaryAddressForTest($person, [
            'country_id' => (string) $country->getKey(),
            'country_code' => 'MY',
            'state' => 'Selangor',
            'city' => 'Shah Alam',
            'postcode' => '40100',
        ]);

        $payload = $person->fresh()->toSearchableArray();

        expect($person->fresh()->shouldBeSearchable())->toBeTrue()
            ->and($payload)->toHaveKey('id', (string) $person->id)
            ->and($payload)->toHaveKey('formatted_name', Person::formatDisplayedName('Samad Hassan'))
            ->and($payload)->toHaveKey('country_code', 'MY')
            ->and($payload)->toHaveKey('state', 'Selangor')
            ->and($payload)->toHaveKey('city', 'Shah Alam')
            ->and($payload)->toHaveKey('postcode', '40100')
            ->and($payload)->not->toHaveKey('state_id')
            ->and($payload)->toHaveKey('status', 'pending')
            ->and($payload['updated_at'])->toBeInt();
    });
});

it('only indexes active verified or pending persons', function () {
    withGlobalOwnerContext(function (): void {
        $pendingPerson = Person::factory()->create([
            'status' => 'pending',
        ]);

        $rejectedPerson = Person::factory()->create([
            'status' => 'rejected',
        ]);

        $inactivePerson = Person::factory()->create([
            'status' => 'inactive',
        ]);

        expect($pendingPerson->fresh()->shouldBeSearchable())->toBeTrue()
            ->and($rejectedPerson->fresh()->shouldBeSearchable())->toBeFalse()
            ->and($inactivePerson->fresh()->shouldBeSearchable())->toBeFalse();
    });
});

it('builds the institution searchable payload with alternative name description and geography facets', function () {
    withGlobalOwnerContext(function (): void {
        $country = ensureTestMalaysiaCountry();

        $institution = Institution::factory()
            ->create([
                'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
                'description' => '<p>Pusat komuniti dan kuliah.</p>',
                'status' => 'pending',
            ]);

        $institution->names()->create([
            'name_type' => InstitutionNameType::Nickname,
            'full_name' => 'Masjid Biru',
            'language_code' => 'ms',
            'is_primary' => true,
        ]);

        syncPrimaryAddressForTest($institution, [
            'country_id' => (string) $country->getKey(),
            'country_code' => 'MY',
            'state' => 'Selangor',
            'city' => 'Shah Alam',
            'postcode' => '40100',
        ]);

        $payload = $institution->fresh()->toSearchableArray();

        expect($institution->fresh()->shouldBeSearchable())->toBeTrue()
            ->and($payload)->toHaveKey('display_name', Institution::formatDisplayName($institution->name, $institution->primaryNickname))
            ->and($payload)->toHaveKey('description', 'Pusat komuniti dan kuliah.')
            ->and($payload['search_text'])->toContain('Masjid Biru')
            ->and($payload['search_text'])->toContain('Pusat komuniti dan kuliah.')
            ->and($payload)->toHaveKey('country_code', 'MY')
            ->and($payload)->toHaveKey('state', 'Selangor')
            ->and($payload)->toHaveKey('city', 'Shah Alam')
            ->and($payload)->toHaveKey('postcode', '40100')
            ->and($payload)->not->toHaveKey('state_id')
            ->and($payload['updated_at'])->toBeInt();
    });
});

it('only indexes active verified or pending institutions', function () {
    withGlobalOwnerContext(function (): void {
        $pendingInstitution = Institution::factory()->create([
            'status' => 'pending',
        ]);

        $rejectedInstitution = Institution::factory()->create([
            'status' => 'rejected',
        ]);

        $inactiveInstitution = Institution::factory()->create([
            'status' => 'inactive',
        ]);

        expect($pendingInstitution->fresh()->shouldBeSearchable())->toBeTrue()
            ->and($rejectedInstitution->fresh()->shouldBeSearchable())->toBeFalse()
            ->and($inactiveInstitution->fresh()->shouldBeSearchable())->toBeFalse();
    });
});

it('builds the reference searchable payload and only indexes published verified or pending references', function () {
    $reference = Reference::factory()->create([
        'title' => 'Tafsir Al-Hikmah',
        'author' => 'Dr. Ahmad',
        'publisher' => 'Pustaka Hikmah',
        'description' => '<p>Rujukan utama kuliah.</p>',
        'year' => '2020',
        'status' => 'pending',
    ]);

    $payload = $reference->fresh()->toSearchableArray();

    expect($reference->fresh()->shouldBeSearchable())->toBeTrue()
        ->and($payload)->toHaveKey('id', (string) $reference->id)
        ->and($payload)->toHaveKey('title', 'Tafsir Al-Hikmah')
        ->and($payload)->toHaveKey('description', 'Rujukan utama kuliah.')
        ->and($payload['search_text'])->toContain('Dr. Ahmad')
        ->and($payload['search_text'])->toContain('Pustaka Hikmah')
        ->and($payload)->toHaveKey('publication_year', 2020)
        ->and($payload['published_at'])->toBeInt()
        ->and($payload['updated_at'])->toBeInt();

    $rejectedReference = Reference::factory()->create([
        'status' => 'rejected',
    ]);

    expect($rejectedReference->fresh()->shouldBeSearchable())->toBeFalse();

    $unpublishedReference = Reference::factory()->pending()->unpublished()->create();

    expect($unpublishedReference->fresh()->shouldBeSearchable())->toBeFalse();
});

it('excludes unpublished references from public event search payloads', function () {
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now(),
    ]);
    $publishedPendingReference = Reference::factory()->pending()->create();
    $unpublishedReference = Reference::factory()->pending()->unpublished()->create();

    $event->references()->attach([
        $publishedPendingReference->getKey(),
        $unpublishedReference->getKey(),
    ]);

    $payload = $event->toSearchableArray();

    expect($payload['reference_ids'])
        ->toContain((string) $publishedPendingReference->getKey())
        ->not->toContain((string) $unpublishedReference->getKey());
});

it('scopes make all searchable queries to the intended scout-ready records', function () {
    withGlobalOwnerContext(function (): void {
        $searchablePerson = Person::factory()->create([
            'status' => 'verified',
        ]);
        $hiddenPerson = Person::factory()->create([
            'status' => 'rejected',
        ]);

        $searchableInstitution = Institution::factory()->create([
            'status' => 'verified',
        ]);
        $hiddenInstitution = Institution::factory()->create([
            'status' => 'rejected',
        ]);

        $searchableReference = Reference::factory()->create([
            'status' => 'verified',
        ]);
        $hiddenReference = Reference::factory()->create([
            'status' => 'rejected',
        ]);

        $unpublishedReference = Reference::factory()->pending()->unpublished()->create();

        $searchableEvent = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
        ]);
        $hiddenEvent = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'private',
        ]);

        expect(Person::makeAllSearchableQuery()->pluck('persons.id')->all())
            ->toContain((string) $searchablePerson->id)
            ->not->toContain((string) $hiddenPerson->id)
            ->and(Institution::makeAllSearchableQuery()->pluck('institutions.id')->all())
            ->toContain((string) $searchableInstitution->id)
            ->not->toContain((string) $hiddenInstitution->id)
            ->and(Reference::makeAllSearchableQuery()->pluck('references.id')->all())
            ->toContain((string) $searchableReference->id)
            ->not->toContain((string) $hiddenReference->id)
            ->not->toContain((string) $unpublishedReference->id)
            ->and(Event::makeAllSearchableQuery()->pluck('events.id')->all())
            ->toContain((string) $searchableEvent->id)
            ->not->toContain((string) $hiddenEvent->id)
            ->and(array_keys(Event::makeAllSearchableQuery()->getEagerLoads()))
            ->toContain('institution')
            ->toContain('references');
    });
});

it('only marks search indexes dirty when searchable fields change', function () {
    withGlobalOwnerContext(function (): void {
        $institution = Institution::factory()->create([
            'status' => 'verified',
        ])->fresh();
        $institution->update(['description' => 'Pusat komuniti ilmu']);

        expect($institution->searchIndexShouldBeUpdated())->toBeTrue();

        $reference = Reference::factory()->create([
            'status' => 'verified',
        ])->fresh();
        $reference->update(['publisher' => 'Darul Bayan']);

        expect($reference->searchIndexShouldBeUpdated())->toBeTrue();

        $event = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
        ])->fresh();
        $event->update(['title' => 'Majlis Ilmu Perdana']);

        expect($event->searchIndexShouldBeUpdated())->toBeTrue();
    });
});
