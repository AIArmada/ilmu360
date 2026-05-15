<?php

use App\Actions\Membership\AddMemberToSubject;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    securityChecklistMalaysiaCountryExists();
});

it('preserves omitted address fields in the shared address payload', function (): void {
    $payload = SharedFormSchema::prepareAddressPersistenceData([
        'country_id' => 132,
        'line1' => 'Jalan Duta',
    ]);

    expect($payload)
        ->toHaveKey('country_id')
        ->toHaveKey('line1')
        ->and($payload['country_id'])->toBe(132)
        ->and($payload['line1'])->toBe('Jalan Duta')
        ->and($payload)->not->toHaveKey('lat')
        ->and($payload)->not->toHaveKey('lng')
        ->and($payload)->not->toHaveKey('google_maps_url')
        ->and($payload)->not->toHaveKey('google_place_id')
        ->and($payload)->not->toHaveKey('waze_url');
});

it('ignores hidden institution slug injections and preserves coordinates across admin and member scopes', function (): void {
    $admin = securityChecklistAdminUser();
    $adminInstitution = Institution::factory()->create([
        'name' => 'Security Checklist Admin Institution',
        'status' => 'verified',
        'is_active' => true,
    ]);
    $adminAddress = $adminInstitution->fresh()?->addressModel;
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
                'is_active' => true,
                'allow_public_event_submission' => true,
                'slug' => 'attempted-admin-institution-injection',
                'address' => [
                    'country_id' => 132,
                ],
            ],
        ])
        ->assertOk();

    expect($adminInstitution->fresh()?->slug)->not->toBe('attempted-admin-institution-injection')
        ->and(abs(((float) ($adminInstitution->fresh()?->addressModel?->lat ?? 0.0)) - $adminLat))->toBeLessThan(0.000001)
        ->and(abs(((float) ($adminInstitution->fresh()?->addressModel?->lng ?? 0.0)) - $adminLng))->toBeLessThan(0.000001);

    [$member, $memberInstitution] = securityChecklistMemberInstitutionContext();
    $memberAddress = $memberInstitution->fresh()?->addressModel;
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
                'is_active' => true,
                'allow_public_event_submission' => true,
                'slug' => 'attempted-member-institution-injection',
                'address' => [
                    'country_id' => 132,
                ],
            ],
        ])
        ->assertOk();

    expect($memberInstitution->fresh()?->slug)->not->toBe('attempted-member-institution-injection')
        ->and(abs(((float) ($memberInstitution->fresh()?->addressModel?->lat ?? 0.0)) - $memberLat))->toBeLessThan(0.000001)
        ->and(abs(((float) ($memberInstitution->fresh()?->addressModel?->lng ?? 0.0)) - $memberLng))->toBeLessThan(0.000001);
});

it('preserves explicit false venue facilities when saving a venue', function (): void {
    $venue = Venue::factory()->create([
        'name' => 'Security Checklist Venue',
        'type' => 'dewan',
        'status' => 'verified',
        'facilities' => [
            'parking' => true,
        ],
    ]);

    app(SaveVenueAction::class)->handle([
        'name' => 'Security Checklist Venue',
        'type' => 'dewan',
        'status' => 'verified',
        'facilities' => [
            'parking' => true,
            'women_section' => false,
        ],
    ], $venue);

    expect($venue->fresh()?->facilities)->toBe([
        'parking' => true,
        'women_section' => false,
    ]);
});

function securityChecklistMalaysiaCountryExists(): int
{
    DB::table('countries')->updateOrInsert([
        'id' => 132,
    ], [
        'iso2' => 'MY',
        'name' => 'Malaysia',
        'status' => 1,
        'phone_code' => '60',
        'iso3' => 'MYS',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);

    return 132;
}

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
        'is_active' => true,
    ]);

    $member = User::factory()->create([
        'phone' => '+60112223344',
        'phone_verified_at' => now(),
    ]);

    app(AddMemberToSubject::class)->handle($institution, $member, 'admin');

    return [$member, $institution];
}
