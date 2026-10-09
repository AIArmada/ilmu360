<?php

declare(strict_types=1);

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Actions\Share\PublishCommunityReleaseAction;
use App\Filament\Resources\CommunityReleases\Pages\ListCommunityReleases;
use App\Filament\Resources\CommunityReleases\Pages\ViewCommunityRelease;
use App\Models\CommunityRelease;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Support\Share\CommunityReleaseReader;
use App\Support\ShareTracking\ShareTrackingProfile;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setPermissionsTeamId(null);
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function communityStaff(string $role = 'admin'): User
{
    $staff = User::factory()->create();
    setPermissionsTeamId(null);
    $staff->assignRole($role);
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $staff;
}

function communityAffiliate(string $code, bool $marked = true): Affiliate
{
    return Affiliate::query()->create([
        'code' => $code,
        'handle' => $code,
        'name' => 'Community test affiliate',
        'status' => 'active',
        'metadata' => $marked ? ShareTrackingProfile::memberMetadata() : [],
    ]);
}

function communityConversion(Affiliate $affiliate, array $overrides = []): AffiliateConversion
{
    return AffiliateConversion::query()->create(array_merge([
        'affiliate_id' => $affiliate->getKey(),
        'affiliate_code' => $affiliate->code,
        'commission_currency' => 'MYR',
        'status' => 'approved',
        'channel' => 'link',
        'conversion_type' => 'event_checkin',
        'occurred_at' => CarbonImmutable::parse('2026-05-10T00:00:00Z'),
    ], $overrides));
}

function communityCleanPair(Affiliate $affiliate, ?string $channel = 'link', string $type = 'event_checkin'): array
{
    $sharer = User::factory()->create();
    $actor = User::factory()->create();

    communityConversion($affiliate, [
        'sharer_user_id' => (string) $sharer->getKey(),
        'actor_user_id' => (string) $actor->getKey(),
        'channel' => $channel,
        'conversion_type' => $type,
    ]);

    return [$sharer, $actor];
}

function communityFraudSignal(Affiliate $affiliate, ?string $conversionId, ?string $touchpointId = null): AffiliateFraudSignal
{
    return AffiliateFraudSignal::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'conversion_id' => $conversionId,
        'touchpoint_id' => $touchpointId,
        'rule_code' => 'COMMUNITY-PROBE',
        'risk_points' => 90,
        'severity' => 'high',
        'description' => 'community release test signal',
        'status' => 'confirmed',
        'detected_at' => CarbonImmutable::parse('2026-05-11T00:00:00Z'),
        'confirmed_at' => CarbonImmutable::parse('2026-05-12T00:00:00Z'),
    ]);
}

it('publishes a fixed month behind dual thresholds with per-cell dual gating', function () {
    $affiliate = communityAffiliate('community-r8-1');
    $staff = communityStaff();

    for ($i = 0; $i < 10; $i++) {
        communityCleanPair($affiliate);
    }

    // Recent months refuse: releases are delayed, never instantaneous.
    expect(fn () => PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-01T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'at least 7 days ago');

    $release = PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    expect($release->release_key)->toBe('community:2026-05')
        ->and($release->period_start)->toBe('2026-05-01')
        ->and($release->period_end)->toBe('2026-05-31')
        ->and($release->sharer_count)->toBe(10)
        ->and($release->participant_count)->toBe(10)
        ->and($release->cells['total_outcomes'])->toBe(10)
        ->and($release->cells['by_channel'])->toBe(['link' => 10])
        ->and($release->cells['by_outcome'])->toBe(['event_checkin' => 10])
        ->and($release->cells['suppressed'])->toBe([])
        ->and($release->published_by_role)->toBe('admin');

    // Repetition converges on the published row; rows never rewrite.
    $again = PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-11T00:00:00Z'),
    );

    expect((string) $again->getKey())->toBe((string) $release->getKey())
        ->and(CommunityRelease::query()->where('release_key', 'community:2026-05')->count())->toBe(1);

    expect(fn () => $release->forceFill(['sharer_count' => 99])->save())
        ->toThrow(LogicException::class, 'immutable');

    // Below-threshold months refuse with honest insufficient-data.
    expect(fn () => PublishCommunityReleaseAction::run(
        '2026-04', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'Insufficient data');
});

it('refuses arbitrary ranges and verifies real publisher roles', function () {
    $staff = communityStaff();
    $member = User::factory()->create();
    $viewer = communityStaff('viewer');

    expect(fn () => PublishCommunityReleaseAction::run('2026-05-01', 'user', (string) $staff->getKey()))
        ->toThrow(InvalidArgumentException::class, 'fixed calendar month')
        ->and(fn () => PublishCommunityReleaseAction::run('May 2026', 'user', (string) $staff->getKey()))
        ->toThrow(InvalidArgumentException::class, 'fixed calendar month')
        ->and(fn () => PublishCommunityReleaseAction::run('2026-05', 'system', null))
        ->toThrow(InvalidArgumentException::class, 'staff/admin user identity')
        ->and(fn () => PublishCommunityReleaseAction::run('2026-05', 'user', (string) Str::uuid()))
        ->toThrow(InvalidArgumentException::class, 'staff/admin user identity')
        ->and(fn () => PublishCommunityReleaseAction::run('2026-05', 'user', (string) $member->getKey()))
        ->toThrow(InvalidArgumentException::class, 'staff/admin user identity')
        ->and(fn () => PublishCommunityReleaseAction::run('2026-05', 'user', (string) $viewer->getKey()))
        ->toThrow(InvalidArgumentException::class, 'staff/admin user identity');
});

it('suppresses a whole cut when one cell fails either side of the dual threshold', function () {
    $affiliate = communityAffiliate('community-r8-cut');
    $staff = communityStaff();

    // Channel link: 10 distinct sharers but only 5 distinct participants.
    $linkActors = [];

    for ($i = 0; $i < 5; $i++) {
        $linkActors[] = User::factory()->create();
    }

    for ($i = 0; $i < 10; $i++) {
        communityConversion($affiliate, [
            'sharer_user_id' => (string) User::factory()->create()->getKey(),
            'actor_user_id' => (string) $linkActors[$i % 5]->getKey(),
            'channel' => 'link',
        ]);
    }

    // Channel copy: fully evidenced, carries the global thresholds.
    for ($i = 0; $i < 10; $i++) {
        communityCleanPair($affiliate, 'copy');
    }

    $release = PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    // Global: 20 sharers, 15 participants. The link cell fails the
    // participant side, so the whole channel cut suppresses rather than
    // leaking the sparse side through the total. The outcome cut (one
    // fully evidenced kind) still publishes.
    expect($release->sharer_count)->toBe(20)
        ->and($release->participant_count)->toBe(15)
        ->and($release->cells['total_outcomes'])->toBe(20)
        ->and($release->cells['by_channel'])->toBeNull()
        ->and($release->cells['by_outcome'])->toBe(['event_checkin' => 20])
        ->and($release->cells['suppressed'])->toBe(['by_channel']);
});

it('never lets one prolific sharer disclose a channel cell', function () {
    $affiliate = communityAffiliate('community-r8-solo');
    $staff = communityStaff();

    // Solo channel: 25 actions, ONE sharer, 10 distinct actors.
    $solo = User::factory()->create();

    for ($i = 0; $i < 25; $i++) {
        communityConversion($affiliate, [
            'sharer_user_id' => (string) $solo->getKey(),
            'actor_user_id' => (string) User::factory()->create()->getKey(),
            'channel' => 'solo',
        ]);
    }

    for ($i = 0; $i < 10; $i++) {
        communityCleanPair($affiliate, 'crowd');
    }

    $release = PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    expect($release->sharer_count)->toBe(11)
        ->and($release->cells['total_outcomes'])->toBe(35)
        ->and($release->cells['by_channel'])->toBeNull()
        ->and($release->cells['suppressed'])->toContain('by_channel');
});

it('excludes staff actors from every proven count and cell', function () {
    $affiliate = communityAffiliate('community-r8-staff');
    $staff = communityStaff();

    $sharers = [];

    for ($i = 0; $i < 10; $i++) {
        [$sharer] = communityCleanPair($affiliate);
        $sharers[] = $sharer;
    }

    // Five more shares acted on by staff members: staff act staff-side,
    // never as community participants, and the single canonical proven
    // set excludes them from counts, totals, and every cell alike.
    for ($i = 0; $i < 5; $i++) {
        communityConversion($affiliate, [
            'sharer_user_id' => (string) $sharers[$i]->getKey(),
            'actor_user_id' => (string) communityStaff($i % 2 === 0 ? 'admin' : 'moderator')->getKey(),
        ]);
    }

    $release = PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    expect($release->sharer_count)->toBe(10)
        ->and($release->participant_count)->toBe(10)
        ->and($release->cells['total_outcomes'])->toBe(10)
        ->and($release->cells['evidence_basis'])->toBe('proven-only')
        ->and($release->cells['by_channel'])->toBe(['link' => 10])
        ->and($release->cells['by_outcome'])->toBe(['event_checkin' => 10]);
});

it('excludes commercial, invalid, fraudulent, and unproven evidence consistently', function () {
    $affiliate = communityAffiliate('community-r8-noise');
    $commercial = communityAffiliate('community-r8-comm', false);
    $staff = communityStaff();

    $sharers = [];

    for ($i = 0; $i < 10; $i++) {
        [$sharer] = communityCleanPair($affiliate);
        $sharers[] = $sharer;
    }

    // Commercial affiliate outcomes never enter share evidence.
    for ($i = 0; $i < 5; $i++) {
        communityConversion($commercial, [
            'sharer_user_id' => (string) User::factory()->create()->getKey(),
            'actor_user_id' => (string) User::factory()->create()->getKey(),
        ]);
    }

    // Invalid statuses: rejected, reversed, pending.
    foreach (['rejected', 'reversed', 'pending'] as $status) {
        communityConversion($affiliate, [
            'sharer_user_id' => (string) User::factory()->create()->getKey(),
            'actor_user_id' => (string) User::factory()->create()->getKey(),
            'status' => $status,
        ]);
    }

    // Non-dawah kinds are not participation evidence.
    foreach (['purchase', 'order_paid'] as $type) {
        communityConversion($affiliate, [
            'sharer_user_id' => (string) User::factory()->create()->getKey(),
            'actor_user_id' => (string) User::factory()->create()->getKey(),
            'conversion_type' => $type,
        ]);
    }

    // Confirmed conversion fraud excludes the row (fresh users: taint
    // must not bleed onto the clean evidence).
    $fraudRow = communityConversion($affiliate, [
        'sharer_user_id' => (string) User::factory()->create()->getKey(),
        'actor_user_id' => (string) User::factory()->create()->getKey(),
    ]);
    communityFraudSignal($affiliate, (string) $fraudRow->getKey());

    // A sharer tainted by outbound-share fraud loses every row.
    $tainted = User::factory()->create();
    $attribution = AffiliateAttribution::query()->create([
        'affiliate_id' => $affiliate->getKey(),
        'affiliate_code' => $affiliate->code,
        'attribution_type' => 'landing',
        'visitor_key' => 'visitor-taint-1',
        'first_seen_at' => CarbonImmutable::parse('2026-05-02T00:00:00Z'),
        'last_seen_at' => CarbonImmutable::parse('2026-05-02T00:00:00Z'),
        'sharer_user_id' => (string) $tainted->getKey(),
    ]);
    $touchpoint = AffiliateTouchpoint::query()->create([
        'affiliate_attribution_id' => $attribution->getKey(),
        'affiliate_id' => $affiliate->getKey(),
        'affiliate_code' => $affiliate->code,
        'touchpoint_type' => 'outbound_share',
        'touched_at' => CarbonImmutable::parse('2026-05-03T00:00:00Z'),
    ]);
    communityFraudSignal($affiliate, null, (string) $touchpoint->getKey());
    communityConversion($affiliate, [
        'sharer_user_id' => (string) $tainted->getKey(),
        'actor_user_id' => (string) User::factory()->create()->getKey(),
    ]);

    // Unproven sharers: null and ghost.
    communityConversion($affiliate, [
        'sharer_user_id' => null,
        'actor_user_id' => (string) User::factory()->create()->getKey(),
    ]);
    communityConversion($affiliate, [
        'sharer_user_id' => (string) Str::uuid(),
        'actor_user_id' => (string) User::factory()->create()->getKey(),
    ]);

    // Ghost and null actors: excluded from the single canonical proven
    // set — invisible in counts, totals, and every cell.
    communityConversion($affiliate, [
        'sharer_user_id' => (string) $sharers[1]->getKey(),
        'actor_user_id' => (string) Str::uuid(),
    ]);
    communityConversion($affiliate, [
        'sharer_user_id' => (string) $sharers[2]->getKey(),
        'actor_user_id' => null,
    ]);

    $release = PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    // Only the 10 clean proven rows survive, in counts, totals, AND cuts
    // alike: one canonical qualified/proven participant set everywhere.
    expect($release->sharer_count)->toBe(10)
        ->and($release->participant_count)->toBe(10)
        ->and($release->cells['total_outcomes'])->toBe(10)
        ->and($release->cells['evidence_basis'])->toBe('proven-only')
        ->and($release->cells['by_channel'])->toBe(['link' => 10])
        ->and($release->cells['by_outcome'])->toBe(['event_checkin' => 10])
        ->and($release->cells['suppressed'])->toBe([]);
});

it('publishes unknown channels as an explicit dual-gated cell', function () {
    $affiliate = communityAffiliate('community-r8-unknown');
    $staff = communityStaff();

    for ($i = 0; $i < 10; $i++) {
        communityCleanPair($affiliate, 'link');
        communityCleanPair($affiliate, null);
    }

    $release = PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    expect($release->cells['by_channel'])->toBe(['link' => 10, 'unknown' => 10]);

    // A sparse unknown side suppresses the whole cut instead of leaking
    // through the complement.
    for ($i = 0; $i < 10; $i++) {
        communityConversion($affiliate, [
            'sharer_user_id' => (string) User::factory()->create()->getKey(),
            'actor_user_id' => (string) User::factory()->create()->getKey(),
            'channel' => 'link',
            'occurred_at' => CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        ]);
    }

    for ($i = 0; $i < 3; $i++) {
        communityConversion($affiliate, [
            'sharer_user_id' => (string) User::factory()->create()->getKey(),
            'actor_user_id' => (string) User::factory()->create()->getKey(),
            'channel' => null,
            'occurred_at' => CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        ]);
    }

    $june = PublishCommunityReleaseAction::run(
        '2026-06', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-07-10T00:00:00Z'),
    );

    expect($june->cells['total_outcomes'])->toBe(13)
        ->and($june->cells['by_channel'])->toBeNull()
        ->and($june->cells['suppressed'])->toBe(['by_channel']);
});

it('serves the latest period publicly with honest states and no history surface', function () {
    $affiliate = communityAffiliate('community-r8-public');
    $staff = communityStaff();

    // Nothing published yet: honest insufficient-data, not an error.
    $this->getJson('/api/v1/community/release')->assertOk()
        ->assertJsonPath('data.status', 'insufficient-data');

    for ($i = 0; $i < 10; $i++) {
        communityCleanPair($affiliate);
        communityConversion($affiliate, [
            'sharer_user_id' => (string) User::factory()->create()->getKey(),
            'actor_user_id' => (string) User::factory()->create()->getKey(),
            'occurred_at' => CarbonImmutable::parse('2026-06-10T00:00:00Z'),
        ]);
    }

    // Publish June first, then backfill May: the public surface still
    // shows the latest PERIOD, and per-month drilldown does not exist.
    PublishCommunityReleaseAction::run(
        '2026-06', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-07-10T00:00:00Z'),
    );
    PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-07-11T00:00:00Z'),
    );

    $this->getJson('/api/v1/community/release')->assertOk()
        ->assertJsonPath('data.status', 'released')
        ->assertJsonPath('data.period.start', '2026-06-01')
        ->assertJsonPath('data.period.end', '2026-06-30')
        ->assertJsonPath('data.sharer_count', 10);

    $this->getJson('/api/v1/community/release/2026-05')->assertNotFound();
    $this->getJson('/api/v1/community/releases')->assertNotFound();

    expect(app(CommunityReleaseReader::class)->latest()['period']['start'])->toBe('2026-06-01');
});

it('authorizes viewing and publishing separately', function () {
    $admin = communityStaff('admin');
    $super = communityStaff('super_admin');
    $viewer = communityStaff('viewer');
    $member = User::factory()->create();

    expect($admin->can('viewAny', CommunityRelease::class))->toBeTrue()
        ->and($admin->can('publish', CommunityRelease::class))->toBeTrue()
        ->and($super->can('publish', CommunityRelease::class))->toBeTrue()
        ->and($viewer->can('viewAny', CommunityRelease::class))->toBeTrue()
        ->and($viewer->can('publish', CommunityRelease::class))->toBeFalse()
        ->and($member->can('viewAny', CommunityRelease::class))->toBeFalse()
        ->and($member->can('publish', CommunityRelease::class))->toBeFalse();
});

it('publishes months through the operator command with truthful exits', function () {
    $affiliate = communityAffiliate('community-r8-cmd');
    $staff = communityStaff();

    for ($i = 0; $i < 10; $i++) {
        communityCleanPair($affiliate);
    }

    Artisan::call('rewards:publish-community-release', [
        'month' => '2026-05',
        '--actor' => (string) $staff->getKey(),
        '--as-of' => '2026-06-10T00:00:00Z',
    ]);

    expect(Artisan::output())->toContain('Published community release for 2026-05-01..2026-05-31')
        ->and(CommunityRelease::query()->where('release_key', 'community:2026-05')->count())->toBe(1);

    // Idempotent repetition still exits success.
    expect(Artisan::call('rewards:publish-community-release', [
        'month' => '2026-05',
        '--actor' => (string) $staff->getKey(),
        '--as-of' => '2026-06-10T00:00:00Z',
    ]))->toBe(0);

    // Insufficient data is a truthful failure, not success.
    expect(Artisan::call('rewards:publish-community-release', [
        'month' => '2026-04',
        '--actor' => (string) $staff->getKey(),
        '--as-of' => '2026-06-10T00:00:00Z',
    ]))->toBe(1);

    // Usage and authorization errors exit invalid.
    expect(Artisan::call('rewards:publish-community-release', ['month' => '2026-05']))->toBe(2)
        ->and(Artisan::call('rewards:publish-community-release', [
            'month' => 'May 2026',
            '--actor' => (string) $staff->getKey(),
        ]))->toBe(2)
        ->and(Artisan::call('rewards:publish-community-release', [
            'month' => '2026-05',
            '--actor' => (string) User::factory()->create()->getKey(),
        ]))->toBe(2);
});

it('lists, views, and publishes releases in admin', function () {
    $affiliate = communityAffiliate('community-r8-admin');
    $staff = communityStaff();

    for ($i = 0; $i < 10; $i++) {
        communityCleanPair($affiliate);
    }

    Livewire::actingAs($staff)
        ->test(ListCommunityReleases::class)
        ->assertOk()
        ->callAction('publish_release', data: ['month' => '2026-05'])
        ->assertHasNoActionErrors();

    $release = CommunityRelease::query()->where('release_key', 'community:2026-05')->firstOrFail();

    expect((int) $release->sharer_count)->toBe(10);

    Livewire::actingAs($staff)
        ->test(ListCommunityReleases::class)
        ->assertOk()
        ->assertSee('community:2026-05');

    Livewire::actingAs($staff)
        ->test(ViewCommunityRelease::class, ['record' => $release->getKey()])
        ->assertOk()
        ->assertSee('Distinct sharers');
});

it('hides unknown, corrupt, staff, and purchaser rows from every proven count and cell', function () {
    $affiliate = communityAffiliate('community-r8-proven10');
    $staff = communityStaff();

    for ($i = 0; $i < 10; $i++) {
        communityCleanPair($affiliate);
    }

    $extraSharer = fn (): string => (string) User::factory()->create()->getKey();

    communityConversion($affiliate, [
        'sharer_user_id' => $extraSharer(),
        'actor_user_id' => null,
    ]);

    communityConversion($affiliate, [
        'sharer_user_id' => $extraSharer(),
        'actor_user_id' => (string) Str::uuid(),
    ]);

    communityConversion($affiliate, [
        'sharer_user_id' => $extraSharer(),
        'actor_user_id' => 'not-a-uuid',
    ]);

    communityConversion($affiliate, [
        'sharer_user_id' => $extraSharer(),
        'actor_user_id' => (string) communityStaff('moderator')->getKey(),
    ]);

    $event = Event::factory()->create(['status' => 'approved', 'visibility' => 'public']);
    $paidRegistration = Registration::factory()->create([
        'event_id' => $event->getKey(),
        'total_amount' => 1000,
        'payment_status' => 'paid',
    ]);

    communityConversion($affiliate, [
        'sharer_user_id' => $extraSharer(),
        'actor_user_id' => (string) User::factory()->create()->getKey(),
        'conversion_type' => 'event_registration',
        'metadata' => ['registration_id' => (string) $paidRegistration->getKey()],
    ]);

    $release = PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    expect($release->sharer_count)->toBe(10)
        ->and($release->participant_count)->toBe(10)
        ->and($release->cells['total_outcomes'])->toBe(10)
        ->and($release->cells['evidence_basis'])->toBe('proven-only')
        ->and($release->cells['by_channel'])->toBe(['link' => 10])
        ->and($release->cells['by_outcome'])->toBe(['event_checkin' => 10])
        ->and($release->cells['suppressed'])->toBe([])
        ->and($release->cells)->not->toHaveKey('total_all');

    $reader = app(CommunityReleaseReader::class)->latest();

    expect($reader['status'])->toBe('released')
        ->and($reader['sharer_count'])->toBe(10)
        ->and($reader['participant_count'])->toBe(10)
        ->and($reader['cells']['total_outcomes'])->toBe(10)
        ->and($reader['cells']['evidence_basis'])->toBe('proven-only');

    $this->getJson('/api/v1/community/release')->assertOk()
        ->assertJsonPath('data.sharer_count', 10)
        ->assertJsonPath('data.participant_count', 10)
        ->assertJsonPath('data.cells.total_outcomes', 10)
        ->assertJsonPath('data.cells.evidence_basis', 'proven-only');

    Livewire::actingAs($staff)
        ->test(ViewCommunityRelease::class, ['record' => $release->getKey()])
        ->assertOk()
        ->assertSee('10')
        ->assertSee('proven-only');
});

it('includes free event registrations while excluding paid purchaser linkage', function () {
    $affiliate = communityAffiliate('community-r8-purchaser');
    $staff = communityStaff();

    for ($i = 0; $i < 10; $i++) {
        communityCleanPair($affiliate);
    }

    $event = Event::factory()->create(['status' => 'approved', 'visibility' => 'public']);
    $freeRegistration = Registration::factory()->create([
        'event_id' => $event->getKey(),
        'total_amount' => 0,
        'payment_status' => 'free',
    ]);

    communityConversion($affiliate, [
        'sharer_user_id' => (string) User::factory()->create()->getKey(),
        'actor_user_id' => (string) User::factory()->create()->getKey(),
        'conversion_type' => 'event_registration',
        'metadata' => ['registration_id' => (string) $freeRegistration->getKey()],
    ]);

    $release = PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    );

    expect($release->sharer_count)->toBe(11)
        ->and($release->participant_count)->toBe(11)
        ->and($release->cells['total_outcomes'])->toBe(11)
        ->and($release->cells['by_channel'])->toBe(['link' => 11])
        ->and($release->cells['by_outcome'])->toBeNull()
        ->and($release->cells['suppressed'])->toBe(['by_outcome']);
});

it('never lets repeated events satisfy the distinct-person threshold', function () {
    $affiliate = communityAffiliate('community-r8-repeats');
    $staff = communityStaff();

    $sharers = [];

    for ($i = 0; $i < 5; $i++) {
        $sharers[] = User::factory()->create();
    }

    $actors = [];

    for ($i = 0; $i < 5; $i++) {
        $actors[] = User::factory()->create();
    }

    for ($i = 0; $i < 20; $i++) {
        communityConversion($affiliate, [
            'sharer_user_id' => (string) $sharers[$i % 5]->getKey(),
            'actor_user_id' => (string) $actors[$i % 5]->getKey(),
        ]);
    }

    expect(fn () => PublishCommunityReleaseAction::run(
        '2026-05', 'user', (string) $staff->getKey(), CarbonImmutable::parse('2026-06-10T00:00:00Z'),
    ))->toThrow(InvalidArgumentException::class, 'Insufficient data');
});
