<?php

use AIArmada\CommerceSupport\Models\Role;
use AIArmada\Events\Models\FacilityType;
use App\Actions\Venues\SaveVenueAction;
use App\Forms\SharedFormSchema;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Servers\MemberServer;
use App\Mcp\Tools\Admin\AdminUpdateRecordTool;
use App\Mcp\Tools\Member\MemberUpdateRecordTool;
use App\Models\Institution;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('preserves omitted address fields in the shared address payload', function (): void {
    $country = ensureTestMalaysiaCountry();

    $payload = SharedFormSchema::prepareAddressPersistenceData([
        'country_id' => (string) $country->getKey(),
        'line1' => 'Jalan Duta',
    ]);

    expect($payload)
        ->toHaveKey('country_id')
        ->toHaveKey('line1')
        ->and($payload['country_id'])->toBe((string) $country->getKey())
        ->and($payload['line1'])->toBe('Jalan Duta')
        ->and($payload)->not->toHaveKey('lat')
        ->and($payload)->not->toHaveKey('lng')
        ->and($payload)->not->toHaveKey('google_maps_url')
        ->and($payload)->not->toHaveKey('google_place_id')
        ->and($payload)->not->toHaveKey('waze_url');
});

it('ignores hidden institution slug injections and preserves coordinates across admin and member scopes', function (): void {
    $country = ensureTestMalaysiaCountry();
    $admin = securityChecklistAdminUser();
    $adminInstitution = Institution::factory()->create([
        'name' => 'Security Checklist Admin Institution',
        'status' => 'verified',
    ]);
    $adminAddress = $adminInstitution->fresh()?->primaryAddress();
    $adminLat = (float) ($adminAddress?->lat ?? 0.0);
    $adminLng = (float) ($adminAddress?->lng ?? 0.0);

    AdminServer::actingAs($admin)
        ->tool(AdminUpdateRecordTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $adminInstitution->getKey(),
            'payload' => [
                'name' => 'Security Checklist Admin Institution Updated',
                'nickname' => 'Security Checklist Masjid',
                'type' => 'masjid',
                'status' => 'pending',
                'allow_public_event_submission' => true,
                'slug' => 'attempted-admin-institution-injection',
                'address' => [
                    'country_id' => (string) $country->getKey(),
                ],
            ],
        ])
        ->assertOk();

    expect($adminInstitution->fresh()?->slug)->not->toBe('attempted-admin-institution-injection')
        ->and(abs(((float) ($adminInstitution->fresh()?->primaryAddress()?->lat ?? 0.0)) - $adminLat))->toBeLessThan(0.000001)
        ->and(abs(((float) ($adminInstitution->fresh()?->primaryAddress()?->lng ?? 0.0)) - $adminLng))->toBeLessThan(0.000001);

    [$member, $memberInstitution] = securityChecklistMemberInstitutionContext();
    $memberAddress = $memberInstitution->fresh()?->primaryAddress();
    $memberLat = (float) ($memberAddress?->lat ?? 0.0);
    $memberLng = (float) ($memberAddress?->lng ?? 0.0);

    MemberServer::actingAs($member)
        ->tool(MemberUpdateRecordTool::class, [
            'resource_key' => 'institutions',
            'record_key' => $memberInstitution->getKey(),
            'payload' => [
                'name' => 'Security Checklist Member Institution Updated',
                'nickname' => 'Security Checklist Member Masjid',
                'type' => 'masjid',
                'status' => 'pending',
                'allow_public_event_submission' => true,
                'slug' => 'attempted-member-institution-injection',
                'address' => [
                    'country_id' => (string) $country->getKey(),
                ],
            ],
        ])
        ->assertOk();

    expect($memberInstitution->fresh()?->slug)->not->toBe('attempted-member-institution-injection')
        ->and(abs(((float) ($memberInstitution->fresh()?->primaryAddress()?->lat ?? 0.0)) - $memberLat))->toBeLessThan(0.000001)
        ->and(abs(((float) ($memberInstitution->fresh()?->primaryAddress()?->lng ?? 0.0)) - $memberLng))->toBeLessThan(0.000001);
});

it('syncs venue facility codes as VenueFacility records', function (): void {
    FacilityType::factory()->create(['code' => 'parking', 'name' => 'Parking', 'is_active' => true]);
    FacilityType::factory()->create(['code' => 'women_section', 'name' => 'Women Section', 'is_active' => true]);
    FacilityType::factory()->create(['code' => 'oku', 'name' => 'OKU', 'is_active' => true]);

    $venue = Venue::factory()->create([
        'name' => 'Security Checklist Venue',
        'type' => 'dewan',
        'status' => 'verified',
    ]);

    app(SaveVenueAction::class)->handle([
        'name' => 'Security Checklist Venue',
        'type' => 'dewan',
        'status' => 'verified',
        'facilities' => ['parking', 'women_section'],
    ], $venue);

    $fresh = $venue->fresh();
    $fresh->load('facilities.facilityType');

    expect($fresh->facilities)->toHaveCount(2);
    expect($fresh->facilities->pluck('facilityType.code')->sort()->values()->all())->toBe(['parking', 'women_section']);
});

function securityChecklistAdminUser(): User
{
    $roleName = 'super_admin';

    if (! Role::query()->where('name', $roleName)->where('guard_name', 'web')->exists()) {
        $roleRecord = new Role;
        $roleRecord->forceFill([
            'id' => (string) Str::uuid(),
            'name' => $roleName,
            'guard_name' => 'web',
        ])->save();
    }

    $user = User::factory()->create();
    $user->assignRole($roleName);

    return $user;
}

/**
 * @return array{0: User, 1: Institution}
 */
function securityChecklistMemberInstitutionContext(): array
{
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    addTestMember($institution, $member, 'admin');

    return [$member, $institution];
}
