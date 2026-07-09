<?php

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds the speaker searchable payload with title text and geography facets', function () {
    withGlobalOwnerContext(function (): void {
        $country = ensureTestMalaysiaCountry();

        $speaker = Speaker::factory()->create([
            'name' => 'Samad Hassan',
            'gender' => 'male',
            'honorific' => null,
            'pre_nominal' => ['ustaz'],
            'post_nominal' => ['PhD'],
            'qualifications' => [],
            'is_freelance' => false,
            'job_title' => 'Pensyarah',
            'status' => 'pending',
        ]);

        syncPrimaryAddressForTest($speaker, [
            'country_id' => (string) $country->getKey(),
            'country_code' => 'MY',
            'state' => 'Selangor',
            'city' => 'Shah Alam',
            'postcode' => '40100',
        ]);

        $payload = $speaker->fresh()->toSearchableArray();

        expect($speaker->fresh()->shouldBeSearchable())->toBeTrue()
            ->and($payload)->toHaveKey('id', (string) $speaker->id)
            ->and($payload)->toHaveKey('formatted_name', Speaker::formatDisplayedName('Samad Hassan', null, ['ustaz'], ['PhD']))
            ->and($payload['search_text'])->toContain('Ustaz Samad Hassan, PhD')
            ->and($payload['search_text'])->toContain('Pensyarah')
            ->and($payload)->toHaveKey('country_code', 'MY')
            ->and($payload)->toHaveKey('state', 'Selangor')
            ->and($payload)->toHaveKey('city', 'Shah Alam')
            ->and($payload)->toHaveKey('postcode', '40100')
            ->and($payload)->not->toHaveKey('state_id')
            ->and($payload)->not->toHaveKey('admin_area_1_id')
            ->and($payload)->not->toHaveKey('admin_area_2_id')
            ->and($payload)->toHaveKey('status', 'pending')
            ->and($payload['updated_at'])->toBeInt();
    });
});

it('only indexes active verified or pending speakers', function () {
    withGlobalOwnerContext(function (): void {
        $pendingSpeaker = Speaker::factory()->create([
            'status' => 'pending',
        ]);

        $rejectedSpeaker = Speaker::factory()->create([
            'status' => 'rejected',
        ]);

        $inactiveSpeaker = Speaker::factory()->create([
            'status' => 'inactive',
        ]);

        expect($pendingSpeaker->fresh()->shouldBeSearchable())->toBeTrue()
            ->and($rejectedSpeaker->fresh()->shouldBeSearchable())->toBeFalse()
            ->and($inactiveSpeaker->fresh()->shouldBeSearchable())->toBeFalse();
    });
});

it('builds the institution searchable payload with nickname description and geography facets', function () {
    withGlobalOwnerContext(function (): void {
        $country = ensureTestMalaysiaCountry();

        $institution = Institution::factory()->create([
            'name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
            'nickname' => 'Masjid Biru',
            'description' => '<p>Pusat komuniti dan kuliah.</p>',
            'status' => 'pending',
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
            ->and($payload)->toHaveKey('display_name', Institution::formatDisplayName($institution->name, $institution->nickname))
            ->and($payload)->toHaveKey('description', 'Pusat komuniti dan kuliah.')
            ->and($payload['search_text'])->toContain('Masjid Biru')
            ->and($payload['search_text'])->toContain('Pusat komuniti dan kuliah.')
            ->and($payload)->toHaveKey('country_code', 'MY')
            ->and($payload)->toHaveKey('state', 'Selangor')
            ->and($payload)->toHaveKey('city', 'Shah Alam')
            ->and($payload)->toHaveKey('postcode', '40100')
            ->and($payload)->not->toHaveKey('state_id')
            ->and($payload)->not->toHaveKey('admin_area_1_id')
            ->and($payload)->not->toHaveKey('admin_area_2_id')
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

it('builds the reference searchable payload and only indexes active verified or pending references', function () {
    $reference = Reference::factory()->create([
        'title' => 'Tafsir Al-Hikmah',
        'author' => 'Dr. Ahmad',
        'publisher' => 'Pustaka Hikmah',
        'description' => '<p>Rujukan utama kuliah.</p>',
        'publication_year' => '2020',
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
        ->and($payload['updated_at'])->toBeInt();

    $rejectedReference = Reference::factory()->create([
        'status' => 'rejected',
    ]);

    expect($rejectedReference->fresh()->shouldBeSearchable())->toBeFalse();
});

it('scopes make all searchable queries to the intended scout-ready records', function () {
    withGlobalOwnerContext(function (): void {
        $searchableSpeaker = Speaker::factory()->create([
            'status' => 'verified',
        ]);
        $hiddenSpeaker = Speaker::factory()->create([
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

        $searchableEvent = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'public',
        ]);
        $hiddenEvent = Event::factory()->create([
            'status' => 'approved',
            'visibility' => 'private',
        ]);

        expect(Speaker::makeAllSearchableQuery()->pluck('speakers.id')->all())
            ->toContain((string) $searchableSpeaker->id)
            ->not->toContain((string) $hiddenSpeaker->id)
            ->and(Institution::makeAllSearchableQuery()->pluck('institutions.id')->all())
            ->toContain((string) $searchableInstitution->id)
            ->not->toContain((string) $hiddenInstitution->id)
            ->and(Reference::makeAllSearchableQuery()->pluck('references.id')->all())
            ->toContain((string) $searchableReference->id)
            ->not->toContain((string) $hiddenReference->id)
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
        $speaker = Speaker::factory()->create([
            'status' => 'verified',
        ])->fresh();
        $speaker->touch();

        expect($speaker->searchIndexShouldBeUpdated())->toBeFalse();

        $speaker->update(['job_title' => 'Mudir']);

        expect($speaker->searchIndexShouldBeUpdated())->toBeTrue();

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
        $event->update(['views_count' => 99]);

        expect($event->searchIndexShouldBeUpdated())->toBeFalse();

        $event->update(['title' => 'Majlis Ilmu Perdana']);

        expect($event->searchIndexShouldBeUpdated())->toBeTrue();
    });
});
