<?php

use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\FacilityType;
use AIArmada\Events\Models\VenueFacility;
use AIArmada\Signals\Models\SignalEvent;
use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Actions\Institutions\SaveInstitutionAction;
use App\Actions\Institutions\SyncInstitutionVenuesAction;
use App\Enums\DonationChannelStatus;
use App\Enums\InstitutionStatus;
use App\Enums\InstitutionVenueRole;
use App\Models\DonationChannel;
use App\Models\Event as EventModel;
use App\Models\Institution;
use App\Models\InstitutionImportExclusion;
use App\Models\InstitutionVenue;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Support\Institutions\InstitutionFacilities;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('defaults new institutions to pending with an enum cast', function (): void {
    $institution = Institution::factory()->create(['status' => InstitutionStatus::Pending]);

    expect($institution->status)->toBe(InstitutionStatus::Pending)
        ->and($institution->getCasts()['status'])->toBe(InstitutionStatus::class);
});

it('records matching timestamps and refreshes last_state_change_at on institution transitions', function (): void {
    $institution = Institution::factory()->create(['status' => InstitutionStatus::Pending]);
    $institution->refresh();

    expect($institution->verified_at)->toBeNull()
        ->and($institution->rejected_at)->toBeNull()
        ->and($institution->inactive_at)->toBeNull();

    $institution->update(['status' => InstitutionStatus::Verified]);
    $institution->refresh();
    $verifiedChange = $institution->last_state_change_at;

    expect($institution->verified_at)->not->toBeNull()
        ->and($verifiedChange)->not->toBeNull();

    $institution->update(['status' => InstitutionStatus::Rejected]);
    $institution->refresh();
    $rejectedChange = $institution->last_state_change_at;

    expect($institution->rejected_at)->not->toBeNull()
        ->and($rejectedChange?->greaterThanOrEqualTo($verifiedChange))->toBeTrue();

    $institution->update(['status' => InstitutionStatus::Inactive]);
    $institution->refresh();

    expect($institution->inactive_at)->not->toBeNull()
        ->and($institution->last_state_change_at?->greaterThanOrEqualTo($rejectedChange))->toBeTrue();
});

it('rejects invalid institution statuses at the action boundary', function (): void {
    $actor = User::factory()->create();
    $institution = Institution::factory()->create();

    expect(fn () => SaveInstitutionAction::run(['name' => $institution->name, 'status' => 'bogus'], $actor, $institution))
        ->toThrow(ValidationException::class);
});

it('keeps backing string status shape in array and scout payloads', function (): void {
    $institution = Institution::factory()->create(['status' => InstitutionStatus::Verified]);

    expect($institution->toArray()['status'])->toBe('verified')
        ->and($institution->toSearchableArray()['status'])->toBe('verified');
});

it('deactivates donation channels with the inactive timestamp and rejects invalid transitions', function (): void {
    $channel = DonationChannel::factory()->verified()->create();

    $channel->deactivate();
    $channel->refresh();

    expect($channel->status)->toBe(DonationChannelStatus::Inactive)
        ->and($channel->inactive_at)->not->toBeNull()
        ->and($channel->getCasts()['status'])->toBe(DonationChannelStatus::class);

    expect(fn () => $channel->transitionStatus('bogus'))->toThrow(TypeError::class);
});

it('keeps institution provenance immutable after create', function (): void {
    $institution = Institution::factory()->create([
        'source' => 'masjid-csv',
        'external_ref' => 'ref-1',
    ]);

    $importedAt = $institution->refresh()->imported_at;

    expect($importedAt)->not->toBeNull();

    $institution->forceFill([
        'source' => 'changed',
        'external_ref' => 'changed',
        'imported_at' => now()->addDay(),
    ])->save();

    $fresh = $institution->fresh();

    expect($fresh)->toBeInstanceOf(Institution::class);

    expect($fresh->getAttribute('source'))->toBe('masjid-csv')
        ->and($fresh->getAttribute('external_ref'))->toBe('ref-1')
        ->and($fresh->imported_at)->toEqual($importedAt);
});

it('never attaches provenance to an existing manual row', function (): void {
    $institution = Institution::factory()->create();

    $institution->forceFill([
        'source' => 'masjid-csv',
        'external_ref' => 'ref-1',
    ])->save();

    $fresh = $institution->fresh();

    expect($fresh)->toBeInstanceOf(Institution::class);

    expect($fresh->getAttribute('source'))->toBeNull()
        ->and($fresh->getAttribute('external_ref'))->toBeNull()
        ->and($fresh->getAttribute('imported_at'))->toBeNull();
});

it('ignores provenance keys in the save institution action', function (): void {
    $actor = User::factory()->create();
    $institution = Institution::factory()->create();

    SaveInstitutionAction::run([
        'name' => $institution->name,
        'source' => 'masjid-csv',
        'external_ref' => 'ref-9',
        'imported_at' => now()->toDateTimeString(),
    ], $actor, $institution);

    expect($institution->fresh()->getAttribute('source'))->toBeNull()
        ->and($institution->fresh()->getAttribute('external_ref'))->toBeNull()
        ->and($institution->fresh()->getAttribute('imported_at'))->toBeNull();
});

it('preserves generated slugs for sourced institutions', function (): void {
    $institution = Institution::factory()->create([
        'name' => 'Masjid Sourced Slug',
        'slug' => 'masjid-sourced-slug-imported',
        'source' => 'osm',
        'external_ref' => 'n1',
    ]);

    $synced = app(GenerateInstitutionSlugAction::class)->syncInstitutionSlug($institution->refresh());

    expect($synced)->toBeFalse()
        ->and($institution->refresh()->slug)->toBe('masjid-sourced-slug-imported');
});

it('persists an explicit slug on save institution update', function (): void {
    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Masjid Lama Slug', 'slug' => 'masjid-lama']);

    SaveInstitutionAction::run(['name' => 'Masjid Baharu Slug', 'slug' => 'masjid-baharu-custom'], $actor, $institution);

    expect($institution->refresh()->slug)->toBe('masjid-baharu-custom');
});

it('rejects duplicate explicit slugs on save institution update', function (): void {
    $actor = User::factory()->create();
    $blocker = Institution::factory()->create();
    $taken = (string) $blocker->refresh()->slug;
    $institution = Institution::factory()->create();

    expect(fn () => SaveInstitutionAction::run(['name' => $institution->name, 'slug' => $taken], $actor, $institution))
        ->toThrow(ValidationException::class);

    expect($institution->refresh()->slug)->not->toBe($taken);
});

it('saves omits and clears facilities through the save institution action', function (): void {
    $actor = User::factory()->create();
    $institution = Institution::factory()->create();

    SaveInstitutionAction::run(['name' => $institution->name, 'facilities' => ['parking', 'oku']], $actor, $institution);

    expect($institution->fresh()->facilities)->toBe(['parking' => true, 'oku' => true]);

    SaveInstitutionAction::run(['name' => 'Renamed Institution'], $actor, $institution->fresh());

    expect($institution->fresh()->facilities)->toBe(['parking' => true, 'oku' => true]);

    SaveInstitutionAction::run(['name' => 'Renamed Institution', 'facilities' => null], $actor, $institution->fresh());

    expect($institution->fresh()->facilities)->toBeNull();

    expect(fn () => SaveInstitutionAction::run(['name' => 'Renamed Institution', 'facilities' => ['bogus' => true]], $actor, $institution->fresh()))
        ->toThrow(ValidationException::class);
});

it('emits no facilities signal when facilities are omitted from the save', function (): void {
    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['facilities' => ['parking' => true]]);

    SaveInstitutionAction::run(['name' => 'Renamed Without Facilities'], $actor, $institution);

    expect($institution->fresh()->facilities)->toBe(['parking' => true])
        ->and(SignalEvent::query()->where('event_name', 'admin.institution_facilities.updated')->count())->toBe(0);
});

it('emits no facilities signal when the save rolls back', function (): void {
    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['facilities' => ['parking' => true]]);

    expect(fn () => DB::transaction(function () use ($actor, $institution): void {
        SaveInstitutionAction::run(
            ['name' => $institution->name, 'facilities' => ['parking' => true, 'oku' => true]],
            $actor,
            $institution,
        );

        throw new RuntimeException('Simulated enclosing failure.');
    }))->toThrow(RuntimeException::class, 'Simulated enclosing failure.');

    // The signal registers after commit, so the rollback discards it
    // together with the facilities write.
    expect($institution->fresh()->facilities)->toBe(['parking' => true])
        ->and(SignalEvent::query()->where('event_name', 'admin.institution_facilities.updated')->count())->toBe(0);
});

it('emits the facilities signal only after the enclosing transaction commits', function (): void {
    $actor = User::factory()->create();
    $institution = Institution::factory()->create(['facilities' => ['parking' => true]]);

    DB::transaction(function () use ($actor, $institution): void {
        SaveInstitutionAction::run(
            ['name' => $institution->name, 'facilities' => ['parking' => true, 'oku' => true]],
            $actor,
            $institution,
        );

        expect(SignalEvent::query()->where('event_name', 'admin.institution_facilities.updated')->count())->toBe(0);
    });

    expect($institution->fresh()->facilities)->toBe(['parking' => true, 'oku' => true])
        ->and(SignalEvent::query()->where('event_name', 'admin.institution_facilities.updated')->count())->toBe(1);
});

it('rejects unknown facility keys and non boolean values at the model boundary', function (): void {
    expect(InstitutionFacilities::validate(['parking' => true, 'air_conditioning' => false]))->toBe([])
        ->and(InstitutionFacilities::validate(['bogus' => true]))->not->toBe([])
        ->and(InstitutionFacilities::validate(['parking' => 'yes']))->not->toBe([]);

    expect(fn () => Institution::factory()->create(['facilities' => ['bogus' => true]]))
        ->toThrow(InvalidArgumentException::class);
});

it('merges effective facilities from operated venues and active spaces only', function (): void {
    $parking = FacilityType::create(['code' => 'parking', 'name' => 'Parking', 'is_active' => true]);
    $oku = FacilityType::create(['code' => 'oku', 'name' => 'OKU Access', 'is_active' => true]);
    $ablution = FacilityType::create(['code' => 'ablution_area', 'name' => 'Ablution Area', 'is_active' => true]);
    $women = FacilityType::create(['code' => 'women_section', 'name' => 'Women Section', 'is_active' => false]);

    $institution = Institution::factory()->create(['facilities' => ['oku' => false]]);
    $operated = Venue::factory()->create();
    $preferred = Venue::factory()->create();

    SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => (string) $operated->getKey(), 'role' => InstitutionVenueRole::Operated, 'is_primary' => true],
        ['venue_id' => (string) $preferred->getKey(), 'role' => InstitutionVenueRole::Preferred, 'is_primary' => true],
    ]);

    VenueFacility::create([
        'venue_id' => $operated->getKey(),
        'facility_type_id' => $parking->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);
    VenueFacility::create([
        'venue_id' => $operated->getKey(),
        'facility_type_id' => $oku->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);
    VenueFacility::create([
        'venue_id' => $operated->getKey(),
        'facility_type_id' => $women->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);
    VenueFacility::create([
        'venue_id' => $preferred->getKey(),
        'facility_type_id' => $parking->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);

    $space = Space::factory()->create(['venue_id' => $operated->getKey(), 'status' => 'active']);
    $institution->spaces()->attach($space->getKey());

    VenueFacility::create([
        'venue_id' => $operated->getKey(),
        'venue_space_id' => $space->getKey(),
        'facility_type_id' => $ablution->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);

    $effective = $institution->fresh()->effective_facilities;

    expect($institution->fresh()->own_facilities)->toBe(['oku' => false])
        ->and($effective)->toBe(['parking' => true, 'ablution_area' => true]);
});

it('excludes private linked spaces from effective facilities', function (): void {
    $parking = FacilityType::create(['code' => 'parking', 'name' => 'Parking', 'is_active' => true]);
    $wheelchair = FacilityType::create(['code' => 'wheelchair_access', 'name' => 'Wheelchair Access', 'is_active' => true]);

    $institution = Institution::factory()->create(['facilities' => null]);
    $venue = Venue::factory()->create();

    $privateSpace = Space::factory()->create([
        'venue_id' => $venue->getKey(),
        'status' => 'active',
        'visibility' => 'private',
    ]);
    $publicSpace = Space::factory()->create([
        'venue_id' => $venue->getKey(),
        'status' => 'active',
        'visibility' => 'public',
    ]);
    $institution->spaces()->attach([$privateSpace->getKey(), $publicSpace->getKey()]);

    VenueFacility::create([
        'venue_id' => $venue->getKey(),
        'venue_space_id' => $privateSpace->getKey(),
        'facility_type_id' => $parking->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);
    VenueFacility::create([
        'venue_id' => $venue->getKey(),
        'venue_space_id' => $publicSpace->getKey(),
        'facility_type_id' => $wheelchair->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);

    expect($institution->fresh()->effective_facilities)->toBe(['wheelchair_access' => true]);
});

it('validates institution venue links and keeps one primary per role', function (): void {
    $institution = Institution::factory()->create();
    $venue = Venue::factory()->create();
    $other = Venue::factory()->create();

    expect(fn () => SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => (string) $venue->getKey(), 'role' => 'bogus'],
    ]))->toThrow(ValidationException::class);

    expect(fn () => SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => 'not-a-uuid', 'role' => InstitutionVenueRole::Operated],
    ]))->toThrow(ValidationException::class);

    expect(fn () => SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => (string) $venue->getKey(), 'role' => InstitutionVenueRole::Operated, 'is_primary' => true],
        ['venue_id' => (string) $other->getKey(), 'role' => InstitutionVenueRole::Operated, 'is_primary' => true],
    ]))->toThrow(ValidationException::class);

    SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => (string) $venue->getKey(), 'role' => 'operated', 'is_primary' => true],
    ]);

    expect($institution->operatedVenues()->count())->toBe(1)
        ->and($institution->operatedVenues()->first()->pivot->role)->toBe(InstitutionVenueRole::Operated)
        ->and($institution->operatedVenues()->first()->pivot->is_primary)->toBeTrue();

    SyncInstitutionVenuesAction::run($institution, []);

    expect(InstitutionVenue::query()->where('institution_id', $institution->getKey())->count())->toBe(0);
});

it('cleans up institution venue links when either side is deleted', function (): void {
    $institution = Institution::factory()->create();
    $venue = Venue::factory()->create();
    $otherVenue = Venue::factory()->create();

    SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => (string) $venue->getKey(), 'role' => InstitutionVenueRole::Operated],
        ['venue_id' => (string) $otherVenue->getKey(), 'role' => InstitutionVenueRole::Preferred],
    ]);

    $venue->delete();

    expect(InstitutionVenue::query()->where('institution_id', $institution->getKey())->count())->toBe(1);

    $institution->delete();

    expect(InstitutionVenue::query()->where('institution_id', $institution->getKey())->count())->toBe(0);
});

it('cleans up venue facilities through the canonical parent hook when an app venue is deleted', function (): void {
    $parking = FacilityType::create(['code' => 'parking', 'name' => 'Parking', 'is_active' => true]);
    $venue = Venue::factory()->create();
    $otherVenue = Venue::factory()->create();

    $facility = VenueFacility::create([
        'venue_id' => $venue->getKey(),
        'facility_type_id' => $parking->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);
    $otherFacility = VenueFacility::create([
        'venue_id' => $otherVenue->getKey(),
        'facility_type_id' => $parking->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);

    $venue->delete();

    expect(VenueFacility::query()->whereKey($facility->getKey())->exists())->toBeFalse()
        ->and(VenueFacility::query()->whereKey($otherFacility->getKey())->exists())->toBeTrue();
});

it('cleans up space facilities when an unreferenced app space is deleted', function (): void {
    $parking = FacilityType::create(['code' => 'parking', 'name' => 'Parking', 'is_active' => true]);
    $venue = Venue::factory()->create();
    $space = Space::factory()->create(['venue_id' => $venue->getKey()]);

    $facility = VenueFacility::create([
        'venue_id' => $venue->getKey(),
        'venue_space_id' => $space->getKey(),
        'facility_type_id' => $parking->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);

    $space->delete();

    expect(Space::query()->whereKey($space->getKey())->exists())->toBeFalse()
        ->and(VenueFacility::query()->whereKey($facility->getKey())->exists())->toBeFalse();
});

it('keeps space facilities intact when an event-referenced app space deletion is rejected', function (): void {
    $parking = FacilityType::create(['code' => 'parking', 'name' => 'Parking', 'is_active' => true]);
    $venue = Venue::factory()->create();
    $space = Space::factory()->create(['venue_id' => $venue->getKey()]);
    $event = EventModel::factory()->create();

    $facility = VenueFacility::create([
        'venue_id' => $venue->getKey(),
        'venue_space_id' => $space->getKey(),
        'facility_type_id' => $parking->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);

    EventLocation::query()->create([
        'event_id' => $event->getKey(),
        'location_role' => 'primary',
        'venue_space_id' => $space->getKey(),
        'visibility' => 'public',
        'status' => 'active',
    ]);

    expect(fn () => $space->delete())->toThrow(ValidationException::class);

    // The app guard runs before the parent facility cleanup, so the
    // rejected delete leaves both the space and its facilities intact.
    expect(Space::query()->whereKey($space->getKey())->exists())->toBeTrue()
        ->and(VenueFacility::query()->whereKey($facility->getKey())->exists())->toBeTrue();
});

it('rejects invalid app space venue references and reparenting while facilities exist', function (): void {
    $parking = FacilityType::create(['code' => 'parking', 'name' => 'Parking', 'is_active' => true]);
    $venue = Venue::factory()->create();
    $otherVenue = Venue::factory()->create();

    expect(fn () => Space::factory()->create(['venue_id' => (string) Str::uuid()]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => Space::factory()->create(['venue_id' => 'not-a-uuid']))
        ->toThrow(InvalidArgumentException::class);

    $space = Space::factory()->create(['venue_id' => $venue->getKey()]);

    $facility = VenueFacility::create([
        'venue_id' => $venue->getKey(),
        'venue_space_id' => $space->getKey(),
        'facility_type_id' => $parking->getKey(),
        'availability' => 'available',
        'visibility' => 'public',
    ]);

    $space->venue_id = (string) $otherVenue->getKey();

    expect(fn () => $space->save())->toThrow(InvalidArgumentException::class);

    expect(VenueFacility::query()->whereKey($facility->getKey())->exists())->toBeTrue()
        ->and($space->refresh()->venue_id)->toBe((string) $venue->getKey());

    // Spaces without facilities move freely in either direction.
    $bareSpace = Space::factory()->create(['venue_id' => $venue->getKey()]);
    $bareSpace->venue_id = (string) $otherVenue->getKey();
    $bareSpace->save();

    expect($bareSpace->refresh()->venue_id)->toBe((string) $otherVenue->getKey());
});

it('keeps the old primary when a direct primary attach fails', function (): void {
    $institution = Institution::factory()->create();
    $venue = Venue::factory()->create();

    SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => (string) $venue->getKey(), 'role' => InstitutionVenueRole::Operated, 'is_primary' => true],
    ]);

    // The duplicate-link unique index rejects the insert after the demote,
    // so without an atomic pivot save the old primary would be lost.
    expect(fn () => $institution->venues()->attach((string) $venue->getKey(), ['role' => 'operated', 'is_primary' => true]))
        ->toThrow(QueryException::class);

    $links = InstitutionVenue::query()->where('institution_id', $institution->getKey())->get();

    expect($links)->toHaveCount(1)
        ->and($links->firstOrFail()->is_primary)->toBeTrue();
});

it('rolls back the institution delete when exclusion recording fails', function (): void {
    $institution = Institution::factory()->create([
        'source' => 'masjid-csv',
        'external_ref' => 'rollback-1',
    ]);
    $venue = Venue::factory()->create();

    SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => (string) $venue->getKey(), 'role' => InstitutionVenueRole::Operated],
    ]);

    $targetId = (string) $institution->getKey();

    Event::listen('eloquent.deleted: '.Institution::class, function (Institution $deleted) use ($targetId): void {
        if ((string) $deleted->getKey() === $targetId) {
            throw new RuntimeException('Simulated exclusion failure.');
        }
    });

    expect(fn () => $institution->delete())->toThrow(RuntimeException::class);

    expect(Institution::query()->whereKey($targetId)->exists())->toBeTrue()
        ->and(InstitutionImportExclusion::query()->count())->toBe(0)
        ->and(InstitutionVenue::query()->where('institution_id', $targetId)->count())->toBe(1);
});

it('starts a fresh inactivity cycle with a new timestamp and reset stale flag', function (): void {
    $firstCycleAt = CarbonImmutable::parse('2026-01-10 10:00:00', 'UTC');
    $flaggingAt = $firstCycleAt->addDays(200);
    $secondCycleAt = $flaggingAt->addDays(10);

    Carbon::setTestNow($firstCycleAt);
    CarbonImmutable::setTestNow($firstCycleAt);

    try {
        $institution = Institution::factory()->create(['status' => InstitutionStatus::Verified]);

        $institution->update(['status' => InstitutionStatus::Inactive]);

        $firstInactiveAt = $institution->refresh()->inactive_at;

        expect($firstInactiveAt)->not->toBeNull();

        Carbon::setTestNow($flaggingAt);
        CarbonImmutable::setTestNow($flaggingAt);

        $this->artisan('institutions:flag-stale-inactive', ['--apply' => true])->assertSuccessful();

        expect($institution->refresh()->stale_inactive_flagged_at)->not->toBeNull();

        $institution->update(['status' => InstitutionStatus::Verified]);

        expect($institution->refresh()->stale_inactive_flagged_at)->toBeNull();

        Carbon::setTestNow($secondCycleAt);
        CarbonImmutable::setTestNow($secondCycleAt);

        $institution->update(['status' => InstitutionStatus::Inactive]);

        $secondInactiveAt = $institution->refresh()->inactive_at;

        expect($secondInactiveAt)->not->toBeNull()
            ->and($secondInactiveAt->equalTo($firstInactiveAt))->toBeFalse()
            ->and($secondInactiveAt->greaterThan($firstInactiveAt))->toBeTrue()
            ->and($institution->stale_inactive_flagged_at)->toBeNull();

        $this->artisan('institutions:flag-stale-inactive')
            ->expectsOutputToContain('Dry run: 0 inactive institution(s) would be flagged')
            ->assertSuccessful();

        expect($institution->refresh()->stale_inactive_flagged_at)->toBeNull();
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
});
