<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\FacilityType;
use AIArmada\Events\Models\VenueFacility;
use AIArmada\Signals\Models\SignalEvent;
use App\Actions\Institutions\SyncInstitutionVenuesAction;
use App\Enums\InstitutionStatus;
use App\Enums\InstitutionVenueRole;
use App\Filament\Resources\Institutions\Pages\EditInstitution;
use App\Models\Institution;
use App\Models\User;
use App\Models\Venue;
use App\Support\Institutions\InstitutionFacilities;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

function facilitiesAdminUser(): User
{
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    return $administrator;
}

function saveInstitutionFacilitiesForm(User $administrator, Institution $institution, array $formData = []): void
{
    OwnerContext::withOwner(null, function () use ($administrator, $institution, $formData): void {
        $page = Livewire::actingAs($administrator)
            ->test(EditInstitution::class, ['record' => $institution->getKey()]);

        if ($formData !== []) {
            $page->fillForm($formData);
        }

        $page->call('save')->assertHasNoFormErrors();
    });
}

function adminFacilitiesType(string $code, bool $active = true): FacilityType
{
    return FacilityType::create([
        'code' => $code,
        'name' => $code,
        'is_active' => $active,
    ]);
}

function adminFacilitiesGrant(
    Venue $venue,
    FacilityType $type,
    string $availability = 'available',
    string $visibility = 'public',
): VenueFacility {
    return VenueFacility::create([
        'venue_id' => $venue->getKey(),
        'facility_type_id' => $type->getKey(),
        'availability' => $availability,
        'visibility' => $visibility,
    ]);
}

it('saves enabled and disabled facility lists into the canonical merged map through the admin form', function (): void {
    $administrator = facilitiesAdminUser();
    $institution = Institution::factory()->create(['status' => InstitutionStatus::Verified]);
    $venue = Venue::factory()->create(['status' => 'verified', 'visibility' => 'public']);

    SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => (string) $venue->getKey(), 'role' => InstitutionVenueRole::Operated, 'is_primary' => true],
    ]);
    adminFacilitiesGrant($venue, adminFacilitiesType('wheelchair_access'));

    saveInstitutionFacilitiesForm($administrator, $institution, [
        'facilities' => ['parking'],
        'facilities_disabled' => ['wheelchair_access'],
    ]);

    $fresh = $institution->fresh();

    expect($fresh->facilities)->toBe(['parking' => true, 'wheelchair_access' => false])
        ->and($fresh->status)->toBe(InstitutionStatus::Verified)
        ->and($fresh->primaryAddress())->not->toBeNull()
        ->and($fresh->primaryAddress()?->country_code)->toBe('MY')
        ->and($fresh->effective_facilities)->toBe(['parking' => true]);
});

it('preserves explicit disabled facilities when the admin form is saved unchanged', function (): void {
    $administrator = facilitiesAdminUser();
    $institution = Institution::factory()->create([
        'status' => InstitutionStatus::Verified,
        'facilities' => ['parking' => true, 'wheelchair_access' => false],
    ]);

    saveInstitutionFacilitiesForm($administrator, $institution);

    expect($institution->fresh()->facilities)->toBe(['parking' => true, 'wheelchair_access' => false]);
});

it('clears the facility map and restores venue inheritance when both admin lists are emptied', function (): void {
    $administrator = facilitiesAdminUser();
    $institution = Institution::factory()->create(['facilities' => ['parking' => true]]);
    $venue = Venue::factory()->create(['status' => 'verified', 'visibility' => 'public']);

    SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => (string) $venue->getKey(), 'role' => InstitutionVenueRole::Operated, 'is_primary' => true],
    ]);
    adminFacilitiesGrant($venue, adminFacilitiesType('wheelchair_access'));

    saveInstitutionFacilitiesForm($administrator, $institution, [
        'facilities' => [],
        'facilities_disabled' => [],
    ]);

    $fresh = $institution->fresh();

    expect($fresh->facilities)->toBeNull()
        ->and($fresh->effective_facilities)->toBe(['wheelchair_access' => true]);
});

it('edits the disabled list to suppress and re-enable an inherited venue facility', function (): void {
    $administrator = facilitiesAdminUser();
    $institution = Institution::factory()->create();
    $venue = Venue::factory()->create(['status' => 'verified', 'visibility' => 'public']);

    SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => (string) $venue->getKey(), 'role' => InstitutionVenueRole::Operated, 'is_primary' => true],
    ]);
    adminFacilitiesGrant($venue, adminFacilitiesType('wheelchair_access'));

    saveInstitutionFacilitiesForm($administrator, $institution, [
        'facilities_disabled' => ['wheelchair_access'],
    ]);

    expect($institution->fresh()->facilities)->toBe(['wheelchair_access' => false])
        ->and($institution->fresh()->effective_facilities)->toBe([]);

    saveInstitutionFacilitiesForm($administrator, $institution->fresh(), [
        'facilities' => ['wheelchair_access'],
        'facilities_disabled' => [],
    ]);

    expect($institution->fresh()->facilities)->toBe(['wheelchair_access' => true])
        ->and($institution->fresh()->effective_facilities)->toBe(['wheelchair_access' => true]);
});

it('inherits only public facilities from active operated venues on the saved institution', function (): void {
    $administrator = facilitiesAdminUser();
    $institution = Institution::factory()->create();

    $operated = Venue::factory()->create(['status' => 'verified', 'visibility' => 'public']);
    $privateOperated = Venue::factory()->create(['status' => 'verified', 'visibility' => 'private']);
    $rejectedOperated = Venue::factory()->create(['status' => 'rejected', 'visibility' => 'public']);
    $preferred = Venue::factory()->create(['status' => 'verified', 'visibility' => 'public']);

    SyncInstitutionVenuesAction::run($institution, [
        ['venue_id' => (string) $operated->getKey(), 'role' => InstitutionVenueRole::Operated, 'is_primary' => true],
        ['venue_id' => (string) $privateOperated->getKey(), 'role' => InstitutionVenueRole::Operated],
        ['venue_id' => (string) $rejectedOperated->getKey(), 'role' => InstitutionVenueRole::Operated],
        ['venue_id' => (string) $preferred->getKey(), 'role' => InstitutionVenueRole::Preferred, 'is_primary' => true],
    ]);

    adminFacilitiesGrant($operated, adminFacilitiesType('parking'));
    adminFacilitiesGrant($operated, adminFacilitiesType('ablution_area'), availability: 'unavailable');
    adminFacilitiesGrant($operated, adminFacilitiesType('cafeteria', active: false));
    adminFacilitiesGrant($privateOperated, adminFacilitiesType('oku'));
    adminFacilitiesGrant($rejectedOperated, adminFacilitiesType('air_conditioning'));
    adminFacilitiesGrant($preferred, adminFacilitiesType('women_section'));

    saveInstitutionFacilitiesForm($administrator, $institution);

    expect($institution->fresh()->effective_facilities)->toBe(['parking' => true]);
});

it('exposes both facility checkbox lists with the canonical options on the edit form', function (): void {
    $administrator = facilitiesAdminUser();
    $institution = Institution::factory()->create();

    OwnerContext::withOwner(null, function () use ($administrator, $institution): void {
        Livewire::actingAs($administrator)
            ->test(EditInstitution::class, ['record' => $institution->getKey()])
            ->assertFormFieldExists('facilities', function (CheckboxList $field): bool {
                expect($field->getOptions())->toBe(InstitutionFacilities::options());

                return true;
            })
            ->assertFormFieldExists('facilities_disabled', function (CheckboxList $field): bool {
                expect($field->getOptions())->toBe(InstitutionFacilities::options());

                return true;
            });
    });
});

it('requires a status on the admin form', function (): void {
    $administrator = facilitiesAdminUser();
    $institution = Institution::factory()->create(['status' => InstitutionStatus::Verified]);

    OwnerContext::withOwner(null, function () use ($administrator, $institution): void {
        Livewire::actingAs($administrator)
            ->test(EditInstitution::class, ['record' => $institution->getKey()])
            ->fillForm(['status' => null])
            ->call('save')
            ->assertHasFormErrors(['status' => 'required']);
    });

    expect($institution->fresh()->status)->toBe(InstitutionStatus::Verified);
});

it('records one facilities signal when the admin form changes the own map', function (): void {
    $administrator = facilitiesAdminUser();
    $institution = Institution::factory()->create(['facilities' => ['parking' => true]]);

    saveInstitutionFacilitiesForm($administrator, $institution, [
        'facilities' => ['parking', 'oku'],
        'facilities_disabled' => ['wheelchair_access'],
    ]);

    $events = SignalEvent::query()->where('event_name', 'admin.institution_facilities.updated')->get();

    expect($events)->toHaveCount(1);

    $event = $events->firstOrFail();

    expect($event->event_category)->toBe('admin')
        ->and(data_get($event->properties, 'institution_id'))->toBe((string) $institution->getKey())
        ->and(data_get($event->properties, 'changed_codes'))->toBe(['oku', 'wheelchair_access'])
        ->and(data_get($event->properties, 'enabled_count'))->toBe(2)
        ->and(data_get($event->properties, 'disabled_count'))->toBe(1)
        ->and(data_get($event->properties, 'cleared'))->toBeFalse();
});

it('records one facilities signal when the admin form clears the map', function (): void {
    $administrator = facilitiesAdminUser();
    $institution = Institution::factory()->create(['facilities' => ['parking' => true]]);

    saveInstitutionFacilitiesForm($administrator, $institution, [
        'facilities' => [],
        'facilities_disabled' => [],
    ]);

    $events = SignalEvent::query()->where('event_name', 'admin.institution_facilities.updated')->get();

    expect($events)->toHaveCount(1);

    $event = $events->firstOrFail();

    expect(data_get($event->properties, 'institution_id'))->toBe((string) $institution->getKey())
        ->and(data_get($event->properties, 'changed_codes'))->toBe(['parking'])
        ->and(data_get($event->properties, 'enabled_count'))->toBe(0)
        ->and(data_get($event->properties, 'disabled_count'))->toBe(0)
        ->and(data_get($event->properties, 'cleared'))->toBeTrue();
});

it('records no facilities signal when the admin form saves facilities unchanged', function (): void {
    $administrator = facilitiesAdminUser();
    $institution = Institution::factory()->create([
        'facilities' => ['parking' => true, 'wheelchair_access' => false],
    ]);

    saveInstitutionFacilitiesForm($administrator, $institution);

    expect($institution->fresh()->facilities)->toBe(['parking' => true, 'wheelchair_access' => false])
        ->and(SignalEvent::query()->where('event_name', 'admin.institution_facilities.updated')->count())->toBe(0);
});

it('records no facilities signal when the admin form save is invalid', function (): void {
    $administrator = facilitiesAdminUser();
    $institution = Institution::factory()->create([
        'status' => InstitutionStatus::Verified,
        'facilities' => ['parking' => true],
    ]);

    OwnerContext::withOwner(null, function () use ($administrator, $institution): void {
        Livewire::actingAs($administrator)
            ->test(EditInstitution::class, ['record' => $institution->getKey()])
            ->fillForm([
                'status' => null,
                'facilities' => ['parking', 'oku'],
            ])
            ->call('save')
            ->assertHasFormErrors(['status' => 'required']);
    });

    expect($institution->fresh()->facilities)->toBe(['parking' => true])
        ->and(SignalEvent::query()->where('event_name', 'admin.institution_facilities.updated')->count())->toBe(0);
});
